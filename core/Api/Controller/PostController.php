<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api\Controller;

use Molin\Api\ApiSupport;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\PostWriter;
use Molin\Content\StatusMachine;
use Molin\Content\TermAssigner;
use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\Authorizer;
use Molin\User\User;

/**
 * 文章与页面端点(《25》§3.1、§3.2):公开列表 / 单篇 / 我的文章 / 新建 / 更新 / 回收站。
 *
 * 公开读走 visible() 这一道可见性闸;写端先过能力,再按状态机把 contributor 的"发布"降成待审。
 */
final class PostController extends ApiSupport
{
    /** 写端点收的字段白名单(《21》§2 的子集;口令、特色图等留给后台专属形态) */
    private const WRITABLE = ['title', 'slug', 'excerpt', 'content', 'status', 'comment_status', 'categories', 'tags'];

    /** App 的文章状态口径 → 内核状态机(《34》)。App 说 published,内核说 publish */
    private const STATUS_ALIASES = ['published' => 'publish'];

    public function __construct(
        Options $options,
        Router $router,
        private readonly PostRepository $posts,
        private readonly PostWriter $writer,
        private readonly Authorizer $can,
        private readonly TermAssigner $terms,
        private readonly CommentController $commentController
    ) {
        parent::__construct($options, $router);
    }

