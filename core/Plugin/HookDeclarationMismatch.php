<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Exception\PluginException;

/**
 * 插件实际注册的钩子与 plugin.json 的 hooks 声明不一致(既有规范R2)。
 *
 * 这是 PHP 插件最难查的一类 bug:网站不报错,只是功能"看起来没生效"。
 * 所以开发模式当场炸,生产记 WARNING。
 */
final class HookDeclarationMismatch extends PluginException
{
}
