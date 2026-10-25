<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Exception\ConfigException;

/**
 * 内置角色与能力矩阵(《32-用户与权限模型》第 3 节)。
 *
 * 矩阵照文档逐格抄录,一格不多一格不少 —— 多给一格就是越权,少给一格后台就会莫名 403。
 * 插件可以 register_role()/grant_capability() 往上添,但不能改写内置角色的既有格子(默认拒绝)。
 */
final class Roles
{
    public const ADMINISTRATOR = 'administrator';
    public const EDITOR = 'editor';
    public const AUTHOR = 'author';
    public const CONTRIBUTOR = 'contributor';
    public const SUBSCRIBER = 'subscriber';

    /** 内置五角色的能力集(《32》第 3 节矩阵) */
    private const BUILT_IN = [
        self::ADMINISTRATOR => [
            'read', 'post.create', 'post.edit.own', 'post.edit.others', 'post.publish',
            'post.delete.own', 'post.delete.others', 'page.manage', 'media.upload',
            'media.delete.own', 'media.delete.others', 'comment.moderate', 'category.manage',
            'user.list', 'user.create', 'user.role.change', 'plugin.activate', 'theme.switch',
            'setting.manage', 'update.core', 'cache.manage',
        ],
        self::EDITOR => [
            'read', 'post.create', 'post.edit.own', 'post.edit.others', 'post.publish',
            'post.delete.own', 'post.delete.others', 'page.manage', 'media.upload',
            'media.delete.own', 'media.delete.others', 'comment.moderate', 'category.manage',
            'user.list',
        ],
        self::AUTHOR => [
            'read', 'post.create', 'post.edit.own', 'post.publish', 'post.delete.own',
            'media.upload', 'media.delete.own',
        ],
        self::CONTRIBUTOR => [
            'read', 'post.create', 'post.edit.own', 'media.upload', 'media.delete.own',
        ],
        self::SUBSCRIBER => ['read'],
    ];

    /** @var array<string, list<string>> */
    private static array $roles = self::BUILT_IN;

    /** @var array<string, string> 角色 => 显示名(界面规格《83》《105》《106》统一用「贡献者」) */
    private static array $labels = [
        self::ADMINISTRATOR => '管理员',
        self::EDITOR => '编辑',
        self::AUTHOR => '作者',
        self::CONTRIBUTOR => '贡献者',
        self::SUBSCRIBER => '订阅者',
    ];

    public static function reset(): void
    {
        self::$roles = self::BUILT_IN;
        self::$labels = [
            self::ADMINISTRATOR => '管理员',
            self::EDITOR => '编辑',
            self::AUTHOR => '作者',
            self::CONTRIBUTOR => '贡献者',
            self::SUBSCRIBER => '订阅者',
        ];
    }

    /**
     * 注册(或补充)一个角色。已存在的内置角色不许被整体覆盖 ——
     * 那等于让插件把编辑降权或把自己提到管理员。
     *
     * @param list<string> $capabilities
     */
    public static function register(string $role, string $label, array $capabilities): void
    {
        if (isset(self::BUILT_IN[$role])) {
            throw new ConfigException("内置角色「{$role}」不能被插件重新注册;要加能力请用 grant_capability()");
        }

        self::$roles[$role] = array_values(array_unique($capabilities));
        self::$labels[$role] = $label;
    }

    public static function grant(string $role, string $capability): void
    {
        if (!isset(self::$roles[$role])) {
            throw new ConfigException("没有这个角色: {$role}");
        }

        if (!Capabilities::isKnown($capability)) {
            throw new ConfigException("能力「{$capability}」未注册;先调用 register_capability()");
        }

        $caps = self::$roles[$role];
        $caps[] = $capability;

        self::$roles[$role] = array_values(array_unique($caps));
    }

    public static function exists(string $role): bool
    {
        return isset(self::$roles[$role]);
    }

    public static function label(string $role): string
    {
        return self::$labels[$role] ?? $role;
    }

    /**
     * @return list<string>
     */
    public static function capabilitiesOf(string $role): array
    {
        return self::$roles[$role] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::$roles);
    }

    /**
     * @return array<string, list<string>> 只读快照,给后台"角色总览"用
     */
    public static function matrix(): array
    {
        return self::$roles;
    }
}
