<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

use Closure;
use Molin\Database\Database;
use Molin\Router\Router;
use Molin\Security\Password;

/**
 * 内容仓储:把 posts 行变成带 url 的 Post 对象,并交出流式查询入口。
 */
final class PostRepository
{
    /**
     * 取文章时必须列明字段(禁 SELECT *、content 单独查)。
     * password 列永不外取 —— 那是私密正文的口令哈希,前台与列表都不该碰。
     */
    public const COLUMNS = ['id', 'type', 'status', 'title', 'slug', 'author_id', 'excerpt', 'published_at', 'created_at', 'updated_at', 'thumbnail_id', 'comment_status', 'comment_count', 'view_count'];

    public function __construct(
        private readonly Database $db,
        private readonly Router $router
    ) {
    }

    public function query(): PostQuery
    {
        return new PostQuery($this->db, Closure::fromCallable($this->hydrate(...)), $this->primaryCategoryColumn());
    }

    /**
     * 交出底层连接:装配层之外的静态工厂(如 Renderer::archiveProvider)拿不到容器,
     * 靠它取同一份 Database 去建 TermAssigner 等仓储。
     */
    public function database(): Database
    {
        return $this->db;
    }

    /**
     * 默认只认"没在回收站里"的文章;后台回收站要看得见,才显式带 withTrashed。
     *
     * $raw = true 跳过 content/fetch/* 两个展示钩子,拿库里的真相 —— 写入链路(PostWriter)
     * 必须走这条路:插件在 fetch/after 里改过的正文一旦被当"当前值"回写,库里的原文就被改了。
     */
    public function find(int $id, bool $withTrashed = false, bool $raw = false): ?Post
    {
        if ($raw) {
            return $this->selectById($id, $withTrashed);
        }

        $criteria = $this->fetchCriteria(['id' => $id, 'with_trashed' => $withTrashed], 'find');
        $criteriaId = $criteria['id'] ?? null;
        $criteriaTrashed = $criteria['with_trashed'] ?? null;
        $found = $this->selectById(
            is_int($criteriaId) ? $criteriaId : $id,
            is_bool($criteriaTrashed) ? $criteriaTrashed : $withTrashed
        );

        return $this->afterFetch($found, 'find');
    }

