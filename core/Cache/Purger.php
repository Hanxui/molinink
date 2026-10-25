<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cache;

/**
 * 编译缓存清理(《24-缓存抽象层规范》第 6 节:清理动作要有,但业务代码不认具体适配器)。
 *
 * 按扩展名删:装配线给的是 Twig 编译产物目录(storage/cache/twig,删 `.php`),
 * 页面缓存(PageCache)另复用它删 `.html`。缓存适配器(CacheManager / FileStore)已落地
 * (2026-10-12 更正旧注释"缓存适配器还没落地");本类只管"按后缀清一个目录",
 * 别的一概不碰 —— `.gitkeep`、上传件、日志都不在射程内。
 */
final class Purger
{
    public function __construct(
        private readonly string $directory,
        private readonly string $suffix = '.php'
    ) {
    }

    /**
     * 删掉编译产物,返回删了几份。目录还没长出来 = 一份都没有,不是错误。
     */
    public function purge(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $deleted = 0;

        foreach ($this->files($this->directory) as $path) {
            if (str_ends_with($path, $this->suffix) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        $paths = [];

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;

            if (is_dir($path)) {
                $paths = array_merge($paths, $this->files($path));

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }
}
