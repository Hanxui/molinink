<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Support\Performance;

use Molin\Cache\CacheManager;

/**
 * 请求耗时的采样落盘与读数(《57-性能预算》§2 的首页渲染口径、《111-后台站点健康》性能预算组)。
 *
 * 量的是**真实访问**的耗时,不是健康屏自己现测一次:采样点在 `ErrorHandler::shutdown()`
 * —— 它是全站唯一的请求计时器(《26》§9),每个走到收尾的请求都过这里。首页按"走没走页面静态缓存"
 * 分成两桶(《24》§7 的第 3 层),分别对《57》§2 的「缓存命中 < 50 ms」「缓存未命中 < 300 ms」;
 * 后台按 /admin 前缀归一类(后台列表页 < 500 ms)。
 *
 * 落盘位置与 Cron / 限速同一族:`storage/cache/data/perf-samples.json`,只存最近 WINDOW 份样本。
 * 为了不给前台添 IO,写入按 `interval` 秒节流(默认 30 秒最多一份):采样是给健康屏看的读数,
 * 不是逐请求埋点 —— 逐请求读写会让"测性能"本身变成性能问题,也踩《57》§2「单请求写入 storage ≤ 2」的红线。
 *
 * 绝不编数据:采到才报,采不到就让调用方如实说"尚未采到样本"。
 */
final class RequestSamples
{
    /** 首页、命中页面静态缓存的请求(≈《57》§2 的「首页渲染(缓存命中)」) */
    public const HOME_CACHED = 'home_cached';

    /** 首页、没命中页面静态缓存的请求(≈《57》§2 的「首页渲染(缓存未命中)」) */
    public const HOME_UNCACHED = 'home_uncached';

    /** 后台页面(GET /admin*);「列表页」无更细的识别口径,整类归此 */
    public const ADMIN = 'admin';

    /** 每桶保留的最近样本数(滚动窗口) */
    public const WINDOW = 40;

    /** 写入节流:同一份样本文件至少间隔这么多秒才写一次 */
    public const SAMPLE_INTERVAL = 30;

    private readonly string $file;

    private readonly string $pageDir;

    public function __construct(?string $root = null, private readonly int $interval = self::SAMPLE_INTERVAL)
    {
        $state = getenv('MOLIN_STATE_ROOT');
        $base = $root ?? (is_string($state) && $state !== '' ? $state : MOLIN_ROOT);
        $base = rtrim($base, '/\\');

        $this->file = $base . '/storage/cache/data/perf-samples.json';
        $this->pageDir = $base . '/storage/cache/pages';
    }

    /**
     * 本次请求该记进哪一桶;不在采样范围(非 GET、非首页、非后台)回 null。
     */
    public function kindOf(string $method, string $uri, float $startedAt): ?string
    {
        $kind = self::pathKind($method, $uri);

        if ($kind === null) {
            return null;
        }

        return $kind === 'home' ? $this->homeBucket($uri, $startedAt) : self::ADMIN;
    }

    /**
     * 只按方法 + 路径分类:GET `/` → `home`;GET `/admin*` → `admin`;其余不采(null)。
     * 纯函数,方便单测与阅读。
     */
    public static function pathKind(string $method, string $uri): ?string
    {
        if (strtoupper($method) !== 'GET') {
            return null;
        }

        $parsed = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : $uri;

        if ($path === '/') {
            return 'home';
        }

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return 'admin';
        }

        return null;
    }

    /**
     * 首页请求到底命没命中页面静态缓存。判据是**靠得住的现场证据**:页面缓存开着,
     * 且该 URL 的整页文件已在盘上、落盘时间早于本次请求开始 —— 那这一趟就是被旧整页直接吐出去的
     * (index.php 命中即 `echo` 并 return,不会重写文件)。没命中、或页面缓存关着,都算未命中。
     */
    public function homeBucket(string $uri, float $startedAt): string
    {
        if (!CacheManager::instance()->enabled()) {
            return self::HOME_UNCACHED;
        }

        $file = $this->pageDir . '/' . hash('sha256', $uri) . '.html';

        // 先清 stat 缓存:同一个请求里这一页刚被写过,旧 mtime 还挂在 PHP 的 stat 缓存上,
        // 不清就会把"本次自己写的"误判成"命中缓存"(Linux 上实测如此,Windows 上不显)。
        clearstatcache(true, $file);

        $mtime = @filemtime($file);

        return $mtime !== false && $mtime < (int) $startedAt ? self::HOME_CACHED : self::HOME_UNCACHED;
    }

    /**
     * 记一份样本(毫秒)。写不写、写哪一桶全在内部判定;不在采样范围就静静略过。
     */
    public function record(float $startedAt, float $ms, string $method, string $uri): void
    {
        $kind = $this->kindOf($method, $uri, $startedAt);

        if ($kind === null) {
            return;
        }

        $now = time();

        if ($this->interval > 0) {
            $mtime = @filemtime($this->file);

            if ($mtime !== false && $now - $mtime < $this->interval) {
                return;
            }
        }

        $all = $this->read();
        $bucket = $all[$kind] ?? null;
        $samples = self::floats(is_array($bucket) ? ($bucket['samples'] ?? null) : null);

        $samples[] = round($ms, 1);

        if (count($samples) > self::WINDOW) {
            $samples = array_slice($samples, -self::WINDOW);
        }

        $all[$kind] = ['at' => $now, 'samples' => array_values($samples)];

        $this->write($all);
    }

    /**
     * 采样入口给 ErrorHandler 用:从 SAPI 全局取本次请求的方法与 URI。
     * 采样绝不能把收尾流程带崩,所以这里整段兜底。
     */
    public function recordFromGlobals(float $startedAt, float $ms): void
    {
        $rawMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $rawUri = $_SERVER['REQUEST_URI'] ?? null;
        $method = is_string($rawMethod) ? $rawMethod : '';
        $uri = is_string($rawUri) ? $rawUri : '';

        if ($method === '' || $uri === '') {
            return;
        }

        try {
            $this->record($startedAt, $ms, $method, $uri);
        } catch (\Throwable) {
            // 采样失败不影响请求本身(理由同《26》§8:日志写不了也不能把页面拖没)
        }
    }

    /**
     * 某一桶的读数;一桶样本都没有时回 null,调用方据此如实说"尚未采到样本"。
     *
     * @return array{count:int, last:float, median:float, max:float, at:int}|null
     */
    public function stats(string $kind): ?array
    {
        $all = $this->read();
        $bucket = $all[$kind] ?? null;

        if (!is_array($bucket)) {
            return null;
        }

        $samples = self::floats($bucket['samples'] ?? null);

        if ($samples === []) {
            return null;
        }

        $last = $samples[count($samples) - 1];
        sort($samples);
        $count = count($samples);
        $at = $bucket['at'] ?? null;

        return [
            'count' => $count,
            'last' => $last,
            'median' => $samples[intdiv($count - 1, 2)],
            'max' => $samples[$count - 1],
            'at' => is_int($at) ? $at : 0,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<float>
     */
    private static function floats(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $value) {
            if (is_int($value) || is_float($value)) {
                $out[] = (float) $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $body = @file_get_contents($this->file);

        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $all
     */
    private function write(array $all): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode($all, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        // 临时文件 + rename:读者要么看到旧样本、要么看到新样本,读不到半截
        $temp = $this->file . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temp, $json) === false) {
            return;
        }

        @chmod($temp, 0o600);

        if (!@rename($temp, $this->file)) {
            @unlink($temp);
        }
    }
}
