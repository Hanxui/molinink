<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 身份未确认 → 401。层级。
 */
final class UnauthorizedException extends AuthException
{
    private const CODE = 2001;

    public function __construct(string $message = '身份未确认')
    {
        parent::__construct($message, self::CODE);
    }

    public function statusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'unauthorized';
    }
}
