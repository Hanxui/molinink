<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

use Closure;
use Molin\Cache\CacheManager;
use Molin\Database\Database;
use Molin\Exception\DatabaseException;

/**
 * 流式内容查询,最终编译成参数化 SQL。
 *
 * 前台可见性三条规矩来自既有规范与
 * trash 不进前台、定时发布到点才可见、软删除的行一律排除。
 */
final class PostQuery
{
    /** 可排序列,取自既有规范的 posts 列名,杜绝把用户输入拼进 ORDER BY */
    private const ORDER_COLUMNS = [
        'id',
        'title',
        'slug',
        'menu_order',
        'comment_count',
        'view_count',
        'published_at',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<string> */
    private array $where = [];

    /** @var array<string, mixed> */
    private array $params = [];

    private string $orderBy = 'published_at';

    private string $orderDir = 'desc';

    /** 排序值并列时的次序:页面「序号」并列要按创建先后(id asc),其余场景维持默认 id desc */
    private string $tiebreakDir = 'desc';

    private int $limit = 10;

    private int $page = 1;

    private int $seq = 0;

    private ?string $status = null;

    private bool $withContent = false;

    private bool $withTrashed = false;

    /**
     * 把回收站里的行也放进来。后台的"回收站"这一栏非用不可 ——
     * 默认的 `deleted_at IS NULL` 会把它们全藏掉,人就再也还原/真删不了。
     */
    public function trashed(bool $with = true): self
    {
        $this->withTrashed = $with;

        return $this;
    }

    /**
     * 显式要正文。默认列表不取 content —— 一篇几万字 ×  20 条,首屏字节和内存都栽在它身上。
     */
    public function content(bool $with = true): self
    {
        $this->withContent = $with;

        return $this;
    }

    private function columns(): string
    {
        $columns = array_map(static fn (string $name): string => 'p.' . $name, PostRepository::COLUMNS);

        if ($this->withContent) {
            $columns[] = 'p.content';
        }

        // 固定链接结构需要主分类时,随行捎回一列(见 PostRepository::primaryCategoryColumn)
        if ($this->extraColumn !== null) {
            $columns[] = $this->extraColumn;
        }

        return implode(', ', $columns);
    }

    /**
     * @param ?string $extraColumn 附加列(形如 `(...) AS primary_category`),由仓储层按需给
     */
    public function __construct(
        private readonly Database $db,
        private readonly Closure $hydrate,
        private readonly ?string $extraColumn = null
    ) {
    }

    public function type(string $type): self
    {
        $this->where[] = 'p.type = ' . $this->bind('type', $type);

        return $this;
    }

    /**
     * 一次限定多个类型(既有规范in_main_list 的首页取文):空数组 = 不加条件。
     *
     * @param list<string> $types
     */
    public function types(array $types): self
    {
        $keep = [];

        foreach ($types as $type) {
            if (is_string($type) && $type !== '') {
                $keep[$type] = $type;
            }
        }

        if ($keep === []) {
            return $this;
        }

        $marks = [];

        foreach (array_values($keep) as $index => $type) {
            $marks[] = $this->bind('type' . $index, $type);
        }

        $this->where[] = 'p.type IN (' . implode(', ', $marks) . ')';

        return $this;
    }

    public function status(string $status): self
    {
        $this->status = $status;
        $this->where[] = 'p.status = ' . $this->bind('status', $status);

        return $this;
    }

    /**
     * 一次限定多个状态。后台"全部"这一栏要排除回收站,拼四个查询就是四次往返。
     *
     * @param list<string> $statuses
     */
    public function statuses(array $statuses): self
    {
        if ($statuses === []) {
            return $this;
        }

        $marks = [];

        foreach (array_values($statuses) as $index => $status) {
            $marks[] = $this->bind('status' . $index, $status);
        }

        $this->where[] = 'p.status IN (' . implode(', ', $marks) . ')';

        return $this;
    }

    public function category(string $slug): self
    {
        $this->termJoin('category', [$slug]);

        return $this;
    }

    /**
     * @param list<string> $slugs
     */
    public function tag(array $slugs): self
    {
        if ($slugs === []) {
            return $this;
        }

        $this->termJoin('post_tag', $slugs);

        return $this;
    }

    public function author(int $userId): self
    {
        if ($userId <= 0) {
            return $this;
        }

        $this->where[] = 'p.author_id = ' . $this->bind('author', (string) $userId);

        return $this;
    }

    /**
     * 按父页筛选(「全部父页面」下拉)。0 = 只看顶层,>0 = 该页的直接子页。
     */
    public function parent(int $postId): self
    {
        if ($postId < 0) {
            return $this;
        }

        $this->where[] = 'p.parent_id = ' . $this->bind('parent', (string) $postId);

        return $this;
    }

    /**
     * 关键词搜标题与正文。既有规范那套索引还没落地,后台列表的搜索框先吃 LIKE ——
     * 站点量级在这里不会成为问题,真到需要全文检索时换的是这个方法,不是调用方。
     */
    public function search(string $term): self
    {
        $term = trim($term);

        if ($term === '') {
            return $this;
        }

        // 两个占位符:PDO 不保证同名参数能绑两次
        $this->where[] = '(p.title LIKE ' . $this->bind('q', '%' . $term . '%')
            . ' OR p.content LIKE ' . $this->bind('q', '%' . $term . '%') . ')';

        return $this;
    }

    /**
     * 按"文章自己的日期"归月:已发布的看发布时间,草稿没有发布时间就退回创建时间。
     * 月份写成区间而不是 DATE_FORMAT(...) = ?,函数套在列上就用不上索引了。
     */
    public function inMonth(string $month): self
    {
        if (preg_match('#\A\d{4}-\d{2}\z#', $month) !== 1) {
            return $this;
        }

        $from = $month . '-01 00:00:00';
        $next = strtotime($from . ' UTC +1 month');

        if ($next === false) {
            return $this;
        }

        $column = 'COALESCE(p.published_at, p.created_at)';
        // 索引备注:函数套在列上,idx_type_status_del_pub 的 published_at 段在这里用不上,
        // 归档页按月份仍是索引扫描 + CONDITION 过滤。真慢到要管的时候,办法是给
        // (type, status, deleted_at, COALESCE(...)) 建函数索引 —— 本工包不动这条 SQL。
        $this->where[] = $column . ' >= ' . $this->bind('mfrom', $from)
            . ' AND ' . $column . ' < ' . $this->bind('mto', gmdate('Y-m-d H:i:s', $next));

        return $this;
    }

    /**
     * @param string $tiebreakDir 同值时的次序,默认 id desc(近者在前);页面「序号」并列要按创建先后时给 asc
     */
    public function orderBy(string $column, string $dir = 'desc', string $tiebreakDir = 'desc'): self
    {
        if (!in_array($column, self::ORDER_COLUMNS, true)) {
            throw new DatabaseException('不可排序的列: ' . $column);
        }

        $this->orderBy = $column;
        $this->orderDir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        $this->tiebreakDir = strtolower($tiebreakDir) === 'asc' ? 'asc' : 'desc';

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(1, min(200, $limit));

        return $this;
    }

    public function page(int $page): self
    {
        $this->page = max(1, $page);

        return $this;
    }

    /**
     * @return list<Post>
     */
    public function get(): array
    {
        // 索引备注(2026-10,不改 SQL):默认列表 = type + status='publish' + deleted_at IS NULL +
        // ORDER BY published_at,正好由迁移 idx_type_status_del_pub 覆盖;
        // 但按 comment_count / view_count 排序的列表没有对应索引,仍会 filesort(量级到十万级再议)。
        $sql = 'SELECT ' . $this->columns() . ' FROM ' . $this->db->table('posts') . ' p'
            . ($this->joins === [] ? '' : ' ' . implode(' ', $this->joins))
            . $this->whereClause()
            // 联表会让一篇命中多分类时重复出现,按主键收拢(p.id 是主键,取其余列合法)
            . ($this->joins === [] ? '' : ' GROUP BY p.id')
            . ' ORDER BY p.' . $this->orderBy . ' ' . $this->orderDir . ', p.id ' . $this->tiebreakDir
            . ' LIMIT ' . $this->limit . ' OFFSET ' . (($this->page - 1) * $this->limit);

        $manager = CacheManager::instance();

        if (!$this->cacheable($manager)) {
            return $this->hydrateAll($this->db->select($sql, $this->params));
        }

        // 键 = SQL + 绑定参数的哈希(§5:禁用用户输入直拼键;分页/筛选天然进哈希,各存各的)
        return $manager->remember(
            'post:list:' . hash('sha256', $sql . '|' . serialize($this->params)),
            null,
            fn (): array => $this->hydrateAll($this->db->select($sql, $this->params))
        );
    }

    /**
     * 上/下篇:在当前筛选条件内,按发布时间紧邻当前篇的两篇。
     *
     * 「上一篇」= 比当前新的那条,「下一篇」= 比当前旧的那条;时间并列时用 id 破,
     * 与列表的 `published_at DESC, id DESC` 稳定序同一口径。当前篇没有时间
     * (草稿 / 页面)时没有可靠的相邻序,两边都给 null,由展示层决定不渲染。
     *
     * @return array{prev:?Post, next:?Post}
     */
    public function neighbors(int $id, ?string $publishedAt): array
    {
        $stamp = trim((string) $publishedAt);

        if ($id <= 0 || $stamp === '') {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $this->adjacent('>', $stamp, $id, 'asc'),
            'next' => $this->adjacent('<', $stamp, $id, 'desc'),
        ];
    }

    /**
     * 在全部命中里随机取一篇(既有规范404 页的「或许你想看」)。
     *
     * 2026-10 改:`ORDER BY RAND()` 会把整个命中集排序一遍,而 404 页不落整页缓存 ——
     * 爬虫刷 404 就是反复全表随机。改成「先 COUNT 拿总数 → 随机偏移取一行」:
     * 第一条是计数,第二条按主键序扫过 offset+1 行取一行,代价与命中量线性但只有一次,
     * 不再是每次都对全表做随机排序。返回语义不变:仍是命中集里随机的一条,无命中返回 null。
     */
    public function random(): ?Post
    {
        $total = $this->count();

        if ($total <= 0) {
            return null;
        }

        $clone = clone $this;
        $clone->limit = 1;
        $clone->page = 1;

        $row = $this->db->selectOne(
            'SELECT ' . $clone->columns() . ' FROM ' . $this->db->table('posts') . ' p'
            . ($clone->joins === [] ? '' : ' ' . implode(' ', $clone->joins))
            . $clone->whereClause()
            . ($clone->joins === [] ? '' : ' GROUP BY p.id')
            // 主键序保证偏移稳定;OFFSET 是服务端算出的整数,不来自输入
            . ' ORDER BY p.id DESC LIMIT ' . self::randomOffset($total) . ', 1',
            $clone->params
        );

        return $row === null ? null : ($this->hydrate)($row);
    }

    /**
     * 随机偏移:0..total-1。单条命中时恒为 0,不白跑 random_int。
     */
    private static function randomOffset(int $total): int
    {
        return $total <= 1 ? 0 : random_int(0, $total - 1);
    }

    /**
     * 一条紧邻项。`(published_at, id)` 的字典序比较 —— 时间相等时靠 id 分先后。
     */
    private function adjacent(string $operator, string $stamp, int $id, string $direction): ?Post
    {
        $clone = clone $this;
        // 同一个命名占位符只能绑一次(PDO 不保证重名复用),时间出现两处就绑两回
        $first = $clone->bind('adj_at_a', $stamp);
        $second = $clone->bind('adj_at_b', $stamp);
        $other = $clone->bind('adj_id', (string) $id);
        $clone->where[] = '(p.published_at ' . $operator . ' ' . $first
            . ' OR (p.published_at = ' . $second . ' AND p.id ' . $operator . ' ' . $other . '))';
        $clone->orderBy = 'published_at';
        $clone->orderDir = $direction;
        $clone->limit = 1;
        $clone->page = 1;

        return $clone->get()[0] ?? null;
    }

    public function count(): int
    {
        $sql = 'SELECT COUNT(DISTINCT p.id) AS total FROM ' . $this->db->table('posts') . ' p'
            . ($this->joins === [] ? '' : ' ' . implode(' ', $this->joins))
            . $this->whereClause();

        $manager = CacheManager::instance();

        if (!$this->cacheable($manager)) {
            return $this->countRows($sql);
        }

        return $manager->remember(
            'post:count:' . hash('sha256', $sql . '|' . serialize($this->params)),
            null,
            fn (): int => $this->countRows($sql)
        );
    }

    /**
     * 这一趟该不该进缓存(既有规范)。
     *
     * 两条硬闸:① 缓存没接上(backend=null 或本进程未装配)就回源;
     * ② **事务未提交时不缓存** —— 回滚掉的行留在缓存里就是脏读,缓存永远不能成为正确性来源。
     */
    private function cacheable(CacheManager $manager): bool
    {
        if (!$manager->enabled()) {
            return false;
        }

        return !$this->db->pdo()->inTransaction();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Post>
     */
    private function hydrateAll(array $rows): array
    {
        $posts = [];

        foreach ($rows as $row) {
            /** @var Post $post */
            $post = ($this->hydrate)($row);
            $posts[] = $post;
        }

        return $posts;
    }

    private function countRows(string $sql): int
    {
        $row = $this->db->selectOne($sql, $this->params);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param list<string> $slugs
     */
    private function termJoin(string $taxonomy, array $slugs): void
    {
        $taxKey = $this->bind('tax_' . $taxonomy, $taxonomy);
        $marks = [];

        foreach ($slugs as $slug) {
            $marks[] = $this->bind('term_slug', $slug);
        }

        $this->joins[] = 'INNER JOIN ' . $this->db->table('term_relationships') . ' tr ON tr.post_id = p.id';
        $this->joins[] = 'INNER JOIN ' . $this->db->table('term_taxonomy')
            . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = ' . $taxKey;
        $this->joins[] = 'INNER JOIN ' . $this->db->table('terms')
            . ' t ON t.term_id = tt.term_id AND t.slug IN (' . implode(', ', $marks) . ')';
    }

    private function whereClause(): string
    {
        $conditions = $this->where;

        if (!$this->withTrashed) {
            $conditions[] = 'p.deleted_at IS NULL';
        }

        if ($this->status === 'publish') {
            $conditions[] = 'p.published_at <= UTC_TIMESTAMP()';
        }

        return ' WHERE ' . implode(' AND ', $conditions);
    }

    private function bind(string $key, string $value): string
    {
        $name = $key . '_' . (++$this->seq);
        $this->params[':' . $name] = $value;

        return ':' . $name;
    }
}
