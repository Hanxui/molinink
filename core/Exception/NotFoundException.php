<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 资源不存在 → 404。段位 3xxx(资源),见《26》第 2 节。
 */
final class NotFoundException extends MolinException implements HttpMappable
{
    private const CODE = 3001;

    public function __construct(string $message = '要找的东西不存在')
    {
        parent::__construct($message, self::CODE);
    }

    public function statusCode(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'not_found';
    }
}