    /**
     * @param list<string> $segments
     */
    public function collection(Request $request, array $segments, string $type, ?User $user): Response
    {
        // `context=edit` 是 App 的编辑台:列出"我的"文章(含草稿/待审,正文带全),不走公开可见性。
        // 只认裸列表这一形状 —— 带尾段的 `/posts/{id}?context=edit` 不许被它吞成整张表。
        if ($type === 'post' && !isset($segments[1]) && (string) $request->query('context', '') === 'edit') {
            return $this->editCollection($request, $user);
        }

        $orderby = (string) $request->query('orderby', 'date');

        if (!isset(self::ORDERS[$orderby])) {
            return $this->fail('bad_request', 'orderby 只认 date / title / id / views', 400);
        }

        $order = strtolower((string) $request->query('order', 'desc'));

        if ($order !== 'asc' && $order !== 'desc') {
            return $this->fail('bad_request', 'order 只认 asc / desc', 400);
        }

        // 《25》§5:status 默认 publish,别的取值需要权限;令牌体系落地前只放公开态
        if ((string) $request->query('status', 'publish') !== 'publish') {
            return $this->fail('forbidden', '按状态取内容需要权限', 403);
        }

        $resource = $type === 'page' ? 'pages' : 'posts';

        if (isset($segments[1]) && $segments[1] !== '') {
            // 单篇/子资源只有 `{id}`、`slug/{slug}`、`{id}/comments` 三种形状(`item()` 只认前两种)。
            // 别的形状(多带段、错的子资源名)一律 404,不许静默当成单篇或列表吐回来。
            $second = $segments[1];
            $third = $segments[2] ?? null;

            if ($third === 'comments') {
                if ($type !== 'post' || isset($segments[3])) {
                    return $this->fail('not_found', '没有这个端点', 404);
                }

                return $this->commentController->commentsFor($second);
            }

            if (isset($segments[3]) || ($third !== null && !($type === 'post' && $second === 'slug'))) {
                return $this->fail('not_found', '没有这个端点', 404);
            }

            return $this->item($second, $third ?? '', $type);
        }

        $search = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $tag = trim((string) $request->query('tag', ''));
        $author = $request->queryInt('author', 0);
        $perPage = $request->queryInt('per_page', self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $page = $request->queryInt('page', 1);

        $query = $this->posts->query()->type($type)->status('publish');

        if ($search !== '') {
            $query->search($search);
        }

        if ($category !== '') {
            $query->category($category);
        }

        if ($tag !== '') {
            $query->tag([$tag]);
        }

        if ($author > 0) {
            $query->author($author);
        }

        $total = $query->count();

        $items = $query
            ->orderBy(self::ORDERS[$orderby], $order)
            ->limit($perPage)
            ->page($page)
            ->get();

        // 链接只带生效过的筛选(归一化后的值),这样 next/last 取出来的集合同自
        $carry = [];

        if ($search !== '') {
            $carry['search'] = $search;
        }

        if ($category !== '') {
            $carry['category'] = $category;
        }

        if ($tag !== '') {
            $carry['tag'] = $tag;
        }

        if ($author > 0) {
            $carry['author'] = (string) $author;
        }

        if ($orderby !== 'date') {
            $carry['orderby'] = $orderby;
        }

        if ($order !== 'desc') {
            $carry['order'] = $order;
        }

        if ($perPage !== self::PER_PAGE_DEFAULT) {
            $carry['per_page'] = (string) $perPage;
        }

        $pages = max(1, (int) ceil($total / $perPage));

        return $this->ok([
            'data' => array_map(fn (Post $post): array => $this->toArray($post), $items),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
            'links' => $this->links($resource, $carry, $page, $pages),
        ]);
    }

    /**
     * `GET /api/v1/posts?context=edit` —— 我的文章(含草稿/待审/私密),正文带全。
     * 以作者为限:App 是作者端,别人写的文章不在这里出现(要管别人用后台)。
     * `status=trash` 时这一栏换成"我的回收站",与上面同构(分页/links/字段只差集);别的取值不改行为。
     */
    private function editCollection(Request $request, ?User $user): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (!$this->can->canAny($user, ['post.create', 'post.edit.own', 'post.edit.others'])) {
            return $this->fail('forbidden', '没有查看自己文章的权限', 403);
        }

        $perPage = $request->queryInt('per_page', self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $page = $request->queryInt('page', 1);
        $search = trim((string) $request->query('search', ''));
        $trashOnly = trim((string) $request->query('status', '')) === StatusMachine::TRASH;

        $query = $this->posts->query()
            ->type('post')
            ->author($user->id)
            ->content();

        if ($trashOnly) {
            // 回收站行 deleted_at 非空,默认查询会把它们全滤掉,必须显式 trashed()(《34》第 5 节)
            $query->status(StatusMachine::TRASH)->trashed();
        } else {
            $query->statuses([StatusMachine::DRAFT, StatusMachine::PENDING, StatusMachine::PUBLISH, StatusMachine::PRIVATE]);
        }

        if ($search !== '') {
            $query->search($search);
        }

        $total = $query->count();
        $items = $query->orderBy('updated_at', 'desc')->limit($perPage)->page($page)->get();
        $pages = max(1, (int) ceil($total / $perPage));

        $carry = ['context' => 'edit'];

        if ($trashOnly) {
            $carry['status'] = StatusMachine::TRASH;
        }

        if ($search !== '') {
            $carry['search'] = $search;
        }

        return $this->ok([
            'data' => $this->withTermsBatch($items, array_map(
                fn (Post $post): array => $this->toArray($post, true) + ['date' => $post->published_at ?? $post->updated_at],
                $items
            )),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
            'links' => $this->links('posts', $carry, $page, $pages),
        ]);
    }

    private function item(string $second, string $third, string $type): Response
    {
        if ($second === 'slug') {
            if ($type !== 'post' || $third === '') {
                return $this->fail('not_found', '没有这个端点', 404);
            }

            return $this->single($this->visible($this->posts->findBySlug($third, 'post')), $type);
        }

        if (preg_match('/\A\d+\z/', $second) !== 1) {
            return $this->fail('not_found', '没有这个端点', 404);
        }

        return $this->single($this->visible($this->posts->find((int) $second)), $type);
    }

    private function single(?Post $post, string $type): Response
    {
        if ($post === null || $post->type !== $type) {
            return $type === 'page'
                ? $this->fail('page_not_found', '页面不存在', 404)
                : $this->fail('post_not_found', '文章不存在', 404);
        }

        return $this->ok(['data' => $this->withTerms($post, $this->toArray($post, true))]);
    }

    /**
     * POST /api/v1/posts —— 新建(《25》§3.1,能力 post.create)。
     */
    public function create(Request $request, User $user, bool $viaSession): Response
    {
        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        if (!$this->can->can($user, 'post.create')) {
            return $this->fail('forbidden', '没有新建文章的权限', 403);
        }

        $data = $this->payload($request) + ['type' => 'post'];

        if (($pending = $this->publishGate($data, $user)) !== null) {
            $data = $pending;
        }

        try {
            $created = $this->writer->save($data, $user->id);
        } catch (ValidationException $e) {
            return $this->validation($e);
        }

        Audit::record('post.create', 'post:' . $created->id . ' status:' . $created->status, true, $request, 'user:' . $user->id);

        return $this->ok(['data' => $this->withTerms($created, $this->toArray($created, true))], 201);
    }

    /**
     * DELETE /api/v1/posts/{id} —— 入回收站(《25》§3.1:删除 == 回收站,不是销毁)。
     * 带 `?force=1|true` 才是彻底删除,且只认回收站里的文章(与后台回收站栏同口径)。
     */
    public function delete(Request $request, User $user, bool $viaSession, int $id): Response
    {
        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        if ($this->forceRequested($request)) {
            return $this->purge($user, $id);
        }

        $post = $this->posts->find($id);

        if ($post === null || $post->type !== 'post') {
            return $this->fail('post_not_found', '文章不存在', 404);
        }

        if (!$this->canDelete($user, $post)) {
            return $this->fail('forbidden', '没有删除这篇文章的权限', 403);
        }

        try {
            $trashed = $this->writer->trash($id, 'user:' . $user->id);
        } catch (ValidationException $e) {
            return $this->validation($e);
        }

        return $this->ok(['data' => ['id' => $trashed->id, 'status' => $trashed->status]]);
    }

    /**
     * force 参数只认 '1' / 'true'(不分大小写);其余取值走普通"进回收站"路径,现有行为不变。
     */
    private function forceRequested(Request $request): bool
    {
        $force = $request->query('force', '');

        return is_string($force) && in_array(strtolower(trim($force)), ['1', 'true'], true);
    }

    /**
     * `?force=1` 的彻底删除:只有回收站里的文章能删 —— 还活着的一律先走"进回收站"。
     * 级联清理(关系/元数据/修订/评论/正文行)与审计都在 PostWriter::forceDelete 里。
     */
    private function purge(User $user, int $id): Response
    {
        $post = $this->posts->find($id, true);

        if ($post === null || $post->type !== 'post') {
            return $this->fail('post_not_found', '文章不存在', 404);
        }

        if (!$this->canDelete($user, $post)) {
            return $this->fail('forbidden', '没有删除这篇文章的权限', 403);
        }

        if ($post->status !== StatusMachine::TRASH) {
            return $this->fail('validation_failed', '只有回收站里的文章才能彻底删除', 422, [
                'force' => ['先移入回收站,再彻底删除'],
            ]);
        }

        $this->writer->forceDelete($id, 'user:' . $user->id);

        return $this->ok(['data' => ['id' => $id, 'deleted' => true]]);
    }

    public function update(Request $request, ?User $user, bool $viaSession, int $id): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        // 回收站里的文章也要能找得到:PUT 对它们只有一个合法动作——恢复(status 置 draft/publish)
        $post = $this->posts->find($id, true);

        if ($post === null || $post->type !== 'post') {
            return $this->fail('post_not_found', '文章不存在', 404);
        }

        if (!$this->canEdit($user, $post)) {
            return $this->fail('forbidden', '没有编辑这篇文章的权限', 403);
        }

        $data = $this->payload($request);

        if ($post->status === StatusMachine::TRASH) {
            return $this->restoreFromTrash($user, $post, $data);
        }

        if (($pending = $this->publishGate($data, $user)) !== null) {
            $data = $pending;
        }

        try {
            $saved = $this->writer->save($data, $user->id, $id);
        } catch (ValidationException $e) {
            return $this->validation($e);
        } catch (NotFoundException) {
            return $this->fail('post_not_found', '文章不存在', 404);
        }

        Audit::record('post.update', 'post:' . $saved->id . ' status:' . $saved->status, true, $request, 'user:' . $user->id);

        return $this->ok(['data' => $this->withTerms($saved, $this->toArray($saved, true))]);
    }

