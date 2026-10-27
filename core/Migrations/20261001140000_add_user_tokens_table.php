<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 「记住我」的独立登录令牌(用独立令牌、哈希存储,非明文密码)。
 *
 * 2026-10-01 作者点头补这张表 —— 既有规范的表清单原本没有它,
 * 已按同一份改定登记进既有规范。cookie 里给的是 selector:verifier,
 * 库里只存 selector 与 sha256(verifier):翻库翻不出可用的凭据,查行也不用比对明文。
 */
final class AddUserTokensTable20261001140000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(<<<SQL
CREATE TABLE `{$p}user_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `selector`   CHAR(12) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_selector` (`selector`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `' . $this->prefix() . 'user_tokens`');
    }
}
