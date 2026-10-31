<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front;

use Molin\Comment\CommentRepository;
use Molin\Container\Container;
use Molin\Content\ContentRenderer;
use Molin\Content\MetaStore;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\TermAssigner;
use Molin\Error\ErrorHandler;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Social\BatchRatings;
use Molin\Social\DatabaseRatings;
use Molin\Search\Searcher;
use Molin\Theme\ListSource;
use Molin\Theme\Renderer;
use Molin\Theme\Theme;
use Molin\Theme\ThemeSettings;
use Molin\User\UserRepository;
use Psr\SimpleCache\CacheInterface;

/**
 * 一次前台请求共用的一份装配:渲染器、列表取数、文章仓储、站点选项。
 *
 * 这些对象原本散在 index.php 的闭包里靠 use 传递;收进一个上下文后,控制器只认这一份,
 * 主题预览与 404 也走同一条口子,不会出现"某个控制器忘了带预览渲染器"这种分叉。
 */
final class FrontContext
{
    /** 口令锁定页给访客建的会话 id:分发完随响应补发 cookie */
    public ?string $guestSessionId = null;

    private function __construct(
        private readonly Container $container,
        private readonly Renderer $renderer,
        private readonly ListSource $listSource,
        private readonly PostRepository $posts,
        private readonly Options $options
    ) {
    }

    public static function fromContainer(Container $container): self
    {
        $options = $container->get(Options::class);
        $router = $container->get(Router::class);
        $posts = $container->get(PostRepository::class);
        $contentRenderer = $container->get(ContentRenderer::class);
        $renderer = $container->get(Renderer::class);

        /*
         * 列表数据装配:整页处理器与片段端点(list.fragment)共用这一份 ——
         * 查询、组项、分页只此一处,不复制第二套。每页条数与「列表显示」口径都收进 ListSource。
         */
        /** @var ?CacheInterface $cache 主色结果的跨请求存储;装配线没给就退回本请求内计算 */
        $cache = $container->has(CacheInterface::class) ? $container->get(CacheInterface::class) : null;

        /*
         * 列表层的赞/踩数(2026-10-31 增)。
         * 这里必须传**闭包**而不是实例 —— 列表装配发生在 index.php 的 plugins->boot() 之前,
         * 那一刻插件还没把实现挂到钩子上,现在取只会拿到 null。等真正组项时再解析一次就对了。
         * 取法与文章页一致:插件在就用插件的批量口,不在就退到内核自带的 DatabaseRatings。
         */
        $ratingsProvider = static function () use ($container): BatchRatings {
            $collected = apply_filters('social/services/collect', ['social' => null, 'notifications' => null, 'notifier' => null]);
            $socialService = is_array($collected) ? ($collected['social'] ?? null) : null;

            return $socialService instanceof BatchRatings
                ? $socialService
                : $container->get(DatabaseRatings::class);
        };

        $listSource = new ListSource(
            $posts,
            $container->get(TermAssigner::class),
            $container->get(UserRepository::class),
            $container->get(Searcher::class),
            $router,
            $options,
            $contentRenderer,
            $container->get(MediaRepository::class),
            $container->get(Store::class),
            $container->get(CommentRepository::class),
            $cache,
            $ratingsProvider
        );

        $context = new self($container, $renderer, $listSource, $posts, $options);

        // 未捕获错误从这里兜底;404 交回主题,其余走内置友好页
        ErrorHandler::boot(static fn (): Response => $context->notFound());

        return $context;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function renderer(): Renderer
    {
        return $this->renderer;
    }

    public function listSource(): ListSource
    {
        return $this->listSource;
    }

    public function posts(): PostRepository
    {
        return $this->posts;
    }

    public function options(): Options
    {
        return $this->options;
    }

    /**
     * 这一趟用哪个渲染器:管理员带 ?molin_theme_preview={slug} 时按指定主题,否则默认。
     */
    public function view(Request $request): Renderer
    {
        return $this->previewRenderer($request) ?? $this->renderer;
    }

    /**
     * 404 页:状态码之外再随机挑一篇已发文章喂「或许你想看」。
     * 随机在服务端算,每次访问可以不同(既有规范可变项);没有文章时那一块整块不渲染。
     */
    public function notFound(?Renderer $override = null): Response
    {
        $view = $override ?? $this->renderer;
        $pick = $this->posts->query()->type('post')->status('publish')->random();

        return $view->render('not-found', [
            // 键始终在(null 也占位):模板在 strict_variables 下不做 defined 猜谜
            'suggest' => $pick === null ? null : ['title' => $pick->title, 'url' => $pick->url],
        ], [], 404);
    }

    /** 前台可见:已发布、未删除、且没到定时发布时间 */
    public function isVisible(Post $post): bool
    {
        if ($post->status !== 'publish') {
            return false;
        }

        if ($post->published_at === null) {
            return true;
        }

        $stamp = strtotime($post->published_at . ' UTC');

        return $stamp === false || $stamp <= time();
    }

    /*
     * 主题预览:管理员带 ?molin_theme_preview={slug} 打开前台,
     * 这一趟按指定主题渲染,不动全站启用状态。权限不过或主题不存在就回默认渲染器。
     */
    private function previewRenderer(Request $request): ?Renderer
    {
        $slug = trim($request->input('molin_theme_preview'));

        if ($slug === '' || !current_user_can('theme.switch')) {
            return null;
        }

        try {
            $theme = Theme::load(MOLIN_ROOT . '/themes', $slug);
        } catch (\Throwable) {
            return null;
        }

        return new Renderer(
            $theme,
            $this->container->get(Router::class),
            $this->container->get(Options::class),
            $this->container->get(ThemeSettings::class),
            Renderer::pagesProvider($this->container->get(PostRepository::class)),
            Renderer::archiveProvider($this->container->get(PostRepository::class)),
            null,
            // 预览态与前台同一条取图通道,否则预览别的主题时图全空
            Renderer::mediaProvider($this->container->get(MediaRepository::class), $this->container->get(Store::class)),
            Renderer::metaProvider($this->container->get(MetaStore::class))
        );
    }
}
