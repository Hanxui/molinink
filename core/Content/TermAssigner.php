<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

use Molin\Database\Database;
use Molin\Exception\ValidationException;
use RuntimeException;

/**
 * 分类法写入(terms + term_taxonomy + term_relationships 三表)。
 *
 * 两个文档没定死的地方,这里各取一条明确规则并写下来:
 * - 分配语义 = **全量替换**(把这次给的集合当成该分类法的完整结果)。增删容易漏配,
 *   留下"以为删了其实还在"的脏关系,比多写几行更危险。
 * - 同名判定按 **slug**,不按 name。`terms` 表没有唯一键,
 *   靠 slug 收敏才不会因大小写差异攒出一堆同义 term。
 */
final class TermAssigner
{
    public const CATEGORY = 'category';
    public const TAG = 'post_tag';

    /** 兜底分类的 slug:不挂子级、不可删。管理屏与编辑器那颗「×」共用这一条 */
    public const UNCATEGORIZED = 'uncategorized';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array{term_taxonomy_id:int, name:string, slug:string}>
     */
    public function termsOf(int $postId, string $taxonomy): array
    {
        $rows = $this->db->select(
            'SELECT tt.term_taxonomy_id AS tt_id, t.name AS name, t.slug AS slug
             FROM ' . $this->db->table('term_relationships') . ' r
             JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id
             JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id
             WHERE r.post_id = :id AND tt.taxonomy = :tax
             ORDER BY r.order, t.name',
            ['id' => $postId, 'tax' => $taxonomy]
        );

        return array_map(static fn (array $row): array => [
            'term_taxonomy_id' => (int) $row['tt_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
        ], $rows);
    }

    /**
     * 一屏列表里每行都要显示分类,逐行查就是 N+1。一次 IN 查询拿全。
     *
     * @param list<int> $postIds
     * @return array<int, list<string>>  post_id => 名字列表
     */
    public function namesFor(array $postIds, string $taxonomy): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($postIds) as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params['id' . $index] = $id;
        }

        $params['tax'] = $taxonomy;

        $rows = $this->db->select(
            'SELECT r.post_id AS post_id, t.name AS name FROM ' . $this->db->table('term_relationships') . ' r
             JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id
             JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id
             WHERE r.post_id IN (' . implode(', ', $placeholders) . ') AND tt.taxonomy = :tax
             ORDER BY r.order, t.name',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['post_id']][] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * 作者页侧栏：该作者已发布文章的分类计数（一条 SQL，不逐篇查）。
     *
     * @return list<array{name:string, slug:string, count:int}>
     */
    public function categoryCountsForAuthor(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT t.name AS name, t.slug AS slug, COUNT(DISTINCT r.post_id) AS count
             FROM ' . $this->db->table('term_relationships') . ' r
             JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id
             JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id
             JOIN ' . $this->db->table('posts') . ' p ON p.id = r.post_id
             WHERE p.author_id = :uid AND p.type = :type AND p.status = :status AND tt.taxonomy = :tax
             GROUP BY t.term_id, t.name, t.slug
             ORDER BY count DESC, t.name',
            ['uid' => $userId, 'type' => 'post', 'status' => 'publish', 'tax' => 'category']
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * 每篇取排序最靠前的那一个词条(标签页的元信息要回带分类名)。
     * 一屏 N 行逐行查就是 N+1,一次 IN 查询拿全,排序口径与 termsOf 相同。
     *
     * @param list<int> $postIds
     * @return array<int, array{name:string, slug:string}>  post_id => 首项
     */
    public function firstTermsFor(array $postIds, string $taxonomy): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($postIds) as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params['id' . $index] = $id;
        }

        $params['tax'] = $taxonomy;

        $rows = $this->db->select(
            'SELECT r.post_id AS post_id, t.name AS name, t.slug AS slug FROM ' . $this->db->table('term_relationships') . ' r
             JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id
             JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id
             WHERE r.post_id IN (' . implode(', ', $placeholders) . ') AND tt.taxonomy = :tax
             ORDER BY r.order, t.name',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];

            if (!isset($out[$postId])) {
                $out[$postId] = ['name' => (string) $row['name'], 'slug' => (string) $row['slug']];
            }
        }

        return $out;
    }

