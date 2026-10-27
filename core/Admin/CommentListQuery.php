<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Database\Database;

/**
 * 评论列表查询:把筛选条件拼成一处,列表与计数共用同一份 WHERE。
 */
final class CommentListQuery
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private readonly Database $db,
        private readonly string $where,
        private readonly array $params
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(int $limit, int $page): array
    {
        return $this->db->select(
            'SELECT c.*, p.slug AS post_slug FROM ' . $this->db->table('comments') . ' c'
            . ' LEFT JOIN ' . $this->db->table('posts') . ' p ON p.id = c.post_id '
            . $this->where . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $limit
            . ' OFFSET ' . (($page - 1) * $limit),
            $this->params
        );
    }

    public function count(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('comments') . ' c ' . $this->where,
            $this->params
        );

        return (int) ($row['n'] ?? 0);
    }
}
