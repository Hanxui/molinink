<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Exception\ConfigException;
use Molin\Plugin\Capability;

/**
 * 能力名登记表(《32-用户与权限模型》第 2、4 节:新能力名先注册再用)。
 *
 * 注意这是**两套命名空间里的一套**:这里是"权限能力"(点分小写,如 post.edit.own),
 * 给 RBAC 用;《62-插件能力清单》那份是插件在 plugin.json 里的申报词表。
 * 两者格式同源(《32》第 2 节的域.动作),但用途与权威文档不同,别混用。
 */
final class Capabilities
{
    /** 《32》第 3 节矩阵里出现的内置能力(`media.delete.others` 增于 2026-10-01,`cache.manage` 同批) */
    private const BUILT_IN = [
        'read' => '阅读内容',
        'post.create' => '创建文章',
        'post.edit.own' => '编辑自己的文章',
        'post.edit.others' => '编辑他人的文章',
        'post.publish' => '发布文章',
        'post.delete.own' => '删除自己的文章',
        'post.delete.others' => '删除他人的文章',
        'page.manage' => '管理页面',
        'media.upload' => '上传媒体',
        'media.delete.own' => '删除自己的媒体',
        'media.delete.others' => '删除他人的媒体',
        'comment.moderate' => '审核评论',
        'category.manage' => '管理分类',
        'user.list' => '查看用户',
        'user.create' => '创建用户',
        'user.role.change' => '变更角色',
        'plugin.activate' => '启停插件',
        'theme.switch' => '切换主题',
        'setting.manage' => '修改设置',
        'update.core' => '升级程序',
        // 《62》第 51 行本就登记了它,《32》§3 漏了一格 —— 两处不一致以《62》为准(2026-10-01 作者定)
        'cache.manage' => '操作缓存',
    ];

    /** @var array<string, string> 能力名 => 说明 */
    private static array $registry = self::BUILT_IN;

    public static function reset(): void
    {
        self::$registry = self::BUILT_IN;
    }

    /**
     * 注册新能力(插件在 system/init 里调用)。已注册过的同名能力不报错也不覆盖 ——
     * 两个插件申报同一个能力是常见情况,谁后加载都不该改掉别人的说明。
     */
    public static function register(string $name, string $label = ''): void
    {
        if (!Capability::isValidName($name)) {
            throw new ConfigException("能力名「{$name}」不合域.动作的格式(点分小写)");
        }

        if (!isset(self::$registry[$name])) {
            self::$registry[$name] = $label === '' ? $name : $label;
        }
    }

    public static function isKnown(string $name): bool
    {
        return isset(self::$registry[$name]);
    }

    public static function label(string $name): string
    {
        return self::$registry[$name] ?? $name;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * 内置 19 项(《32》第 3 节矩阵)。
     *
     * @return list<string>
     */
    public static function builtIn(): array
    {
        return array_keys(self::BUILT_IN);
    }
}
