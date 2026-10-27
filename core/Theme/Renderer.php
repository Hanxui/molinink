<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Theme;

use Closure;
use Molin\Content\MetaStore;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\TermAssigner;
use Molin\Hook\HookBus;
use Molin\Http\Response;
use Molin\Language\Translator;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Site\Seo;
use Molin\User\Auth;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * 渲染器:按页型选模板、注入既有规范的标准变量、经 Twig 受限子集输出。
 *
 * `theme/head/render` / `theme/footer/render` 的输出在这里汇总,交给模板变量
 * `head_output`、`footer_output`—— 内核算好,主题只负责放出去。
 *
 * 正文的 `content/render/before|after` 不在这里做:它属于内容层,见 ContentRenderer。
 */
final class Renderer
{
    /** 前台导航能列出的页面上限:导航是给人点到,不追平 sitemap 口径 */
    private const NAV_LIMIT = 50;

    /**
     * 主题模板里允许调用的 Twig 函数名(白名单)。
     *
     * 与既有规范及 `tests/Contract/TemplateSafetyContractTest::FUNCTIONS` 同一份口径:
     * 这里增删一个名字,那份契约测试也要同步。
     * 上传校验(Uploader)靠它注册同名空实现,才能在不启动真容器的前提下编译模板 ——
     * 否则 `Unknown function: theme_setting` 这种误报会把所有真主题挡在门外。
     */
    public const TWIG_FUNCTIONS = [
        'path', 'asset', 'image', 'media_url', '__', '_e', 'avatar', 'can',
        'format_date', 'format_number', 'theme_setting', 'inline_style', 'archive_tree', 'post_meta',
        // 2026-10-23 增:`<html lang>` 跟随界面语言(zh-Hans / zh-Hant / en),模板不再写死
        'html_lang',
    ];

    /** 允许使用的自定义过滤器名(同上,与契约测试同一份口径) */
    public const TWIG_FILTERS = ['truncate'];

    /**
     * 只做「语法 + 白名单」校验用的 Twig 环境:注册同名空实现,不接仓储、不落渲染。
     *
     * 上传/安装校验起不了真容器(那要 Router / Options / 仓储),但模板里 `theme_setting(...)`
     * 这类调用在编译期就会因"函数不存在"报错。这里按白名单补上空壳,白名单以外的函数照样报错 ——
     * 于是校验既不再误报,也顺手成了一道白名单检查。
     */
    public static function syntaxEnvironment(): Environment
    {
        $twig = new Environment(new ArrayLoader(), ['autoescape' => 'html']);

        foreach (self::TWIG_FUNCTIONS as $name) {
            $twig->addFunction(new TwigFunction($name, static fn (mixed ...$args): string => ''));
        }

        foreach (self::TWIG_FILTERS as $name) {
            $twig->addFilter(new TwigFilter($name, static fn (mixed $value): string => (string) $value));
        }

        return $twig;
    }

    /** 归档树每页取文条数:PostQuery::limit 的封顶就是 200,再大也吃不下 */
    private const ARCHIVE_LIMIT = 200;

    /** 归档树最多翻几页。单次查询封顶 200,不翻页会让第 200 篇以前的旧文在归档里凭空消失 */
    private const ARCHIVE_MAX_PAGES = 20;

    private Environment $twig;

    /** @var array<string, string|int> */
    private array $context = [];

    /** 这一趟渲染的页型与状态码:SEO 要据此决定"能不能给 canonical / 要不要 noindex" */
    private string $kind = '';

    private int $status = 200;

    /**
     * 本渲染器解析过的主题资源:模板里传的路径 => 真实路径(解析不出来记 null)。
     * 一次渲染里同一个文件会被问好几遍(循环里重复出链、asset 与 inline_style 共用一个文件),
     * 每次都 realpath 一遍纯属浪费;渲染器每请求 / 每主题一个实例,记忆不会跨请求。
     *
     * @var array<string, string|null>
     */
    private array $assetFiles = [];

    /**
     * 资源文件的 mtime:真实路径 => 时间戳。
     *
     * @var array<string, int|false>
     */
    private array $assetTimes = [];

    /** 主题 assets 目录的真实路径;算不出来是 null,所以要另记一个"算过了" */
    private ?string $assetsDir = null;

    private bool $assetsDirResolved = false;

    /** 钩子总线:模板选中后过 `theme/template/replace`,插件据此改选模板 */
    private readonly HookBus $hooks;

