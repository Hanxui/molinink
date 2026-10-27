<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

use Molin\Database\Database;

/**
 * 媒体读写。
 *
 * 一条附件占两行:`posts`(`type=attachment`、`status=inherit`、`parent_id` 指向所属内容)
 * 是本体,`media` 存扩展信息(路径、尺寸、alt、说明、各档 sizes)。
 * 删除走 `posts` 的软删(见 Remover),`media` 跟着本体活着 —— 定时清理才物理删文件。
 */
final class MediaRepository
{
    /**
     * 页签的三类(图片 / 文档 / 音视频)。
     * 白名单里的 zip 与其余非图片非音视频的都归"文档" —— 这三段 SQL 是常量,不接用户输入。
     */
    private const CATEGORIES = [
        'image' => "(m.mime_type LIKE 'image/%')",
        'av' => "(m.mime_type LIKE 'video/%' OR m.mime_type LIKE 'audio/%')",
        'doc' => "(m.mime_type NOT LIKE 'image/%' AND m.mime_type NOT LIKE 'video/%' AND m.mime_type NOT LIKE 'audio/%')",
    ];

    /** 排序取自既有规范那个下拉的四个选项,列名钉死在这里 */
    private const ORDERS = [
        '' => 'm.id DESC',
        'new' => 'm.id DESC',
        'old' => 'm.id ASC',
        'name' => 'm.file_name ASC',
        'size' => 'm.file_size DESC',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 一条 media 记录 + 它的 posts 本体,一次事务写完。
     *
     * @param array<string, mixed> $media 既有规范的字段(sizes 传数组,内部转 JSON)
     * @param array<string, mixed> $attachment 本体行:至少给 title / parent_id / author_id
     * @return int media.id
     */
    public function create(array $media, array $attachment): int
    {
        return (int) $this->db->transaction(function () use ($media, $attachment): int {
            $now = gmdate('Y-m-d H:i:s');
            $title = trim((string) ($attachment['title'] ?? ''));

            $postId = $this->db->insert('posts', [
                'type' => 'attachment',
                'status' => 'inherit',
                'author_id' => (int) ($attachment['author_id'] ?? 0),
                // 附件不套正文的状态机:它是被内容"拥有"的,状态随父
                'title' => $title === '' ? '未命名附件' : $title,
                // 附件没有固定链接(不进 post 路由),短名只要不与别人的撞车即可:文件名本身已含时间戳与随机段
                'slug' => (string) ($attachment['slug'] ?? ('attachment-' . $now . '-' . bin2hex(random_bytes(3)))),
                'excerpt' => null,
                'content' => null,
                'parent_id' => (int) ($attachment['parent_id'] ?? 0),
                'menu_order' => 0,
                'comment_status' => 'closed',
                'thumbnail_id' => 0,
                'password' => null,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $sizes = $media['sizes'] ?? null;

            return $this->db->insert('media', [
                'post_id' => $postId,
                'file_path' => (string) $media['file_path'],
                'file_name' => (string) $media['file_name'],
                'mime_type' => (string) $media['mime_type'],
                'file_size' => (int) ($media['file_size'] ?? 0),
                'width' => $media['width'] === null ? null : (int) $media['width'],
                'height' => $media['height'] === null ? null : (int) $media['height'],
                'alt_text' => $media['alt_text'] === null ? null : (string) $media['alt_text'],
                'caption' => $media['caption'] === null ? null : (string) $media['caption'],
                'sizes' => is_array($sizes) && $sizes !== [] ? json_encode($sizes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'hash' => (string) ($media['hash'] ?? ''),
                'uploaded_by' => (int) ($media['uploaded_by'] ?? 0),
                'created_at' => $now,
            ]);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT m.*, p.title, p.parent_id, p.status, p.author_id, (p.deleted_at IS NOT NULL) AS is_removed
             FROM ' . $this->db->table('media') . ' m
             JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id
             WHERE m.id = :id',
            ['id' => $id]
        );

        return $row === null ? null : $this->shape($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byPostId(int $postId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT m.*, p.title, p.parent_id, p.status, p.author_id
             FROM ' . $this->db->table('media') . ' m
             JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id
             WHERE m.post_id = :id',
            ['id' => $postId]
        );

        return $row === null ? null : $this->shape($row);
    }

    /**
     * 一次取回多条 media,给列表页拼特色图用 —— 一页最多 100 篇,不能一篇一条地查。
     *
     * 入参收的是**附件本体 id**:`posts.thumbnail_id` 存的正是这一列
     * (口径与后台缩略图路由 `posts/{id}/thumbnail`、`references()` 的判定一致)。
     *
     * @param list<int> $postIds 附件本体 id(media.post_id)
     * @return array<int, array<string, mixed>> 键 = 附件本体 id
     */
    public function byPostIds(array $postIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $postIds,
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $key = 'id' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $rows = $this->db->select(
            'SELECT m.*, p.title, p.parent_id, p.status, p.author_id
             FROM ' . $this->db->table('media') . ' m
             JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id
             WHERE m.post_id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $media = $this->shape($row);
            $out[(int) $media['post_id']] = $media;
        }

        return $out;
    }

    /**
     * 把一条 media 记录拼成模板直接能用的图片数据。
     *
     * 列表页的特色图与主题设置里的 logo / 大图都走这一把尺:
     * `url` 是默认档(medium → large → xlarge → thumb → 原图),`srcset` 收全部衍生档,
     * `sizes` 给按名点档用(模板 `image(img, 'large')`)。
     *
     * 盘上没有真文件、或那条附件不是图片 → null,调用方据此落回无图版式。
     *
     * @param array<string, mixed> $media find()/byPostId(s) 的返回
     * @return array<string, mixed>|null
     */
    public static function imageData(array $media, Store $store): ?array
    {
        $master = (string) ($media['file_path'] ?? '');

        if ($master === '' || !str_starts_with((string) ($media['mime_type'] ?? ''), 'image/') || !$store->exists($master)) {
            return null;
        }

        // 衍生档:盘上有真文件的才收(指向原图的那份跳过,原图由 src 自己承担)
        $variants = [];

        foreach ((array) ($media['sizes'] ?? []) as $name => $size) {
            $file = (string) ($size['file'] ?? '');
            $width = (int) ($size['width'] ?? 0);

            if ($file === '' || $file === $master || $width <= 0 || !$store->exists($file)) {
                continue;
            }

            $variants[(string) $name] = [
                'url' => $store->url($file),
                'width' => $width,
                'height' => (int) ($size['height'] ?? 0),
            ];
        }

        $pick = $variants['medium'] ?? $variants['large'] ?? $variants['xlarge'] ?? $variants['thumb'] ?? null;
        $srcset = [];

        foreach ($variants as $variant) {
            $srcset[] = $variant['url'] . ' ' . $variant['width'] . 'w';
        }

        return [
            'url' => $pick['url'] ?? $store->url($master),
            'srcset' => implode(', ', $srcset),
            'alt' => (string) ($media['alt'] ?? ''),
            'width' => (int) ($pick['width'] ?? $media['width'] ?? 0),
            'height' => (int) ($pick['height'] ?? $media['height'] ?? 0),
            'sizes' => $variants,
        ];
    }

    /**
     * 按落盘相对路径反查一条 media(App 换头像时把 URL 还原成 media.id 用)。
     *
     * App 手里只有 `avatar_url`,是 `/uploads/年/月/名` 或整条绝对 URL;库里 `file_path`
     * 存的正是 `年/月/名`。这里只认原样路径,不替调用方拼站点前缀 —— 前缀去掉后剩下的部分,
     * 与 `Store::relativePath()` 的口径一字不差。
     *
     * @return array<string, mixed>|null
     */
    public function findByPath(string $filePath): ?array
    {
        $path = ltrim($filePath, '/');

        if (str_contains($path, '://')) {
            $path = ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        }

        $path = (string) preg_replace('#\?.*$#', '', $path);

        if (str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
        }

        if ($path === '' || !str_contains($path, '/')) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT m.*, p.title, p.parent_id, p.status, p.author_id, (p.deleted_at IS NOT NULL) AS is_removed
             FROM ' . $this->db->table('media') . ' m
             JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id
             WHERE m.file_path = :path AND p.deleted_at IS NULL
             ORDER BY m.id DESC LIMIT 1',
            ['path' => $path]
        );

        return $row === null ? null : $this->shape($row);
    }

    /**
     * 媒体库浏览(既有规范):页签 + 月份 + 关键词 + 排序 + 分页。
     *
     * 每一项都带 `ref_count` —— 卡片要在未引用项的元信息尾部标暖赭「未引用」,
     * 列表视图「引用」列要的是数字,一次查完,不在循环里逐条数引用。
     * `tab=removed` 看的是已软删的那批(待清理栏),其余页签一律排除软删行。
     *
     * @param array{tab?:string, month?:string, q?:string, order?:string} $filters
     * @return array{items:list<array<string, mixed>>, total:int}
     */
    public function browse(array $filters, int $page = 1, int $perPage = 9): array
    {
        [$where, $params] = $this->clause($filters);
        $removed = (($filters['tab'] ?? '') === 'removed');
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $from = 'FROM ' . $this->db->table('media') . ' m'
            . ' JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id'
            . ' WHERE ' . ($removed ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL') . $where;

        $total = (int) ($this->db->selectOne('SELECT COUNT(*) AS n ' . $from, $params)['n'] ?? 0);
        $order = self::ORDERS[(string) ($filters['order'] ?? '')] ?? self::ORDERS[''];
        $rows = $this->db->select(
            'SELECT m.*, p.title, p.parent_id, p.status, p.author_id, ' . $this->refCountSql() . ' AS ref_count,'
            . ' (p.deleted_at IS NOT NULL) AS is_removed '
            . $from . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->shape($row) + ['ref_count' => (int) ($row['ref_count'] ?? 0)];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 页签计数与已用空间(既有规范「待清理」)。一次查完。
     *
     * 统计条跟着页签走,所以每一档都要自己的项数与字节;
     * `removed` 是已软删的那批 —— 它们不占"在用"的空间,但清理工位要看得见总量。
     *
     * @return array<string, array{n:int, bytes:int}> 键 = 页签值(all/image/doc/av/unref/removed)
     */
    public function tally(): array
    {
        $live = 'p.deleted_at IS NULL';
        $image = "(m.mime_type LIKE 'image/%')";
        $av = "(m.mime_type LIKE 'video/%' OR m.mime_type LIKE 'audio/%')";
        $doc = "(m.mime_type NOT LIKE 'image/%' AND m.mime_type NOT LIKE 'video/%' AND m.mime_type NOT LIKE 'audio/%')";
        $referenced = $this->referencedSql();

        $row = $this->db->selectOne(
            'SELECT'
            . ' SUM(CASE WHEN ' . $live . ' THEN 1 ELSE 0 END) AS n_all'
            . ', SUM(CASE WHEN ' . $live . ' THEN m.file_size ELSE 0 END) AS b_all'
            . ', SUM(CASE WHEN ' . $live . ' AND ' . $image . ' THEN 1 ELSE 0 END) AS n_image'
            . ', SUM(CASE WHEN ' . $live . ' AND ' . $image . ' THEN m.file_size ELSE 0 END) AS b_image'
            . ', SUM(CASE WHEN ' . $live . ' AND ' . $av . ' THEN 1 ELSE 0 END) AS n_av'
            . ', SUM(CASE WHEN ' . $live . ' AND ' . $av . ' THEN m.file_size ELSE 0 END) AS b_av'
            . ', SUM(CASE WHEN ' . $live . ' AND ' . $doc . ' THEN 1 ELSE 0 END) AS n_doc'
            . ', SUM(CASE WHEN ' . $live . ' AND ' . $doc . ' THEN m.file_size ELSE 0 END) AS b_doc'
            . ', SUM(CASE WHEN ' . $live . ' AND NOT ' . $referenced . ' THEN 1 ELSE 0 END) AS n_unref'
            . ', SUM(CASE WHEN ' . $live . ' AND NOT ' . $referenced . ' THEN m.file_size ELSE 0 END) AS b_unref'
            . ', SUM(CASE WHEN p.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS n_removed'
            . ', SUM(CASE WHEN p.deleted_at IS NOT NULL THEN m.file_size ELSE 0 END) AS b_removed'
            . ' FROM ' . $this->db->table('media') . ' m'
            . ' JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id'
        );

        $out = [];

        foreach (['all', 'image', 'doc', 'av', 'unref', 'removed'] as $key) {
            $out[$key] = [
                'n' => (int) ($row['n_' . $key] ?? 0),
                'bytes' => (int) ($row['b_' . $key] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * 有哪些内容在用这条附件(既有规范「被 N 篇文章引用」下面的那几条链接)。
     *
     * @return list<array{id:int, type:string, slug:string, title:string}>
     */
    public function referencedBy(int $id, int $limit = 10): array
    {
        $media = $this->find($id);

        if ($media === null) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT id, type, slug, title FROM ' . $this->db->table('posts')
            . " WHERE id <> :self AND deleted_at IS NULL AND status <> 'trash'"
            . ' AND (thumbnail_id = :attachment OR content LIKE :needle)'
            . ' ORDER BY published_at DESC LIMIT ' . max(1, min(50, $limit)),
            [
                'self' => (int) $media['post_id'],
                'attachment' => (int) $media['post_id'],
                'needle' => '%' . self::escapeLike((string) $media['file_name']) . '%',
            ]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
            ];
        }

        return $out;
    }

    /**
     * 附件页的引用列表(既有规范条先按此实现):反查带用途与发布时间,新的在前。
     *
     * 与 referencedBy()/references() 同一套条件(特色图片或正文点名,回收站与已删不算),
     * 只是多带 published_at 和「怎么用的」,给附件页的每行用。
     *
     * @return list<array{id:int, type:string, slug:string, title:string, published_at:string, usage:string}>
     */
    public function usages(int $id, int $limit = 20): array
    {
        $media = $this->find($id);

        if ($media === null) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT id, type, slug, title, published_at, thumbnail_id FROM ' . $this->db->table('posts')
            . " WHERE id <> :self AND deleted_at IS NULL AND status <> 'trash'"
            . ' AND (thumbnail_id = :attachment OR content LIKE :needle)'
            . ' ORDER BY published_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)),
            [
                'self' => (int) $media['post_id'],
                'attachment' => (int) $media['post_id'],
                'needle' => '%' . self::escapeLike((string) $media['file_name']) . '%',
            ]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'slug' => (string) $row['slug'],
                'title' => (string) $row['title'],
                'published_at' => (string) ($row['published_at'] ?? ''),
                'usage' => (int) $row['thumbnail_id'] === (int) $media['post_id'] ? 'featured' : 'embed',
            ];
        }

        return $out;
    }

    /**
     * 这条附件是不是被哪篇内容当特色图片用着(既有规范条:h1 后缀「— 特色图片」的判定)。
     */
    public function featuredBy(int $id): bool
    {
        $media = $this->find($id);

        if ($media === null) {
            return false;
        }

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('posts')
            . " WHERE id <> :self AND deleted_at IS NULL AND status <> 'trash' AND thumbnail_id = :attachment",
            [
                'self' => (int) $media['post_id'],
                'attachment' => (int) $media['post_id'],
            ]
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    /**
     * 库里出现过的年月(既有规范「全部时间」下面那些选项),从落盘路径年/月取。
     *
     * @return list<string> 形如 `2026/09`,新在前
     */
    public function months(): array
    {
        $rows = $this->db->select(
            'SELECT LEFT(m.file_path, 7) AS ym FROM ' . $this->db->table('media') . ' m'
            . ' JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id'
            . ' WHERE p.deleted_at IS NULL'
            . ' GROUP BY ym ORDER BY ym DESC LIMIT 24'
        );

        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['ym'] ?? ''),
            $rows
        ), static fn (string $ym): bool => $ym !== ''));
    }

    /**
     * @param array{tab?:string, month?:string, q?:string, order?:string} $filters
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function clause(array $filters): array
    {
        $where = '';
        $params = [];
        $tab = (string) ($filters['tab'] ?? '');

        if (isset(self::CATEGORIES[$tab])) {
            $where .= ' AND ' . self::CATEGORIES[$tab];
        }

        $month = (string) ($filters['month'] ?? '');

        if (preg_match('#\A[0-9]{4}/[0-9]{2}\z#', $month) === 1) {
            $where .= ' AND m.file_path LIKE :month';
            $params['month'] = $month . '/%';
        }

        $keyword = trim((string) ($filters['q'] ?? ''));

        if ($keyword !== '') {
            $where .= ' AND m.file_name LIKE :q';
            $params['q'] = '%' . self::escapeLike($keyword) . '%';
        }

        if ($tab === 'unref') {
            $where .= ' AND NOT ' . $this->referencedSql();
        }

        return [$where, $params];
    }

    /**
     * 「被用着」的判定(既有规范口径:当特色图片用的,或正文里写了这个文件名的,
     * 外加**被当头像用的**)。回收站里的与被删掉的那些都不算,与 references() 同一套条件。
     *
     * 这里必须用 `SELECT 1` 而不是 `SELECT COUNT(*)`:聚合子句永远返回一行,
     * EXISTS 也就永远为真 —— 「未引用」页签会一条都筛不出来。
     */
    private function referencedSql(): string
    {
        return 'EXISTS(SELECT 1 FROM ' . $this->refFrom() . ')';
    }

    /**
     * 数一数被多少篇内容用着(既有规范「引用」列要的是数字,不是有/无)。
     */
    private function refCountSql(): string
    {
        return '(SELECT COUNT(*) FROM ' . $this->refFrom() . ')';
    }

    /**
     * 两处判定共用的一段 FROM 子句 —— 参数是外层查询里的 `m` 行,不是绑定值。
     * 两路并集:内容在用(特色图 / 正文点名),以及**被当头像用** ——
     * `usermeta.avatar` 存的是 media.id,拿它当头像的账号同样在用这个文件。
     * 做成派生表,EXISTS 与 COUNT(*) 两头都能直接套。
     */
    private function refFrom(): string
    {
        $posts = $this->db->table('posts');
        $meta = $this->db->table('usermeta');

        return '('
            . "SELECT 1 FROM {$posts} q WHERE q.id <> m.post_id AND q.deleted_at IS NULL"
            . " AND q.status <> 'trash' AND (q.thumbnail_id = m.post_id OR q.content LIKE CONCAT('%', m.file_name, '%'))"
            . ' UNION ALL '
            . "SELECT 1 FROM {$meta} u WHERE u.meta_key = 'avatar' AND u.meta_value = m.id"
            . ') r';
    }

    /**
     * LIKE 的两个通配符要按字面量看待,否则搜 `%` 会变成"匹配一切"。
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function count(bool $withRemoved = false): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('media') . ' m'
            . ' JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id'
            . ($withRemoved ? '' : ' WHERE p.deleted_at IS NULL')
        );

        return (int) ($row['n'] ?? 0);
    }

    /**
     * 软删:只打"待清理"标记,文件先不动。
     *
     * 附件不走内容的状态机 —— 它的状态是 inherit(随父),删除归媒体库自己管,
     * 所以这里只写 `deleted_at`,不去碰 `status`。所有常规查询都带 `deleted_at IS NULL`,
     * 一条已软删的附件因此自动从媒体库与选择器里消失。
     *
     * @return array<string, mixed>|null
     */
    public function softDelete(int $id): ?array
    {
        return $this->markRemoved($id, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restore(int $id): ?array
    {
        return $this->markRemoved($id, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function markRemoved(int $id, bool $removed): ?array
    {
        $media = $this->find($id);

        if ($media === null) {
            return null;
        }

        $this->db->update('posts', [
            'deleted_at' => $removed ? gmdate('Y-m-d H:i:s') : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $media['post_id']]);

        return $media;
    }

    /**
     * 改 alt / 说明(既有规范"编辑:alt、标题、说明")。标题在 posts 本体上。
     *
     * @param array{alt?:string, caption?:string, title?:string} $edits
     */
    public function updateText(int $id, array $edits): void
    {
        $media = $this->find($id);

        if ($media === null) {
            return;
        }

        $mediaValues = [];

        if (array_key_exists('alt', $edits)) {
            $mediaValues['alt_text'] = trim((string) $edits['alt']);
        }

        if (array_key_exists('caption', $edits)) {
            $mediaValues['caption'] = trim((string) $edits['caption']);
        }

        if ($mediaValues !== []) {
            $this->db->update('media', $mediaValues, 'id = :id', ['id' => $id]);
        }

        if (array_key_exists('title', $edits)) {
            $title = trim((string) $edits['title']);

            if ($title !== '') {
                $this->db->update('posts', ['title' => $title, 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $media['post_id']]);
            }
        }
    }

    /**
     * 被多少处引用(删除时先提示"被 N 处引用")。
     * 算三处:当特色图片用的、正文里写了这个文件名的,以及**被当头像用的**。回收站里的那篇自己不算。
     */
    public function references(int $id): int
    {
        $media = $this->find($id);

        if ($media === null) {
            return 0;
        }

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('posts')
            . " WHERE id <> :self AND deleted_at IS NULL AND status <> 'trash'"
            . ' AND (thumbnail_id = :attachment OR content LIKE :needle)',
            [
                'self' => (int) $media['post_id'],
                'attachment' => (int) $media['post_id'],
                'needle' => '%' . self::escapeLike((string) $media['file_name']) . '%',
            ]
        );

        $avatars = $this->db->selectOne(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('usermeta')
            . " WHERE meta_key = 'avatar' AND meta_value = :mid",
            ['mid' => $id]
        );

        return (int) ($row['n'] ?? 0) + (int) ($avatars['n'] ?? 0);
    }

    /**
     * 拿这条附件当头像的账号(引用列表要把头像那一路也露出来,
     * 否则计数里有它、列表里没它,对不上)。
     *
     * @return list<array{id:int, title:string}>
     */
    public function avatarUsers(int $id, int $limit = 10): array
    {
        $rows = $this->db->select(
            'SELECT u.id, u.username, u.display_name FROM ' . $this->db->table('usermeta') . ' m'
            . ' JOIN ' . $this->db->table('users') . ' u ON u.id = m.user_id'
            . " WHERE m.meta_key = 'avatar' AND m.meta_value = :mid"
            . ' ORDER BY u.id ASC LIMIT ' . max(1, min(50, $limit)),
            ['mid' => $id]
        );

        $out = [];

        foreach ($rows as $row) {
            $name = (string) ($row['display_name'] ?? '');

            $out[] = [
                'id' => (int) $row['id'],
                'title' => $name !== '' ? $name : (string) $row['username'],
            ];
        }

        return $out;
    }

    /**
     * 物理清账(既有规范清理动作的手动版):media 行 + posts 本体 + 它的 meta。
     * 文件不在这儿删 —— 盘上归 `Remover`/`Store` 管,这里只管账。
     */
    public function purge(int $id): bool
    {
        $media = $this->find($id);

        if ($media === null) {
            return false;
        }

        $postId = (int) $media['post_id'];
        $db = $this->db;

        $db->transaction(static function () use ($db, $id, $postId): void {
            // 附件也可能有 meta(比如被谁当过特色图片的反向记录),账要一起清干净
            $db->delete('postmeta', 'post_id = :p', ['p' => $postId]);
            $db->delete('media', 'id = :m', ['m' => $id]);
            $db->delete('posts', 'id = :p', ['p' => $postId]);
        });

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $sizes = json_decode((string) ($row['sizes'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'post_id' => (int) $row['post_id'],
            'file_path' => (string) $row['file_path'],
            'file_name' => (string) $row['file_name'],
            'mime_type' => (string) $row['mime_type'],
            'file_size' => (int) $row['file_size'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
            'alt' => (string) ($row['alt_text'] ?? ''),
            'caption' => (string) ($row['caption'] ?? ''),
            'sizes' => is_array($sizes) ? $sizes : [],
            'hash' => (string) ($row['hash'] ?? ''),
            'uploaded_by' => (int) ($row['uploaded_by'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'parent_id' => (int) ($row['parent_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'removed' => (int) ($row['is_removed'] ?? 0) === 1,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
