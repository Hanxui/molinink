<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Logger\Audit;
use Molin\Security\Password;

/**
 * 内容写入。
 *
 * 一次保存跨五张表(posts / postmeta / terms 三件套 / post_revisions),整段走事务 ——
 * 半套关系落库比保存失败更难查。
 *
 * 播报的钩子:content/save/before|after、post/publish/before|after(转进 publish 的那一刻)、
 * post/status/after、content/delete/before。入参形状以既有规范的签名表为准;
 * 表里没登记的(post/publish/*)按"最少够用"给 —— 发布只给 Post,谁发的由监听自己问 current_user()。
 *
 * 两处口径要记住:
 *  - 新建时直接以 publish 落库不播 post/publish/*:那不是"跃迁",也没有 before 可用的 Post;
 *  - 从回收站还原进 publish 算发布(与 draft/pending → publish 同一条口径)。
 */
final class PostWriter
{
    /** 每篇默认留最近 10 份修订。比对屏(既有规范金属行)同源引用这里 */
    public const REVISIONS_KEPT = 10;

    private const MAX_SLUG_TRIES = 100;

    public function __construct(
        private readonly Database $db,
        private readonly PostRepository $posts,
        private readonly MetaStore $meta,
        private readonly TermAssigner $terms
    ) {
    }

    /**
     * 新建或保存。$id 为 null 就是新建。
     *
     * 可用键:type / status / title / slug / excerpt / content /
     * parent_id / menu_order / comment_status / thumbnail_id / published_at,
     * 外加 categories、tags(名字数组)与 meta(键值对)。
     * 私密正文口令走 password 键:非空明文密封成哈希落库,空串 = 解除保护(见 storedPassword)。
     *
     * @param array<string, mixed> $data
     */
    public function save(array $data, int $authorId, ?int $id = null): Post
    {
        // 插件可在落库前改写入数据(既有规范content/save/before)。
        // 生产模式若回吐了非数组,按"不改"处理 —— 一条坏签名不该把整次保存变成 500。
        $filtered = apply_filters('content/save/before', $data, $id);

        if (is_array($filtered)) {
            $data = $filtered;
        }

        if (array_key_exists('content', $data)) {
            // 正文交主题时是 |raw 的富文本,所以落库前先过白名单。
            // 这一步写在内核里而不是挂到钩子上:插件只能收紧,不能放宽,也不能把它摘掉。
            $data['content'] = HtmlSanitizer::clean((string) $data['content']);
        }

        $isNew = $id === null;

        $post = $this->db->transaction(function () use ($data, $authorId, $id): Post {
            return $id === null ? $this->insert($data, $authorId) : $this->update($id, $data, $authorId);
        });

        // 钩子在事务内触发,插件读到的可能是未提交数据 —— 与 WordPress 的既有习惯一致,
        // 好处是插件写的关联数据能跟这次保存一起提交或一起回滚。
        do_action('content/save/after', $post, $isNew);

        return $post;
    }