    /**
     * @param (Closure(): list<array<string, mixed>>)|null $navPages 顶部导航的默认数据源,由组装层注入
     * @param (Closure(): list<array<string, mixed>>)|null $archiveTree 归档树的数据源,由组装层注入
     * @param ?HookBus $hooks 钩子总线;未注入时用进程内的那一份(HookBus::instance())
     * @param (Closure(int): ?array<string, mixed>)|null $mediaLookup 按附件 id 取图片数据,由组装层注入
     * @param (Closure(int|list<int>): array<string, string>)|null $metaLookup 取自定义字段(postmeta):传 id 取一篇,传 id 列表投递本页待取清单,由组装层注入
     */
    public function __construct(
        private readonly Theme $theme,
        private readonly Router $router,
        private readonly Options $options,
        private readonly ThemeSettings $settings,
        private readonly ?Closure $navPages = null,
        private readonly ?Closure $archiveTree = null,
        ?HookBus $hooks = null,
        private readonly ?Closure $mediaLookup = null,
        private readonly ?Closure $metaLookup = null
    ) {
        $this->hooks = $hooks ?? HookBus::instance();

        $loader = new FilesystemLoader($this->theme->templateDirs());

        $this->twig = new Environment($loader, [
            'cache' => MOLIN_ROOT . '/storage/cache/twig',
            // 自动转义永远不开关;开发期 strict_variables / debug / auto_reload 全开
            'autoescape' => 'html',
            'strict_variables' => APP_DEBUG,
            'debug' => APP_DEBUG,
            'auto_reload' => APP_DEBUG,
        ]);

        $router = $this->router;
        $this->twig->addFunction(new TwigFunction('path', static function (string $name, array $params = []) use ($router): string {
            return $router->generate($name, $params);
        }));

        // 绑定本渲染器正在画的那只主题:后台预览别的主题时,取值不能跟着全站当前主题跑
        $settings = $this->settings;
        $theme = $this->theme;
        $this->twig->addFunction(new TwigFunction('theme_setting', static function (string $path, mixed $default = null) use ($settings, $theme): mixed {
            return $settings->get($path, $default, $theme);
        }));

        // 归档树数据源:数据由组装层注入,未注入给空数组,不抛异常
        $archiveTree = $this->archiveTree;
        $this->twig->addFunction(new TwigFunction('archive_tree', static function () use ($archiveTree): array {
            return $archiveTree === null ? [] : $archiveTree();
        }));

        // i18n:__ 原样返回、_e 转义后返回(Markup 免二次转义),
        // 日期与数字走本地化助手 —— 与 PHP 侧同名同源。
        $this->twig->addFunction(new TwigFunction('__', static fn (string $text, string $domain = 'molin', mixed ...$args): string => __($text, $domain, ...$args)));
        $this->twig->addFunction(new TwigFunction('_e', static fn (string $text, string $domain = 'molin', mixed ...$args): Markup => new Markup(Translator::instance()->escape($text, $domain, ...$args), 'UTF-8')));
        $this->twig->addFunction(new TwigFunction('format_date', static fn (mixed $value, string $style = 'long'): string => format_date($value, $style)));
        $this->twig->addFunction(new TwigFunction('format_number', static fn (int|float $value, int $decimals = 0): string => format_number($value, $decimals)));
        $this->twig->addFunction(new TwigFunction('html_lang', static fn (): string => Translator::instance()->htmlLang()));
        $this->twig->addFunction(new TwigFunction('avatar', static fn (string $email, int $size = 48): string => avatar($email, $size)));

        // 覆盖内置 |date:库里时间存的是 UTC,模板直接 date 会整体偏几小时
        $this->twig->addFilter(new TwigFilter('date', static fn (mixed $value, string $format = 'F j, Y H:i', ?string $timezone = null): string => site_datetime($value, $timezone)->format($format)));

        // 权限判断:与 PHP 助手 current_user_can 同名同源,
        // 底下就是 Authorizer::can —— 能力名 + 可选对象(own 类能力据此再验"是不是他的")。
        // 能力名没注册过、未登录、被封、待验证一律 false(既有规范默认拒绝)。
        $this->twig->addFunction(new TwigFunction('can', static fn (string $capability, ?object $target = null): bool => current_user_can($capability, $target)));

        // 图片输出:把组装层给的缩略图数据落成 <img>,带 srcset 与懒加载。
        //
        // source 收两种:① 图片数据数组(列表页由 index.php 的 $thumbsFor 组装);
        // ② 附件 id(主题设置的 image 字段,经组装层注入的 mediaLookup 现取,取不到就是空)。
        // 没有图(附件不存在 / 盘上没文件 / 字段没配)返回空串,模板据此落回无图版式。
        //
        // 属性走白名单:图片上说得通的命名属性才放行,事件处理器之类一律忽略(值仍逐个转义)。
        $mediaLookup = $this->mediaLookup;
        $this->twig->addFunction(new TwigFunction('image', static function (mixed $source, string $size = 'medium', array $attrs = []) use ($mediaLookup): Markup {
            if (is_int($source) || (is_string($source) && ctype_digit($source))) {
                $source = $mediaLookup === null ? null : $mediaLookup((int) $source);
            }

            if (!is_array($source) || (string) ($source['url'] ?? '') === '') {
                return new Markup('', 'UTF-8');
            }

            // 点名的那一档在就用它,否则回落到调用层给的默认档
            $variants = is_array($source['sizes'] ?? null) ? $source['sizes'] : [];
            $pick = is_array($variants[$size] ?? null) ? $variants[$size] : null;

            $url = (string) ($pick['url'] ?? $source['url']);
            $width = (int) ($pick['width'] ?? $source['width'] ?? 0);
            $height = (int) ($pick['height'] ?? $source['height'] ?? 0);

            $allowed = ['class', 'id', 'style', 'title', 'sizes', 'loading', 'decoding', 'fetchpriority', 'referrerpolicy'];
            $attr = '';

            foreach ($attrs as $name => $value) {
                $name = strtolower(trim((string) $name));

                if (!in_array($name, $allowed, true) || $value === null || is_bool($value)) {
                    continue;
                }

                $attr .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
            }

            $srcset = (string) ($source['srcset'] ?? '');
            $alt = (string) ($attrs['alt'] ?? $source['alt'] ?? '');

            return new Markup(
                '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
                . ($srcset === '' ? '' : ' srcset="' . htmlspecialchars($srcset, ENT_QUOTES, 'UTF-8') . '"')
                . ($width > 0 && $height > 0 ? ' width="' . $width . '" height="' . $height . '"' : '')
                . ' alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"'
                . (isset($attrs['loading']) ? '' : ' loading="lazy"')
                . (isset($attrs['decoding']) ? '' : ' decoding="async"')
                . $attr
                . '>',
                'UTF-8'
            );
        }));

        // 自定义字段读取:模板按「文章 id + 字段键」取值,取不到回落默认。
        // 数据由组装层注入(渲染层不直接碰内容仓储);同一篇在一次请求里只查一遍(见 metaProvider)。
        // 谁登记过什么字段,主题作者自己知道键名(如插件字段 `my-plugin_seo_title`)。
        $metaLookup = $this->metaLookup;
        $this->twig->addFunction(new TwigFunction('post_meta', static function (int $postId, string $key, string $default = '') use ($metaLookup): string {
            if ($metaLookup === null || $postId <= 0 || $key === '') {
                return $default;
            }

            $value = $metaLookup($postId)[$key] ?? '';

            return $value === '' ? $default : $value;
        }));

        // 附件 id → 图片地址:分享卡片(og:image)要的是地址串,不是 <img>。
        // 与 image() 同一条取图通道,取不到给空串 —— 模板据此决定那个 meta 要不要出。
        // 入参收 mixed:模板里常是 postmeta 取出来的数字串,这里自己收口成 int。
        $this->twig->addFunction(new TwigFunction('media_url', static function (mixed $attachmentId) use ($mediaLookup): string {
            $id = is_int($attachmentId) ? $attachmentId : (int) (is_string($attachmentId) ? trim($attachmentId) : 0);
            $data = $id > 0 && $mediaLookup !== null ? $mediaLookup($id) : null;

            return is_array($data) ? (string) ($data['url'] ?? '') : '';
        }));

        // 既有规范/ 主题静态资源的根相对路径 + 自动版本号(防缓存)。
        // 版本号取资源文件自身的修改时间:文件一改即变,比"跟程序版本走"更准,发版忘了升号也不会留旧缓存。
        $this->twig->addFunction(new TwigFunction('asset', function (string $path): string {
            $url = '/themes/' . $this->theme->slug() . '/assets/' . ltrim(str_replace('\\', '/', $path), '/');

            return $url . $this->assetQuery($path);
        }));

        // 关键样式内联:把主题 assets 里的 CSS 原样落进 <head>。
        // 外链样式表是阻塞渲染的,晚到会先画出"没有版式"的半成品(2026-10-09复现)。
        $this->twig->addFunction(new TwigFunction('inline_style', function (string $path): Markup {
            $file = $this->assetFile($path);

            if ($file === null) {
                $safe = (string) preg_replace('/[^A-Za-z0-9._\/-]/', '', $path);

                return new Markup('<!-- molin: 主题资源不存在 ' . $safe . ' -->', 'UTF-8');
            }

            return new Markup('<style data-src="' . $path . '">' . "\n" . rtrim((string) file_get_contents($file)) . "\n</style>", 'UTF-8');
        }));

        // 自定义过滤器 truncate(既有规范):按【字符】截断,不切坏 UTF-8;
        // 超长补省略号,写法沿用仓内既有习惯(mb_substr(..., 0, N) . '…',见 Site/Feed.php:142)。
        // 长度非数字或非正一律原样返回 —— 模板传错值不该 500,也不该把整段文字吞成空。
        $this->twig->addFilter(new TwigFilter('truncate', static function (mixed $value, mixed $length = null): string {
            $text = (string) $value;
            $limit = is_numeric($length) ? (int) $length : 0;

            if ($limit <= 0) {
                return $text;
            }

            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
        }));

        if (APP_DEBUG) {
            $this->twig->addExtension(new DebugExtension());
        }
    }

