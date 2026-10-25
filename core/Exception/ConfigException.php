<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 配置/环境错误。段位 5xxx(系统 / 环境),见《26》第 2 节。
 */
class ConfigException extends MolinException implements HttpMappable
{
    private const CODE = 5001;

    public function __construct(string $message = '配置或环境不正确')
    {
        parent::__construct($message, self::CODE);
    }

    public function statusCode(): int
    {
        return 500;
    }

    public function errorCode(): string
    {
        return 'config_error';
    }
}
