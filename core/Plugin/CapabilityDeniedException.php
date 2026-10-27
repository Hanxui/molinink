<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Exception\PluginException;

/**
 * 插件调用了自己未声明的能力。
 */
final class CapabilityDeniedException extends PluginException
{
    public function __construct(string $capability, ?string $plugin = null)
    {
        parent::__construct($plugin === null
            ? "插件未声明能力: {$capability}"
            : "插件 {$plugin} 未声明能力: {$capability}");
    }
}
