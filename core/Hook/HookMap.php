<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Hook;

use Molin\Plugin\Plugin;

/**
 * 懒加载倒排索引。
 *
 * 索引文件 storage/cache/hookmap.php 在插件启停/更新时重建;运行时一次 include。
 * 装几十个插件,首页也只加载命中钩子的那几个 —— 这是"轻"的地基。
 */
final class HookMap
{
    private const CACHE_KEY_PLUGINS = '_plugins';

    /** 缓存文件里存的上次指纹,判"插件集合变了没有"用(见 fingerprint()) */
    private const CACHE_KEY_FINGERPRINT = '_fingerprint';

    private static ?self $instance = null;

    /** @var array<string, list<string>> 钩子名 => 插件名 */
    private array $index = [];

    /** @var array<string, Plugin> 插件名 => 运行时对象 */
    private array $plugins = [];

    /** @var array<string, string> 插件名 => 入口路径(缓存里那份,兜底用) */
    private array $bootstraps = [];

    /** @var array<string, bool> 已加载过的插件,只加载一次 */
    private array $done = [];

    private bool $readFromCache = false;

    /** 缓存文件里那份指纹;没读过或文件里没有就是 null */
    private ?string $cachedFingerprint = null;

    private string $cacheFile;

    private function __construct()
    {
        $this->cacheFile = MOLIN_ROOT . '/storage/cache/hookmap.php';
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * 测试用:清空全部状态。
     */
    public static function reset(?string $cacheFile = null): void
    {
        self::$instance = null;

        if ($cacheFile !== null) {
            self::instance()->cacheFile = $cacheFile;
        }
    }

    public function cacheFile(): string
    {
        return $this->cacheFile;
    }

    /**
     * 用当前启用的插件重建索引并落盘。
     *
     * 每次请求都调(=Loader::boot),但**只在插件集合真的变了时**才写盘:
     * 指纹(启用的插件名 + 版本 + 入口路径 + plugin.json 的 mtime/size)与缓存文件里那份一致,
     * 就直接沿用缓存 —— 否则每个请求都要 var_export 一份索引再 file_put_contents,
     * 白烧磁盘与 CPU,还让 opcache 里那份索引每次请求都失效。
     *
     * 入口绝对路径也进指纹:整个站点目录搬过家时 mtime/size 一个都不变,
     * 但缓存里存的 bootstrap 路径已经指空 —— 不把它算进去就永远修不回来。
     *
     * @param list<Plugin> $plugins
     */
    public function rebuild(array $plugins): void
    {
        $fingerprint = self::fingerprint($plugins);

        if ($this->cacheIsFresh($fingerprint)) {
            // 索引与入口都已在 readCacheOnce() 里就位,这里只补运行期对象
            foreach ($plugins as $plugin) {
                $this->plugins[$plugin->name()] = $plugin;
            }

            return;
        }

        $universe = $this->knownHooks($plugins);
        $index = [];
        $bootstraps = [];

        foreach ($plugins as $plugin) {
            $name = $plugin->name();
            $this->plugins[$name] = $plugin;
            $bootstraps[$name] = $plugin->manifest->main();
            // 不声明 hooks = 只关心启动加载,因此挂在 system/init 上
            $declared = $plugin->manifest->hooks() === [] ? ['system/init'] : $plugin->manifest->hooks();

            foreach ($declared as $pattern) {
                foreach ($this->expand($pattern, $universe) as $hook) {
                    $index[$hook][] = $name;
                }
            }
        }

        foreach ($index as $hook => $names) {
            $names = array_values(array_unique($names));
            sort($names);
            $index[$hook] = $names;
        }

        ksort($index);
        ksort($bootstraps);

        $data = $index;
        $data[self::CACHE_KEY_PLUGINS] = $bootstraps;
        $data[self::CACHE_KEY_FINGERPRINT] = $fingerprint;

        $this->index = $index;
        $this->bootstraps = $bootstraps;
        $this->readFromCache = true;
        $this->cachedFingerprint = $fingerprint;

        $this->writeCacheFile($data);
    }

    /**
     * 命中钩子时才把相关插件拉起来。
     */
    public function loadFor(string $hook): void
    {
        $this->readCacheOnce();

        foreach ($this->index[$hook] ?? [] as $name) {
            if (isset($this->done[$name])) {
                continue;
            }

            $this->done[$name] = true;

            // 标记"正在加载哪个插件",让插件里的 add_filter 自动带上归属(既有规范currentPlugin)
            $bus = HookBus::instance();
            $bus->beginLoading($name);

            try {
                if (isset($this->plugins[$name])) {
                    $this->plugins[$name]->load();
                } elseif (isset($this->bootstraps[$name])) {
                    require_once $this->bootstraps[$name];
                }
            } finally {
                $bus->endLoading();
            }
        }
    }

    /**
     * 这个钩子上登记了哪些插件(测试与后台展示用)。
     *
     * @return list<string>
     */
    public function pluginsFor(string $hook): array
    {
        $this->readCacheOnce();

        return $this->index[$hook] ?? [];
    }

    /**
     * @param list<Plugin> $plugins
     * @return list<string>
     */
    private function knownHooks(array $plugins): array
    {
        $hooks = array_merge(array_keys(Registry::FROZEN), array_keys(Registry::CANDIDATE));

        foreach ($plugins as $plugin) {
            foreach ($plugin->manifest->hooks() as $pattern) {
                if (!str_contains($pattern, '*')) {
                    $hooks[] = $pattern;
                }
            }
        }

        $hooks = array_values(array_unique($hooks));
        sort($hooks);

        return $hooks;
    }

    /**
     * 通配展开(`*` 匹配任意多段,`shop/*` 表示整个 shop 域)。
     *
     * @param list<string> $universe
     * @return list<string>
     */
    private function expand(string $pattern, array $universe): array
    {
        if ($pattern === '*') {
            return $universe;
        }

        if (str_ends_with($pattern, '/*')) {
            $domain = substr($pattern, 0, -2);

            return array_values(array_filter(
                $universe,
                static fn (string $hook): bool => explode('/', $hook)[0] === $domain
            ));
        }

        return [$pattern];
    }

    private function readCacheOnce(): void
    {
        if ($this->readFromCache || !is_file($this->cacheFile)) {
            $this->readFromCache = true;

            return;
        }

        $data = require $this->cacheFile;
        $this->readFromCache = true;

        if (!is_array($data)) {
            return;
        }

        $bootstraps = $data[self::CACHE_KEY_PLUGINS] ?? [];
        $fingerprint = $data[self::CACHE_KEY_FINGERPRINT] ?? null;
        unset($data[self::CACHE_KEY_PLUGINS], $data[self::CACHE_KEY_FINGERPRINT]);

        $this->cachedFingerprint = is_string($fingerprint) ? $fingerprint : null;
        $this->bootstraps = is_array($bootstraps) ? array_map('strval', $bootstraps) : [];
        $this->index = [];

        foreach ($data as $hook => $names) {
            if (is_string($hook) && is_array($names)) {
                $this->index[$hook] = array_values(array_map('strval', $names));
            }
        }
    }

    /**
     * 缓存文件里那份指纹是否与当前插件集合一致。
     */
    private function cacheIsFresh(string $fingerprint): bool
    {
        $this->readCacheOnce();

        return $this->cachedFingerprint !== null && $this->cachedFingerprint === $fingerprint;
    }

    /**
     * 插件集合的指纹。只读 plugin.json 的元数据,不碰内容 —— 每请求算一次也不心疼。
     *
     * @param list<Plugin> $plugins
     */
    private static function fingerprint(array $plugins): string
    {
        // 词表也进指纹:内核升级新增了冻结钩子时,`shop/*` 这类通配声明的展开结果就变了,
        // 而插件清单一个字节都没动 —— 不把词表算进来,升级后新钩子永远进不了索引。
        $parts = ['universe:' . sha1(implode(',', array_merge(
            array_keys(Registry::FROZEN),
            array_keys(Registry::CANDIDATE)
        )))];

        foreach ($plugins as $plugin) {
            $manifestFile = $plugin->manifest->dir . '/plugin.json';
            $exists = is_file($manifestFile);
            $mtime = $exists ? filemtime($manifestFile) : false;
            $size = $exists ? filesize($manifestFile) : false;

            $parts[] = sprintf(
                '%s|%s|%s|%d|%d',
                $plugin->name(),
                $plugin->version(),
                $plugin->manifest->main(),
                $mtime === false ? 0 : $mtime,
                $size === false ? 0 : $size
            );
        }

        sort($parts);

        return sha1(implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCacheFile(array $data): void
    {
        $dir = dirname($this->cacheFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $php = "<?php\n\n// 内核生成的钩子倒排索引,勿手改;插件启停时由 HookMap::rebuild() 重建\n\nreturn "
            . var_export($data, true) . ";\n";

        // 临时文件同目录 + rename:并发请求里的 require 要么读到旧版、要么读到完整新版。
        // 直写期间别的请求正好 require,读到半截 PHP 就是致命错误 —— 那种白屏最难查。
        $temp = $this->cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $php) !== false && @rename($temp, $this->cacheFile)) {
            clearstatcache(true, $this->cacheFile);
        } else {
            if (is_file($temp)) {
                @unlink($temp);
            }

            // rename 在个别平台/文件锁下会失败,退回直写:宁可短暂有风险,也不能让索引停在旧版本
            file_put_contents($this->cacheFile, $php, LOCK_EX);
        }

        // 生产环境 opcache.validate_timestamps=0 时,不显式失效就会一直端出旧的倒排索引 ——
        // 插件启停看起来"生效了又没生效"。写法与 FileStore 一致。
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }
    }
}
