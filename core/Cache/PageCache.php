<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cache;

use Molin\Support\Files;

/**
 * 页面静态缓存(《24-缓存抽象层规范》第 7 节,分层里的第 3 层"整页 HTML")。
 *
 * 只缓存 **GET + 未登录 + 200 + text/html** 的页面;后台 / 接口 / 登录注册 / 搜索等入口一律不缓存
 * (§7.2)。命中时由入口 index.php 直接吐 HTML、不再查内容与渲染。
 *
 * 两个落点:装配之后入口用 fastHit() 走常规命中;无本站 cookie 的 GET 则走 index.php 最前面的
 * 快速通道,同样是 fastHit() —— 在 Bootstrap::start() 之前就吐盘上的整页,省掉的是内核装配本身。
 * 快速通道读不到容器,所以落盘时把当时的安全头另存一份 sidecar,命中时原样回放。
 *
 * "未登录"只认会话里的 user_id(isLoggedIn()):访客会话(user_id=0)只是挂防跨站令牌的容器,
 * 不是身份。按"有没有会话 cookie"判会把几乎所有回访访客挡在缓存外 —— 文章页曾经给每个访客
 * 建会话,读一篇文章就 14 天不走缓存。
 *
 * 存 `storage/cache/pages/{hash}.html`,哈希取整条请求 URI(path + query)。查询串里的
 * 分享跟踪参数(utm_* / fbclid / gclid)不进键:同一页不该因为别人怎么转发而各存一份。
 * 其余查询参数照旧参与哈希,分页、`?p=1` 这类同路径不同页面不会互相串。
 * TTL 由 Bootstrap 从 `cache.ttl` 传入,看文件 mtime,过期即当未命中顺手删掉。
 *
 * 失效(§7.3 / §6.2)两档:purge() 整片清(列表/首页/归档会变的内容事件),
 * purgeUris() 按地址精确清(只有某一篇文章变了的评论事件)。
 */
final class PageCache
{
    /** 缓存文件后缀;purge 只删这个形状 */
    public const SUFFIX = '.html';

    /** 安全头 sidecar 后缀;与整页同生同灭,单独留着会在页删掉后越积越多 */
    public const HEADERS_SUFFIX = '.headers.json';

    /** 命中即直接返回的页面类型 */
    private const CACHEABLE_TYPE = 'text/html';

    /**
     * 不缓存的前台入口前缀(后台 / 接口 / 账号 / 搜索 / 评论回跳 / 口令解锁 / 非整页输出)(§7.2)。
     * 按路径段匹配,免得 `/feedback` 这种文章被 `/feed` 误伤。
     */
    private const EXCLUDED_PREFIXES = [
        '/admin',
        '/api',
        '/login',
        '/logout',
        '/register',
        '/lostpassword',
        '/resetpassword',
        '/search',
        '/comment',
        '/unlock',
        '/feed',
        '/sitemap.xml',
        '/robots.txt',
        '/notifications',
        '/list',
        '/install',
    ];

    /** 分享链接带上的跟踪参数,不影响页面内容 */
    private const TRACKING_PARAMS = ['fbclid', 'gclid'];

    /** 快速通道允许回放的头:只认安全头,Set-Cookie 之类的响应头一律不落盘 */
    private const REPLAY_HEADERS = [
        'X-Content-Type-Options',
        'X-Frame-Options',
        'Referrer-Policy',
        'Content-Security-Policy',
        'Strict-Transport-Security',
    ];

    public function __construct(
        private readonly string $directory,
        /** 整页存活秒数,由 Bootstrap 从 config.php 的 `cache.ttl` 传入;≤0 视作永不过期 */
        private readonly int $ttl = 3600
    ) {
    }

    /**
     * 这一趟算不算"真登录":只有会话里带 user_id 才算。
     *
     * 入口只拿得到 `Auth::id()`(未登录给 0),所以判据收在这里,好被测试钉住 ——
     * 光有 molin_session cookie 的匿名访客不算登录,页面照常可缓存、可命中。
     */
    public static function isLoggedIn(int $userId): bool
    {
        return $userId > 0;
    }

