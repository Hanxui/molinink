<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

/**
 * 已登记的插件(清单 + 是否已加载)。
 */
final class Plugin
{
    private bool $loaded = false;

    public function __construct(public readonly Manifest $manifest)
    {
    }

    public function name(): string
    {
        return $this->manifest->name();
    }

    public function version(): string
    {
        return $this->manifest->version();
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function markLoaded(): void
    {
        $this->loaded = true;
    }

    /**
     * 加载入口文件。require_once 保证同一个入口只进一次(即使被懒加载和启动各命中一次)。
     */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        require_once $this->manifest->main();
    }
}
