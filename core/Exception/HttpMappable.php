<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 能映射到 HTTP 状态的异常实现本接口,见《26-错误处理与日志规范》第 1 节。
 */
interface HttpMappable
{
    public function statusCode(): int;

    public function errorCode(): string;
}