    /**
     * 回收站文章的 PUT = 恢复。目标状态按状态机只认 draft / publish;
     * 其余字段(body 里带了也不看)一律忽略 —— 恢复不清 content,清 deleted_at 由 PostWriter::restore 负责。
     *
     * @param array<string, mixed> $data
     */
    private function restoreFromTrash(User $user, Post $post, array $data): Response
    {
        $status = trim((string) ($data['status'] ?? ''));

        if (!in_array($status, [StatusMachine::DRAFT, StatusMachine::PUBLISH], true)) {
            return $this->fail('validation_failed', '回收站里的文章只能恢复为 draft / publish', 422, [
                'status' => ['可选值: draft / publish'],
            ]);
        }

        try {
            $restored = $this->writer->restore($post->id, $status, 'user:' . $user->id);
        } catch (ValidationException $e) {
            return $this->validation($e);
        }

        return $this->ok(['data' => $this->withTerms($restored, $this->toArray($restored, true))]);
    }

    private function canEdit(User $user, Post $post): bool
    {
        return $this->can->can($user, 'post.edit.others') || $this->can->can($user, 'post.edit.own', $post);
    }

    private function canDelete(User $user, Post $post): bool
    {
        return $this->can->can($user, 'post.delete.others') || $this->can->can($user, 'post.delete.own', $post);
    }