    /**
     * 记一次浏览(既有规范的 items.views)。口径由作者 2026-10-14 定:前端单篇每请求一次 `+1`,不做去重。
     *
     * 交给 SQL 自增,不读改写 —— 并发下两条请求各自 +1,不会互相盖掉。
     * 刻意不播任何钩子:浏览量不是内容变更,不该把页面缓存冲掉。
     */
    public function touchView(int $id): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->table('posts') . ' SET view_count = view_count + 1 WHERE id = :id',
            ['id' => $id]
        );
    }

    public function findBySlug(string $slug, string $type = 'post'): ?Post
    {
        $criteria = $this->fetchCriteria(['slug' => $slug, 'type' => $type], 'find_by_slug');
        $criteriaSlug = $criteria['slug'] ?? null;
        $criteriaType = $criteria['type'] ?? null;

        $row = $this->db->selectOne(
            'SELECT ' . implode(', ', self::COLUMNS) . ', p.content' . $this->primaryCategorySuffix()
            . ' FROM ' . $this->db->table('posts') . ' p'
            . ' WHERE p.slug = :slug AND p.type = :type AND p.deleted_at IS NULL LIMIT 1',
            [
                'slug' => is_string($criteriaSlug) ? $criteriaSlug : $slug,
                'type' => is_string($criteriaType) ? $criteriaType : $type,
            ]
        );

        return $this->afterFetch($row === null ? null : $this->hydrate($row), 'find_by_slug');
    }

    /**
     * 单篇读取前给插件改条件的机会(`content/fetch/before` = 查询前,改条件)。
     *
     * 单个 Post 上没有"条件"可谈,所以入参就是这次读取的条件数组:
     * find() 收 {id: int, with_trashed: bool},findBySlug() 收 {slug: string, type: string};
     * 第二个参数是读取种类,同一份监听可以分清是哪种读法。只认这两个键、只认类型对得上的值,
     * 插件回吐坏形状时按"不改"处理 —— 一条写错签名的监听不该把整站读取变成 500。
     *
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function fetchCriteria(array $criteria, string $source): array
    {
        $filtered = apply_filters('content/fetch/before', $criteria, $source);

        return is_array($filtered) ? $filtered : $criteria;
    }

    /**
     * 查出后给插件改结果集的机会(`content/fetch/after` = 查出后,改结果集)。
     * 返回 Post 才算数,其它一律按"不改"处理(含 null:想藏就显式返回 null)。
     */
    private function afterFetch(?Post $post, string $source): ?Post
    {
        $filtered = apply_filters('content/fetch/after', $post, $source);

        if ($filtered instanceof Post) {
            return $filtered;
        }

        return $filtered === null ? null : $post;
    }

    /**
     * 按 id 取行并水合。不带任何钩子 —— 展示层的改写在这里之后发生。
     */
    private function selectById(int $id, bool $withTrashed): ?Post
    {
        $row = $this->db->selectOne(
            'SELECT ' . implode(', ', self::COLUMNS) . ', p.content' . $this->primaryCategorySuffix()
            . ' FROM ' . $this->db->table('posts') . ' p'
            . ' WHERE p.id = :id' . ($withTrashed ? '' : ' AND p.deleted_at IS NULL') . ' LIMIT 1',
            ['id' => $id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * 按 id 批量取原始正文。列表查询故意不带 content,但自动摘要要从正文取字,
     * 于是只给"确实要用的那几行"补一次 IN 查询 —— 一页一次,不逐行往返。
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function contentsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params['id' . $index] = $id;
        }

        $rows = $this->db->select(
            'SELECT id, content FROM ' . $this->db->table('posts')
            . ' WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) ($row['content'] ?? '');
        }

        return $out;
    }

    /**
     * 这篇是否设了私密正文口令。只回"有没有",哈希不跨出仓储 —— 前台/列表绝不碰明文与哈希。
     */
    public function requiresPassword(int $id): bool
    {
        $row = $this->db->selectOne(
            'SELECT password FROM ' . $this->db->table('posts') . ' WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        return trim((string) ($row['password'] ?? '')) !== '';
    }

    /**
     * 口令校验只发生在服务端。比对挂在 Repository 而不是控制器:
     * 哈希存取这一层知道,上层只问"对不对",不回吐哈希。
     */
    public function verifyPassword(int $id, string $plain): bool
    {
        if ($plain === '') {
            return false;
        }

        $row = $this->db->selectOne(
            'SELECT password FROM ' . $this->db->table('posts') . ' WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        $hash = (string) ($row['password'] ?? '');

        return $hash !== '' && Password::verify($plain, $hash);
    }

    /**
     * 固定链接结构含 %category% 时,取每篇的主分类 slug 供生成地址用。
     * 用一条关联子查询在同行内取全(不给每行单开一次往返);
     * 结构不需要分类时整列不取,查询保持轻。
     */
    private function primaryCategoryColumn(): ?string
    {
        return $this->router->needsCategoryInPath() ? $this->primaryCategorySql() . ' AS primary_category' : null;
    }

    private function primaryCategorySuffix(): string
    {
        $column = $this->primaryCategoryColumn();

        return $column === null ? '' : ', ' . $column;
    }

    private function primaryCategorySql(): string
    {
        $rels = $this->db->table('term_relationships');
        $tax = $this->db->table('term_taxonomy');
        $terms = $this->db->table('terms');

        return '(SELECT t.slug FROM ' . $rels . ' r'
            . ' JOIN ' . $tax . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = \'category\''
            . ' JOIN ' . $terms . ' t ON t.term_id = tt.term_id'
            . ' WHERE r.post_id = p.id ORDER BY r.order, t.name LIMIT 1)';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Post
    {
        $url = $this->router->generate('post', [
            'slug'          => (string) ($row['slug'] ?? ''),
            'post_id'       => (int) ($row['id'] ?? 0),
            'type'          => (string) ($row['type'] ?? 'post'),
            'published_at'  => (string) ($row['published_at'] ?? ($row['created_at'] ?? '')),
            'category'      => (string) ($row['primary_category'] ?? ''),
        ]);

        return Post::fromRow($row, $url);
    }
}