    /**
     * 移入回收站(软删除,30 天后才真删,既有规范)。
     *
     * 层级内容的子项不跟着走:提升到上一层。挂在回收站里的父项下面,
     * 列表里就再也找不着它了 —— 还原/删除父项时子项也不该莫名消失。
     */
    public function trash(int $id, string $actor = 'system'): Post
    {
        $post = $this->existingIncludingTrash($id);

        if (!StatusMachine::canGo($post->status, StatusMachine::TRASH)) {
            $this->refuseTransition($post->status, StatusMachine::TRASH);
        }

        do_action('content/delete/before', $post);

        $this->write($id, [
            'status' => StatusMachine::TRASH,
            'deleted_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->promoteChildren($id);
        $this->recountFor($id);
        Audit::record('post.delete', 'post:' . $id, true, null, $actor);

        $trashed = $this->existingIncludingTrash($id);
        do_action('post/status/after', $trashed, $post->status, StatusMachine::TRASH);

        return $trashed;
    }

    /**
     * 从回收站还原。既有规范只画了 trash → draft / publish。
     */
    public function restore(int $id, string $status = StatusMachine::DRAFT, string $actor = 'system'): Post
    {
        $post = $this->existingIncludingTrash($id);

        if ($post->status !== StatusMachine::TRASH) {
            throw new ValidationException('只有回收站里的内容需要还原', ['status' => ['当前:' . $post->status]]);
        }

        if (!StatusMachine::canGo(StatusMachine::TRASH, $status)) {
            $this->refuseTransition(StatusMachine::TRASH, $status);
        }

        // 还原进 publish 也算"发布",与 update 同一条口径
        $publishing = $status === StatusMachine::PUBLISH;

        if ($publishing) {
            do_action('post/publish/before', $post);
        }

        $this->write($id, [
            'status' => $status,
            'deleted_at' => null,
        ]);
        $this->recountFor($id);
        Audit::record('post.restore', 'post:' . $id, true, null, $actor);

        $restored = $this->existing($id);

        if ($publishing) {
            do_action('post/publish/after', $restored);
        }

        do_action('post/status/after', $restored, StatusMachine::TRASH, $status);

        return $restored;
    }

    /**
     * 彻底删除。既有规范没写要不要级联清 meta / 关系 / 修订,这里按"清干净"处理:
     * 留下孤儿行,日后任何按 post_id 聚合的统计都会凭空多出一截。
     */
    public function forceDelete(int $id, string $actor = 'system'): void
    {
        $post = $this->existingIncludingTrash($id);
        do_action('content/delete/before', $post);
        // 分类计数要按"删掉之前"的关系重算,所以先取出来
        $affected = $this->termTaxonomyIds($id);

        $this->db->transaction(function () use ($id, $affected): void {
            $this->db->delete('term_relationships', 'post_id = :id', ['id' => $id]);
            $this->db->delete('postmeta', 'post_id = :id', ['id' => $id]);
            $this->db->delete('post_revisions', 'post_id = :id', ['id' => $id]);
            $this->db->delete('commentmeta', 'comment_id IN (SELECT id FROM ' . $this->db->table('comments') . ' WHERE post_id = :id)', ['id' => $id]);
            $this->db->delete('comments', 'post_id = :id', ['id' => $id]);
            $this->db->delete('posts', 'id = :id', ['id' => $id]);

            foreach ($affected as $ttId) {
                $this->terms->recount($ttId);
            }
        });

        Audit::record('post.purge', 'post:' . $id, true, null, $actor);
    }

    /**
     * 父项进回收站时,活着的子项提升到父项原先的层级。
     * 已在回收站的子项不动 —— 保持原父,还原时还找得回位置。
     */
    private function promoteChildren(int $id): void
    {
        $row = $this->db->selectOne(
            'SELECT parent_id FROM ' . $this->db->table('posts') . ' WHERE id = :id',
            ['id' => $id]
        );

        $this->db->update(
            'posts',
            ['parent_id' => (int) ($row['parent_id'] ?? 0)],
            'parent_id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    /**
     * @return list<array{id:int, title:string, created_at:string}>
     */
    public function revisions(int $id): array
    {
        $rows = $this->db->select(
            'SELECT id, title, created_at FROM ' . $this->db->table('post_revisions')
            . ' WHERE post_id = :id ORDER BY created_at DESC, id DESC',
            ['id' => $id]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(array $data, int $authorId): Post
    {
        $type = $this->text($data, 'type', 'post');
        $title = $this->text($data, 'title', '');
        $status = $this->text($data, 'status', StatusMachine::DRAFT);
        $content = (string) ($data['content'] ?? '');
        $excerpt = $this->text($data, 'excerpt', '');
        $now = gmdate('Y-m-d H:i:s');

        if (!in_array($status, StatusMachine::CREATABLE, true)) {
            throw new ValidationException("新建时不能直接用状态「{$status}」", ['status' => ['可选:' . implode(', ', StatusMachine::CREATABLE)]]);
        }

        if ($title === '' && $content === '') {
            throw new ValidationException('标题和正文不能同时为空', ['title' => ['给一个就行']]);
        }

        // 无标题的类型(说说)没有"标题"可看:没手填摘要就取正文 —— 列表卡与后台行标识都得有字
        if ($excerpt === '' && !PostTypeRegistry::instance()->hasSupport($type, 'title')) {
            $excerpt = self::deriveExcerpt($content);
        }

        $id = $this->db->insert('posts', [
            'type' => $type,
            'status' => $status,
            'author_id' => $authorId,
            'title' => $title,
            'slug' => $this->uniqueSlug($type, Slug::fromInput($this->text($data, 'slug', ''), $title, $type)),
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'content' => $content,
            'content_filtered' => null,
            'parent_id' => (int) ($data['parent_id'] ?? 0),
            'menu_order' => (int) ($data['menu_order'] ?? 0),
            'comment_status' => $this->text($data, 'comment_status', 'open'),
            'thumbnail_id' => (int) ($data['thumbnail_id'] ?? 0),
            'password' => $this->storedPassword($data),
            'published_at' => $this->publishedAt($data, $status, $now),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->applyMeta($id, $data);
        $this->applyTerms($id, $data);

        return $this->existing($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(int $id, array $data, int $authorId): Post
    {
        $current = $this->existing($id);
        $status = $this->text($data, 'status', $current->status);
        $now = gmdate('Y-m-d H:i:s');

        if (!StatusMachine::canGo($current->status, $status)) {
            $this->refuseTransition($current->status, $status);
        }

        $title = $this->text($data, 'title', $current->title);
        $content = array_key_exists('content', $data) ? (string) $data['content'] : (string) $current->content;
        $excerpt = array_key_exists('excerpt', $data) ? $this->text($data, 'excerpt', '') : (string) $current->excerpt;

        // 无标题的类型(说说)没手填摘要就跟着正文走:改过正文后摘要不会停在旧值
        if (!array_key_exists('excerpt', $data) && !PostTypeRegistry::instance()->hasSupport($current->type, 'title')) {
            $excerpt = self::deriveExcerpt($content);
        }

        $values = [
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'status' => $status,
            // 渲染缓存随本次改动作废,下次输出重新过一遍钩子
            'content_filtered' => null,
            'deleted_at' => $status === StatusMachine::TRASH ? $now : null,
        ];

        foreach (['parent_id' => 'int', 'menu_order' => 'int', 'thumbnail_id' => 'int'] as $key => $_type) {
            if (array_key_exists($key, $data)) {
                $values[$key] = (int) $data[$key];
            }
        }

        if (array_key_exists('comment_status', $data)) {
            $values['comment_status'] = $this->text($data, 'comment_status', 'open');
        }

        // 口令键在就按它走:非空换新哈希,空串解除保护;键不在则留原值(编辑时没动口令)
        if (array_key_exists('password', $data)) {
            $values['password'] = $this->storedPassword($data);
        }

        $providedSlug = $this->text($data, 'slug', '');

        if ($providedSlug !== '') {
            // 与新建同一条口径。只走 slugify() 的话,整串标点会被洗成空串 —— 那等于把这篇的地址
            // 写没了;fromInput 会退到标题、再退到带时间戳的兜底。唯一性交给 uniqueSlug(排除自己)
            $values['slug'] = $this->uniqueSlug($current->type, Slug::fromInput($providedSlug, $title, $current->type), $id);
        }

        if ($status === StatusMachine::PUBLISH) {
            // 时间格填了就按填的走;没填就留住原来那份,全新的才取当下
            $values['published_at'] = $this->stamp($data['published_at'] ?? null)
                ?? $current->published_at
                ?? $now;
        }

        // 发布 = 状态跃迁进 publish(发布前 / 发布后)。
        // 入参只给 Post:谁发的由插件自己问 current_user(),内核不替它猜。
        $publishing = $status === StatusMachine::PUBLISH && $current->status !== StatusMachine::PUBLISH;

        if ($publishing) {
            do_action('post/publish/before', $current);
        }

        $this->snapshotRevision($current, $title, $content, $authorId, $now);
        $this->write($id, $values);
        $this->applyMeta($id, $data);
        $this->applyTerms($id, $data);
        $this->recountFor($id);

        $saved = $this->existing($id);

        if ($publishing) {
            do_action('post/publish/after', $saved);
        }

        if ($saved->status !== $current->status) {
            do_action('post/status/after', $saved, $current->status, $saved->status);
        }

        return $saved;
    }

    /**
     * 存的是**改之前**的那一版 —— 修订的作用就是"退回上一步"。
     */
    private function snapshotRevision(Post $previous, string $title, string $content, int $authorId, string $now): void
    {
        if ($title === $previous->title && $content === (string) $previous->content) {
            return;
        }

        $this->db->insert('post_revisions', [
            'post_id' => $previous->id,
            'title' => $previous->title,
            'content' => (string) $previous->content,
            'author_id' => $authorId,
            'created_at' => $now,
        ]);

        $stale = $this->db->select(
            'SELECT id FROM ' . $this->db->table('post_revisions')
            . ' WHERE post_id = :id ORDER BY created_at DESC, id DESC LIMIT 999 OFFSET ' . self::REVISIONS_KEPT,
            ['id' => $previous->id]
        );

        foreach ($stale as $row) {
            $this->db->delete('post_revisions', 'id = :id', ['id' => (int) $row['id']]);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(int $id, array $values): void
    {
        $values['updated_at'] = gmdate('Y-m-d H:i:s');

        $this->db->update('posts', $values, 'id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyMeta(int $postId, array $data): void
    {
        foreach ((array) ($data['meta'] ?? []) as $key => $value) {
            $this->meta->set($postId, (string) $key, $value);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyTerms(int $postId, array $data): void
    {
        $map = [TermAssigner::CATEGORY => 'categories', TermAssigner::TAG => 'tags'];

        foreach ($map as $taxonomy => $field) {
            // 没传这个键就是"不碰该分类法",传空数组才是"清空"
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $ids = [];

            foreach ((array) $data[$field] as $item) {
                if (is_int($item)) {
                    $ids[] = $item;

                    continue;
                }

                $name = trim((string) $item);

                if ($name !== '') {
                    $ids[] = $this->terms->findOrCreate($taxonomy, $name);
                }
            }

            $this->terms->assign($postId, $taxonomy, array_values(array_unique($ids)));
        }
    }

    /**
     * @return list<int>
     */
    private function termTaxonomyIds(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT term_taxonomy_id AS tt FROM ' . $this->db->table('term_relationships') . ' WHERE post_id = :id',
            ['id' => $postId]
        );

        return array_map(static fn (array $row): int => (int) $row['tt'], $rows);
    }

    private function recountFor(int $postId): void
    {
        foreach ($this->termTaxonomyIds($postId) as $ttId) {
            $this->terms->recount($ttId);
        }
    }

    private function uniqueSlug(string $type, string $slug, ?int $exceptId = null): string
    {
        for ($try = 1; $try <= self::MAX_SLUG_TRIES; $try++) {
            $candidate = Slug::withSuffix($slug, $try);
            $where = 'type = :type AND slug = :slug';
            $params = ['type' => $type, 'slug' => $candidate];

            if ($exceptId !== null) {
                $where .= ' AND id <> :id';
                $params['id'] = $exceptId;
            }

            $taken = $this->db->selectOne(
                'SELECT id FROM ' . $this->db->table('posts') . ' WHERE ' . $where . ' LIMIT 1',
                $params
            );

            if ($taken === null) {
                return $candidate;
            }
        }

        throw new ValidationException('这个短名连续 ' . self::MAX_SLUG_TRIES . ' 个候选都被占了,请手工换一个', ['slug' => ['起始:' . $slug]]);
    }

    /**
     * 私密正文口令只存哈希,明文绝不进库也不要回吐给模板。空串 = 解除保护(存 null)。
     *
     * @param array<string, mixed> $data
     */
    private function storedPassword(array $data): ?string
    {
        if (!array_key_exists('password', $data)) {
            return null;
        }

        $plain = trim((string) $data['password']);

        return $plain === '' ? null : Password::hash($plain);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publishedAt(array $data, string $status, string $now): ?string
    {
        if ($status !== StatusMachine::PUBLISH) {
            return null;
        }

        // 定时发布:给了未来时间就照给,到点前前台不可见
        return $this->stamp($data['published_at'] ?? null) ?? $now;
    }

    /**
     * 时间格收上来的值:datetime-local 给的是 2026-10-05T14:30,是**站点时区**的钟点。
     * 库里一律存 UTC —— 与 created_at、定时发布的 `UTC_TIMESTAMP()` 判断同一口径;
     * 展示时再由 `|date` 过滤器换回站点时区。
     */
    private function stamp(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = str_replace('T', ' ', $value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $value, $matches) === 1) {
            $value .= ':00';
        }

        $stamp = strtotime($value);

        return $stamp === false ? null : gmdate('Y-m-d H:i:s', $stamp);
    }

    private function refuseTransition(string $from, string $to): never
    {
        throw new ValidationException("状态不能从「{$from}」改成「{$to}」:未定义这条跃迁", [
            'status' => ['可去向:' . (StatusMachine::nextStates($from) === [] ? '(无)' : implode(', ', StatusMachine::nextStates($from)))],
        ]);
    }

    /**
     * 正文 → 纯文本摘要(最多 120 字)。
     *
     * 只给"内容即摘要"的无标题类型用(supports 里没有 title),不碰有标题的文章 ——
     * 那些的摘要仍由作者手填,内核不替他编。
     */
    private static function deriveExcerpt(string $content): string
    {
        // 块级收尾先换成空格,免得两段文字粘成一句;实体还原成人读的字面量再存
        $spaced = (string) preg_replace('#</(?:p|div|li|h[1-6]|blockquote|tr)>|<br\s*/?>#i', ' ', $content);
        $text = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '…' : $text;
    }

    private function existing(int $id): Post
    {
        // raw 读:写链路拿的是库里的原文,展示钩子(content/fetch/*)的改写绝不参与回写
        $post = $this->posts->find($id, false, true);

        if ($post === null) {
            throw new NotFoundException("文章 {$id} 不存在");
        }

        return $post;
    }

    /**
     * 回收站里的文章也要能还原、能彻底删,所以这三个动作走这条宽口。
     */
    private function existingIncludingTrash(int $id): Post
    {
        $post = $this->posts->find($id, true, true);

        if ($post === null) {
            throw new NotFoundException("文章 {$id} 不存在");
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? $default : $trimmed;
    }
}
