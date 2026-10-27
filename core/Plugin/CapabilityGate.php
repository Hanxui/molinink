<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Hook\HookBus;
use Molin\Logger\Log;

/**
 * 插件能力门卫。
 *
 * 底层包装层(文件 / DB / HTTP / 设置 / 邮箱)放行前喊一声 assert():查此刻的调用方插件有没有
 * 在清单里声明过这项能力。调用方取自 HookBus::currentPlugin() —— 返回 null 表示内核自身在跑,
 * 一律放行:门卫只拦插件,不能反过来挡住内核自己的活。
 *
 * 未声明即拒绝:开发模式直接抛异常(当场暴露),生产模式先记 plugin 通道日志再抛,
 * 由调用方兜住 —— 一个插件越权不该把整站打崩。
 */
final class CapabilityGate
{
    /** 声明按插件名缓存:一次请求里同一插件只读一次清单 */
    /** @var array<string, list<string>> */
    private static array $declared = [];

    /** 插件根目录;生产走 MOLIN_ROOT/plugins */
    private static ?string $pluginsRoot = null;

    /**
     * 校验此刻的调用方插件是否声明了 $capability。
     */
    public static function assert(string $capability): void
    {
        $plugin = HookBus::instance()->currentPlugin();

        // 无插件上下文 = 内核自身在跑,门卫放行
        if ($plugin === null) {
            return;
        }

        // 词表名与插件自定义名(shop.payment)都算合法;两者都不沾的,连"声明过"都无从谈起
        if (!Capability::isKnown($capability) && !Capability::isValidName($capability)) {
            self::deny($plugin, $capability, "illegal capability name for plugin {$plugin}: {$capability}");
        }

        if (!in_array($capability, self::declared($plugin), true)) {
            self::deny($plugin, $capability, "plugin {$plugin} attempted {$capability} without capability");
        }
    }

    /**
     * 换插件根目录。生产不调用(默认 MOLIN_ROOT/plugins),留给用例指向临时夹具。
     */
    public static function setPluginsRoot(?string $root): void
    {
        self::$pluginsRoot = $root === null ? null : rtrim($root, '/\\');
        self::$declared = [];
    }

    /**
     * 拒绝:开发模式只抛(立刻暴露),生产模式先留痕再抛。
     */
    private static function deny(string $plugin, string $capability, string $log): void
    {
        if (!APP_DEBUG) {
            Log::channel('plugin')->warning($log, ['plugin' => $plugin, 'capability' => $capability]);
        }

        throw new CapabilityDeniedException($capability, $plugin);
    }

    /**
     * @return list<string>
     */
    private static function declared(string $plugin): array
    {
        return self::$declared[$plugin] ??= self::readDeclared($plugin);
    }

    /**
     * 插件启动时清单已校验过一遍,这里只取它声明的能力列表。
     *
     * @return list<string>
     */
    private static function readDeclared(string $plugin): array
    {
        $root = self::$pluginsRoot ?? (defined('MOLIN_ROOT') ? MOLIN_ROOT . '/plugins' : '');
        $dir = $root . '/' . $plugin;

        if ($root === '' || !is_file($dir . '/plugin.json')) {
            return [];
        }

        return Manifest::load($dir)->capabilities();
    }
}
