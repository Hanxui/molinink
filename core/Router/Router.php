<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Router;

use Closure;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

use Molin\Content\PostTypeRegistry;
use Molin\Exception\RouteException;
use Molin\Http\Request;
use Molin\Http\Response;

/**
 * fast-route 薄封装。
 *
 * 两条硬规矩:① 同方法同路径重复注册 → 当场报错并列出来源,不给"后者悄悄覆盖前者";
 * ② URL 一律由路由名生成,path() 与模板都不许拼字符串。
 */
final class Router
{
    /** ALL 的落地写法:fast-route 1.x 没有 ANY,逐个方法登记 */
    private const ALL_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /** 占位符预设,默认"名称" */
    private const DEFAULT_PERMALINK = '/%postname%/';

    /** @var array<string, array{name:string, handler:Closure}> "METHOD 路径" => 处理器 */
    private array $map = [];

    /** @var array<string, list<string>> 路由名 => 已登记路径(供生成用) */
    private array $paths = [];

    private ?Dispatcher $engine = null;

    private ?Closure $notFound = null;

    private ?string $matched = null;

    private ?string $currentPath = null;

    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $permalink = self::DEFAULT_PERMALINK
    ) {
        // 名称表先于处理器:URL 生成只依赖既有规范的映射表,不依赖某条路由挂没挂处理器
        foreach (self::kernelNames() as $name => $route) {
            $this->paths[$name] = $route['paths'];
        }
    }

    /**
     * @return array<string, array{methods: list<string>, paths: list<string>}>
     */
    public static function kernelNames(): array
    {
        return require __DIR__ . '/names.php';
    }

    public function add(string $name, string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);

        foreach ($method === 'ALL' ? self::ALL_METHODS : [$method] as $verb) {
            $key = $verb . ' ' . $path;

            if (isset($this->map[$key])) {
                throw new RouteException(sprintf(
                    '路由冲突:%s 已被「%s」占用,本次来自「%s」',
                    $key,
                    $this->map[$key]['name'],
                    $name
                ));
            }

            $this->map[$key] = ['name' => $name, 'handler' => Closure::fromCallable($handler)];
            $this->paths[$name][] = $path;
        }

        $this->engine = null;
    }

    /**
     * 404 的落点(内部路由名 not-found,不是钩子)。
     */
    public function fallback(callable $handler): void
    {
        $this->notFound = Closure::fromCallable($handler);
    }

    public function dispatch(Request $request): Response
    {
        // 既有规范的固定链接默认带尾斜杠(/%postname%/),而 fast-route 把尾斜杠当两个不同 URL;
        // 这里统一去掉多余的尾斜杠,'/' 本身保留。
        $path = $request->path();

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $this->currentPath = $path;

        $result = $this->engine()->dispatch($request->method(), $path);

        if ($result[0] === Dispatcher::FOUND) {
            /** @var array{name:string, handler:Closure} $route */
            $route = $result[1];
            /** @var array<string, string> $vars */
            $vars = $result[2];
            $this->matched = $route['name'];

            return $this->toResponse(($route['handler'])($request, $vars));
        }

        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            /** @var list<string> $allowed */
            $allowed = $result[1];

            return Response::text('Method Not Allowed: ' . implode(', ', $allowed), 405);
        }

        $this->matched = null;

        if ($this->notFound === null) {
            return Response::text('Not Found', 404);
        }

        return $this->toResponse(($this->notFound)($request, []));
    }

    /** 本次分发归一后的请求路径(去尾斜杠口径),菜单标"当前页"用;没分发过为 null。 */
    public function currentPath(): ?string
    {
        return $this->currentPath;
    }

    /**
     * 单篇的路由模式:按当前固定链接结构现算,交给 fast-route 分发。
     *
     * 结构与分发的对应是这套机制成立的关键 —— 换结构只改选项,
     * 这里跟着算出新模式,不必再手工维护一条 `/{slug:[^/]+}`。
     * 查询式结构(`?p=%post_id%` 这种)没有路径段,返回 null,单篇改由 `/` 上的
     * `?p=` / `?page_id=` 解析(见 index.php 首页处理器)。
     */
    public function postPattern(): ?string
    {
        if (str_starts_with($this->permalink, '?')) {
            return null;
        }

        $pattern = rtrim(strtr($this->permalink, [
            '%postname%' => '{postname:[^/]+}',
            '%post_id%'  => '{post_id:\d+}',
            '%category%' => '{category:[^/]+}',
            '%type%'     => '{type:[^/]+}',
            '%year%'     => '{year:\d{4}}',
            '%month%'    => '{month:\d{2}}',
            '%day%'      => '{day:\d{2}}',
        ]), '/');

        // 没有落下任何占位符就不算一条单篇模式(免得与 `/` 之类撞车)
        return str_contains($pattern, '{') ? $pattern : null;
    }

    /** 结构里含 %category% 时,生成与查询都要带上主分类。 */
    public function needsCategoryInPath(): bool
    {
        return str_contains($this->permalink, '%category%');
    }

    /**
     * 把一条由本站生成的绝对地址压回站内相对地址(302 Location 只认 '/' 开头,
     * 见 path() 的说明)。
     */
    public function relative(string $url): string
    {
        $base = rtrim($this->baseUrl, '/');

        return $base !== '' && str_starts_with($url, $base) ? substr($url, strlen($base)) : $url;
    }

    /**
     * 由路由名生成 URL。
     *
     * @param array<string, string|int> $params
     */
    public function generate(string $name, array $params = []): string
    {
        $path = $name === 'post' ? $this->postPath($params) : $this->patternPath($name, $params);

        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * 同一个路由名,只给站内路径(不带 baseUrl)。
     *
     * 跳转(302 Location)要的是站内相对地址:`Response::redirect()` 只认 '/' 开头,
     * 传绝对地址会被当成外站而退回 '/'。页面里的 <a href> 仍用 generate(),
     * 那样才跟着 site_url 走。
     *
     * @param array<string, string|int> $params
     */
    public function path(string $name, array $params = []): string
    {
        return $this->relative($this->generate($name, $params));
    }

    public function matchedName(): ?string
    {
        return $this->matched;
    }

    private function engine(): Dispatcher
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        // 既有规范优先级:静态 > 带占位 > 通配。fast-route 按登记顺序建表,
        // 静态路由若排在 `/{slug:[^/]+}` 之后会直接抛 BadRouteException(整站 500),
        // 所以在这里定序,处理器注册顺序不用操这份心。PHP 8 排序稳定,同级保持原序。
        $map = $this->map;
        uksort($map, static fn (string $a, string $b): int => self::priority(explode(' ', $a, 2)[1])
            <=> self::priority(explode(' ', $b, 2)[1]));

        $this->engine = simpleDispatcher(static function (RouteCollector $collector) use ($map): void {
            foreach ($map as $key => $route) {
                [$method, $path] = explode(' ', $key, 2);
                $collector->addRoute($method, $path, $route);
            }
        });

        return $this->engine;
    }

    /**
     * 0 静态 / 1 含占位 / 2 含 `:.+` 通配(通配必须最后,否则会盖掉它后面注册的一切)。
     */
    private static function priority(string $path): int
    {
        if (!str_contains($path, '{')) {
            return 0;
        }

        return str_contains($path, ':.+}') ? 2 : 1;
    }

    /**
     * 单篇地址按当前固定链接结构生成(%postname% / %post_id% / %year% 等)。
     *
     * @param array<string, string|int> $params
     */
    private function postPath(array $params): string
    {
        $slug = isset($params['slug']) ? (string) $params['slug'] : '';
        $postId = (string) ($params['post_id'] ?? $params['id'] ?? '');
        $category = (string) ($params['category'] ?? '');
        $year = (string) ($params['year'] ?? '');
        $month = (string) ($params['month'] ?? '');
        $day = (string) ($params['day'] ?? '');

        if ($year === '' && isset($params['published_at'])) {
            $stamp = strtotime((string) $params['published_at'] . ' UTC');
            $year = date('Y', $stamp === false ? time() : $stamp);
            $month = date('m', $stamp === false ? time() : $stamp);
            $day = date('d', $stamp === false ? time() : $stamp);
        }

        // 自定义内容类型不吃固定链接结构:地址恒为 `/{rewrite.slug}/{slug}`。
        // 段从登记表取,登记表里非 public / rewrite=false 的类型取不到段,回落下面的既有口径。
        $type = (string) ($params['type'] ?? '');
        $segment = PostTypeRegistry::instance()->rewriteSlug($type);

        if ($segment !== null && $slug !== '') {
            return '/' . $segment . '/' . $slug . '/';
        }

        if ($slug === '' && str_contains($this->permalink, '%postname%')) {
            throw new RouteException("生成 post 地址缺少 slug,结构:{$this->permalink}");
        }

        if ($postId === '' && str_contains($this->permalink, '%post_id%')) {
            throw new RouteException("生成 post 地址缺少 post_id,结构:{$this->permalink}");
        }

        // 独立页面不吃固定链接结构:地址恒为 `/自定义地址/`。
        // 页面没有年月与主分类,套文章结构必然生成打不开或语义错误的地址;
        // ?p= 查询式入口也已对页面关闸(见 index.php 单篇处理器)。空 slug 走原回退。
        if (($params['type'] ?? '') === 'page' && $slug !== '') {
            return '/' . $slug . '/';
        }

        $structure = $this->permalink;

        // 独立页面与未归类内容没有主分类:结构里的 %category% 整段省略,
        // 而不是留下 `//` 这种会 404 的空段。
        if ($category === '' && str_contains($structure, '%category%')) {
            $structure = str_replace(['/%category%', '%category%/'], '', $structure);
        }

        return strtr($structure, [
            '%postname%' => $slug,
            '%post_id%'  => $postId,
            '%category%' => $category,
            '%type%'     => (string) ($params['type'] ?? 'post'),
            '%year%'     => $year,
            '%month%'    => $month === '' ? '01' : $month,
            '%day%'      => $day === '' ? '01' : $day,
        ]);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function patternPath(string $name, array $params): string
    {
        $pattern = $this->paths[$name][0] ?? null;

        if ($pattern === null) {
            throw new RouteException("路由名未登记,无法生成: {$name}");
        }

        return (string) preg_replace_callback(
            '#\{([a-z_][a-z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}#',
            static function (array $m) use ($name, $params): string {
                $key = $m[1];

                if (!isset($params[$key])) {
                    throw new RouteException("生成 {$name} 缺少参数:{$key}");
                }

                // 逐段编码:斜杠要留着。`/admin/{path:.+}` 这类路径参数天生带斜杠
                // (posts/7/edit),整体 rawurlencode 会变成 posts%2F7%2Fedit —— 难看、
                // 且不少 WAF/代理会直接拦掉编码斜杠。普通 slug 不含斜杠,行为不变。
                return implode('/', array_map(
                    'rawurlencode',
                    explode('/', (string) $params[$key])
                ));
            },
            $pattern
        );
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        throw new RouteException('路由处理器必须返回 Response 或字符串');
    }
}