    /**
     * 按附件 id 取图片数据:主题设置里的 image 字段存的就是附件 id,
     * 模板要按 id 出图,得有这条通道。仓储与盘的事由组装层注入 ——
     * 渲染层不直接碰内容仓储(与 pagesProvider / archiveProvider 同一条纪律)。
     *
     * 入参是**附件本体 id**(= media.post_id),与 `posts.thumbnail_id`、后台缩略图路由同一口径。
     */
    public static function mediaProvider(MediaRepository $media, Store $store): Closure
    {
        return static function (int $attachmentId) use ($media, $store): ?array {
            $record = $attachmentId > 0 ? $media->byPostId($attachmentId) : null;

            return $record === null ? null : MediaRepository::imageData($record, $store);
        };
    }

    /**
     * 自定义字段数据源:按文章 id 取该篇的全部 postmeta。
     *
     * 渲染层不直接碰内容仓储(与 pagesProvider / mediaProvider 同一条纪律);
     * 同一篇在一次请求里只查一遍 —— 模板里 `post_meta` 会被调好几次(既有规范禁 N+1)。
     *
     * 收 id 列表 = 渲染器投递"这一页要画哪些文章",此时**不查库**;等模板第一次真的调
     * `post_meta()` 才用 many() 一条 SQL 把整页补齐 —— 模板不用 meta 的页面一次也不查。
     */
    public static function metaProvider(MetaStore $meta): Closure
    {
        /** @var array<int, array<string, string>> $memo */
        $memo = [];

        /** @var list<int> $pending 本页待取清单,由 Renderer::begin() 每趟渲染重投 */
        $pending = [];

        return static function (int|array $postId) use ($meta, &$memo, &$pending): array {
            if (is_array($postId)) {
                $pending = array_values(array_unique(array_filter(
                    array_map(static fn (mixed $id): int => (int) $id, $postId),
                    static fn (int $id): bool => $id > 0
                )));

                return [];
            }

            if (!isset($memo[$postId])) {
                if ($pending !== []) {
                    $memo += $meta->many($pending);

                    // many() 只给"有字段"的行建键;没字段的也要落成空数组,否则下一行又回库
                    foreach ($pending as $id) {
                        $memo[$id] ??= [];
                    }

                    $pending = [];
                }

                $memo[$postId] ??= $meta->all($postId);
            }

            return $memo[$postId];
        };
    }

