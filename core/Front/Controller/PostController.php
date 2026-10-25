<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Comment\Thread;
use Molin\Content\ContentContext;
use Molin\Content\ContentRenderer;
use Molin\Content\MetaStore;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\TermAssigner;
use Molin\Front\FrontContext;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Social\SocialRepository;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\SessionStore;
use Molin\User\UserRepository;

/**
 * 单篇 / 独立页面 / 自定义内容类型的详情页(《73》《114》《34》§2.2),外加口令解锁端点。
 *
 * 三条入口共用 handle():固定链接路由、首页的 ?p= / ?page_id=、以及 CPT 的 /{rewrite}/{slug}。
 * 差别只在 $kind(页型)与 $type(内容类型)两个参数上。
 */
final class PostController
{
    public function __construct(
        private readonly FrontContext $context,
        private readonly ContentRenderer $contentRenderer,
        private readonly Router $router,
        private readonly ?SocialRepository $social,
        private readonly TermAssigner $terms,
        private readonly MediaRepository $media,
        private readonly Store $mediaStore,
        private readonly UserRepository $users,
        private readonly MetaStore $meta,
        private readonly Thread $thread,
        private readonly SessionStore $sessions,
        private readonly Auth $auth,
        private readonly Authorizer $authorizer
    ) {
    }

