<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 查询索引补齐(W3 工包):posts / term_relationships / comments / postmeta 各一条。
 *
 * **本迁移不可回滚到数据层,只回滚索引。**
 * up() 为了让 postmeta 能加唯一键,先删掉了重复的 meta 行(每组 post_id + meta_key 只留 meta_id
 * 最大的那条)。那些行删掉就是删掉了,down() 造不回来 —— down() 只负责把四个索引摘掉,不动任何一行数据。
 *
 * 另:DDL 在 MySQL 里隐式提交,和上面那条 DELETE 不存在"整段一起回滚"的可能。所以
 * - 每条 DDL 单独一句,不合并成一条 ALTER 的多个动作(失败时更容易定位,也不会一半成功一半报错);
 * - 加/删之前先查 information_schema,存在就跳过 —— 中途失败后重跑不会撞 "Duplicate key name"。
 */
final class AddQueryIndexes20261022100000 extends AbstractMigration
{
    public function up(): void
    {
        // 1) posts:前台每一条查询都带 `deleted_at IS NULL`,而它不在任何复合索引里;
        //    (type, status, published_at) 走到 deleted_at 那一列就断档,只能回主键逐行判断。
        $this->ensureIndex('posts', 'idx_type_status_del_pub', '(`type`, `status`, `deleted_at`, `published_at`)');

        // 2) term_relationships:分类页 join 与 PostQuery::count() 的 COUNT(DISTINCT p.id) 只用
        //    term_taxonomy_id 定位,现有 idx_tt 不带 post_id,每命中一行都要回表拿主键。
        //    加 (term_taxonomy_id, post_id) 后这两条都成了覆盖扫描。
        //    (index 就绪后 idx_tt 只剩前缀冗余,清理留给后续批次,本迁移不动既有索引。)
        $this->ensureIndex('term_relationships', 'idx_tt_post', '(`term_taxonomy_id`, `post_id`)');

        // 3) comments:卡片评论预览 = post_id + status='approved' 按时间倒序取前 3 条。
        //    现有 idx_post_status 只到 (post_id, status),排序要回表再 filesort。
        $this->ensureIndex('comments', 'idx_post_status_created', '(`post_id`, `status`, `created_at`)');

        // 4) postmeta:MetaStore::set() 是"先 delete 再 insert",两个请求并发时两边都删空、都插入,
        //    同一个 (post_id, meta_key) 就留成两行;get()/all() 取到哪一行全看运气。
        //    唯一键从库层堵住这条路 —— 但前提是库里此刻没有重复行,否则 ALTER 直接失败(1062)。
        $this->dedupePostMeta();
        $this->ensureIndex('postmeta', 'uk_post_meta_key', '(`post_id`, `meta_key`)', true);
    }

    public function down(): void
    {
        // 只摘索引,不删数据:重复行在 up() 已经删掉,回滚只能回到"允许重复"的结构
        $this->dropIndex('postmeta', 'uk_post_meta_key');
        $this->dropIndex('comments', 'idx_post_status_created');
        $this->dropIndex('term_relationships', 'idx_tt_post');
        $this->dropIndex('posts', 'idx_type_status_del_pub');
    }

    /**
     * 建唯一键之前的去重:每组 (post_id, meta_key) 只留 meta_id 最大的一条。
     *
     * 为什么非要这一步:唯一索引不会"顺手帮你合并"重复行,只要库里有一条重复,ALTER 就整体失败,
     * 迁移卡在这里、站点停在半升级状态。所以清理必须显式做,并且做在加索引之前。
     *
     * 写法说明:子查询按 (post_id, meta_key) 聚合并带 HAVING,MySQL 8 会把派生表物化,
     * 因此 `DELETE pm FROM ... JOIN (SELECT ... FROM 同一张表)` 合法(不会撞 1093
     * "You can't specify target table")。join 条件 pm.meta_id < keep_id 即"除了最大的那条全删"。
     */
    private function dedupePostMeta(): void
    {
        // 唯一键已经在,说明此前已经清理过(或迁移整体重跑):不必再对整表做一次分组
        if ($this->hasIndex('postmeta', 'uk_post_meta_key')) {
            return;
        }

        $table = '`' . $this->db->table('postmeta') . '`';

        $this->execute(
            'DELETE pm FROM ' . $table . ' pm'
            . ' JOIN ('
            . ' SELECT post_id, meta_key, MAX(meta_id) AS keep_id'
            . ' FROM ' . $table
            . ' GROUP BY post_id, meta_key'
            . ' HAVING COUNT(*) > 1'
            . ' ) dup ON dup.post_id = pm.post_id AND dup.meta_key = pm.meta_key'
            . ' WHERE pm.meta_id < dup.keep_id'
        );
    }

    /**
     * 加索引;已存在就跳过(重跑安全,DDL 隐式提交后没有事务兜底)。
     *
     * @param string $columns 带括号的列清单,只由本文件给出,不接受外部输入
     */
    private function ensureIndex(string $table, string $index, string $columns, bool $unique = false): void
    {
        if ($this->hasIndex($table, $index)) {
            return;
        }

        $this->execute(
            'ALTER TABLE `' . $this->db->table($table) . '`'
            . ' ADD ' . ($unique ? 'UNIQUE ' : '') . 'INDEX `' . $index . '` ' . $columns
        );
    }

    private function dropIndex(string $table, string $index): void
    {
        if (!$this->hasIndex($table, $index)) {
            return;
        }

        $this->execute('ALTER TABLE `' . $this->db->table($table) . '` DROP INDEX `' . $index . '`');
    }

    /**
     * 索引是否已经在表上。表名取 db->table(),前缀可配置,不硬编码 molin_。
     */
    private function hasIndex(string $table, string $index): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index',
            ['table' => $this->db->table($table), 'index' => $index]
        );

        return $row !== null && (int) $row['n'] > 0;
    }
}
