<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

/**
 * 能力词表(《62-插件能力清单》是唯一权威来源,《12》第 6 节只是摘录)。
 *
 * 此处只承载词表本身;"未声明即拒绝"的门卫拦截点在《62》第 6 节,
 * 那要等内核门卫层(HTTP / 文件 / DB 包装)与插件清单校验落地后接入。
 */
final class Capability
{
    /** 能力名格式:点分小写;段内允许下划线(user.read_email),允许多段(content.type.register) */
    public const NAME_PATTERN = '#\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z#';

    /** 核心能力词表,共 32 项 */
    public const VOCABULARY = [
        // 内容
        'content.read',
        'content.write',
        'content.delete',
        'content.type.register',
        'taxonomy.register',
        'meta.read',
        'meta.write',
        // 用户
        'user.read',
        'user.read_email',
        'user.write',
        'user.role.change',
        'auth.hook',
        // 媒体
        'media.read',
        'media.upload',
        'media.delete',
        // 系统
        'setting.read',
        'setting.write',
        'cache.manage',
        'backup.manage',
        'crypto.keys',
        'cron.register',
        'log.write',
        'db.direct',
        'file.write',
        'http.outbound',
        'mail.send',
        'admin.menu',
        'rest.route',
        'cli.command',
        // 界面
        'admin.page',
        'admin.asset',
        'frontend.asset',
    ];

    /** 预定义保留名:插件不得自定义同名(《62》第 10 节) */
    public const RESERVED = ['db.direct', 'file.write', 'user.role.change', 'auth.hook', 'crypto.keys'];

    /** 旧名 => 规范名(《62》第 11 节),清单校验按此提示改法 */
    public const DEPRECATED = [
        'network'          => 'http.outbound',
        'filesystem.write' => 'file.write',
        'db.external'      => 'db.direct',
        'cron'             => 'cron.register',
        'upload'           => 'media.upload',
        'user.role.manage' => 'user.role.change',
    ];

    /**
     * 已从词表移除、无替代名的旧写法(《62》第 11 节):
     * filesystem.read 不设独立能力;eval / exec 在 PHP 层拦不住,声明它只会制造虚假安全感。
     */
    public const REVOKED = ['filesystem.read', 'eval', 'exec'];

    public static function isKnown(string $capability): bool
    {
        return in_array($capability, self::VOCABULARY, true);
    }

    public static function isReserved(string $capability): bool
    {
        return in_array($capability, self::RESERVED, true);
    }

    public static function isValidName(string $capability): bool
    {
        return preg_match(self::NAME_PATTERN, $capability) === 1;
    }
}
