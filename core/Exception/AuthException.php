<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 认证/授权相关错误的基类。段位 2xxx,见《26》第 2 节。
 * 数字段位码直接用异常自带的 code,便于日志与 CLI 分支判断。
 */
class AuthException extends MolinException implements HttpMappable
{
    private const CODE = 2000;

    public function __construct(string $message = '需要登录后才能继续', int $code = self::CODE)
    {
        parent::__construct($message, $code);
    }

    public function statusCode(): int
    {
        return 401;
    }

    public function errorCode(): string
    {
        return 'auth_required';
    }
}
