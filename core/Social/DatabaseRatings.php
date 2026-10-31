<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Social;

use Molin\Database\Database;

/**
 * 赞 / 踩的内核实现：直接读写 `{p}post_ratings`。
 *
 * 2026-10-31 从插件 molin-social 搬回内核，代码未改（它 2026-10-22 才从这里搬出去）。
 *
 * 为什么搬回来：赞是内容主题的最低配，不该跟着「关注」一起被插件开关决定生死。
 * 现在装配层的取法是「插件在就用插件的，不在就用这一份」——
 * 装了插件行为与拆分前完全一致，没装插件赞也照常能用。
 *
 * 不冗余计数器：赞/踩数实时聚合，少一份可能对不上的缓存数。
 * 当前站点量级下 `GROUP BY type` 一条 SQL 足够。
 */
final class DatabaseRatings implements Ratings, VoterBatchRatings
{
    public function __construct(private readonly Database $db)
    {
    }

    public function ratingFor(int $postId, int $userId, string $voterKey = ''): array
    {
        $rows = $this->db->select(
            'SELECT type, COUNT(*) AS n FROM ' . $this->db->table('post_ratings') . ' WHERE post_id = :id GROUP BY type',
            ['id' => $postId]
        );

        $out = ['like' => 0, 'dislike' => 0, 'mine' => ''];

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');

            if ($type === 'like' || $type === 'dislike') {
                $out[$type] = (int) ($row['n'] ?? 0);
            }
        }

        $key = $voterKey !== '' ? $voterKey : ($userId > 0 ? 'u' . $userId : '');

        if ($key !== '') {
            $mine = $this->db->selectOne(
                'SELECT type FROM ' . $this->db->table('post_ratings') . ' WHERE post_id = :p AND voter_key = :k',
                ['p' => $postId, 'k' => $key]
            );

            $out['mine'] = (string) ($mine['type'] ?? '');
        }

        return $out;
    }

    public function rate(int $postId, int $userId, string $type, string $voterKey): array
    {
        if ($voterKey === '') {
            throw new \InvalidArgumentException('投票标识不能为空');
        }

        $current = $this->db->selectOne(
            'SELECT id, type FROM ' . $this->db->table('post_ratings') . ' WHERE post_id = :p AND voter_key = :k',
            ['p' => $postId, 'k' => $voterKey]
        );

        if ($current !== null && (string) $current['type'] === $type) {
            // 再点同一类就是取消
            $this->db->execute(
                'DELETE FROM ' . $this->db->table('post_ratings') . ' WHERE id = :id',
                ['id' => (int) $current['id']]
            );
        } elseif ($current !== null) {
            // 换边
            $this->db->execute(
                'UPDATE ' . $this->db->table('post_ratings') . ' SET type = :type, updated_at = :now WHERE id = :id',
                ['type' => $type, 'now' => gmdate('Y-m-d H:i:s'), 'id' => (int) $current['id']]
            );
        } else {
            $now = gmdate('Y-m-d H:i:s');

            $this->db->insert('post_ratings', [
                'post_id' => $postId,
                'user_id' => $userId,
                'voter_key' => $voterKey,
                'type' => $type,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->ratingFor($postId, $userId, $voterKey);
    }

    /**
     * 只有计数、没有「我投没投」—— 老调用方的口径,形状必须保持 `{like, dislike}` 两个键。
     *
     * @param list<int> $postIds
     * @return array<int, array{like:int, dislike:int}>
     */
    public function ratingsFor(array $postIds): array
    {
        return $this->tally($this->normalize($postIds));
    }

    /**
     * 计数 + 当前投票者自己那一票(2026-10-31 增,列表卡片画实心/描边心用)。
     *
     * 比 ratingsFor 只多一条查询:整页 `post_id IN (...)` 再加 `voter_key = :k`,
     * 不逐篇查。没有投票者(空串)时一条都不多查,行为与 ratingsFor 完全一样,
     * 只是每条仍带 `mine = ''` —— 模板不必做 defined 猜谜。
     *
     * @param list<int> $postIds
     * @return array<int, array{like:int, dislike:int, mine:string}>
     */
    public function ratingsForVoter(array $postIds, string $voterKey): array
    {
        $ids = $this->normalize($postIds);
        $out = [];

        // 一开始就把三个键凑齐:在场的每一条都必须有 mine,不能后补(形状固定,模板不猜)
        foreach ($this->tally($ids) as $postId => $counts) {
            $out[$postId] = ['like' => $counts['like'], 'dislike' => $counts['dislike'], 'mine' => ''];
        }

        if ($out === []) {
            return [];
        }

        if ($voterKey === '') {
            return $out;
        }

        [$in, $params] = $this->inMarks($ids);
        $rows = $this->db->select(
            'SELECT post_id, type FROM ' . $this->db->table('post_ratings')
            . ' WHERE post_id IN (' . $in . ') AND voter_key = :voter',
            $params + ['voter' => $voterKey]
        );

        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];
            $type = (string) ($row['type'] ?? '');

            // 能查到这一行,就说明它已经被上面的聚合算进 like/dislike 了(类型只可能是那两种)
            if (isset($out[$postId]) && ($type === 'like' || $type === 'dislike')) {
                $out[$postId]['mine'] = $type;
            }
        }

        return $out;
    }

    /**
     * 去重并把非正数剔掉:0 / 负数 / 重复值都不该进 IN 里。
     *
     * @param list<int> $postIds
     * @return list<int>
     */
    private function normalize(array $postIds): array
    {
        $ids = [];

        foreach ($postIds as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * 一条 `IN (...)` 的占位串与绑定参数。逐个命名占位,不做字符串拼接。
     *
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private function inMarks(array $ids): array
    {
        $marks = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $marks[] = ':p' . $index;
            $params['p' . $index] = $id;
        }

        return [implode(', ', $marks), $params];
    }

    /**
     * 一批文章各自的赞/踩计数。没有票的文章不出现,调用方按 0 兜底。
     *
     * @param list<int> $ids
     * @return array<int, array{like:int, dislike:int}>
     */
    private function tally(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$in, $params] = $this->inMarks($ids);

        $rows = $this->db->select(
            'SELECT post_id, type, COUNT(*) AS n FROM ' . $this->db->table('post_ratings')
            . ' WHERE post_id IN (' . $in . ')'
            . ' GROUP BY post_id, type',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');

            if ($type !== 'like' && $type !== 'dislike') {
                continue;
            }

            $postId = (int) $row['post_id'];
            $out[$postId] ??= ['like' => 0, 'dislike' => 0];
            $out[$postId][$type] = (int) ($row['n'] ?? 0);
        }

        return $out;
    }
}
