<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Container;

use Molin\Exception\MolinException;
use Psr\Container\ContainerExceptionInterface;

/**
 * 容器解析失败。基类归属见《26-错误处理与日志规范》第 1 节。
 */
class ContainerException extends MolinException implements ContainerExceptionInterface
{
}
