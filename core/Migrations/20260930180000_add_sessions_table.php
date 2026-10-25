<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 会话表(《21-数据模型》第 10 节 ⭕)。第 10 轮做登录才需要它,所以建表走迁移,
 * 与首次安装、内核升级同一条路(《29》第 6 节)。
 */
final class AddSessionsTable20260930180000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(<<<SQL
CREATE TABLE `{$p}sessions` (
  `id`         CHAR(64) NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `data`       MEDIUMTEXT NULL,
  `ip`         VARCHAR(45) NULL,
  `ua`         VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `' . $this->prefix() . 'sessions`');
    }
}