    /**
     * 《32》§3:contributor 有内容没 post.publish —— 想发布就是"提交待审",与后台同口径。
     * 没碰状态或没这个键时返回 null(不改)。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function publishGate(array $data, User $user): ?array
    {
        $wanted = (string) ($data['status'] ?? '');

        if ($wanted === '' || !in_array($wanted, [StatusMachine::PUBLISH, StatusMachine::PRIVATE], true)) {
            return null;
        }

        if ($this->can->can($user, 'post.publish')) {
            return null;
        }

        $data['status'] = StatusMachine::PENDING;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $body = $request->json();
        $out = [];

        foreach (self::WRITABLE as $key) {
            if (array_key_exists($key, $body)) {
                $out[$key] = $body[$key];
            }
        }

        // App 说的是 published,内核说 publish(《34》状态机);别让 App 的词汇渗进内核
        if (isset($out['status']) && is_string($out['status'])) {
            $out['status'] = self::STATUS_ALIASES[$out['status']] ?? $out['status'];
        }

        return $out;
    }

    /**
     * 给文章载荷补上分类与标签。
     *
     * 写端(WRITABLE)早就收这两个字段,读端却一直不吐:App 看不到旧文已有哪些词条,
     * 一保存就整体覆盖,把网页端打的标签冲掉。这里补齐,形状与写端对称 —— 纯名字数组。
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function withTerms(Post $post, array $base): array
    {
        return $base + [
            'categories' => array_column($this->terms->termsOf($post->id, TermAssigner::CATEGORY), 'name'),
            'tags' => array_column($this->terms->termsOf($post->id, TermAssigner::TAG), 'name'),
        ];
    }

    /**
     * 一屏列表批量补词条 —— 逐行 termsOf 就是 N+1(namesFor 的注释已点过)。
     *
     * @param list<Post> $posts
     * @param list<array<string, mixed>> $rows 与 $posts 同序
     * @return list<array<string, mixed>>
     */
    private function withTermsBatch(array $posts, array $rows): array
    {
        $ids = array_map(static fn (Post $post): int => $post->id, $posts);

        $cats = $this->terms->namesFor($ids, TermAssigner::CATEGORY);
        $tags = $this->terms->namesFor($ids, TermAssigner::TAG);

        foreach ($posts as $i => $post) {
            $rows[$i] += [
                'categories' => $cats[$post->id] ?? [],
                'tags' => $tags[$post->id] ?? [],
            ];
        }

        return array_values($rows);
    }
}
