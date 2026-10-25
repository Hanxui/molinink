<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cache\Psr16;

use Molin\Exception\MolinException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * 缓存键不合 PSR-16 规范时抛出。PSR-16 只给了接口,实现类得自己出。
 */
final class InvalidCacheKeyException extends MolinException implements PsrInvalidArgumentException
{
}
