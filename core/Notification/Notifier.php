<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Notification;

/**
 * 通知写入：评论 / 回复 / 赞 / 踩（《31》§4）。
 *
 * 2026-10-22（0.97.0）：实现搬到内置插件 molin-social，内核只留这张接口。
 * 接收人一律是文章作者（ZAXU 原版口径：回复也发给作者，不追到被回复的人）。
 */
interface Notifier
{
    public function comment(int $commentId, int $postId): void;

    public function rating(int $postId, int $actorId, string $type, string $voterKey): void;

    public function removeRating(int $postId, string $voterKey): void;
}
