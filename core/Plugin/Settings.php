<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Exception\MolinException;
use Molin\Option\Options;

/**
 * 插件自己的配置读写(《12》§14)。
 *
 * 落在 `options` 表,键名统一前缀 `plugin.{插件名}.` —— 与站点设置同库不同名:插件碰不到
 * `site_url` 这类站内核设置,反过来也不会被别的插件覆盖。一律 `autoload = 0`,插件配置
 * 只有它自己那几屏要读,不该让每个请求都替它预载一遍。
 *
 * 值只收发字符串(与 options 表一致)。要存结构就自己 json_encode —— 这里不做类型戏法,
 * 免得 `get()` 回来的东西和 `set()` 进去的长得不一样。
 */
final class Settings
{
    private const NAME_PATTERN = '#\A[a-z][a-z0-9_-]{0,49}\z#';

    private const PREFIX = 'plugin.';

    private static ?Options $booted = null;

    private function __construct(
        private readonly Options $options,
        private readonly string $plugin
    ) {
    }

    /**
     * 启动时接上内核那一份 Options —— `plugin_setting()` 全局助手与插件后台页共用同一个口。
     */
    public static function boot(Options $options): void
    {
        self::$booted = $options;
    }

    /** 测试用:清掉全局引用,免得两条用例互相串味。 */
    public static function resetInstance(): void
    {
        self::$booted = null;
    }

    public static function for(string $plugin): self
    {
        if (self::$booted === null) {
            throw new MolinException('插件配置存储还没启动');
        }

        return new self(self::$booted, $plugin);
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->options->get($this->optionName($key));

        return is_scalar($value) ? (string) $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->options->set($this->optionName($key), $value, false);
    }

    /**
     * 键名收窄到小写字母数字与 `-_`:放宽就等于让插件用点号把前缀撑开,挤进别人的命名空间。
     */
    private function optionName(string $key): string
    {
        if (preg_match(self::NAME_PATTERN, $key) !== 1) {
            throw new MolinException(
                "插件 {$this->plugin} 的配置键不合规(小写字母开头,只含 a-z 0-9 _ -,最长 50):{$key}"
            );
        }

        return self::PREFIX . $this->plugin . '.' . $key;
    }
}
