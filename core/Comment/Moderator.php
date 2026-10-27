<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Comment;

use Molin\Logger\Audit;
use Molin\User\User;

/**
 * 评论审核状态转移的唯一入口。
 *
 * 后台列表/仪表盘/接口三处都从这里过,`comment/moderate/before|after` 两个钩子
 * 也只在这里触发 —— 两处各写一份,插件挂哪个入口都要想一遍,早晚分叉。
 */
final class Moderator
{
    public function __construct(private readonly CommentRepository $comments)
    {
    }

    public function apply(int $id, string $status, User $user): bool
    {
        $row = $this->comments->find($id);

        if ($row === null || $row['status'] === $status) {
            return false;
        }

        $status = apply_filters('comment/moderate/before', $status, $id);

        if (!in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            return false;
        }

        if (!$this->comments->setStatus($id, $status)) {
            return false;
        }

        $this->comments->recomputeCount((int) $row['post_id']);
        Audit::record('comment.status', 'comment:' . $id, true, null, 'user:' . $user->id);
        do_action('comment/moderate/after', $id, $status);

        return true;
    }
}
