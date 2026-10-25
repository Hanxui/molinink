<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * 请求的服务不存在。
 */
final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
