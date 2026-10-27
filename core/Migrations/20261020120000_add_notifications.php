<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 站内通知。
 *
 * 接收人统一是文章作者（原版口径，回复也发给作者）；actor_id = 0 表示游客，
 * 展示时回落「匿名」。voter_key 让游客点赞也能精确撤回自己那条通知。
 */
final class AddNotifications20261020120000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 'notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                voter_key VARCHAR(64) NOT NULL DEFAULT \'\',
                type VARCHAR(20) NOT NULL DEFAULT \'comment\',
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(10) NOT NULL DEFAULT \'unread\',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY author_status (author_id, status, created_at),
                KEY author_created (author_id, created_at),
                KEY post_voter (post_id, voter_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . $this->collation()
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS ' . $this->prefix() . 'notifications');
    }
}
