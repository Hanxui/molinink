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
 * 修订版本仓储(数据取自 post_revisions)。
 *
 * 表在首个内核迁移里就已建好(`core/Migrations/20260929000000_init_core_schema.php`),
 * 列:post_id / title / content / author_id / created_at。平日只读 ——
 * "何时留一版"属内容写入侧(PostWriter)的事;restore() 是比对屏「恢复」回写 posts 的唯一出口。
 */
final class RevisionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 某篇文章的全部修订,按时间正序(旧 → 新)。每行都存"改之前的那一版"——
     * 当前版本不在表里,是 posts 上正在生效的正文(见 PostWriter::snapshotRevision)。
     *
     * @return list<array{id:int, post_id:int, title:string, content:string, author_id:int, created_at:string, author:string}>
     */
    public function forPost(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT r.id, r.post_id, r.title, r.content, r.author_id, r.created_at, u.display_name AS author'
            . ' FROM ' . $this->db->table('post_revisions') . ' r'
            . ' LEFT JOIN ' . $this->db->table('users') . ' u ON u.id = r.author_id'
            . ' WHERE r.post_id = :post ORDER BY r.created_at ASC, r.id ASC',
            ['post' => $postId]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[] = $this->shape($row);
        }

        return $out;
    }

    /**
     * 取某篇下指定的一条修订;不属于这篇就返回 null(防串号)。
     *
     * @return array{id:int, post_id:int, title:string, content:string, author_id:int, created_at:string, author:string}|null
     */
    public function find(int $postId, int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT r.id, r.post_id, r.title, r.content, r.author_id, r.created_at, u.display_name AS author'
            . ' FROM ' . $this->db->table('post_revisions') . ' r'
            . ' LEFT JOIN ' . $this->db->table('users') . ' u ON u.id = r.author_id'
            . ' WHERE r.post_id = :post AND r.id = :id LIMIT 1',
            ['post' => $postId, 'id' => $id]
        );

        return $row === null ? null : $this->shape($row);
    }

    /**
     * 把一条修订写回 posts 并留一条新修订留痕(既有规范已由作者定为「真恢复」)。
     *
     * 修订存的是「改之前的那一版」(见 PostWriter::snapshotRevision),所以回写前先把当前正文档进
     * post_revisions 当留痕,再覆盖 title / content —— 恢复动作本身也进历史,可再回退。
     * author_id 不随恢复改动(回写内容不等于换作者)。
     */
    public function restore(int $postId, string $title, string $content, int $authorId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->db->transaction(function () use ($postId, $title, $content, $authorId, $now): void {
            $current = $this->db->selectOne(
                'SELECT title, content FROM ' . $this->db->table('posts') . ' WHERE id = :id LIMIT 1',
                ['id' => $postId]
            );

            // 留痕:恢复前的当前态存成一版,供日后回看 / 再回退
            $this->db->insert('post_revisions', [
                'post_id' => $postId,
                'title' => (string) ($current['title'] ?? ''),
                'content' => (string) ($current['content'] ?? ''),
                'author_id' => $authorId,
                'created_at' => $now,
            ]);

            $this->db->update(
                'posts',
                [
                    'title' => $title,
                    'content' => $content,
                    // 渲染缓存随本次改动作废,下次输出重新过一遍钩子(既有规范与 PostWriter 同一口径)
                    'content_filtered' => null,
                    'updated_at' => $now,
                ],
                'id = :id',
                ['id' => $postId]
            );
        });
    }

    public function countFor(int $postId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('post_revisions') . ' WHERE post_id = :post',
            ['post' => $postId]
        );

        return (int) ($row['n'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, post_id:int, title:string, content:string, author_id:int, created_at:string, author:string}
     */
    private function shape(array $row): array
    {
        $author = trim((string) ($row['author'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'post_id' => (int) ($row['post_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'author_id' => (int) ($row['author_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'author' => $author === '' ? __('佚名') : $author,
        ];
    }
}
