<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 赞/踩增加 voter_key：游客用匿名浏览器令牌投票，登录用户用 `u{id}`。
 *
 * 原先唯一键是 (post_id, user_id)，游客全挤在 user_id=0 上，只能算一票；
 * 换到 (post_id, voter_key) 后每个游客也能各自有一票，登录用户行为不变。
 */
final class AddRatingVoterKey20261020121000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(
            'ALTER TABLE ' . $p . 'post_ratings
                ADD COLUMN voter_key VARCHAR(64) NOT NULL DEFAULT \'\' AFTER user_id'
        );

        // 存量行都是登录用户投的，按 user_id 回填，避免唯一键冲突
        $this->execute(
            'UPDATE ' . $p . 'post_ratings SET voter_key = CONCAT(\'u\', user_id)'
        );

        $this->execute(
            'ALTER TABLE ' . $p . 'post_ratings
                DROP INDEX post_user,
                ADD UNIQUE KEY post_voter (post_id, voter_key)'
        );
    }

    public function down(): void
    {
        $p = $this->prefix();

        // (post_id, user_id) 容不下多个游客，回滚前把游客票清掉
        $this->execute('DELETE FROM ' . $p . 'post_ratings WHERE user_id = 0');

        $this->execute(
            'ALTER TABLE ' . $p . 'post_ratings
                DROP INDEX post_voter,
                ADD UNIQUE KEY post_user (post_id, user_id)'
        );

        $this->execute('ALTER TABLE ' . $p . 'post_ratings DROP COLUMN voter_key');
    }
}
