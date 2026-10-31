<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 按天访问计数(2026-10-31 增)。
 *
 * 站点的 `posts.view_count` 只能回答「某篇文章被看过几次」,回答不了「今天来了多少人」——
 * 后者要按天留痕,而旧站没有这个维度。这里补一张极简的日计数表:一天一行,不自增 id、
 * 不记 IP、不记 UA,只记当天命中数。主题的访问统计条(今日/昨日/近 7 天/近 30 天/总计)
 * 全部从这一张表聚合出来。
 *
 * 刻意不记明细行:访客数属于个人可识别信息,留痕越少越好;而统计条只需要计数,
 * 一行一天足够,数据量也是常数级(一年 365 行)。
 */
final class AddVisits20261031120000 extends AbstractMigration
{
    public function up(): void
    {
        $p = $this->prefix();

        $this->execute(
            'CREATE TABLE IF NOT EXISTS ' . $p . 'visits (
                day DATE NOT NULL,
                hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . $this->collation()
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS ' . $this->prefix() . 'visits');
    }
}
