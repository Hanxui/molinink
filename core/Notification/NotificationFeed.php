<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Notification;

use Molin\Router\Router;

/**
 * 通知列表的展示数据：把一行通知翻译成模板直接能用的字段。
 *
 * 文案留给 Twig 拼（模板已有 `__()`），这里只负责「谁、做了什么、点去哪」。
 */
final class NotificationFeed
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly Router $router
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forAuthor(int $authorId, int $limit = 50): array
    {
        $this->notifications->purgeOlderThan($authorId);

        $items = [];

        foreach ($this->notifications->forAuthor($authorId, $limit) as $row) {
            $item = $this->present($row);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function present(array $row): ?array
    {
        $type = (string) ($row['type'] ?? '');
        $postSlug = trim((string) ($row['post_slug'] ?? ''));

        // 文章删了就把这条藏起来（通知行留给 30 天清理）
        if ($postSlug === '') {
            return null;
        }

        $commentId = (int) ($row['comment_id'] ?? 0);
        $commentStatus = (string) ($row['comment_status'] ?? '');
        // 固定链接可能是 ?p=%post_id%、/年/月/日/%postname%/ 等；只给 slug 在查询式结构下会抛异常
        $postUrl = $this->router->path('post', [
            'slug' => $postSlug,
            'post_id' => (int) ($row['post_id'] ?? 0),
            'published_at' => (string) ($row['post_published_at'] ?? ''),
        ]);

        if ($type === 'comment' || $type === 'reply') {
            if ($commentId <= 0 || !isset($row['comment_content'])) {
                return null;
            }

            $actor = trim((string) ($row['comment_author_name'] ?? ''));
            $avatar = (string) ($row['comment_author_email'] ?? '');

            // 待审 / 垃圾 / 回收站的评论在前台看不到，点进文章等于什么都没发生；
            // 照原版 get_edit_comment_link 的口径，直接送到后台对应状态的评论列表。
            $url = in_array($commentStatus, ['pending', 'spam', 'trash'], true)
                ? $this->router->path('admin', ['path' => 'comments'])
                    . '?status=' . $commentStatus . '&post=' . (int) ($row['post_id'] ?? 0)
                : $postUrl . '#comment-' . $commentId;

            $parentExcerpt = $this->excerpt((string) ($row['parent_content'] ?? ''));
        } else {
            $actor = trim((string) ($row['actor_display_name'] ?? ''));
            if ($actor === '') {
                $actor = trim((string) ($row['actor_username'] ?? ''));
            }
            $avatar = (string) ($row['actor_email'] ?? '');
            $url = $postUrl;
            $parentExcerpt = '';
        }

        if ($actor === '') {
            $actor = '匿名';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'status' => (string) ($row['status'] ?? 'read'),
            'type' => $type,
            'actor' => $actor,
            'avatar' => $avatar === '' ? '' : avatar($avatar, 200),
            'post_title' => (string) ($row['post_title'] ?? ''),
            'url' => $url,
            'parent_excerpt' => $parentExcerpt,
            'status_label' => match ($commentStatus) {
                'pending' => '[待审核]',
                'spam' => '[垃圾]',
                'trash' => '[回收站]',
                default => '',
            },
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function excerpt(string $html): string
    {
        $text = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        return mb_strlen($text) > 70 ? mb_substr($text, 0, 70) . '...' : $text;
    }
}
