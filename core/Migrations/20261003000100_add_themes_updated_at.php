<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * `themes` 表补一列 `updated_at`(「上次保存」时间取库里最后一次写入)。
 */
final class AddThemesUpdatedAt20261003000100 extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE `' . $this->prefix() . 'themes`'
            . ' ADD COLUMN `updated_at` DATETIME NULL AFTER `settings`'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE `' . $this->prefix() . 'themes` DROP COLUMN `updated_at`');
    }
}
