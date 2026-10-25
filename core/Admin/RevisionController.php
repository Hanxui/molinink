<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\PostWriter;
use Molin\Content\RevisionDiff;
use Molin\Content\RevisionRepository;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 修订版本比对屏(《113-后台修订版本比对实现规格》§1 属「文章」组,侧栏当前项借 posts 的位)。
 *
 * 两块内容并排:左「选中修订」、右「当前版本」。进页靠 `?post=<id>` 认文章,`?rev=<id>` 认左列选中
 * 哪一版;没给 rev 就默认最早那版(与基准图一致)。
 *
 * ── 「当前版本」的取数(§10)──
 * post_revisions 存的是**改之前**的那一版(见 PostWriter::snapshotRevision),所以右列"当前版本"
 * 不是某条修订,而是 posts 表里正在生效的正文;左列才是历史修订。
 *
 * ── 记录数上限(§7 金属行「每篇最多留最近 K 份」)──
 * K 与 PostWriter 的保留上限同源,引用它、不另写常量,免得两处漂移。
 *
 * ── 恢复动作(§11-4 已由作者定为「真恢复」)──
 * POST revisions/restore 过 CSRF 闸 → 校验修订属于这篇 → 把所选修订的 title/content 回写 posts
 * 并留一条新修订留痕(RevisionRepository::restore)→ 记审计 → 跳回比对页。校验不过/令牌不对一律
 * 回本页不落库(防串号)。不依赖 PostWriter —— 它与 PostsController 在别的同事手里。
 */
final class RevisionController
{
    private const PAGE = 'revisions';

    public function __construct(
        private readonly View $view,
        private readonly PostRepository $posts,
        private readonly RevisionRepository $revisions,
        private readonly UserRepository $users,
        private readonly Router $router,
        private readonly Authorizer $can
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        // 藏菜单不算防护:进页与扣扳机都在这道闸上再校验一次(《61》§3.1)。
        // 口径与 pages.php 的 revisions 条目同源:《113》§10 的 content.write 是《62》插件申报词表
        // 里的名,不在用户权限登记表,内置角色无从持有 —— 按 §10 意图落到 post.edit.*(2026-10-04 作者定)。
        if (!$user->holds('post.edit.own') && !$user->holds('post.edit.others')) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __('修订版本'),
                'capability' => 'post.edit.own / post.edit.others',
            ], $menu, 403);
        }

        if ($path === self::PAGE && !$request->isPost()) {
            return $this->render($request, $user, $menu);
        }

        if ($path === self::PAGE . '/restore' && $request->isPost()) {
            return $this->restore($request, $user, $menu);
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function render(Request $request, User $user, array $menu): Response
    {
        $postId = $request->queryInt('post', 0);
        $post = $postId > 0 ? $this->posts->find($postId) : null;

        if ($post === null) {
            throw new NotFoundException('找不到要比对的文章:' . $postId);
        }

        // 光有 post.edit.own 只说明"能改自己的",不说明"这篇是自己的"。
        // 归属得单独判(2026-10-17 修:此前只看能力,任何作者都能读他人正文、也能覆盖他人文章)。
        if (!$this->canTouch($user, $post)) {
            return $this->forbidden($menu);
        }

        $revisions = $this->revisions->forPost($postId);
        $count = count($revisions);

        // 左列选中:给了 rev 且属于这篇就用它,否则回落到最早一版(基准图默认)
        $rev = $request->queryInt('rev', 0);
        $selected = $rev > 0 ? $this->revisions->find($postId, $rev) : null;
        $selected ??= $revisions[0] ?? null;

        $currentWhen = $this->stamp($post->updated_at);
        $currentAuthor = $this->authorName($post);

        $options = [];

        foreach ($revisions as $revision) {
            $options[] = [
                'value' => (string) $revision['id'],
                'label' => $this->stamp($revision['created_at']) . ' · ' . $revision['author'],
                'selected' => $selected !== null && $selected['id'] === $revision['id'],
            ];
        }

        $diff = RevisionDiff::render(
            $selected === null ? '' : $selected['content'],
            (string) $post->content
        );

        return $this->view->render('revisions.twig', [
            'page_kind' => self::PAGE,
            'page_title' => __('修订版本'),
            'title' => $post->title,
            'count' => $count,
            'kept' => PostWriter::REVISIONS_KEPT,
            'has_any' => $count > 0,
            'options' => $options,
            'selected' => $selected === null ? null : [
                'when' => $this->stamp($selected['created_at']),
                'author' => $selected['author'],
            ],
            'current' => [
                'when' => $currentWhen,
                'author' => $currentAuthor,
            ],
            'diff' => $diff,
            'post_id' => $postId,
            'rev_id' => $selected === null ? 0 : $selected['id'],
            'self_url' => $this->router->path('admin', ['path' => self::PAGE]),
            'restore_url' => $this->router->path('admin', ['path' => self::PAGE . '/restore']) . '?post=' . $postId,
            // 「取消」回这篇文章的编辑页 —— 与本仓 post 子页同一个口径(如 PostsController 的图片选择器)
            'cancel_url' => $this->router->path('admin', ['path' => 'posts/' . $postId . '/edit']),
        ], $menu);
    }

    /**
     * 恢复入口:CSRF 闸 → 校验修订属于这篇文章 → 回写 posts 并留痕 → 记审计 → 回比对页。
     * 令牌不对、post/rev 缺、或修订不属于这篇,一律回本页不落库(防串号,口径与 render 的回落一致)。
     */
    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function restore(Request $request, User $user, array $menu): Response
    {
        $postId = $request->queryInt('post', 0);
        $rev = (int) $request->input('rev');
        $back = $this->listUrl($postId, $rev);

        // 归属先判:不是自己的文章,连令牌都不用看(否则任意作者都能覆盖他人文章并留痕)
        $post = $postId > 0 ? $this->posts->find($postId) : null;

        if ($post === null || !$this->canTouch($user, $post)) {
            return $this->forbidden($menu);
        }

        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($back);
        }

        // 修订必须属于这篇才回写;拿不到就回本页 —— 不把别家的修订写进别人的文章
        $revision = $this->revisions->find($postId, $rev);

        if ($postId < 1 || $rev < 1 || $revision === null) {
            return Response::redirect($back);
        }

        $this->revisions->restore($postId, $revision['title'], $revision['content'], $user->id);

        Audit::record('content.revision.restore', 'post:' . $postId . ' / rev:' . $rev, true, $request, 'user:' . $user->id);

        return Response::redirect($back);
    }

    private function listUrl(int $postId, int $revId): string
    {
        $url = $this->router->path('admin', ['path' => self::PAGE]) . '?post=' . $postId;

        return $revId > 0 ? $url . '&rev=' . $revId : $url;
    }

    /**
     * 这一篇归不归他管:与 PostsController::canEdit 同一条口径,规则只认 Authorizer,不在这里另写一份。
     */
    private function canTouch(User $user, Post $post): bool
    {
        return $this->can->can($user, 'post.edit.others') || $this->can->can($user, 'post.edit.own', $post);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function forbidden(array $menu): Response
    {
        return $this->view->render('forbidden.twig', [
            'page_title' => __('修订版本'),
            'capability' => 'post.edit.others',
        ], $menu, 403);
    }

    private function stamp(string $datetime): string
    {
        $value = trim($datetime);

        return $value === '' ? '' : substr($value, 0, 16);
    }

    private function authorName(Post $post): string
    {
        $names = $this->users->namesFor([$post->author_id]);
        $name = trim((string) ($names[$post->author_id] ?? ''));

        return $name === '' ? __('佚名') : $name;
    }
}
