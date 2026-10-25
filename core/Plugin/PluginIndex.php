<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Throwable;

/**
 * 插件目录扫描结果缓存(storage/cache/plugins.index.php)。
 *
 * 每个请求都要知道 plugins/ 下有什么,但逐目录读 plugin.json、校验清单、检查入口与迁移文件
 * 只该在目录真的变了的时候做。指纹口径与 HookMap 一致:目录 + plugin.json 的 mtime/size,
 * 不看文件内容 —— 每请求算一次也不心疼。
 *
 * 缓存里存的是原始清单数据,重建时走 Manifest::fromArray(),校验一步不少。
 */
final class PluginIndex
{
    private const CACHE_KEY_FINGERPRINT = '_fingerprint';

    private const CACHE_KEY_AVAILABLE = 'available';

    private const CACHE_KEY_BROKEN = 'broken';

    private readonly string $cacheFile;

    /**
     * @param string      $pluginsRoot 插件根目录绝对路径
     * @param string|null $cacheFile   默认落在内核的 storage/cache 下;测试与多站点可另给
     */
    public function __construct(
        private readonly string $pluginsRoot,
        ?string $cacheFile = null
    ) {
        $this->cacheFile = $cacheFile ?? MOLIN_ROOT . '/storage/cache/plugins.index.php';
    }

    /**
     * @return array{available: array<string, Plugin>, broken: array<string, string>}
     */
    public function all(bool $force = false): array
    {
        $fingerprint = $this->fingerprint();

        if (!$force) {
            $cached = $this->readCache($fingerprint);

            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->rescan($fingerprint);
    }

    public function cacheFile(): string
    {
        return $this->cacheFile;
    }

    /**
     * @return array{available: array<string, Plugin>, broken: array<string, string>}
     */
    private function rescan(string $fingerprint): array
    {
        $available = [];
        $broken = [];
        $entries = [];

        foreach ($this->directories() as $dir) {
            try {
                $manifest = Manifest::load($dir);
                $available[$manifest->name()] = new Plugin($manifest);
                $entries[] = ['dir' => $dir, 'data' => $manifest->rawData()];
            } catch (Throwable $e) {
                $broken[basename($dir)] = $e->getMessage();
            }
        }

        ksort($available);
        ksort($broken);

        $this->writeCacheFile([
            self::CACHE_KEY_FINGERPRINT => $fingerprint,
            self::CACHE_KEY_AVAILABLE   => $entries,
            self::CACHE_KEY_BROKEN      => $broken,
        ]);

        return ['available' => $available, 'broken' => $broken];
    }

    /**
     * @return array{available: array<string, Plugin>, broken: array<string, string>}|null
     */
    private function readCache(string $fingerprint): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        try {
            $data = require $this->cacheFile;
        } catch (Throwable) {
            // 文件被写坏 / PHP 语法坏了:当没有缓存,重扫一份覆盖它
            return null;
        }

        if (!is_array($data) || ($data[self::CACHE_KEY_FINGERPRINT] ?? null) !== $fingerprint) {
            return null;
        }

        $entries = $data[self::CACHE_KEY_AVAILABLE] ?? null;
        $broken = $data[self::CACHE_KEY_BROKEN] ?? null;

        if (!is_array($entries) || !is_array($broken)) {
            return null;
        }

        $available = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['dir'] ?? null) || !is_array($entry['data'] ?? null)) {
                return null;
            }

            try {
                $manifest = Manifest::fromArray($entry['data'], $entry['dir']);
            } catch (Throwable) {
                // 清单没改、但入口/迁移文件被删了:缓存说不准,整份重扫,让它落进 broken
                return null;
            }

            $available[$manifest->name()] = new Plugin($manifest);
        }

        $clean = [];

        foreach ($broken as $dir => $reason) {
            if (!is_string($dir) || !is_string($reason)) {
                return null;
            }

            $clean[$dir] = $reason;
        }

        ksort($available);
        ksort($clean);

        return ['available' => $available, 'broken' => $clean];
    }

    /**
     * 插件集合指纹:目录 + plugin.json 的 mtime/size。
     *
     * 绝对路径也进指纹 —— 整站搬过家时 mtime/size 一个都不变,但缓存里的目录已经指空,
     * 不把它算进去就永远修不回来(与 HookMap 同一个理由)。
     */
    private function fingerprint(): string
    {
        $parts = [];

        foreach ($this->directories() as $dir) {
            $file = $dir . '/plugin.json';
            $exists = is_file($file);
            $mtime = $exists ? filemtime($file) : false;
            $size = $exists ? filesize($file) : false;

            $parts[] = sprintf(
                '%s|%s|%d|%d',
                basename($dir),
                str_replace('\\', '/', $dir),
                $mtime === false ? 0 : $mtime,
                $size === false ? 0 : $size
            );
        }

        sort($parts);

        return sha1(implode("\n", $parts));
    }

    /**
     * @return list<string>
     */
    private function directories(): array
    {
        $dirs = glob($this->pluginsRoot . '/*', GLOB_ONLYDIR);

        if ($dirs === false) {
            return [];
        }

        // 覆盖升级时旧目录会临时改名成 {name}.bak-{hex},删大目录可能要几秒;
        // 这期间它仍带着同名 plugin.json,扫进来会盖掉目录里已经落位的新版本
        $dirs = array_values(array_filter(
            $dirs,
            static fn (string $dir): bool => preg_match('/\.bak-[0-9a-f]{8}\z/', basename($dir)) !== 1
        ));

        sort($dirs);

        return $dirs;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCacheFile(array $data): void
    {
        $dir = dirname($this->cacheFile);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return;
        }

        $php = "<?php\n\n// 内核生成的插件扫描缓存,勿手改;目录或清单变化时由 PluginIndex::all() 重建\n\nreturn "
            . var_export($data, true) . ";\n";

        // 临时文件 + rename:并发请求要么读到旧版、要么读到完整新版,不会 require 到半截 PHP
        $temp = $this->cacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $php) !== false && @rename($temp, $this->cacheFile)) {
            clearstatcache(true, $this->cacheFile);
        } else {
            if (is_file($temp)) {
                @unlink($temp);
            }

            // rename 在个别平台/文件锁下会失败,退回直写:不能让缓存永远停在旧版本
            @file_put_contents($this->cacheFile, $php, LOCK_EX);
        }

        // 生产环境 opcache.validate_timestamps=0 时不显式失效,会一直端出旧索引
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }
    }
}
