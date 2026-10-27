<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Backup\Backup;
use Molin\Cache\CacheManager;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\StatusMachine;
use Molin\Database\Database;
use Molin\Kernel;
use Molin\Router\Router;
use Molin\Update\Feed;
use Molin\User\UserRepository;

/**
 * 仪表盘的读模型(既有规范)。
 *
 * 只读,不写:数一数、取最近几条、报四项健康。评论的审核动作在 AdminController 里,
 * 因为那是"改数据",与这一屏的取数分开放,免得读模型顺手带上写权限。
 */
final class Dashboard
{
    /** 双栏各取 5 与 3,再多就没进设计稿 */
    private const RECENT_POSTS = 5;

    private const RECENT_COMMENTS = 3;

    public function __construct(
        private readonly Database $db,
        private readonly PostRepository $posts,
        private readonly UserRepository $users,
        private readonly Router $router,
        // 「更新」页检查后留下清单,这里只读它的缓存(Feed::cachedStatus,不联网);没有就指路「更新」页
        private readonly ?Feed $feed = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(bool $canModerate, bool $canPurge): array
    {
        $counts = $this->commentCounts();
        $postCount = $this->posts->query()->type('post')->statuses(StatusMachine::CREATABLE)->count();
        $pending = $counts['pending'];

        return [
            'page_kind' => 'dashboard',
            // 三句导语(既有规范/§10.1;待审为零那句作者 2026-10-01 已点头)
            'lede' => $postCount === 0
                ? __('站点还是空的。先写下第一篇，其余会跟着长起来。')
                : ($pending > 0 ? __('站点此刻的样子。有新的评论等你过目，其余一切安好。') : __('站点此刻的样子。一切安好。')),
            'stats' => [
                ['num' => $postCount, 'lab' => __('文章')],
                ['num' => $counts['visible'], 'lab' => __('评论')],
                ['num' => $this->users->count(), 'lab' => __('用户')],
                ['num' => $pending, 'lab' => __('待审评论'), 'flag' => $pending > 0 ? __('需处理') : ''],
            ],
            'posts' => $this->recentPosts(),
            'comments' => $canModerate ? $this->recentComments() : [],
            'can_moderate' => $canModerate,
            'pending_text' => $pending > 0 ? __('还有 %s 条待审评论', 'molin', $pending) : '',
            'health' => $this->health(),
            'cards' => $this->cards(),
            'write_url' => $this->url('posts/new'),
            'posts_url' => $this->url('posts'),
            'comments_url' => $this->url('comments'),
            'tools_url' => $this->url('tools'),
            // 站点健康那张卡的「查看详情」要落到健康屏本身(2026-10-17 修:此前指向备份页,点进去是另一件事)
            'health_url' => $this->url('health'),
            'cache_url' => $canPurge ? $this->url('cache/clear') : '',
        ];
    }

    /**
     * 侧栏「评论」那颗徽标要同一个数(待审数量同时驱动徽标),
     * 所以别在屏里再算一遍,出口只留这一个。
     */
    public function pendingCount(): int
    {
        return $this->commentCounts()['pending'];
    }

    /**
     * @return array{visible:int, pending:int}
     */
    private function commentCounts(): array
    {
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS total FROM ' . $this->db->table('comments') . ' GROUP BY status'
        );

        $visible = 0;
        $pending = 0;

        foreach ($rows as $row) {
            $total = (int) $row['total'];

            if ((string) $row['status'] === 'pending') {
                $pending = $total;
            }

            // 回收站与垃圾不进概况:那是要清的,不是站点的样子
            if (!in_array((string) $row['status'], ['pending', 'spam', 'trash'], true)) {
                $visible += $total;
            }
        }

        return ['visible' => $visible + $pending, 'pending' => $pending];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPosts(): array
    {
        $posts = $this->posts->query()
            ->type('post')
            ->statuses(StatusMachine::CREATABLE)
            // 按最后动过的时间排:草稿没有 published_at,按它排会被挤到末尾,
            // 与设计稿"草稿排在中间那一档"对不上
            ->orderBy('updated_at')
            ->limit(self::RECENT_POSTS)
            ->get();

        $tallies = $this->commentTallies($posts);
        $rows = [];

        foreach ($posts as $post) {
            $status = $this->statusLabel($post->status);

            $rows[] = [
                'title' => $post->title === '' ? __('(无标题)') : $post->title,
                'edit_url' => $this->url('posts/' . $post->id . '/edit'),
                'date' => self::stamp((string) ($post->published_at ?? $post->updated_at)),
                'status' => $status['label'],
                'is_draft' => $status['draft'],
                'comments' => $tallies[$post->id] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * 一次查完,不按行查。
     *
     * @param list<Post> $posts
     *
     * @return array<int, int> 文章 id => 可见评论数
     */
    private function commentTallies(array $posts): array
    {
        $ids = [];

        foreach ($posts as $post) {
            $ids[] = $post->id;
        }

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT post_id, COUNT(*) AS total FROM ' . $this->db->table('comments')
            . ' WHERE post_id IN (' . implode(',', array_map('intval', $ids)) . ") AND status = 'approved' GROUP BY post_id"
        );

        $tallies = [];

        foreach ($rows as $row) {
            $tallies[(int) $row['post_id']] = (int) $row['total'];
        }

        return $tallies;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentComments(): array
    {
        $rows = $this->db->select(
            'SELECT c.id, c.user_id, c.author_name, c.author_email, c.content, c.status, c.created_at,'
            . ' u.display_name FROM ' . $this->db->table('comments') . ' c'
            . ' LEFT JOIN ' . $this->db->table('users') . ' u ON u.id = c.user_id'
            . " WHERE c.status <> 'trash' ORDER BY c.created_at DESC, c.id DESC LIMIT " . self::RECENT_COMMENTS
        );

        $comments = [];

        foreach ($rows as $row) {
            $who = trim((string) ($row['display_name'] ?? '')) !== ''
                ? (string) $row['display_name']
                : (string) $row['author_name'];
            $status = (string) $row['status'];
            $body = $this->excerpt((string) $row['content']);

            $comments[] = [
                'id' => (int) $row['id'],
                'email' => trim((string) ($row['author_email'] ?? '')),
                'initial' => $who === '' ? '?' : mb_substr($who, 0, 1),
                'who' => $who === '' ? __('访客') : $who,
                'when' => self::ago(self::ts((string) $row['created_at'])),
                'body' => $body,
                'pending' => $status === 'pending',
                'approve_url' => $this->url('comments/' . (int) $row['id'] . '/approve'),
                'spam_url' => $this->url('comments/' . (int) $row['id'] . '/spam'),
            ];
        }

        return $comments;
    }

    /**
     * @return list<array{k:string, v:string, s:string, warn:bool}>
     */
    private function health(): array
    {
        $backupAt = (new Backup($this->db, MOLIN_ROOT))->latest();
        $update = $this->updateStatus();

        return [
            [
                'k' => __('程序版本'),
                'v' => 'v' . Kernel::VERSION,
                // 既有规范的升级检查已落在后台「更新」页;这里只读那份检查留下的已验签清单,
                // 不联网、不写 —— 仪表盘是取数屏。有新版据实报,没查过就指路「更新」页。
                's' => $update['text'],
                'warn' => $update['warn'],
            ],
            [
                'k' => __('运行环境'),
                'v' => 'PHP ' . PHP_VERSION,
                's' => version_compare(PHP_VERSION, '8.2.0', '>=') ? __('符合要求') : __('低于程序要求的 8.2'),
                'warn' => version_compare(PHP_VERSION, '8.2.0', '<') ? true : false,
            ],
            [
                'k' => __('最近备份'),
                'v' => $backupAt === null ? __('尚无备份') : date('Y-m-d H:i', $backupAt),
                's' => $backupAt === null ? __('安装后还没备份') : __('备份可用'),
                'warn' => $backupAt === null,
            ],
            [
                'k' => __('缓存'),
                // 既有规范的适配器与页面缓存已接线:显示实际生效的后端,不再写"已启用"这种空话
                'v' => $this->cacheLabel(),
                's' => $this->cacheNote(),
                'warn' => false,
            ],
        ];
    }

    /** 缓存实际生效的后端(未接装配线时如实说"仅编译缓存") */
    private function cacheLabel(): string
    {
        $manager = CacheManager::instance();

        if (!$manager->managed()) {
            return __('仅编译缓存');
        }

        return self::backendLabel($manager->backend());
    }

    /** 缓存状态行:降级要明说,接了就说已启用 */
    private function cacheNote(): string
    {
        $manager = CacheManager::instance();

        if (!$manager->managed()) {
            return __('数据缓存未接');
        }

        return $manager->degraded() ? __('缓存后端不可用，已降级为文件后端') : __('数据缓存已启用');
    }

    private static function backendLabel(string $backend): string
    {
        return match ($backend) {
            'file' => __('文件'),
            'yac' => __('YAC'),
            'redis' => __('Redis'),
            default => __('已关闭'),
        };
    }

    /**
     * 「程序版本」那行的更新状态:复用「更新」页检查后落下的清单缓存(Feed::cachedStatus)。
     * 没接上 Feed(测试自造)或还没检查过时,指路「更新」页 —— 不谎称"已是最新"。
     *
     * @return array{text:string, warn:bool}
     */
    private function updateStatus(): array
    {
        $status = $this->feed?->cachedStatus(Kernel::VERSION) ?? ['state' => 'unchecked', 'version' => ''];

        return match ($status['state']) {
            'available' => ['text' => __('可更新到 %s', 'molin', $status['version']), 'warn' => true],
            'latest' => ['text' => __('已是最新'), 'warn' => false],
            default => ['text' => __('去「更新」页检查'), 'warn' => false],
        };
    }

    /**
     * @return array{label:string, draft:bool}
     */
    private function statusLabel(string $status): array
    {
        return match ($status) {
            StatusMachine::PUBLISH => ['label' => __('已发布'), 'draft' => false],
            StatusMachine::PRIVATE => ['label' => __('私密'), 'draft' => false],
            StatusMachine::PENDING => ['label' => __('待审'), 'draft' => true],
            default => ['label' => __('草稿'), 'draft' => true],
        };
    }

    private function excerpt(string $content): string
    {
        $text = trim(preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ) ?? '');

        if (function_exists('mb_substr') && mb_strlen($text) > 60) {
            return mb_substr($text, 0, 60) . '…';
        }

        return $text;
    }

    /**
     * 插件卡片(既有规范admin/dashboard/collect):每张 {title: string, html: string}。
     * 钩子层的类型校验只在开发环境跑,生产环境靠这道归一 —— 形状不合的丢,别把首页打崩。
     *
     * @return list<array{title:string, html:string}>
     */
    private function cards(): array
    {
        $out = [];

        foreach ((array) apply_filters('admin/dashboard/collect', []) as $card) {
            if (!is_array($card)) {
                continue;
            }

            $title = $card['title'] ?? null;
            $html = $card['html'] ?? null;

            if (!is_string($title) || !is_string($html) || $title === '') {
                continue;
            }

            $out[] = ['title' => $title, 'html' => $html];
        }

        return $out;
    }

    private function url(string $path): string
    {
        return $this->router->path('admin', ['path' => $path]);
    }

    private static function stamp(string $databaseTime): string
    {
        $parsed = self::ts($databaseTime);

        return $parsed === 0 ? '' : date('Y-m-d', $parsed);
    }

    /**
     * 库存的是 UTC(既有规范通用约定),不写时区就会被按本地时间读一遍,差一个时区。
     */
    private static function ts(string $databaseTime): int
    {
        $parsed = strtotime($databaseTime . ' UTC');

        return $parsed === false ? 0 : $parsed;
    }

    private static function ago(int $timestamp): string
    {
        if ($timestamp === 0) {
            return '';
        }

        $seconds = time() - $timestamp;

        if ($seconds < 60) {
            return __('刚刚');
        }

        if ($seconds < 3600) {
            return __('%s 分钟前', 'molin', intdiv($seconds, 60));
        }

        if ($seconds < 86400) {
            return __('%s 小时前', 'molin', intdiv($seconds, 3600));
        }

        if ($seconds < 604800) {
            return __('%s 天前', 'molin', intdiv($seconds, 86400));
        }

        return date('Y-m-d', $timestamp);
    }
}
