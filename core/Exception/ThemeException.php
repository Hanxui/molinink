<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 主题相关错误:清单不合法、模板找不到、父主题成环。见《26》第 1 节。
 */
class ThemeException extends MolinException
{
}
