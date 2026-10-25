<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

/**
 * 内容对象。模板可见字段是契约,见《13-主题目录约定》第 6.4 节。
 *
 * author(User)、thumbnail(Media|null)、terms(Term[])、meta(array)、blocks(Block[])
 * 依赖用户 / 媒体 / 分类法 / 区块各模块,按"契约只增不改"后续增量补齐;
 * 本类先交付 posts 表能直接给出的字段。
 */
final class Post
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $status,
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly ?string $content,
        public readonly ?string $published_at,
        public readonly string $updated_at,
        public readonly int $thumbnail_id,
        public readonly string $url,
        /** 归属判定要用(《32》第 4 节:own 类能力还要验作者) */
        public readonly int $author_id = 0,
        /** 编辑页「讨论」盒那一格要读它(《79》§4.7 第 5 盒) */
        public readonly string $comment_status = 'open',
        /** 列表页元信息「N 条评论」(《88》§4);计数由评论模块维护 */
        public readonly int $comment_count = 0,
        /** 浏览量(《13》§6.3 列表项的 views);由内核维护,主题只读 */
        public readonly int $view_count = 0
    ) {
    }

    /**
     * @param array<string, mixed> $row posts 表的一行
     */
    public static function fromRow(array $row, string $url): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['type'] ?? 'post'),
            (string) ($row['status'] ?? 'draft'),
            (string) ($row['title'] ?? ''),
            (string) ($row['slug'] ?? ''),
            self::nullable($row, 'excerpt'),
            self::nullable($row, 'content'),
            self::nullable($row, 'published_at'),
            (string) ($row['updated_at'] ?? ''),
            (int) ($row['thumbnail_id'] ?? 0),
            $url,
            (int) ($row['author_id'] ?? 0),
            (string) ($row['comment_status'] ?? 'open'),
            (int) ($row['comment_count'] ?? 0),
            (int) ($row['view_count'] ?? 0)
        );
    }

    /**
     * 列表查询故意不取 content / excerpt 这类大字段(《57》第 5 节),
     * 所以这里要接受"键根本不存在",而不是只有 null。
     *
     * @param array<string, mixed> $row
     */
    private static function nullable(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Post 是只读值对象,渲染后的正文只能落在副本上。
     */
    public function withContent(string $content): self
    {
        return $this->copy($content);
    }

    /**
     * 记过浏览量之后留在副本上:这一次请求刚 +1,页面该显示新数而不是库里读出来的旧值。
     */
    public function withViewCount(int $viewCount): self
    {
        return $this->copy(null, $viewCount);
    }

    private function copy(?string $content = null, ?int $viewCount = null): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            status: $this->status,
            title: $this->title,
            slug: $this->slug,
            excerpt: $this->excerpt,
            content: $content ?? $this->content,
            published_at: $this->published_at,
            updated_at: $this->updated_at,
            thumbnail_id: $this->thumbnail_id,
            url: $this->url,
            author_id: $this->author_id,
            comment_status: $this->comment_status,
            comment_count: $this->comment_count,
            view_count: $viewCount ?? $this->view_count
        );
    }
}