    /**
     * 按 slug 读一个词条(既有规范的归档页头要 name 与 description)。不存在返回 null。
     *
     * @return array{term_taxonomy_id:int, term_id:int, name:string, slug:string, description:string}|null
     */
    public function findBySlug(string $taxonomy, string $slug): ?array
    {
        $row = $this->db->selectOne(
            'SELECT tt.term_taxonomy_id AS tt_id, tt.term_id AS term_id, t.name AS name, t.slug AS slug,'
            . ' tt.description AS description'
            . ' FROM ' . $this->db->table('terms') . ' t'
            . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_id = t.term_id'
            . ' WHERE t.slug = :slug AND tt.taxonomy = :tax',
            ['slug' => $slug, 'tax' => $taxonomy]
        );

        if ($row === null) {
            return null;
        }

        return [
            'term_taxonomy_id' => (int) $row['tt_id'],
            'term_id' => (int) $row['term_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
        ];
    }

    /**
     * 编辑页要列出可勾选的分类(既有规范的分类盒),count 是这一项下已发布的文章数。
     * slug 是给列表页的筛选下拉用的 —— 那边的查询条件走 `PostQuery::category(slug)`。
     *
     * @return list<array{term_taxonomy_id:int, name:string, slug:string, count:int}>
     */
    public function choices(string $taxonomy): array
    {
        // 计数是编辑页「分类」盒右边那个灰数字(既有规范盒),只数已发布的正经内容
        $rows = $this->db->select(
            'SELECT tt.term_taxonomy_id AS tt_id, t.name AS name, t.slug AS slug,'
            . ' (SELECT COUNT(*) FROM ' . $this->db->table('term_relationships') . ' tr'
            . '  JOIN ' . $this->db->table('posts') . ' p ON p.id = tr.post_id'
            . "  WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND p.status = 'publish'"
            . "   AND p.deleted_at IS NULL AND p.type IN ('post', 'page')) AS n"
            . ' FROM ' . $this->db->table('term_taxonomy') . ' tt'
            . ' JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id'
            . ' WHERE tt.taxonomy = :tax ORDER BY t.name',
            ['tax' => $taxonomy]
        );

        return array_map(static fn (array $row): array => [
            'term_taxonomy_id' => (int) $row['tt_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) ($row['n'] ?? 0),
        ], $rows);
    }

    /**
     * 按名字找到(或建出)一个 term,返回 term_taxonomy_id。
     */
    public function findOrCreate(string $taxonomy, string $name): int
    {
        $slug = Slug::slugify($name);
        $existing = $this->db->selectOne(
            'SELECT tt.term_taxonomy_id AS tt_id FROM ' . $this->db->table('terms') . ' t
             JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_id = t.term_id
             WHERE t.slug = :slug AND tt.taxonomy = :tax',
            ['slug' => $slug, 'tax' => $taxonomy]
        );

        if ($existing !== null) {
            return (int) $existing['tt_id'];
        }

        $termId = $this->db->insert('terms', [
            'name' => $name,
            'slug' => $slug === '' ? 'term-' . date('Ymd-His') : $slug,
        ]);

        return $this->addTerm($taxonomy, $termId);
    }

    /**
     * 把一个已存在的 term 挂到该分类法下(默认分类走这条路,免得重复建 terms 行)。
     */
    public function addTerm(string $taxonomy, int $termId, int $parent = 0): int
    {
        $existing = $this->db->selectOne(
            'SELECT term_taxonomy_id AS tt_id FROM ' . $this->db->table('term_taxonomy')
            . ' WHERE term_id = :term AND taxonomy = :tax',
            ['term' => $termId, 'tax' => $taxonomy]
        );

        if ($existing !== null) {
            return (int) $existing['tt_id'];
        }

        return $this->db->insert('term_taxonomy', [
            'term_id'  => $termId,
            'taxonomy' => $taxonomy,
            'parent'   => $parent,
            'count'    => 0,
        ]);
    }

    /**
     * 全量替换该分类法下的分配,并刷新受影响项的 count。
     *
     * @param list<int> $termTaxonomyIds
     */
    public function assign(int $postId, string $taxonomy, array $termTaxonomyIds): void
    {
        $current = array_values(array_map(
            static fn (array $row): int => $row['term_taxonomy_id'],
            $this->termsOf($postId, $taxonomy)
        ));

        foreach (array_diff($current, $termTaxonomyIds) as $gone) {
            $this->db->execute(
                'DELETE FROM ' . $this->db->table('term_relationships')
                . ' WHERE post_id = :post AND term_taxonomy_id = :tt',
                ['post' => $postId, 'tt' => $gone]
            );
        }

        foreach ($termTaxonomyIds as $index => $ttId) {
            if (in_array($ttId, $current, true)) {
                $this->db->update(
                    'term_relationships',
                    ['order' => $index],
                    'post_id = :post AND term_taxonomy_id = :tt',
                    ['post' => $postId, 'tt' => $ttId]
                );

                continue;
            }

            $this->db->insert('term_relationships', [
                'post_id'            => $postId,
                'term_taxonomy_id'   => $ttId,
                'order'              => $index,
            ]);
        }

        foreach ($termTaxonomyIds as $ttId) {
            $this->recount($ttId);
        }

        foreach (array_diff($current, $termTaxonomyIds) as $gone) {
            $this->recount($gone);
        }
    }

    /**
     * count 只数前台可见的文章。
     */
    public function recount(int $termTaxonomyId): void
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM ' . $this->db->table('term_relationships') . ' r
             JOIN ' . $this->db->table('posts') . ' p ON p.id = r.post_id
             WHERE r.term_taxonomy_id = :tt AND p.status = :status AND p.deleted_at IS NULL',
            ['tt' => $termTaxonomyId, 'status' => StatusMachine::PUBLISH]
        );

        $this->db->update(
            'term_taxonomy',
            ['count' => (int) ($row['total'] ?? 0)],
            'term_taxonomy_id = :tt',
            ['tt' => $termTaxonomyId]
        );
    }

