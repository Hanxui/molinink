<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli;

use Molin\Exception\AuthException;
use Molin\Exception\ConfigException;
use Molin\Exception\DatabaseException;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Throwable;

/**
 * 统一退出码(《58-CLI工具》第 4 节)。脚本靠它分支,所以映射必须稳定、可测。
 */
final class ExitCode
{
    public const OK = 0;
    public const GENERIC = 1;
    public const USAGE = 2;
    public const CONFIG = 3;
    public const DATABASE = 4;
    public const PERMISSION = 5;

    public static function fromException(Throwable $e): int
    {
        // 用法错误由 Console 自己判定,其余按内核异常层级归类
        if ($e instanceof ConsoleExceptionInterface) {
            return self::USAGE;
        }

        if ($e instanceof ConfigException) {
            return self::CONFIG;
        }

        if ($e instanceof DatabaseException) {
            return self::DATABASE;
        }

        if ($e instanceof AuthException) {
            return self::PERMISSION;
        }

        return self::GENERIC;
    }
}
