<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

use Molin\Database\Database;

/**
 * 文章自定义字段(《21》第 3 节 postmeta、《34》第 4 节)。
 *
 * 《57》第 5 节的红线:列表页要批量取 meta,不许一篇查一次。
 * 所以这里的主入口是 all()/many(),get() 只给单篇用。
 */
final class MetaStore
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string, string>
     */
    public function all(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT meta_key, meta_value FROM ' . $this->db->table('postmeta') . ' WHERE post_id = :id ORDER BY meta_key',
            ['id' => $postId]
        );

        $out = [];

        foreach ($rows as $row) {
            // 同键可多行(《21》第 3 节没有唯一键),这里取最后写入的那条,与 set() 的"整键替换"一致
            $out[(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
        }

        return $out;
    }

    /**
     * 批量取多篇:一条 SQL 拿完,列表页靠它避免 N+1。
     *
     * @param list<int> $postIds
     * @return array<int, array<string, string>>
     */
    public function many(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($postIds) as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params['id' . $index] = $id;
        }

        $rows = $this->db->select(
            'SELECT post_id, meta_key, meta_value FROM ' . $this->db->table('postmeta')
            . ' WHERE post_id IN (' . implode(', ', $placeholders) . ') ORDER BY post_id, meta_key',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['post_id']][(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
        }

        return $out;
    }

    public function get(int $postId, string $key): ?string
    {
        $row = $this->db->selectOne(
            'SELECT meta_value FROM ' . $this->db->table('postmeta')
            . ' WHERE post_id = :id AND meta_key = :key ORDER BY meta_id DESC LIMIT 1',
            ['id' => $postId, 'key' => $key]
        );

        return $row === null ? null : (string) ($row['meta_value'] ?? '');
    }

    /**
     * 整键替换:结构型值按《34》第 4 节存成 JSON 字符串。
     */
    public function set(int $postId, string $key, mixed $value): void
    {
        $encoded = is_array($value) || is_object($value)
            ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $value;

        $this->delete($postId, $key);
        $this->db->insert('postmeta', ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $encoded]);
    }

    public function delete(int $postId, string $key): void
    {
        $this->db->delete('postmeta', 'post_id = :id AND meta_key = :key', ['id' => $postId, 'key' => $key]);
    }
}
