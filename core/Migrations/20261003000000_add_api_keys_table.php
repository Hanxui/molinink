<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * API 密钥表(《25-接口规范》第 2 节的落地件)。
 *
 * 《25》只规定了用法(`Authorization: Bearer <key>` / `X-Api-Key`),发放路径没写 ——
 * 按"命令行是运维正门"(《58》开篇)以 `molin api:key:*` 为发放入口,本表随之登记进《21》。
 * 库里只存 sha256:翻库翻不出可用凭据,查行也不需要比对明文。
 */
final class AddApiKeysTable20261003000000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(<<<SQL
CREATE TABLE `{$p}api_keys` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL DEFAULT '',
  `key_hash`    CHAR(64) NOT NULL,
  `created_at`  DATETIME NOT NULL,
  `last_used_at` DATETIME NULL,
  `revoked_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key_hash` (`key_hash`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$this->collation()}
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `' . $this->prefix() . 'api_keys`');
    }
}