    /**
     * 尝试命中。命中返回整页 HTML;不命中(不该缓存 / 没存过 / 已过期)返回 null。
     */
    public function hit(string $method, string $uri, bool $loggedIn): ?string
    {
        $entry = $this->read($method, $uri, $loggedIn);

        return $entry === null ? null : $entry['html'];
    }

    /**
     * 快速通道命中:除正文外还带上 ETag 与落盘时的安全头,供装配之前的入口直接输出。
     * 判据与 hit() 相同,只是不接受"已登录"——这条路本来就只服务无会话的访客。
     *
     * @return array{html:string, etag:string, headers:array<string, string>}|null
     */
    public function fastHit(string $method, string $uri): ?array
    {
        return $this->read($method, $uri, false);
    }

    /**
     * 落盘。仅当该请求可缓存、状态 200、内容类型是 text/html 且正文非空;
     * 其它情况一律返回 false,调用方不必先自行判断。
     *
     * @param array<string, string> $headers 这一趟实际会发的响应头,只有安全头会被存进 sidecar
     */
    public function store(string $method, string $uri, bool $loggedIn, int $status, string $contentType, string $body, array $headers = []): bool
    {
        if (!$this->eligible($method, $uri, $loggedIn) || $status !== 200) {
            return false;
        }

        if (!str_starts_with(strtolower(trim($contentType)), self::CACHEABLE_TYPE) || trim($body) === '') {
            return false;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            return false;
        }

        $path = $this->path($uri);
        // 临时文件 + rename:读者要么看到旧整页、要么看到新整页,读不到半截
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $body) === false) {
            return false;
        }

        @chmod($temp, 0o644);

        if (!@rename($temp, $path)) {
            Files::delete($temp);

            return false;
        }

        $this->writeHeaders($path, $headers);

        return true;
    }

    /**
     * 这一趟请求该不该走页面缓存。抽成纯字符串/布尔判断,方便入口最前面在**没有内核**时也调用。
     */
    public function eligible(string $method, string $uri, bool $loggedIn): bool
    {
        if (strtoupper($method) !== 'GET' || $loggedIn) {
            return false;
        }

        $path = strtolower((string) (parse_url($uri, PHP_URL_PATH) ?? $uri));

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }

    /** 整片清掉页面缓存,返回删了几份(class 里没有 .html 时返回 0,不是错误) */
    public function purge(): int
    {
        $deleted = (new Purger($this->directory, self::SUFFIX))->purge();
        // sidecar 不在 .html 射程里,不清就会在整页删掉后一直留着
        (new Purger($this->directory, self::HEADERS_SUFFIX))->purge();

        return $deleted;
    }

    /**
     * 精确清掉若干条地址的整页缓存,返回删了几份。
     *
     * 地址必须与入口拿到的 REQUEST_URI 逐字一致(命中/落盘都按它哈希,差一个尾斜杠就是另一把钥匙),
     * 所以候选地址(尾斜杠两种写法、`?p=` 查询式、site_url 的子目录前缀)由调用方用 Router /
     * 配置生成后传进来 —— 只有那边知道固定链接结构。
     *
     * @param list<string> $uris
     */
    public function purgeUris(array $uris): int
    {
        $deleted = 0;

        foreach (array_unique($uris) as $uri) {
            // 本来就不进缓存的地址不去碰:排除前缀的判断与落盘同一口径
            if ($uri === '' || !$this->eligible('GET', $uri, false)) {
                continue;
            }

            $path = $this->path($uri);

            if (is_file($path) && Files::delete($path)) {
                $deleted++;
            }

            Files::delete($path . self::HEADERS_SUFFIX);
        }

        return $deleted;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * 后台显示用的读数。
     *
     * @return array{files:int, bytes:int}
     */
    public function stats(): array
    {
        if (!is_dir($this->directory)) {
            return ['files' => 0, 'bytes' => 0];
        }

        $entries = scandir($this->directory);
        $files = 0;
        $bytes = 0;

        foreach ($entries === false ? [] : $entries as $name) {
            if (!str_ends_with($name, self::SUFFIX)) {
                continue;
            }

            $path = $this->directory . '/' . $name;

            if (!is_file($path)) {
                continue;
            }

            $files++;
            $bytes += (int) @filesize($path);
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * If-None-Match 判定:支持列表、弱比较前缀与 `*`。
     * 304 只省带宽,不能因为客户端写法不同就把该省的流量漏掉。
     */
    public static function matchesEtag(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /** 由文件 mtime + size 生成:整页按 rename 原子替换,这两个值一起变就说明是另一份内容 */
    public static function etag(int $mtime, int $size): string
    {
        return '"' . dechex($mtime) . '-' . dechex($size) . '"';
    }

    /**
     * @return array{html:string, etag:string, headers:array<string, string>}|null
     */
    private function read(string $method, string $uri, bool $loggedIn): ?array
    {
        if (!$this->eligible($method, $uri, $loggedIn)) {
            return null;
        }

        $path = $this->path($uri);

        if (!is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);
        $size = @filesize($path);

        if ($mtime === false || $size === false) {
            return null;
        }

        if ($this->ttl > 0 && time() - $mtime > $this->ttl) {
            $this->forget($path);

            return null;
        }

        $html = @file_get_contents($path);

        if (!is_string($html) || $html === '') {
            return null;
        }

        return [
            'html' => $html,
            'etag' => self::etag($mtime, $size),
            'headers' => $this->readHeaders($path),
        ];
    }

    /**
     * 安全头 sidecar。读取时再过一遍白名单与换行检查:盘上的文件也不能盲信。
     *
     * @return array<string, string>
     */
    private function readHeaders(string $path): array
    {
        $raw = @file_get_contents($path . self::HEADERS_SUFFIX);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $headers = [];

        foreach (self::REPLAY_HEADERS as $name) {
            $value = $data[$name] ?? null;

            if (is_string($value) && $value !== '' && strpbrk($value, "\r\n") === false) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function writeHeaders(string $path, array $headers): void
    {
        $clean = [];

        foreach (self::REPLAY_HEADERS as $name) {
            $value = $headers[$name] ?? null;

            if (is_string($value) && $value !== '' && strpbrk($value, "\r\n") === false) {
                $clean[$name] = $value;
            }
        }

        $sidecar = $path . self::HEADERS_SUFFIX;

        // 没有要回放的头就把旧 sidecar 收掉,免得新页面配着上一版的安全头
        if ($clean === []) {
            Files::delete($sidecar);

            return;
        }

        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($json)) {
            return;
        }

        $temp = $sidecar . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $json) === false) {
            return;
        }

        @chmod($temp, 0o644);

        if (!@rename($temp, $sidecar)) {
            Files::delete($temp);

            return;
        }

        // rename 不会刷新目标路径的 stat 缓存:同一进程里紧接着 file_exists 会读到"还不存在"
        clearstatcache(true, $sidecar);
    }

    /** 整页与 sidecar 一起消失,免得留下没有对应页面的头文件 */
    private function forget(string $path): void
    {
        @unlink($path);
        @unlink($path . self::HEADERS_SUFFIX);
    }

    private function path(string $uri): string
    {
        return $this->directory . '/' . hash('sha256', self::cacheKey($uri)) . self::SUFFIX;
    }

    /**
     * 缓存键口径:整条 URI,但剔除分享链接的跟踪参数。
     * 不剔的话,同一页会被 utm_* 分叉成许多份,命中率被摊薄。
     *
     * 按 `&` 原样切,不走 parse_str —— 后者受 max_input_vars 截断,超长查询串会丢参数、
     * 把两个不同的地址算成同一把钥匙。
     */
    public static function cacheKey(string $uri): string
    {
        $queryAt = strpos($uri, '?');

        if ($queryAt === false) {
            return $uri;
        }

        $base = substr($uri, 0, $queryAt);
        $query = substr($uri, $queryAt + 1);

        if ($query === '') {
            return $base;
        }

        $kept = [];

        foreach (explode('&', $query) as $pair) {
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));

            if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING_PARAMS, true)) {
                continue;
            }

            $kept[] = $pair;
        }

        return $kept === [] ? $base : $base . '?' . implode('&', $kept);
    }
}