    /**
     * 管理屏的左列表:分类按树序展平、标签平铺。行序照设计稿 ——
     * 顶层按文章数降序、同数按名称,子行紧跟父后;
     * (图右边的注写着「按名称排序」,图里的行序却是文章数降序:取图,注挂待办清单待裁。)
     * 计数口径与 choices() 相同:只数前台可见的 post/page,编辑页与本屏的数字对得上。
     *
     * @return list<array{term_taxonomy_id:int, term_id:int, name:string, slug:string, description:string, parent:int, count:int, depth:int}>
     */
    public function listAll(string $taxonomy): array
    {
        $rows = $this->db->select(
            'SELECT tt.term_taxonomy_id AS tt_id, tt.term_id AS term_id, t.name AS name, t.slug AS slug,'
            . ' tt.description AS description, tt.parent AS parent,'
            . ' (SELECT COUNT(*) FROM ' . $this->db->table('term_relationships') . ' tr'
            . '  JOIN ' . $this->db->table('posts') . ' p ON p.id = tr.post_id'
            . "  WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND p.status = 'publish'"
            . "   AND p.deleted_at IS NULL AND p.type IN ('post', 'page')) AS n"
            . ' FROM ' . $this->db->table('term_taxonomy') . ' tt'
            . ' JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id'
            . ' WHERE tt.taxonomy = :tax',
            ['tax' => $taxonomy]
        );

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'term_taxonomy_id' => (int) $row['tt_id'],
                'term_id' => (int) $row['term_id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'description' => (string) ($row['description'] ?? ''),
                'parent' => (int) $row['parent'],
                'count' => (int) ($row['n'] ?? 0),
                'depth' => 1,
            ];
        }

        if ($taxonomy !== self::CATEGORY) {
            usort($items, static fn (array $a, array $b): int => self::byCountThenName($a, $b));

            return $items;
        }

        $byParent = [];

        foreach ($items as $index => $item) {
            $byParent[$item['parent']][] = $index;
        }

