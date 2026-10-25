<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Hook;

use Molin\Exception\MolinException;

/**
 * 钩子名不合规、或 filter 回调违反返回值约定时抛出。见《11》第 3.2、8 节。
 */
final class HookTypeException extends MolinException
{
}