    /**
     * 本渲染器正在画的那只主题:前台组装层用它做页面模板的白名单校验 ——
     * 有主题预览时不能拿全站当前主题来卡(预览别的主题要按那只主题的清单走)。
     */
    public function theme(): Theme
    {
        return $this->theme;
    }

    /**
     * 默认顶部导航的数据源:站点「已发布」独立页面,按「序号」升序,同号按创建先后。
     * 由组装层注入(index.php 与 Bootstrap),渲染层不直接碰内容仓储。
     */
    public static function pagesProvider(PostRepository $posts): Closure
    {
        return static function () use ($posts): array {
            $nav = [];

            foreach ($posts->query()->type('page')->status('publish')->orderBy('menu_order', 'asc', 'asc')->limit(self::NAV_LIMIT)->get() as $page) {
                $nav[] = ['title' => $page->title, 'url' => $page->url];
            }

            return $nav;
        };
    }

    /**
     * 归档树数据源:已发布文章按 年 → 月 → 日 聚合,给归档页模板的 `archive_tree()` 用。
     *
     * URL 直接取 `Post::$url`:仓储 hydrate 时已用 Router::generate('post') 算好,是绝对地址;
     * 本工厂按冻结签名只收 PostRepository 一个参数,不再为此把 Router 拉进来(模板里 <a href> 用绝对地址一样可用)。
     *
     * 年份 / 月号降序,月份速览(preview)升序;分类名一次批量取,不逐篇查(既有规范禁 N+1)。
     *
     * @return Closure(): list<array{year: int, count: int, preview: string, months: list<array{month: int, count: int, rows: list<array{day: int, title: string, url: string, category: ?string, category_slug: string}>}>}>
     */
    public static function archiveProvider(PostRepository $posts): Closure
    {
        return static function () use ($posts): array {
            $found = [];

            // 归档要的是"全部文章",而单次查询封顶 200 条,所以取满一页就再翻一页;
            // 上限 ARCHIVE_MAX_PAGES 是天花板,防住病态站点把这次渲染拖死
            for ($page = 1; $page <= self::ARCHIVE_MAX_PAGES; $page++) {
                $chunk = $posts->query()
                    ->type('post')
                    ->status('publish')
                    ->orderBy('published_at', 'desc')
                    ->limit(self::ARCHIVE_LIMIT)
                    ->page($page)
                    ->get();

                $found = array_merge($found, $chunk);

                if (count($chunk) < self::ARCHIVE_LIMIT) {
                    break;
                }
            }

            /** @var list<int> $ids */
            $ids = array_map(static fn (Post $post): int => $post->id, $found);
            // 全量文章的分类名一次批量取:逐篇查就是 N+1。TermAssigner 需要连接,
            // 从 PostRepository 取出共享的那一份(静态工厂拿不到容器)。
            $categories = (new TermAssigner($posts->database()))->firstTermsFor($ids, TermAssigner::CATEGORY);

            /** @var array<int, array<int, list<array{day: int, title: string, url: string, category: ?string, category_slug: string}>>> $years */
            $years = [];

            foreach ($found as $post) {
                $stamp = (string) ($post->published_at ?? '');

                if ($stamp === '') {
                    continue; // 没有发布时间的(理论上不会出现在 publish 里)不进归档
                }

                $time = strtotime($stamp . ' UTC');

                if ($time === false) {
                    continue;
                }

                // 归档的"哪一年哪一月"按 UTC 切:与库里 published_at 的字面、归档 URL 的
                // 年份段同一口径;换成站点时区切会和 URL / 查询对不上
                $year = (int) gmdate('Y', $time);
                $month = (int) gmdate('n', $time);
                $first = $categories[$post->id] ?? null;
                $catName = $first['name'] ?? null;
                $catSlug = $first === null ? '' : $first['slug'];

                // $found 已按 published_at 降序:插入序天然是"月降序、日降序"
                /** @var array{day: int, title: string, url: string, category: ?string, category_slug: string} $row */
                $row = [
                    'day' => (int) date('j', $time),
                    'title' => $post->title,
                    'url' => $post->url,
                    'category' => $catName,
                    // 分类别名:主题要给分类做链接得用它,拿名称当 slug 会链空(别名≠名称的站)
                    'category_slug' => $catSlug,
                ];
                $years[$year][$month][] = $row;
            }

            $tree = [];

            // 年份要降序。聚合的插入序恰好也是降序,但那不是不变量,这里显式排一次
            krsort($years);

            foreach ($years as $year => $months) {
                ksort($months);

                $monthList = [];
                $yearCount = 0;

                foreach (array_reverse($months, true) as $month => $rows) {
                    $monthList[] = ['month' => (int) $month, 'count' => count($rows), 'rows' => $rows];
                    $yearCount += count($rows);
                }

                // 月份速览升序,两位数字用 " · " 连接(如 "02 · 03 · 04")
                $preview = array_map(static fn (int $month): string => sprintf('%02d', $month), array_keys($months));

                $tree[] = [
                    'year' => (int) $year,
                    'count' => $yearCount,
                    'preview' => implode(' · ', $preview),
                    'months' => $monthList,
                ];
            }

            return $tree;
        };
    }