        $flat = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$flat, $byParent, $items): void {
            $group = array_map(static fn (int $index): array => $items[$index], $byParent[$parent] ?? []);
            usort($group, static fn (array $a, array $b): int => self::byCountThenName($a, $b));

            foreach ($group as $item) {
                $item['depth'] = $depth;
                $flat[] = $item;
                $walk($item['term_taxonomy_id'], $depth + 1);
            }
        };
        $walk(0, 1);

        return $flat;
    }

    /**
     * 页签上的两枚计数(分类 5 / 标签 12)。键是 taxonomy 原值。
     *
     * @return array{category:int, post_tag:int}
     */
    public function counts(): array
    {
        $rows = $this->db->select(
            'SELECT taxonomy, COUNT(*) AS n FROM ' . $this->db->table('term_taxonomy')
            . ' WHERE taxonomy IN (:category, :tag) GROUP BY taxonomy',
            ['category' => self::CATEGORY, 'tag' => self::TAG]
        );

        $out = [self::CATEGORY => 0, self::TAG => 0];

        foreach ($rows as $row) {
            $taxonomy = (string) $row['taxonomy'];

            if ($taxonomy === self::CATEGORY || $taxonomy === self::TAG) {
                $out[$taxonomy] = (int) $row['n'];
            }
        }

        return $out;
    }

    /**
     * @return array{term_taxonomy_id:int, term_id:int, name:string, slug:string, description:string, parent:int, taxonomy:string}|null
     */
    public function findByTTid(int $termTaxonomyId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT tt.term_taxonomy_id AS tt_id, tt.term_id AS term_id, t.name AS name, t.slug AS slug,'
            . ' tt.description AS description, tt.parent AS parent, tt.taxonomy AS taxonomy'
            . ' FROM ' . $this->db->table('term_taxonomy') . ' tt'
            . ' JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id'
            . ' WHERE tt.term_taxonomy_id = :tt',
            ['tt' => $termTaxonomyId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'term_taxonomy_id' => (int) $row['tt_id'],
            'term_id' => (int) $row['term_id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
            'parent' => (int) $row['parent'],
            'taxonomy' => (string) $row['taxonomy'],
        ];
    }

    /**
     * 父分类候选:到第二层为止、不含兜底项、不含自己与后代,树序展平。
     * 在 listAll 的树序输出上过滤 —— 与列表的行序天然一致。
     *
     * @return list<array{term_taxonomy_id:int, name:string, depth:int}>
     */
    public function parentChoices(int $excludeTermTaxonomyId = 0): array
    {
        $items = $this->listAll(self::CATEGORY);
        $banned = $excludeTermTaxonomyId > 0 ? $this->withDescendants($items, $excludeTermTaxonomyId) : [];
        $out = [];

        foreach ($items as $item) {
            if ($item['depth'] > 2 || $item['slug'] === 'uncategorized') {
                continue;
            }

            if (in_array($item['term_taxonomy_id'], $banned, true)) {
                continue;
            }

            $out[] = [
                'term_taxonomy_id' => $item['term_taxonomy_id'],
                'name' => $item['name'],
                'depth' => $item['depth'],
            ];
        }

        return $out;
    }

    /**
     * 建一个新词条(管理屏的「添加分类 / 添加标签」)。校验不通过抛 ValidationException。
     * slug 留空按名称转写;标签不收父级。
     */
    public function create(string $taxonomy, string $name, string $slug, int $parent, string $description): int
    {
        $name = trim($name);
        $slug = $this->assertTermFields($taxonomy, $name, $slug, 0);

        if ($taxonomy === self::CATEGORY) {
            $this->assertParent(0, $parent);
        } else {
            $parent = 0;
        }

        return (int) $this->db->transaction(function () use ($taxonomy, $name, $slug, $parent, $description): int {
            $termId = $this->db->insert('terms', ['name' => $name, 'slug' => $slug, 'term_group' => 0]);

            return $this->db->insert('term_taxonomy', [
                'term_id' => $termId,
                'taxonomy' => $taxonomy,
                'description' => $description === '' ? null : $description,
                'parent' => $parent,
                'count' => 0,
            ]);
        });
    }

    /**
     * 改一个词条。父级规则同候选口径:「层级最多三层」在这里兜底校验。
     */
    public function update(int $termTaxonomyId, string $name, string $slug, int $parent, string $description): void
    {
        $target = $this->findByTTid($termTaxonomyId);

        if ($target === null) {
            throw new RuntimeException('词条不存在:' . $termTaxonomyId);
        }

        $name = trim($name);
        $slug = $this->assertTermFields($target['taxonomy'], $name, $slug, $target['term_id']);

        if ($target['taxonomy'] === self::CATEGORY) {
            $this->assertParent($termTaxonomyId, $parent);
        } else {
            $parent = 0;
        }

        $this->db->transaction(function () use ($target, $name, $slug, $parent, $description): void {
            $this->db->update('terms', ['name' => $name, 'slug' => $slug], 'term_id = :id', ['id' => $target['term_id']]);
            $this->db->update(
                'term_taxonomy',
                ['description' => $description === '' ? null : $description, 'parent' => $parent],
                'term_taxonomy_id = :tt',
                ['tt' => $target['term_taxonomy_id']]
            );
        });
    }

    /**
     * 删一个词条:分类把子级提升到上一层,标签与分类都只解除归属,内容不动。
     * 兜底项「未分类」不在这里挡,由调用方(后台屏)先拦。
     */
    public function remove(int $termTaxonomyId): void
    {
        $target = $this->findByTTid($termTaxonomyId);

        if ($target === null) {
            return;
        }

        $this->db->transaction(function () use ($target, $termTaxonomyId): void {
            $this->db->execute(
                'UPDATE ' . $this->db->table('term_taxonomy') . ' SET parent = :up'
                . ' WHERE parent = :tt AND taxonomy = :tax',
                ['up' => $target['parent'], 'tt' => $termTaxonomyId, 'tax' => $target['taxonomy']]
            );
            $this->db->delete('term_relationships', 'term_taxonomy_id = :tt', ['tt' => $termTaxonomyId]);
            $this->db->delete('term_taxonomy', 'term_taxonomy_id = :tt', ['tt' => $termTaxonomyId]);

            // 一个 term 可能还挂在别的自定义分类法下,那种情况只摘本层的行
            $left = $this->db->selectOne(
                'SELECT COUNT(*) AS n FROM ' . $this->db->table('term_taxonomy') . ' WHERE term_id = :id',
                ['id' => $target['term_id']]
            );

            if ((int) ($left['n'] ?? 0) === 0) {
                $this->db->delete('terms', 'term_id = :id', ['id' => $target['term_id']]);
            }
        });
    }

    /**
     * 名称与别名的共同校验(两态同口径)。slug 留空按名称转写;撞名按同 taxonomy 判定。
     * 通过后返回最终 slug。
     */
    private function assertTermFields(string $taxonomy, string $name, string $slug, int $exceptTermId): string
    {
        if ($name === '' || mb_strlen($name) > 60) {
            throw new ValidationException('名称要有,且不超过 60 个字符', ['name' => ['名称要有,且不超过 60 个字符']]);
        }

        $final = Slug::fromInput($slug, $name, $taxonomy === self::CATEGORY ? 'category' : 'tag');
        $taken = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('terms') . ' t'
            . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_id = t.term_id'
            . ' WHERE t.slug = :slug AND tt.taxonomy = :tax AND t.term_id != :except',
            ['slug' => $final, 'tax' => $taxonomy, 'except' => $exceptTermId]
        );

        if ((int) ($taken['n'] ?? 0) > 0) {
            throw new ValidationException('别名已被占用,换一个。', ['slug' => ['别名已被占用,换一个。']]);
        }

        return $final;
    }

    /**
     * 父分类合法性:到第二层为止、非兜底、不含自己与后代,且挂过去后整棵树不超过三层。
     * $excludeTermTaxonomyId=0 表示新建(没有要排除的自己)。
     */
    private function assertParent(int $excludeTermTaxonomyId, int $parent): void
    {
        if ($parent === 0) {
            return;
        }

        $items = $this->listAll(self::CATEGORY);
        $byId = [];

        foreach ($items as $item) {
            $byId[$item['term_taxonomy_id']] = $item;
        }

        $candidate = $byId[$parent] ?? null;

        if ($candidate === null || $candidate['slug'] === 'uncategorized' || $candidate['depth'] > 2) {
            throw new ValidationException('父分类不合法。', ['parent' => ['父分类不合法。']]);
        }

        if ($excludeTermTaxonomyId > 0 && in_array($parent, $this->withDescendants($items, $excludeTermTaxonomyId), true)) {
            throw new ValidationException('父分类不合法。', ['parent' => ['父分类不合法。']]);
        }

        if ($candidate['depth'] + $this->heightOf($items, $excludeTermTaxonomyId === 0 ? -1 : $excludeTermTaxonomyId) > 3) {
            throw new ValidationException('层级最多三层。', ['parent' => ['层级最多三层。']]);
        }
    }

    /**
     * $root 及其全部后代(含自己)。新父级不能从这里面挑(禁止选自己或自己的后代)。
     *
     * @param list<array{term_taxonomy_id:int, parent:int}> $items
     * @return list<int>
     */
    private function withDescendants(array $items, int $root): array
    {
        $banned = [$root];

        do {
            $added = false;

            foreach ($items as $item) {
                if (!in_array($item['term_taxonomy_id'], $banned, true) && in_array($item['parent'], $banned, true)) {
                    $banned[] = $item['term_taxonomy_id'];
                    $added = true;
                }
            }
        } while ($added);

        return $banned;
    }

    /**
     * 以 $root 为根的这一棵子树有几层(只有自己 = 1)。新建时传 -1(没有子树,恒 1)。
     *
     * @param list<array{term_taxonomy_id:int, parent:int}> $items
     */
    private function heightOf(array $items, int $root): int
    {
        $height = 1;

        foreach ($items as $item) {
            if ($item['parent'] === $root) {
                $height = max($height, 1 + $this->heightOf($items, $item['term_taxonomy_id']));
            }
        }

        return $height;
    }

    /**
     * 先按文章数降序、同数按名称 —— 列表与父候选共用的行序。
     *
     * @param array{count:int, name:string} $a
     * @param array{count:int, name:string} $b
     */
    private static function byCountThenName(array $a, array $b): int
    {
        if ($a['count'] !== $b['count']) {
            return $b['count'] <=> $a['count'];
        }

        return $a['name'] <=> $b['name'];
    }
}
