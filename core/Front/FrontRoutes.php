<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front;

use Molin\Api\ListFragmentController;
use Molin\Comment\Submitter;
use Molin\Comment\Thread;
use Molin\Content\ContentRenderer;
use Molin\Content\MetaStore;
use Molin\Content\PostTypeRegistry;
use Molin\Content\TermAssigner;
use Molin\Front\Controller\ArchiveController;
use Molin\Front\Controller\AttachmentController;
use Molin\Front\Controller\CommentController;
use Molin\Front\Controller\HomeController;
use Molin\Front\Controller\NotificationController;
use Molin\Front\Controller\PostController;
use Molin\Front\Controller\UploadController;
use Molin\Front\Controller\SiteOutputController;
use Molin\Front\Controller\SocialController;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Notification\NotificationFeed;
use Molin\Notification\NotificationRepository;
use Molin\Notification\Notifier;
use Molin\Router\PostTypeRoutes;
use Molin\Router\Router;
use Molin\Social\SocialRepository;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\SessionStore;
use Molin\User\UserRepository;

/**
 * 前台路由表:把 index.php 里原本的闭包按控制器归类,注册顺序与路由名保持原样。
 *
 * 路由名是契约(core/Router/names.php),这里一个字都不改;CPT 路由依赖 system/init 的登记结果,
 * 所以拆成两段:register() 挂内核固定路由,registerPostTypes() 在 system/init 之后调用。
 */
final class FrontRoutes
{
    /**
     * 控制器集合。**第一次分发时**才装配 —— index.php 先构造本类、之后才 boot 插件,
     * 而 social/services/collect 的实现由插件提供,必须等插件的 PSR-4 与钩子索引就绪后才取得回来。
     *
     * @var array{
     *     post: PostController,
     *     home: HomeController,
     *     archive: ArchiveController,
     *     attachment: AttachmentController,
     *     uploads: UploadController,
     *     siteOutput: SiteOutputController,
     *     social: SocialController,
     *     notification: NotificationController,
     *     comment: CommentController
     * }|null
     */
    private ?array $controllers = null;

    public function __construct(private readonly FrontContext $context)
    {
    }

    /**
     * @return array{
     *     post: PostController,
     *     home: HomeController,
     *     archive: ArchiveController,
     *     attachment: AttachmentController,
     *     uploads: UploadController,
     *     siteOutput: SiteOutputController,
     *     social: SocialController,
     *     notification: NotificationController,
     *     comment: CommentController
     * }
     */
    private function controllers(): array
    {
        if ($this->controllers !== null) {
            return $this->controllers;
        }

        $container = $this->context->container();

        /** @var Router $router */
        $router = $container->get(Router::class);
        /** @var TermAssigner $terms */
        $terms = $container->get(TermAssigner::class);

        /*
         * 社交 / 通知的实现全由插件提供:一次 filter 取三件,类型不对的一律当没启用。
         * 插件未启用时三项都是 null,页面照常渲染,相关 UI 由主题的 features.social / features.notifications 隐藏。
         */
        $collected = apply_filters('social/services/collect', ['social' => null, 'notifications' => null, 'notifier' => null]);
        /** @var array<string, mixed> $services */
        $services = is_array($collected) ? $collected : [];

        $socialService = $services['social'] ?? null;
        $social = $socialService instanceof SocialRepository ? $socialService : null;

        $notificationService = $services['notifications'] ?? null;
        $notifications = $notificationService instanceof NotificationRepository ? $notificationService : null;

        $notifierService = $services['notifier'] ?? null;
        $notifier = $notifierService instanceof Notifier ? $notifierService : null;

        $post = new PostController(
            $this->context,
            $container->get(ContentRenderer::class),
            $router,
            $social,
            $terms,
            $container->get(MediaRepository::class),
            $container->get(Store::class),
            $container->get(UserRepository::class),
            $container->get(MetaStore::class),
            $container->get(Thread::class),
            $container->get(SessionStore::class),
            $container->get(Auth::class),
            $container->get(Authorizer::class)
        );

        return $this->controllers = [
            'post' => $post,
            'home' => new HomeController($this->context, $post, $terms),
            'archive' => new ArchiveController($this->context, $router, $social),
            'attachment' => new AttachmentController(
                $this->context,
                $container->get(MediaRepository::class),
                $container->get(UserRepository::class),
                $this->context->options(),
                $router
            ),
            'uploads' => new UploadController(MOLIN_ROOT . '/storage/uploads'),
            'siteOutput' => new SiteOutputController(
                $this->context,
                $router,
                $this->context->options(),
                $terms,
                $container->get(UserRepository::class)
            ),
            'social' => new SocialController(
                $this->context,
                $social,
                $container->get(UserRepository::class),
                $notifier
            ),
            'notification' => new NotificationController(
                $this->context->renderer(),
                $notifications === null ? null : new NotificationFeed($notifications, $router),
                $notifications
            ),
            'comment' => new CommentController(
                $this->context,
                $router,
                $container->get(Submitter::class),
                $container->get(SessionStore::class)
            ),
        ];
    }

