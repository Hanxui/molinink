<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Notification;

/**
 * 站内通知的存取口。
 *
 * 2026-10-22（0.97.0）：实现搬到内置插件 molin-social，内核只留这张接口。
 * 写入口在 Notifier，读/改/删在控制器与 NotificationFeed。
 */
interface NotificationRepository
{
    public function unreadCount(int $authorId): int;

    /**
     * @return list<array<string, mixed>>
     */
    public function forAuthor(int $authorId, int $limit = 50): array;

    public function markRead(int $authorId, int $id): void;

    public function markAllRead(int $authorId): void;

    public function delete(int $authorId, int $id): void;

    public function deleteAll(int $authorId): void;

    public function purgeOlderThan(int $authorId, int $days = 30): void;
}