    /**
     * @param array<string, mixed> $vars 页面变量(posts / post / pagination 等)
     * @param array<string, string|int> $context 回退链替换用的上下文
     */
    public function render(string $kind, array $vars = [], array $context = [], int $status = 200): Response
    {
        $this->begin($vars, $context, $kind, $status);

        return Response::html($this->twig->render($this->pickTemplate($kind, $context), $this->withGlobals($vars)), $status)
            // 前台 HTML 不缓存:样式与脚本都带版本戳,HTML 每次回源才不会出现"新页面配旧资源"
            ->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * 渲染一段列表片段(既有规范`list.fragment`:列表翻页 / 切分类不再整页刷新)。
     *
     * 与整页共用同一条回退链与模板 / partial —— 换的只是页型与变量,不另造一套标记;
     * 不过 withGlobals:片段没有 head / footer 倒出口,也不该触发那两个钩子。
     *
     * @param array<string, mixed> $vars
     * @param array<string, string|int> $context
     */
    public function renderFragment(string $kind, array $vars = [], array $context = []): Response
    {
        $this->begin($vars, $context);

        return Response::html($this->twig->render($this->pickTemplate($kind, $context), $vars))
            ->withHeaders(['Cache-Control' => 'no-cache']);
    }

    /**
     * 记住当次路由上下文;每次都重置单篇缓存。
     *
     * @param array<string, mixed>      $vars
     * @param array<string, string|int> $context
     */
    private function begin(array $vars, array $context, string $kind = '', int $status = 200): void
    {
        $this->context = $context;
        $this->kind = $kind;
        $this->status = $status;
        $this->primeMeta($vars);
    }

    /**
     * 把这一页要用的文章 id 交给 meta 数据源。列表页的模板可能对每一行调 post_meta(),
     * 逐行查就是 N+1;投递清单后由数据源在首次取值时一次批量取完。
     * 每次渲染都投一遍(空也投),清掉上一趟留下的清单。
     *
     * @param array<string, mixed> $vars
     */
    private function primeMeta(array $vars): void
    {
        if ($this->metaLookup === null) {
            return;
        }

        /** @var array<int, true> $ids */
        $ids = [];

        $collect = static function (mixed $post) use (&$ids): void {
            if ($post instanceof Post && $post->id > 0) {
                $ids[$post->id] = true;
            }
        };

        // 单篇给的是 Post;列表给 posts(Post[])与 items(模板行,只有 id)
        $collect($vars['post'] ?? null);

        foreach ((array) ($vars['posts'] ?? []) as $post) {
            $collect($post);
        }

        foreach ((array) ($vars['items'] ?? []) as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) > 0) {
                $ids[(int) $item['id']] = true;
            }
        }