    public function register(Router $router): void
    {
        $router->add('home', 'GET', '/', fn (Request $request, array $vars = []): Response => $this->controllers()['home']->home($request, $vars));
        $router->add('home.page', 'GET', '/page/{page:\d+}', fn (Request $request, array $vars = []): Response => $this->controllers()['home']->homePage($request, $vars));
        $router->add('home.archive', 'GET', '/blog', fn (Request $request, array $vars = []): Response => $this->controllers()['home']->archive($request, $vars));
        $router->add('home.archive.page', 'GET', '/blog/page/{page:\d+}', fn (Request $request, array $vars = []): Response => $this->controllers()['home']->archivePage($request, $vars));

        $router->add('category', 'GET', '/category/{slug:[^/]+}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->category($request, $vars));
        $router->add('category.page', 'GET', '/category/{slug:[^/]+}/page/{page:\d+}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->categoryPage($request, $vars));
        $router->add('tag', 'GET', '/tag/{slug:[^/]+}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->tag($request, $vars));
        $router->add('tag.page', 'GET', '/tag/{slug:[^/]+}/page/{page:\d+}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->tagPage($request, $vars));
        $router->add('author', 'GET', '/author/{slug:[^/]+}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->author($request, $vars));
        $router->add('author.page', 'GET', '/author/{slug:[^/]+}/page/{page:\d+}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->authorPage($request, $vars));
        $router->add('date', 'GET', '/{year:\d{4}}/{month:\d{2}}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->date($request, $vars));
        $router->add('date.page', 'GET', '/{year:\d{4}}/{month:\d{2}}/page/{page:\d+}', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->datePage($request, $vars));

        $router->add('attachment', 'GET', '/attachment/{slug:[^/]+}', fn (Request $request, array $vars = []): Response => $this->controllers()['attachment']->show($request, $vars));
        // 上传文件直发兜底：服务器侧配了 /uploads/ 别名时 nginx 先接走，走不到这里
        $router->add('uploads.file', 'GET', '/uploads/{path:.*}', fn (Request $request, array $vars = []): Response => $this->controllers()['uploads']->serve($request, $vars));

        $router->add('feed', 'GET', '/feed', fn (Request $request, array $vars = []): Response => $this->controllers()['siteOutput']->rss($request, $vars));
        $router->add('feed', 'GET', '/feed/rss', fn (Request $request, array $vars = []): Response => $this->controllers()['siteOutput']->rss($request, $vars));
        $router->add('feed', 'GET', '/feed/atom', fn (Request $request, array $vars = []): Response => $this->controllers()['siteOutput']->atom($request, $vars));
        $router->add('sitemap', 'GET', '/sitemap.xml', fn (Request $request, array $vars = []): Response => $this->controllers()['siteOutput']->sitemap($request, $vars));
        $router->add('robots', 'GET', '/robots.txt', fn (Request $request, array $vars = []): Response => $this->controllers()['siteOutput']->robots($request, $vars));

        // 单篇注册在"当前固定链接结构"算出的模式上(《23》第 3 节)。查询式结构(?p=%post_id%)
        // 没有路径段,不挂这条路由,单篇由首页处理器读 ?p= / ?page_id= 解析。
        $postPattern = $router->postPattern();

        if ($postPattern !== null) {
            $router->add('post', 'GET', $postPattern, fn (Request $request, array $vars = []): Response => $this->controllers()['post']->handle($request, $vars));
        }

        // 兜底一条只认 slug 的地址(历史链接、搜索/后台里只带 slug 的链接都能落地)。
        // 结构恰好就是"名称"时不重复挂。
        if ($postPattern !== '/{postname:[^/]+}') {
            $router->add('post', 'GET', '/{postname:[^/]+}', fn (Request $request, array $vars = []): Response => $this->controllers()['post']->handle($request, $vars));
        }

        $router->add('post.unlock', 'POST', '/unlock', fn (Request $request, array $vars = []): Response => $this->controllers()['post']->unlock($request));

        $router->add('social.follow', 'POST', '/social/follow', fn (Request $request, array $vars = []): Response => $this->controllers()['social']->follow($request));
        $router->add('social.rate', 'POST', '/social/rate', fn (Request $request, array $vars = []): Response => $this->controllers()['social']->rate($request));

        $router->add('notifications.list', 'GET', '/notifications', fn (Request $request, array $vars = []): Response => $this->controllers()['notification']->listItems($request, $vars));
        $router->add('notifications.count', 'GET', '/notifications/count', fn (Request $request, array $vars = []): Response => $this->controllers()['notification']->count($request, $vars));
        $router->add('notifications.read', 'POST', '/notifications/read', fn (Request $request, array $vars = []): Response => $this->controllers()['notification']->read($request, $vars));
        $router->add('notifications.delete', 'POST', '/notifications/delete', fn (Request $request, array $vars = []): Response => $this->controllers()['notification']->delete($request, $vars));

        $router->fallback(fn (Request $request, array $vars = []): Response => $this->context->notFound());

        $router->add('search', 'GET', '/search', fn (Request $request, array $vars = []): Response => $this->controllers()['archive']->search($request, $vars));

        /*
         * 列表片段端点(《23》§4 路由名 list.fragment):列表翻页 / 切分类不再整页刷新,只吐列表项 + 分页。
         * 公开只读:GET、免会话、免 CSRF(与其余前台路由同一口径,guard = none 语义)。
         */
        $listFragment = new ListFragmentController($this->context->listSource(), $this->context->renderer());
        $router->add('list.fragment', 'GET', '/list/fragment', $listFragment->handle(...));

        $router->add('comment.submit', 'POST', '/comment', fn (Request $request, array $vars = []): Response => $this->controllers()['comment']->submit($request));
    }

    /**
     * 自定义内容类型(CPT)的前台(《34》§2.2):类型登记表在 system/init 里填好,本节必须排在其后。
     * 单篇 = /{rewrite.slug}/{slug},归档 = /{rewrite.slug}(has_archive=true,含 /page/{n} 分页)。
     * 非 public / rewrite=false / 段与内核保留段重名的类型不挂路由(前台不可达);冲突原因记日志,不崩站。
     * 单篇复用文章处理器,归档复用既有查询与分页链(PostQuery + Renderer),不另造一套。
     */
    public function registerPostTypes(Router $router): void
    {
        $cptSingle = fn (string $type, Request $request, array $vars): Response => $this->controllers()['post']->handle($request, $vars, '', $type);
        $cptArchive = fn (string $type, Request $request, array $vars): Response => $this->controllers()['archive']->cptArchive($type, $request, $vars);

        foreach (PostTypeRoutes::register($router, PostTypeRegistry::instance(), $cptSingle, $cptArchive) as $note) {
            \Molin\Logger\Log::channel('plugin')->warning($note);
        }
    }
}
