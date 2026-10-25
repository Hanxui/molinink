<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 身份已确认但没有该能力 → 403。层级见《26》第 1 节。
 */
final class ForbiddenException extends AuthException
{
    private const CODE = 2002;

    public function __construct(string $message = '没有执行该操作的权限')
    {
        parent::__construct($message, self::CODE);
    }

    public function statusCode(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }
}
