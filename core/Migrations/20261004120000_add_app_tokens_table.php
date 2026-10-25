<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * App 登录令牌表(《25》第 3.3 节的落地件)。
 *
 * App 没有浏览器那套 cookie/CSRF,靠 `Authorization: Bearer` 带一对不透明令牌:
 * access 短命(1 小时)、refresh 长命(默认 30 天);两者共用 series,登出或检出
 * refresh 被复用就按 series 连坐。库里只存 sha256,翻库翻不出可用凭据。
 */
final class AddAppTokensTable20261004120000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(<<<SQL
CREATE TABLE `{$p}app_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `series`       CHAR(32) NOT NULL,
  `kind`         VARCHAR(8) NOT NULL,
  `token_hash`   CHAR(64) NOT NULL,
  `device`       VARCHAR(120) NOT NULL DEFAULT '',
  `created_at`   DATETIME NOT NULL,
  `expires_at`   DATETIME NOT NULL,
  `last_used_at` DATETIME NULL,
  `revoked_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_series` (`series`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `' . $this->prefix() . 'app_tokens`');
    }
}
