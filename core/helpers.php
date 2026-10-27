<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

use Molin\Content\FieldRegistry;
use Molin\Content\PostTypeRegistry;
use Molin\Content\TaxonomyRegistry;
use Molin\Hook\HookBus;
use Molin\Language\Translator;
use Molin\Plugin\Settings;
use Molin\Theme\ThemeSettings;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\Avatar;
use Molin\User\Capabilities;
use Molin\User\Roles;
use Molin\User\User;

/**
 * 钩子族全局函数 —— 面向插件作者的稳定 API,签名即契约。
 * 名字一旦发布不改,故只做最薄的一层包装。
 * 内容 / 用户 / 缓存等其余助手随各自模块落地。
 */
function add_filter(string $hook, callable $cb, int $priority = 10, int $acceptedArgs = 1): void
{
    HookBus::instance()->add($hook, $cb, $priority, $acceptedArgs);
}

function add_action(string $hook, callable $cb, int $priority = 10, int $acceptedArgs = 1): void
{
    HookBus::instance()->add($hook, $cb, $priority, $acceptedArgs);
}

function remove_filter(string $hook, callable $cb, int $priority = 10): bool
{
    return HookBus::instance()->remove($hook, $cb, $priority);
}

function remove_action(string $hook, callable $cb, int $priority = 10): bool
{
    return HookBus::instance()->remove($hook, $cb, $priority);
}

/**
 * filter 有入值,回调必须返回同类型值。
 */
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return HookBus::instance()->applyFilters($hook, $value, ...$args);
}

function do_action(string $hook, mixed ...$args): void
{
    HookBus::instance()->doAction($hook, ...$args);
}

function has_filter(string $hook, ?callable $cb = null): bool
{
    return HookBus::instance()->has($hook, $cb);
}

function has_action(string $hook, ?callable $cb = null): bool
{
    return HookBus::instance()->has($hook, $cb);
}

function did_action(string $hook): int
{
    return HookBus::instance()->did($hook);
}

function current_hook(): ?string
{
    return HookBus::instance()->current();
}

/**
 * 用户族全局函数。
 * 名字一旦发布不改,所以只做最薄的一层包装。
 */
function current_user(): ?User
{
    return Auth::user();
}

/**
 * 当前登录者是否有某项能力;带对象时还要过"是不是他的"这一关。
 */
function current_user_can(string $capability, ?object $target = null): bool
{
    return (new Authorizer())->can(Auth::user(), $capability, $target);
}

/**
 * @param list<string> $capabilities
 */
function register_role(string $role, string $label, array $capabilities): void
{
    Roles::register($role, $label, $capabilities);
}

function register_capability(string $name, string $label = ''): void
{
    Capabilities::register($name, $label);
}

function grant_capability(string $role, string $capability): void
{
    Roles::grant($role, $capability);
}

/**
 * 内容类型与分类法登记,插件在 system/init 里调用。
 *
 * 能力门卫:`content.type.register` / `taxonomy.register` 目前**没有运行时拦截** ——
 * 拦截点将来落在插件能力门卫层(既有规范与 HTTP / 文件 / DB 包装同一层),
 * 那里先校验清单声明、再放行到登记表。本批不在这一层伪造校验。
 *
 * @param array<string, mixed> $args ;前台已消费 label / public / has_archive / rewrite / taxonomies,
 *                                   其余参数(supports 等)仍由后续批次接管
 */
function register_post_type(string $type, array $args = []): void
{
    PostTypeRegistry::instance()->register($type, $args);
}

/**
 * @param list<string> $objectTypes 该分类法绑定的内容类型
 * @param array<string, mixed> $args
 */
function register_taxonomy(string $taxonomy, array $objectTypes = [], array $args = []): void
{
    TaxonomyRegistry::instance()->register($taxonomy, $objectTypes, $args);
}

/**
 * 自定义字段数据契约:type / single / default / show_in_rest。
 * 键名为 `{插件名}_{字段}`;必须带 default。两段式的第一段,先于 register_post_field(§3.3)。
 *
 * @param array<string, mixed> $meta
 */
function register_post_meta(string $type, string $key, array $meta): void
{
    FieldRegistry::instance()->registerMeta($type, $key, $meta);
}

/**
 * 自定义字段界面契约(既有规范):label / control / position / priority …
 * `control` 取自既有规范词表;词表外 → 拒绝装载。调用前该键须已有 register_post_meta。
 *
 * @param array<string, mixed> $ui
 */
function register_post_field(string $type, string $key, array $ui): void
{
    FieldRegistry::instance()->registerField($type, $key, $ui);
}

/**
 * 主题设置取值,回退链:用户值 → 主题 default → 兜底。
 * PHP 与 Twig 同名,插件与模板都走这一个口。
 */
function theme_setting(string $path, mixed $default = null): mixed
{
    return ThemeSettings::instance()->get($path, $default);
}

/**
 * 插件自己的配置取值。**只读** —— 写是插件后台页控制器里
 * `AdminPageContext::$settings` 的事。钩子、主题、前台要读插件配置就走这里。
 */
function plugin_setting(string $plugin, string $key, string $default = ''): string
{
    return Settings::for($plugin)->get($key, $default);
}

/**
 * 界面文字翻译(既有规范签名定死):第 2 参数永远是域,占位符实参从第 3 参起。
 */
function __(string $text, string $domain = 'molin', mixed ...$args): string
{
    return Translator::instance()->translate($text, $domain, ...$args);
}

/**
 * 翻译后直接输出,自动转义。
 */
function _e(string $text, string $domain = 'molin', mixed ...$args): void
{
    echo Translator::instance()->escape($text, $domain, ...$args);
}

/**
 * 本地化日期;intl 缺失时走内置模板。
 */
function format_date(mixed $value, string $style = 'long'): string
{
    return Translator::instance()->formatDate($value, $style);
}

/**
 * 库里时间一律按 UTC 存(gmdate),展示按站点时区。
 *
 * 模板与后台的 `|date` 都过这里:裸的 `Y-m-d H:i:s` 当作 UTC 解析再换到站点时区,
 * 否则服务器 PHP 默认时区一变,整站时间就整体偏几小时。
 */
function site_datetime(mixed $value, ?string $timezone = null): DateTimeImmutable
{
    $zone = new DateTimeZone($timezone ?? date_default_timezone_get());

    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value)->setTimezone($zone);
    }

    if (is_int($value) || (is_string($value) && preg_match('/^\d{9,}$/', $value) === 1)) {
        return (new DateTimeImmutable('@' . (int) $value))->setTimezone($zone);
    }

    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($zone);
    }

    return new DateTimeImmutable(is_string($value) && trim($value) !== '' ? $value : 'now', $zone);
}

/**
 * 本地化数字。
 */
function format_number(int|float $value, int $decimals = 0): string
{
    return Translator::instance()->formatNumber($value, $decimals);
}

/**
 * 头像地址:自定义附件 → Gravatar → 本地首字母 SVG。
 * 主题与后台模板同名同源,只给一个邮箱地址就能出图。
 */
function avatar(string $email, int $size = 48): string
{
    return Avatar::instance()->url($email, $size);
}
