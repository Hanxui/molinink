<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 启用内置插件 molin-social(社交与通知)。
 *
 * 0.97.0 起关注 / 赞踩 / 站内通知的实现从内核搬到 plugins/molin-social，
 * 内核只留接口与路由。升级站点的 plugins 表里还没有这一行，靠本迁移补上；
 * 已经手工启用过的站点按 ON DUPLICATE KEY 覆盖成同样的启用态，不重复插行。
 *
 * 只写启用状态，不碰插件目录与 options 启动缓存 —— Loader::boot() 下一趟会按表重建。
 */
final class EnableMolinSocial20261022200600 extends AbstractMigration
{
    private const SLUG = 'molin-social';

    private const VERSION = '1.0.0';

    public function up(): void
    {
        // 正式版安装包不随包 molin-social(2026-10-23 发布口径),目录不在就别写启用行,
        // 否则后台插件页会挂一条"目录不存在"的坏记录。装完市场里的插件再启用即可。
        if (!is_dir(MOLIN_ROOT . '/plugins/' . self::SLUG)) {
            return;
        }

        $this->db->execute(
            'INSERT INTO ' . $this->db->table('plugins') . ' (`slug`, `version`, `active`, `activated_at`)'
            . ' VALUES (:slug, :version, 1, NOW())'
            . ' ON DUPLICATE KEY UPDATE `version` = VALUES(`version`), `active` = 1, `activated_at` = NOW()',
            ['slug' => self::SLUG, 'version' => self::VERSION]
        );
    }

    /**
     * 回滚只关开关，不删行：插件的设置与历史启用时间还有用，重新启用时不该从零开始。
     */
    public function down(): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->table('plugins') . ' SET `active` = 0 WHERE `slug` = :slug',
            ['slug' => self::SLUG]
        );
    }
}
