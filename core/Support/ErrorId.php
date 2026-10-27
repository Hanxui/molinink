<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Support;

/**
 * 面向用户的错误编号,如 ERR-9F3A2C。
 * 它只用于"用户报号、开发者查日志",本身不含任何路径或异常信息。
 */
final class ErrorId
{
    public static function generate(): string
    {
        return 'ERR-' . strtoupper(bin2hex(random_bytes(3)));
    }

    public static function isValid(string $id): bool
    {
        return preg_match('#\AERR-[0-9A-F]{6}\z#', $id) === 1;
    }
}
