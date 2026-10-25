<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * `users` 表补一列 `updated_at`(《84-后台个人资料》§9:保存栏「上次保存于」取库里最后一次写入)。
 */
final class AddUsersUpdatedAt20261003120000 extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE `' . $this->prefix() . 'users`'
            . ' ADD COLUMN `updated_at` DATETIME NULL AFTER `registered_at`'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE `' . $this->prefix() . 'users` DROP COLUMN `updated_at`');
    }
}