    /**
     * @param array<string, string|int> $vars 路由变量(post_id / postname)
     * @param string $kind 强制页型(首页挑静态页时是 front-page)
     * @param ?string $type 限定内容类型(CPT 单篇)
     */
    public function handle(Request $request, array $vars = [], string $kind = '', ?string $type = null): Response
    {
        $view = $this->context->view($request);
        $posts = $this->context->posts();

        // 单表 + type(《00》D4):路径段名随固定链接结构变(见 Router::postPattern),
        // 有 post_id 就按 id 取,否则按 slug 先文章后页面;查询式结构(?p= / ?page_id=)
        // 没有路径段,改从查询串解析。$type 给定时(自定义内容类型,见下方 PostTypeRoutes)只在该类型里找。
        $queryId = $request->input('p');

        if ($queryId === '') {
            $queryId = $request->input('page_id');
        }

        if (isset($vars['post_id'])) {
            $post = $posts->find((int) $vars['post_id']);
        } elseif (isset($vars['postname'])) {
            $slug = (string) $vars['postname'];
            $post = $type !== null
                ? $posts->findBySlug($slug, $type)
                : ($posts->findBySlug($slug, 'post') ?? $posts->findBySlug($slug, 'page'));
        } elseif ($queryId !== '' && preg_match('/\A(\d+)/', $queryId, $m) === 1) {
            // 宽松解析:查询式固定链接(?p=1)回跳时可能被拼上 ?comment= / ?replyto= 尾巴,
            // 取起始数字仍能命中文章;纯非数字照旧 404
            $post = $posts->find((int) $m[1]);

            // ?p= / ?page_id= 只服务文章:独立页面恒走 /自定义地址/,
            // 查询式入口对页面一律 404,不留双通道(首页「静态页面」走的是内部 post_id,不经过这里)
            if ($post !== null && $post->type === 'page') {
                $post = null;
            }
        } else {
            $post = null;
        }

        // 草稿 / 私密文章:作者本人(或能改别人文章的人)可以在这里先看一眼(2026-10-17 加)。
        // 起因:编辑页那颗「预览」指向的就是这个地址,可它只放行 publish —— 点下去必 404,
        // 等于"有预览按钮、没有预览"。判定口径与后台一致,走 Authorizer,不另写一套归属规则。
        $draftPreview = false;

        if ($post !== null && !$this->context->isVisible($post)) {
            $viewer = Auth::user();
            $draftPreview = $viewer !== null
                && $post->status !== 'trash'
                && ($this->authorizer->can($viewer, 'post.edit.others') || $this->authorizer->can($viewer, 'post.edit.own', $post));
        }

        if ($post === null || (!$this->context->isVisible($post) && !$draftPreview)) {
            return $this->context->notFound($view);
        }

        // 浏览量(《13》§6.3):前端单篇每请求一次 +1,不去重 —— 口径由作者 2026-10-14 定"刷新一次就加一"。
        // 独立页面不记:主题的页面模板上不显示浏览量,别把数字藏进看不见的地方。
        // 主题预览(管理员带 ?molin_theme_preview=)不算真实浏览;草稿预览也不算(还没发出去,不该有"浏览量")。
        if ($request->method() === 'GET' && $post->type !== 'page' && $view === $this->context->renderer() && !$draftPreview) {
            $posts->touchView($post->id);
            $post = $post->withViewCount($post->view_count + 1);
        }

        // 私密正文口令(密码保护,P0-2):没解锁只给口令表单,不给正文、不给评论区。
        // 解锁态挂在会话的 unlocked_posts 里,随会话失效 —— 不设明文令牌进链接,免得一分享就漏
        if ($posts->requiresPassword($post->id) && !$this->isUnlocked($post->id)) {
            // 口令表单必须当场有会话:令牌与解锁态都挂在会话上。这一支会发 Set-Cookie,
            // 所以锁定页永远进不了整页缓存(见 index.php 落盘条件)—— 这正是它能安全建会话的前提。
            if (Auth::session() === null) {
                $guest = $this->sessions->create(0, $request->ip(), $request->userAgent(), [
                    'csrf_token' => bin2hex(random_bytes(32)),
                ]);
                $this->auth->attach($guest->id);
                $this->context->guestSessionId = $guest->id;
            }

            return $view->render('single', [
                // published_at 照登,供文章头那一行日期条件判断用;正文/评论区交给口令表单那支
                'post' => ['id' => $post->id, 'title' => $post->title, 'url' => $post->url, 'published_at' => null],
                'locked' => true,
                'locked_hint' => $request->input('unlock_error', ''),
                'unlock_action' => $this->router->path('post.unlock'),
            ], ['type' => $post->type, 'slug' => $post->slug, 'id' => $post->id]);
        }

        /*
         * 评论表单的防跨站令牌**不在 GET 里建会话**了。
         *
         * 原来的做法是"文章页开着评论就给访客 create() 一条会话 + 发 cookie",好让表单里带上令牌。
         * 代价是:匿名访客读任何一篇文章都会拿到 Set-Cookie,而"发过 Set-Cookie 的响应不落缓存",
         * 于是读过一篇的访客 14 天内全站都不走整页缓存 —— 缓存等于白建。
         *
         * 现在令牌改成提交前现取:主题的评论表单把令牌字段留空并打上 data-csrf-lazy,
         * 由 single.js 在 focus / submit 时调 GET /api/v1/csrf-token(那条路由会建访客会话并下发 cookie)。
         * 匿名 GET 因此不再产生会话,整页 HTML 里也没有任何与个人绑定的东西,可以放心共享。
         */

        // 评论区(《31》§2/§3:两级嵌套;待审只给提交者自己)+ 提交反馈(?comment= / ?comment_error= / ?replyto=)
        $commentVars = ['comments' => [], 'comment_total' => 0];

        // [朋友圈主题补丁 2026-10-25] 除 post 外，声明了 comments 支持的 CPT（如 moments）也要挂评论区。
        // 这是内核唯一按 type 写死 post 的判断；主题 molin-moments / 插件 molin-moments-plus 依赖它。
        if (in_array($post->type, ['post', 'moments'], true) && $post->comment_status === 'open') {
            $viewer = Auth::user();
            $session = Auth::session();
            $mine = $session === null ? [] : array_values(array_map('intval', (array) ($session->data['pending_comments'] ?? [])));

            $thread = $this->thread->forPost($post->id, $viewer->id ?? 0, $mine);

            $commentVars = [
                'comments' => $thread['comments'],
                'comment_total' => $thread['total'],
                'comment_action' => $this->router->path('comment.submit'),
                'reply_to' => max(0, (int) $request->input('replyto')),
                'comment_notice' => $request->input('comment') === 'approved' ? '评论已发表。'
                    : ($request->input('comment') === 'pending' ? '评论已提交，等待审核后展示。' : ''),
                'comment_error' => $request->input('comment_error', ''),
                'form_ts' => (string) time(),
            ];
        }

        $ratingViewer = Auth::user();
        $ratingSession = Auth::session();
        // 游客也能赞/踩：cookie 里的匿名标识 + localStorage 同款令牌，服务端按 voter_key 区分
        $ratingVoter = $ratingViewer !== null
            ? 'u' . $ratingViewer->id
            : 'g' . trim((string) $request->cookie('molin_voter', ''));
        // 社交插件未启用时不碰社交实现,也不生成投票地址(路由还在,主题靠 features.social 隐藏入口)
        // 键名跟主题/JS 的契约一致(like/dislike);action(投票地址)由这里补,仓储不返回它
        $rating = $this->social === null
            ? ['like' => 0, 'dislike' => 0, 'mine' => '', 'action' => '']
            : $this->social->ratingFor($post->id, $ratingViewer->id ?? 0, $ratingVoter);

        if ($this->social !== null) {
            $rating['action'] = $this->router->path('social.rate');
        }

        $rating['token'] = $ratingSession === null ? '' : Csrf::token($ratingSession);

        $vars = ['post' => $this->contentRenderer->render($post, ContentContext::MODE_FULL, false), 'rating' => $rating] + $commentVars;

        // 独立页面(《114》):.subs 列本页的子页(《34》§1 层级),顺序取自定义序 menu_order;
        // 《114》§11 第 3 条没定排序,先按它实现,拍板后再改
        if ($post->type === 'page') {
            $subs = [];

            foreach ($posts->query()->type('page')->status('publish')->parent($post->id)->orderBy('menu_order', 'asc')->limit(50)->get() as $child) {
                $subs[] = ['title' => $child->title, 'url' => $this->router->relative($child->url)];
            }

            $vars['subs'] = $subs;
        }

        // 文末标签(《73》§12,2026-10-11 增):键始终在,空数组占位,模板不做 defined 猜谜
        $vars['tags'] = [];

        // 头部「标题上面那行」要作者名(2026-10-17 增):列表侧一直有(items[].author.name),详情页补同源的一份
        // —— 主题查不了库。口径与列表一致(显示名优先、回落用户名);取不到给空串,键始终在
        $authorRow = $this->users->postAuthors([$post->id])[$post->id] ?? null;
        $vars['author'] = $authorRow === null
            ? ''
            : ($authorRow['display_name'] !== '' ? $authorRow['display_name'] : $authorRow['username']);

        // 头部头像与作者链接：主题只能吃渲染层给的 email/url，不能自己查库
        if ($authorRow !== null) {
            $vars['author_email'] = (string) $authorRow['email'];
            $vars['author_url'] = $this->router->path('author', ['slug' => (string) $authorRow['username']]);
        }

        // 文章详情页头三件套(《73》§3.2):分类 / 署名 / 上/下篇。独立页面只有标题区,不赘这些(《114》)
        if ($post->type === 'post') {
            $first = $this->terms->firstTermsFor([$post->id], TermAssigner::CATEGORY)[$post->id] ?? null;
            $vars['category'] = $first === null ? null : [
                'name' => $first['name'],
                'url' => $this->router->path('category', ['slug' => $first['slug']]),
            ];

            // 文末那行标签(《73》§12,2026-10-11 增):分类在眉标里,标签在这儿给"更细的入口"
            $vars['tags'] = array_map(
                fn (array $tag): array => [
                    'name' => $tag['name'],
                    'url' => $this->router->path('tag', ['slug' => $tag['slug']]),
                ],
                $this->terms->termsOf($post->id, TermAssigner::TAG)
            );

            // 署名行直接吃上面取好的作者名(同一口径),取不到才退回站点名
            $author = $vars['author'] !== '' ? $vars['author'] : (string) $this->context->options()->get('site_name', '');

            $vars['byline'] = ltrim($author . ' · 阅读约 ' . $this->readingMinutes($post->content) . ' 分钟', ' ·');

            $neighbors = $posts->query()->type('post')->status('publish')->neighbors($post->id, $post->published_at);
            $neighborPosts = array_values(array_filter(
                [$neighbors['prev'], $neighbors['next']],
                static fn (?Post $p): bool => $p !== null
            ));
            $neighborCovers = [];

            // thumbnail_id 指向 attachment post；media 行以 attachment post_id 关联，不是文章自己的 id
            foreach ($this->media->byPostIds(array_map(static fn (Post $p): int => $p->thumbnail_id, $neighborPosts)) as $attachmentId => $media) {
                $image = MediaRepository::imageData($media, $this->mediaStore);

                if ($image !== null) {
                    $neighborCovers[(int) $attachmentId] = $image;
                }
            }

            $vars['pager'] = [
                'prev' => $neighbors['prev'] === null ? null : [
                    'title' => $neighbors['prev']->title,
                    'url' => $neighbors['prev']->url,
                    'cover' => $neighborCovers[$neighbors['prev']->thumbnail_id]['url'] ?? null,
                ],
                'next' => $neighbors['next'] === null ? null : [
                    'title' => $neighbors['next']->title,
                    'url' => $neighbors['next']->url,
                    'cover' => $neighborCovers[$neighbors['next']->thumbnail_id]['url'] ?? null,
                ],
            ];
        }

        // 最终页型:home 首页挑静态页时会带 kind='front-page',它有自己的回退链,不套页面模板
        $finalKind = $kind !== '' ? $kind : ($post->type === 'page' ? 'page' : 'single');
        $context = ['type' => $post->type, 'slug' => $post->slug, 'id' => $post->id];

        // 草稿预览页不该被收录(2026-10-17):地址是真的,内容还没打算公开
        if ($draftPreview) {
            $context['noindex'] = 1;
        }

        // 页面模板(《114》):只有最终页型是 page 才认 meta 里的选择,过了当前渲染主题的白名单才生效
        if ($finalKind === 'page') {
            $chosen = $this->meta->get($post->id, 'page_template');
            $resolved = $chosen === null ? null : $view->theme()->resolvePageTemplate($chosen);

            if ($resolved !== null) {
                $context['template'] = $resolved;
            }
        }

        return $view->render($finalKind, $vars, $context);
    }