        ($this->metaLookup)(array_keys($ids));
    }

    /**
     * 选模板:页面模板白名单命中优先,否则走回退链;选中后再过 `theme/template/replace`。
     *
     * 页面模板:该页 meta 选了主题里声明过的模板就用它,校验走主题白名单,命中即可安全交给 Twig。
     * filter:返回值只认当前主题模板目录里的裸文件名(见 isPlainTemplateName),
     * 带目录分隔符、`..`、空串、非 .twig 或不存在的名字一律忽略,退回原模板 —— 不抛异常把站点打挂,也不会误用到别的文件。
     *
     * @param array<string, string|int> $context
     */
    private function pickTemplate(string $kind, array $context): string
    {
        $requested = $context['template'] ?? null;
        $template = is_string($requested) && $requested !== '' ? $this->theme->resolvePageTemplate($requested) : null;

        if ($template === null) {
            $template = $this->theme->resolveFirst(Fallbacks::candidates($kind, $context));
        }

        $replaced = $this->hooks->applyFilters(
            'theme/template/replace',
            $template,
            array_merge($context, ['kind' => $kind])
        );

        if (is_string($replaced) && self::isPlainTemplateName($replaced) && $this->theme->hasTemplate($replaced)) {
            $template = $replaced;
        }

        return $template;
    }

    /**
     * `theme/template/replace` 的返回值校验:必须是当前主题模板目录里的裸文件名。
     *
     * 空串、`\0`、目录分隔符(`/` 与 `\`)、`..`、非 `.twig` 一律不收 —— 这些名字交给 Twig
     * 文件加载器就是越界读取;存不存在交给 Theme::hasTemplate()(与回退链同一份搜索路径)。
     */
    private static function isPlainTemplateName(string $name): bool
    {
        return $name !== ''
            && !str_contains($name, "\0")
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, '..')
            && str_ends_with($name, '.twig');
    }

    /**
     * 主题 assets 目录里的真实文件;路径不合法或文件不存在给 null。
     *
     * 模板是半可信输入,这里必须挡住 `..` 与绝对路径 —— 否则这两个 helper 就成了任意文件读。
     */
    private function assetFile(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        // 解析结果(含 null)按模板传进来的原路径记一份,别让同一页反复走 realpath
        if (array_key_exists($path, $this->assetFiles)) {
            return $this->assetFiles[$path];
        }

        return $this->assetFiles[$path] = $this->resolveAssetFile($path);
    }

    private function resolveAssetFile(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '..')) {
            return null;
        }

        $assets = $this->assetsDir();

        if ($assets === null) {
            return null;
        }

        $file = realpath($assets . '/' . $path);

        if ($file === false || !str_starts_with($file, $assets . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }

        return $file;
    }

    /** 主题 assets 目录的真实路径(一页只解析一次;解析不出来就是 null,不必每次都试) */
    private function assetsDir(): ?string
    {
        if ($this->assetsDirResolved) {
            return $this->assetsDir;
        }

        $this->assetsDirResolved = true;
        $dirs = $this->theme->templateDirs();
        $first = $dirs[0] ?? '';

        if ($first === '') {
            return null;
        }

        $dir = realpath(dirname($first) . '/assets');

        return $this->assetsDir = $dir === false ? null : $dir;
    }

    /** `asset()` 的版本号后缀;文件不在就是空串(地址原样返回),与未加版本号时一致 */
    private function assetQuery(string $path): string
    {
        $file = $this->assetFile($path);

        if ($file === null) {
            return '';
        }

        if (!array_key_exists($file, $this->assetTimes)) {
            $this->assetTimes[$file] = filemtime($file);
        }

        return '?v=' . base_convert((string) $this->assetTimes[$file], 10, 36);
    }

    /**
     * 头部 SEO 那一段(canonical / OG / Twitter / JSON-LD)。
     *
     * 取数全在核心里:主题只知道 `head_output` 这个字符串,不该自己拼(它也查不了库)。
     * 单篇/独立页面用文章自己那几样;归档页用词条;其余(首页、搜索、404)退回站点级。
     *
     * @param array<string, mixed> $vars
     */
    private function seoHead(array $vars): string
    {
        // 404 与搜索结果页不该有 canonical、也不该被收录(2026-10-17 自查补):
        // 给 404 一个"正版地址"是自相矛盾;搜索结果是动态拼的,收录进去只会跟正文抢位置。
        if ($this->status === 404 || $this->kind === 'not-found' || $this->kind === 'search' || ($this->context['noindex'] ?? 0) === 1) {
            return '<meta name="robots" content="noindex,follow">' . "\n";
        }

        $siteUrl = rtrim((string) $this->options->get('site_url', ''), '/');
        $path = $this->router->currentPath() ?? '';
        $current = $siteUrl === '' ? '' : $siteUrl . ($path === '' ? '/' : $path);

        $page = ['type' => 'website', 'canonical' => $current];

        $post = $vars['post'] ?? null;
        $term = $vars['term'] ?? null;

        if ($post instanceof Post) {
            $page['type'] = 'article';
            $page['title'] = $post->title;
            $page['description'] = $post->excerpt ?? '';
            $page['canonical'] = $post->url !== '' ? $post->url : $current;
            $page['published'] = $post->published_at ?? '';
            $page['modified'] = $post->updated_at;
            $page['image'] = $this->imageUrl($post->thumbnail_id);
            $page['author'] = (string) ($vars['author'] ?? '');
            $category = $vars['category'] ?? null;
            $page['section'] = is_array($category) ? (string) ($category['name'] ?? '') : '';
        } elseif (is_array($term) && ($term['name'] ?? '') !== '') {
            $page['title'] = (string) $term['name'];
            $page['description'] = (string) ($term['description'] ?? '');
        } else {
            $page['title'] = (string) $this->options->get('site_name', '');
            $page['description'] = (string) $this->options->get('site_description', '');
        }

        // 摘要为空时由 Seo 用站点简介兜底(它本来就拿着 site_description)

        $seo = new Seo(
            (string) $this->options->get('site_name', '墨林'),
            (string) $this->options->get('site_description', ''),
            (string) $this->options->get('blog_public', '1') === '1'
        );

        return $seo->head($page);
    }

    /** 封面图地址:附件 id → 媒体行 → 绝对地址;取不到给空串(没图就只出 summary 卡) */
    private function imageUrl(int $attachmentId): string
    {
        if ($attachmentId < 1 || $this->mediaLookup === null) {
            return '';
        }

        $row = ($this->mediaLookup)($attachmentId);
        $url = is_array($row) ? (string) ($row['url'] ?? '') : '';

        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) $this->options->get('site_url', ''), '/') . '/' . ltrim($url, '/');
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function withGlobals(array $vars): array
    {
        $name = (string) ($this->options->get('site_name', '墨林'));

        // 程序版权常显、不可手动关闭(2026-10-08作者定;既有规范)
        $themeSettings = $this->theme->settingsDefaults();
        $themeSettings['show_credit'] = true;

        $globals = [
            'site' => [
                'name' => $name,
                'description' => (string) $this->options->get('site_description', ''),
                'url' => (string) $this->options->get('site_url', ''),
                // 安装向导第 3 步的"搜索引擎可见"开关;关了主题要在 head 里给 noindex
                'public' => $this->options->get('blog_public', '1') === '1',
                // 站点 logo 未接存储,恒 null(默认主题走字标;2026-10-08撤 header.logo 字段)
                'logo' => null,
                // 默认 © {年} {站点名},年份动态;站长可改的那一份等设置项增量
                'copyright' => '© ' . date('Y') . ' ' . $name,
            ],
            'current_user' => Auth::user(),
            // 既有规范的全局变量:表单要带防跨站令牌,主题不该自己想办法弄
            'csrf_token' => Auth::csrfToken(),
            'csrf_field' => Csrf::FIELD,
            'query' => ['name' => $this->router->matchedName()],
            // 前台导航数据源:配置过 `nav_menus` 就以站点存的为准;
            // 未配置时 primary 由注入的页面数据源补(站点的已发布页面),没有数据源就留空。
            'menus' => $this->menus(),
            // 能力开关(0.97):插件停用后主题据此隐藏社交/通知 UI;默认两个都关,由插件打开
            'features' => apply_filters('theme/features/collect', ['social' => false, 'notifications' => false]),
            'settings' => $themeSettings,
            // head_output 是内核汇总后的可信 HTML:站点图标在前,
            // 订阅发现链居中,SEO(2026-10-17 加:canonical / OG / Twitter / JSON-LD)随后,
            // 钩子输出在后,一起交给主题原样放出去。
            'head_output' => new Markup($this->iconLinks() . $this->feedLink($name) . $this->seoHead($vars) . $this->capture('theme/head/render'), 'UTF-8'),
            'footer_output' => new Markup($this->capture('theme/footer/render'), 'UTF-8'),
        ];

        return array_merge($globals, $vars);
    }

    /**
     * 前台菜单:读后台菜单编辑器(MenuController)写的 `nav_menus` option,按菜单位取 primary / footer。
     * `primary` 这个位**从没存过**时才用注入的数据源补(站点的已发布页面,见 pagesProvider);
     * 存过就以存的为准 —— 存成空也照空(2026-10-16:清空菜单后前台仍见旧项,查出来一条是这里的兜底,一条是页面缓存没清)。
     *
     * @return array{primary: list<array<string, mixed>>, footer: list<array<string, mixed>>}
     */
    private function menus(): array
    {
        $primary = [];
        $footer = [];
        $savedPrimary = false;
        $raw = $this->options->get('nav_menus', '');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $slug = (string) ($entry['slug'] ?? $entry['id'] ?? '');
                    $items = $this->menuItems($entry['items'] ?? []);

                    if ($slug === 'primary') {
                        $primary = $items;
                        $savedPrimary = true;
                    } elseif ($slug === 'footer') {
                        $footer = $items;
                    }
                }
            }
        }

        // 兜底只在 primary 这个位从没存过时才补(站点的已发布页面,按「序号」升序、同号按创建先后);
        // 存过就一切以存的为准 —— 哪怕清成空,清空就是要空着。
        // 未注入数据源(个别旧测试)时留空,别再塞假样例。
        if (!$savedPrimary) {
            $primary = $this->navPages === null ? [] : ($this->navPages)();
        }

        $current = $this->router->currentPath();

        return [
            'primary' => $this->withCurrent($primary, $current),
            'footer' => $this->withCurrent($footer, $current),
        ];
    }

    /**
     * 给菜单项标"当前页"(归档页「文章」项高亮)。url 与当前路径都归一成
     * 去尾斜杠的路径段再比;比不了(空串、纯锚点)就不标。CLI 渲染没有当前路径,一律不标。
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function withCurrent(array $items, ?string $current): array
    {
        foreach ($items as &$item) {
            $item = (array) $item;
            $url = trim((string) ($item['url'] ?? ''));
            $parsed = $url === '' ? false : parse_url($url);
            $path = is_array($parsed) ? (string) ($parsed['path'] ?? '') : '';

            $item['current'] = $current !== null && $path !== ''
                && self::normalizePath($path) === self::normalizePath($current);
        }

        unset($item);

        return $items;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * 把菜单编辑器存的原始节点(label / url / children)归一化成模板用的 title / url / children。
     *
     * @return list<array<string, mixed>>
     */
    private function menuItems(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $node) {
            if (!is_array($node)) {
                continue;
            }

            $label = trim((string) ($node['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $children = [];

            foreach ((array) ($node['children'] ?? []) as $child) {
                if (!is_array($child)) {
                    continue;
                }

                $childLabel = trim((string) ($child['label'] ?? ''));

                if ($childLabel === '') {
                    continue;
                }

                $children[] = [
                    'title' => $childLabel,
                    'url' => trim((string) ($child['url'] ?? '')),
                ];
            }

            $out[] = [
                'title' => $label,
                'url' => trim((string) ($node['url'] ?? '')),
                'children' => $children,
            ];
        }

        return $out;
    }

    /**
     * 站点图标(既有规范设计稿:纸白底 + 墨色 M 素面)。与订阅发现链同理,
     * 内核给所有前台页统一供给,主题不必各管一套。地址根相对,和主题里的 /themes/… 静态资源
     * 同一套写法(vhost 走 try_files,真实文件直出)。
     */
    private function iconLinks(): string
    {
        return '<link rel="icon" type="image/png" sizes="32x32" href="/assets/icon/favicon-32.png">' . "\n"
            . '<link rel="icon" type="image/png" sizes="16x16" href="/assets/icon/favicon-16.png">' . "\n"
            . '<link rel="icon" type="image/png" sizes="192x192" href="/assets/icon/icon-192.png">' . "\n"
            . '<link rel="apple-touch-icon" sizes="180x180" href="/assets/icon/apple-touch-icon.png">' . "\n";
    }

    /**
     * RSS 订阅发现链。内核给**所有**前台页统一提供这一条,故放在内核汇总 head_output 处算,
     * 主题不用各自操心(head_output 是内核汇总后的可信 HTML)。
     * 地址只经路由名 feed 生成,不拼字符串。
     */
    private function feedLink(string $name): string
    {
        $title = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($this->router->generate('feed'), ENT_QUOTES, 'UTF-8');

        return '<link rel="alternate" type="application/rss+xml" title="' . $title . '" href="' . $href . '">' . "\n";
    }

    /**
     * 触发钩子并收走它打印的一切。插件 echo 的 meta / 脚本原样进倒出口。
     */
    private function capture(string $hook): string
    {
        ob_start();
        do_action($hook);

        return (string) ob_get_clean();
    }
}
