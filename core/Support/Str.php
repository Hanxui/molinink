<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Support;

/**
 * 字符串工具。studly 是既有规范要求的插件名转换。
 */
final class Str
{
    /**
     * cdn-rewrite / init_core_schema → CdnRewrite / InitCoreSchema
     */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(preg_replace('#[^A-Za-z0-9]+#', ' ', $value) ?? ''));
    }
}
