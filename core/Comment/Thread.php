<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Comment;

use Molin\Option\Options;
use Molin\User\Avatar;

/**
 * 一篇文章的评论树(既有规范嵌套 + §3 待审"作者可见")。
 *
 * 前台只认这一份装配:已通过的全部可见;待审的只给提交者自己(登录按 user_id、
 * 游客按会话里记过的 id)。展示层级与排序由选项定(comment_nest_depth / comment_order):
 * 默认拷成两级 —— 回复"回复"挂到同一层,手机上的缩进就不会一层层往右爬。
 */
final class Thread
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly Options $options,
        private readonly Avatar $avatar
    ) {
    }

    /**
     * @param list<int> $sessionIds 当前会话提交过的评论 id(游客的"仅你可见"凭据)
     * @return array{comments: list<array<string, mixed>>, total: int}
     */
    public function forPost(int $postId, int $userId, array $sessionIds): array
    {
        $order = $this->order();
        $rows = [];

        foreach ($this->comments->approvedForPost($postId, $order) as $row) {
            $row['awaiting'] = false;
            $rows[] = $row;
        }

        $pending = $this->comments->pendingVisibleTo($postId, $userId, $sessionIds);

        if ($order === 'desc') {
            // 待审查询固定升序;倒序展示时一并翻转,和已通过保持同一方向
            $pending = array_reverse($pending);
        }

        foreach ($pending as $row) {
            $row['awaiting'] = true;
            $rows[] = $row;
        }

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $depth = $this->nestDepth();
        $parentOf = [];

        foreach ($rows as $row) {
            $chain = $this->chain($row, $byId);
            // 超过层数上限的,压到上限那一层的祖先下 —— 默认 2 即把"回复的回复"并到顶层下面
            $level = min(count($chain), $depth);
            $parentOf[(int) $row['id']] = $level <= 1 ? 0 : $chain[$level - 2];
        }

        $nodes = [];
        $childrenOf = [];
        $topIds = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $row['initial'] = mb_substr((string) $row['author_name'], 0, 1);
            $row['avatar_url'] = $this->avatar->url((string) $row['author_email'], 72);
            $nodes[$id] = $row + ['children' => []];
            $parent = $parentOf[$id];

            if ($parent === 0) {
                $topIds[] = $id;
            } else {
                $childrenOf[$parent][] = $id;
            }
        }

        return [
            'comments' => array_values(array_map(fn (int $id): array => $this->assemble($id, $nodes, $childrenOf), $topIds)),
            'total' => count($rows),
        ];
    }

    /**
     * 这行评论在可见集合里的祖先链,从顶层到自身。父级对这位访客不可见就到此为止(它自己成为顶层)。
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $byId
     * @return list<int>
     */
    private function chain(array $row, array $byId): array
    {
        $chain = [(int) $row['id']];
        $parentId = (int) $row['parent_id'];

        while ($parentId > 0 && isset($byId[$parentId])) {
            array_unshift($chain, $parentId);
            $parentId = (int) $byId[$parentId]['parent_id'];
        }

        return $chain;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, list<int>> $childrenOf
     * @return array<string, mixed>
     */
    private function assemble(int $id, array $nodes, array $childrenOf): array
    {
        $node = $nodes[$id];
        $node['children'] = array_map(fn (int $child): array => $this->assemble($child, $nodes, $childrenOf), $childrenOf[$id] ?? []);

        return $node;
    }

    private function order(): string
    {
        return $this->options->get('comment_order', 'asc') === 'desc' ? 'desc' : 'asc';
    }

    private function nestDepth(): int
    {
        $depth = (int) $this->options->get('comment_nest_depth', '2');

        return in_array($depth, [1, 2, 3], true) ? $depth : 2;
    }
}
