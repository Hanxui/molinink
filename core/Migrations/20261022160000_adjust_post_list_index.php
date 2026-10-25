<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 列表索引微调:posts 的 idx_type_status_del_pub 换成 (type, status, published_at, deleted_at)。
 *
 * 前台列表的固定条件是 type = ? AND status = ? AND deleted_at IS NULL AND published_at <= now(),
 * 排序 published_at DESC。`IS NULL` 在 MySQL 里算范围条件 —— deleted_at 排在 published_at 前面时,
 * 等值段之后的排序段被它截断,每次列表都要把命中行取回来 filesort。把 published_at 提到 deleted_at
 * 之前,排序就能直接吃索引;deleted_at 退到最后只做过滤,代价远小于一次 filesort。
 *
 * DDL 在 MySQL 里隐式提交,没有事务可包。删和加因此合进同一条 ALTER:拆成两句时 ADD 一旦失败,
 * 索引已经没了,站点在重跑前一直全表扫。
 */
final class AdjustPostListIndex20261022160000 extends AbstractMigration
{
    private const TABLE = 'posts';

    private const INDEX = 'idx_type_status_del_pub';

    public function up(): void
    {
        $this->replace('(`type`, `status`, `published_at`, `deleted_at`)');
    }

    public function down(): void
    {
        $this->replace('(`type`, `status`, `deleted_at`, `published_at`)');
    }

    /**
     * 同名索引原地换列序。索引不存在(空库重跑 / 上一次已经回滚)时只补 ADD。
     */
    private function replace(string $columns): void
    {
        $table = '`' . $this->db->table(self::TABLE) . '`';

        if (!$this->hasIndex()) {
            $this->execute('ALTER TABLE ' . $table . ' ADD INDEX `' . self::INDEX . '` ' . $columns);

            return;
        }

        $this->execute(
            'ALTER TABLE ' . $table
            . ' DROP INDEX `' . self::INDEX . '`,'
            . ' ADD INDEX `' . self::INDEX . '` ' . $columns
        );
    }

    private function hasIndex(): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index',
            ['table' => $this->db->table(self::TABLE), 'index' => self::INDEX]
        );

        return $row !== null && (int) $row['n'] > 0;
    }
}