    /**
     * 私密正文口令的解锁端点(密码保护,P0-2)。POST + CSRF + 服务端校验,三种安全闸一个不少:
     * 口令比对在 PostRepository(哈希不出仓储),通过后把 post id 记进会话,回原文章页看正文
     */
    public function unlock(Request $request): Response
    {
        $session = Auth::session();
        $postId = max(0, (int) $request->input('post_id'));
        $target = $postId > 0 ? $this->context->posts()->find($postId) : null;
        $back = $target === null ? '/' : $this->router->relative($target->url);

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($back . '?unlock_error=' . rawurlencode('页面放得太久了,请重新输入口令。'));
        }

        if ($target === null || !$this->context->posts()->requiresPassword($postId) || !$this->context->posts()->verifyPassword($postId, $request->input('password'))) {
            return Response::redirect($back . '?unlock_error=' . rawurlencode('口令不对,再试一次。'));
        }

        $unlocked = array_map('intval', (array) ($session->data['unlocked_posts'] ?? []));
        $unlocked[] = $postId;
        $session->data['unlocked_posts'] = array_values(array_unique($unlocked));
        $this->sessions->save($session);

        return Response::redirect($back);
    }

    /** 密码保护已解锁?解锁态挂在会话的 unlocked_posts 里(随会话失效)。别再校验明文,免得每次刷新都重输 */
    private function isUnlocked(int $postId): bool
    {
        $session = Auth::session();

        if ($session === null) {
            return false;
        }

        $ids = array_map('intval', (array) ($session->data['unlocked_posts'] ?? []));

        return in_array($postId, $ids, true);
    }

    /** 阅读时长估计(《73》§3.2 署名那一行):去掉标签与空白后按 350 字/分钟估,最少 1 分钟 */
    private function readingMinutes(?string $content): int
    {
        $text = preg_replace('/\s+/u', '', strip_tags((string) $content)) ?? '';
        $length = $text === '' ? 0 : mb_strlen($text, 'UTF-8');

        return max(1, (int) ceil($length / 350));
    }
}
