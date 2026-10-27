<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Comment;

use Molin\Database\Database;

/**
 * 评论的存取(既有规范;表结构)。
 *
 * 只管"存进去、查出来、改状态";提交链的反垃圾与钩子在 Submitter。
 */
final class CommentRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 某篇文章的已通过评论(前台展示用),按创建序排;嵌套靠 parent_id,展示层压平成两级。
     *
     * @return list<array<string, mixed>>
     */
    public function approvedForPost(int $postId, string $order = 'asc'): array
    {
        $dir = strtolower($order) === 'desc' ? 'desc' : 'asc';

        $rows = $this->db->select(
            'SELECT c.id, c.parent_id, c.user_id, c.author_name, c.author_email, c.author_url, c.content,'
            . ' c.created_at, u.display_name FROM ' . $this->db->table('comments') . ' c'
            . ' LEFT JOIN ' . $this->db->table('users') . ' u ON u.id = c.user_id'
            . " WHERE c.post_id = :pid AND c.status = 'approved' ORDER BY c.created_at " . $dir . ', c.id ' . $dir,
            ['pid' => $postId]
        );

        return $this->renderContents($rows);
    }

    /**
     * 一批文章各自的「最近 N 条已通过评论」(既有规范`items[].comments_preview`)。
     *
     * 整页一次查询:窗口函数按 post_id 分区取前 N 条,不做逐篇查询(既有规范的 N+1 红线)。
     * 预览只给纯文本与作者名/头像 —— 卡片上是一行小字,不需要富文本形态。
     *
     * @param list<int> $postIds
     * @return array<int, list<array{id:int, author_name:string, avatar_url:string, content_text:string, created_at:string}>>
     */
    public function previewsFor(array $postIds, int $perPost = 3): array
    {
        $ids = [];

        foreach ($postIds as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $perPost = max(1, min(10, $perPost));

        if ($ids === []) {
            return [];
        }

        $marks = [];
        $params = [];

        foreach (array_values($ids) as $index => $id) {
            $marks[] = ':p' . $index;
            $params['p' . $index] = $id;
        }

        // 条数是这里算出来的整数(非请求输入),按列表查询的老口径直接拼进 SQL
        $rows = $this->db->select(
            'SELECT t.post_id, t.id, t.author_name, t.author_email, t.content, t.created_at FROM ('
            . 'SELECT c.id, c.post_id, c.author_name, c.author_email, c.content, c.created_at,'
            . ' ROW_NUMBER() OVER (PARTITION BY c.post_id ORDER BY c.created_at DESC, c.id DESC) AS rn'
            . ' FROM ' . $this->db->table('comments') . ' c'
            . ' WHERE c.post_id IN (' . implode(', ', $marks) . ") AND c.status = 'approved'"
            . ') t WHERE t.rn <= ' . $perPost
            . ' ORDER BY t.post_id ASC, t.created_at DESC, t.id DESC',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $email = (string) $row['author_email'];
            $out[(int) $row['post_id']][] = [
                'id' => (int) $row['id'],
                'author_name' => (string) $row['author_name'],
                'avatar_url' => $email === '' ? '' : avatar($email, 40),
                'content_text' => self::plainText((string) $row['content']),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /** 预览用的纯文本:去标签、收空白,截到 80 字 */
    private static function plainText(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
    }

    /**
     * 「仅你可见」的待审评论(pending 作者可见"待审")。
     * 登录者按 user_id 认;游客按会话里记过的提交 id 认 —— 会话是服务端数据,伪造不了别人的 id。
     *
     * @param list<int> $ids 当前会话提交过的评论 id
     * @return list<array<string, mixed>>
     */
    public function pendingVisibleTo(int $postId, int $userId, array $ids): array
    {
        $params = ['pid' => $postId];
        $or = [];

        foreach (array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)) as $index => $id) {
            $key = 'm' . $index;
            $or[] = 'c.id = :' . $key;
            $params[$key] = $id;
        }

        if ($userId > 0) {
            $or[] = 'c.user_id = :uid';
            $params['uid'] = $userId;
        }

        if ($or === []) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT c.id, c.parent_id, c.user_id, c.author_name, c.author_email, c.author_url, c.content,'
            . ' c.created_at, u.display_name FROM ' . $this->db->table('comments') . ' c'
            . ' LEFT JOIN ' . $this->db->table('users') . ' u ON u.id = c.user_id'
            . " WHERE c.post_id = :pid AND c.status = 'pending' AND (" . implode(' OR ', $or) . ')'
            . ' ORDER BY c.created_at ASC, c.id ASC',
            $params
        );

        return $this->renderContents($rows);
    }

    /**
     * 评论正文渲染钩子:入参是允许改写的 HTML 字符串,第二个参数是整行评论。
     *
     * 只在前台取数口(approvedForPost / pendingVisibleTo)跑:库里的原文不动,
     * 改动只落在喂给模板的这份副本上;后台列表与审核屏要的是原文,不该被展示钩子改写。
     *
     * 插件回吐非字符串时按"不改"处理 —— 一条坏监听不该把评论区变成 500。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function renderContents(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $html = (string) ($row['content'] ?? '');
            $before = apply_filters('comment/render/before', $html, $row);
            $html = is_string($before) ? $before : $html;
            $after = apply_filters('comment/render/after', $html, $row);
            $row['content'] = is_string($after) ? $after : $html;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * 全站评论列表(App 评论屏,既有规范)。状态口径与后台审核屏一致(pending/approved/spam/trash),
     * 带所属文章标题,新在前。默认不带状态 = 四态全给(App 首屏要"待审"计数,也得看见其余)。
     *
     * @param array{status?:string, post?:int} $filters
     * @return array{items:list<array<string, mixed>>, total:int}
     */
    public function browse(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $params = [];
        $status = (string) ($filters['status'] ?? '');

        if (in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }

        $post = (int) ($filters['post'] ?? 0);

        if ($post > 0) {
            $where[] = 'c.post_id = :post';
            $params['post'] = $post;
        }

        $whereSql = $where === [] ? 'WHERE 1' : 'WHERE ' . implode(' AND ', $where);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('comments') . ' c ' . $whereSql,
            $params
        )['n'] ?? 0);

        $rows = $this->db->select(
            'SELECT c.*, p.title AS post_title FROM ' . $this->db->table('comments') . ' c'
            . ' LEFT JOIN ' . $this->db->table('posts') . ' p ON p.id = c.post_id '
            . $whereSql . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $perPage
            . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * 只改正文(后台「编辑」)。状态、作者、归属一概不动 —— 那是审核动作的活。
     */
    public function updateContent(int $id, string $content): bool
    {
        return $this->db->update('comments', ['content' => $content], 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM ' . $this->db->table('comments') . ' WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->db->insert('comments', $data);
    }

    /**
     * 状态转移(pending/approved/spam/trash 之间由调用方把关,这里只认合法词)。
     */
    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            return false;
        }

        return $this->db->update('comments', ['status' => $status], 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * 真删一行 —— 只有回收站里的评论允许走到这里。
     */
    public function delete(int $id): bool
    {
        return $this->db->delete('comments', 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * 硬删一条评论:连同它的整棵回复树(子、孙及更深)与相关 meta 一并清掉 —— 不留浮空孤儿。
     * App 的「删除」与后台回收站的「永久删除」都走这条路。
     *
     * 逐层展开取后代(带已见集合,脏数据成环也不打转);树通常很小,不必上递归 CTE。
     *
     * @return int 删掉的评论条数(含整棵回复树)
     */
    public function deleteTree(int $id): int
    {
        return $this->db->transaction(function () use ($id): int {
            $ids = [];
            $frontier = [$id];

            while ($frontier !== []) {
                foreach ($frontier as $cid) {
                    $ids[$cid] = $cid;
                }

                $frontier = array_values(array_diff($this->childIds($frontier), $ids));
            }

            $removed = 0;

            foreach (array_chunk(array_values($ids), 500) as $chunk) {
                $in = [];
                $params = [];

                foreach ($chunk as $i => $cid) {
                    $in[] = ':c' . $i;
                    $params['c' . $i] = $cid;
                }

                $list = implode(',', $in);
                $this->db->delete('commentmeta', 'comment_id IN (' . $list . ')', $params);
                $removed += $this->db->delete('comments', 'id IN (' . $list . ')', $params);
            }

            return $removed;
        });
    }

    /**
     * @param list<int> $parents
     * @return list<int>
     */
    private function childIds(array $parents): array
    {
        $in = [];
        $params = [];

        foreach ($parents as $i => $pid) {
            $in[] = ':p' . $i;
            $params['p' . $i] = $pid;
        }

        $rows = $this->db->select(
            'SELECT id FROM ' . $this->db->table('comments') . ' WHERE parent_id IN (' . implode(',', $in) . ')',
            $params
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * 这些评论里,当前作者以行内回复方式回复过哪些(回复行的 parent_id 指向顶层评论)。
     * App 评论屏"未回复/已回复"的判定依据。
     *
     * @param list<int> $ids
     * @return list<int> 已回复过的评论 id
     */
    public function repliedBy(int $userId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($userId <= 0 || $ids === []) {
            return [];
        }

        $in = implode(',', array_map(static fn (int $i): string => ':r' . $i, array_keys($ids)));
        $params = ['uid' => $userId];
        foreach ($ids as $i => $id) {
            $params['r' . $i] = $id;
        }

        $rows = $this->db->select(
            'SELECT DISTINCT parent_id FROM ' . $this->db->table('comments')
            . " WHERE user_id = :uid AND parent_id IN ($in) AND status IN ('approved', 'pending')",
            $params
        );

        return array_map(static fn (array $r): int => (int) $r['parent_id'], $rows);
    }

    /**
     * @return array<string, int> status => 条数(全站,含回收站)
     */
    public function countsByStatus(): array
    {
        $counts = [];

        foreach ($this->db->select('SELECT status, COUNT(*) AS n FROM ' . $this->db->table('comments') . ' GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * 这个邮箱通过过几条 —— "首评必审,过审即免审"就看它。
     */
    public function approvedCountForEmail(string $email): int
    {
        if ($email === '') {
            return 0;
        }

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('comments')
            . " WHERE author_email = :e AND status = 'approved'",
            ['e' => $email]
        );

        return (int) ($row['n'] ?? 0);
    }

    /**
     * 这个 IP 在这篇文章上最近一次评论是什么时候(频率限制用),没有则 null。
     */
    public function lastAtForIp(int $postId, string $ip): ?int
    {
        $row = $this->db->selectOne(
            'SELECT created_at FROM ' . $this->db->table('comments')
            . ' WHERE post_id = :pid AND author_ip = :ip ORDER BY id DESC LIMIT 1',
            ['pid' => $postId, 'ip' => $ip]
        );

        if ($row === null) {
            return null;
        }

        $stamp = strtotime((string) $row['created_at'] . ' UTC');

        return $stamp === false ? null : $stamp;
    }

    /**
     * 这个 IP 最近一小时提了多少条(含未过审的 —— 灌水机不看审核结果)。
     */
    public function countByIpSince(string $ip, int $since): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('comments')
            . ' WHERE author_ip = :ip AND created_at >= :since',
            ['ip' => $ip, 'since' => gmdate('Y-m-d H:i:s', $since)]
        );

        return (int) ($row['n'] ?? 0);
    }

    /**
     * 把文章的 comment_count 校准成"已通过"的真实条数(既有规范⑧)。
     */
    public function recomputeCount(int $postId): void
    {
        $this->db->update('posts', [
            'comment_count' => $this->approvedCountForPost($postId),
        ], 'id = :id', ['id' => $postId]);
    }

    private function approvedCountForPost(int $postId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('comments')
            . " WHERE post_id = :pid AND status = 'approved'",
            ['pid' => $postId]
        );

        return (int) ($row['n'] ?? 0);
    }
}
