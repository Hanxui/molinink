<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 作者页统计需要的两张关系表：关注关系 + 文章赞/踩。
 * 只加关系行，不在 users/posts 上冗余计数器；统计时聚合，避免两份数对不上。
 */
final class AddSocialStats20261018110000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 'user_follows (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                follower_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                following_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY follower_following (follower_id, following_id),
                KEY following_id (following_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . $this->collation()
        );

        $this->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 'post_ratings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                type VARCHAR(10) NOT NULL DEFAULT \'like\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY post_user (post_id, user_id),
                KEY post_type (post_id, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . $this->collation()
        );
    }

    public function down(): void
    {
        $p = $this->prefix();
        $this->execute('DROP TABLE IF EXISTS ' . $p . 'post_ratings');
        $this->execute('DROP TABLE IF EXISTS ' . $p . 'user_follows');
    }
}
