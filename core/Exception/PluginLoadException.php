<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Exception;

/**
 * 插件加载期失败:入口文件读不到、依赖不满足、契约不兼容等。
 */
class PluginLoadException extends PluginException
{
}
