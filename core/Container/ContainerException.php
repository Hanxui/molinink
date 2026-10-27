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
 * 容器解析失败。基类归属。
 */
class ContainerException extends MolinException implements ContainerExceptionInterface
{
}
