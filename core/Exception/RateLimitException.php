<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 频率超限 → 429。归入 5xxx(系统 / 环境),见错误与日志设计第 2 节。
 */
final class RateLimitException extends MolinException implements HttpMappable
{
    private const CODE = 5002;

    public function __construct(string $message = '请求过于频繁,请稍后再试', private readonly int $retryAfter = 60)
    {
        parent::__construct($message, self::CODE);
    }

    public function statusCode(): int
    {
        return 429;
    }

    public function errorCode(): string
    {
        return 'rate_limited';
    }

    /**
     * API设计第 8 节要求 429 带 Retry-After。
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
