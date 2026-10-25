<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Widget;

use Closure;
use Molin\Comment\CommentRepository;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\TermAssigner;
use Molin\Router\Router;

/**
 * 内核自带的五个挂件(《108》§11-5 的极简示例集),行序即后台左栏行序。
 *
 * 取数都落在这里:分类/标签查词条表,最新评论查评论表,搜索框出表单,
 * 文章目录从当前那篇文章的正文里抽小标题。样式一概不管 —— 只出语义标签与最少的结构 class。
 *
 * 服务可缺:后台左栏只要名称与说明,那一路不带仓储也得列得出来(见 WidgetController)。
 * 缺服务的取数口如实返回空串,不假装有数据。
 */
final class Builtin
{
    /** key => [名称, 一句话说明] */
    private const DEFS = [
        'toc'        => ['文章目录', '列出本文的小标题'],
        'comments'   => ['最新评论', '最近几条通过审核的评论'],
        'categories' => ['分类', '分类清单与文章数'],
        'tags'       => ['标签云', '按出现次数排开的标签'],
        'search'     => ['搜索框', '一个指向搜索页的输入框'],
    ];

    /** 最新评论取几条 */
    private const COMMENT_LIMIT = 5;

    /** 评论摘要截断字数 */
    private const SUMMARY_LIMIT = 40;

    /**
     * 把五个内置挂件登记进注册表。已登记过的(含插件先注册的)不动 —— Registry 同名第一次赢。
     *
     * @param Router|null            $router   搜索框与词条链接的地址生成
     * @param TermAssigner|null      $terms    分类 / 标签的词条数据
     * @param CommentRepository|null $comments 最新评论的评论数据
     * @param PostRepository|null    $posts    评论所属文章的地址
     */
    public static function register(
        Registry $registry,
        ?Router $router = null,
        ?TermAssigner $terms = null,
        ?CommentRepository $comments = null,
        ?PostRepository $posts = null
    ): void {
        // 取数口按 key 一次建好:DEFS 里加了新挂件却忘了配取数口,静态分析就会当场报出来
        $bodies = [
            'toc'        => self::toc(),
            'comments'   => self::comments($comments, $posts),
            'categories' => self::categories($terms, $router),
            'tags'       => self::tags($terms, $router),
            'search'     => self::search($router),
        ];

        foreach (self::DEFS as $key => [$name, $desc]) {
            $registry->register($key, $name, $desc, $bodies[$key]);
        }
    }

    /** 分类清单 + 每类文章数。内核兜底的「未分类」不露脸(它只是没归类的落点,不是给读者看的目录) */
    private static function categories(?TermAssigner $terms, ?Router $router): Closure
    {
        return static function (array $ctx) use ($terms, $router): string {
            if ($terms === null || $router === null || ($terms->counts()[TermAssigner::CATEGORY] ?? 0) === 0) {
                return '';
            }

            $items = [];

            foreach ($terms->listAll(TermAssigner::CATEGORY) as $term) {
                if ($term['slug'] === TermAssigner::UNCATEGORIZED) {
                    continue;
                }

                $items[] = '<li><a href="' . self::esc($router->generate('category', ['slug' => $term['slug']]))
                    . '">' . self::esc($term['name']) . '</a><span class="n">' . $term['count'] . '</span></li>';
            }

            return self::listHtml($items);
        };
    }

    /** 标签云:行序照词条表的"出现次数降序、同数按名称" */
    private static function tags(?TermAssigner $terms, ?Router $router): Closure
    {
        return static function (array $ctx) use ($terms, $router): string {
            if ($terms === null || $router === null || ($terms->counts()[TermAssigner::TAG] ?? 0) === 0) {
                return '';
            }

            $items = [];

            foreach ($terms->listAll(TermAssigner::TAG) as $term) {
                $items[] = '<li><a href="' . self::esc($router->generate('tag', ['slug' => $term['slug']]))
                    . '" data-count="' . $term['count'] . '">' . self::esc($term['name']) . '</a></li>';
            }

            return self::listHtml($items);
        };
    }

    /** 最新评论:全站最近几条已通过审核的;所属文章取不到地址(回收站 / 未发布)的那条不列 */
    private static function comments(?CommentRepository $comments, ?PostRepository $posts): Closure
    {
        return static function (array $ctx) use ($comments, $posts): string {
            if ($comments === null || $posts === null) {
                return '';
            }

            /** @var array<int, string> $urls 一次请求里同一篇只查一遍(《57》§5) */
            $urls = [];
            $items = [];

            foreach ($comments->browse(['status' => 'approved'], 1, self::COMMENT_LIMIT)['items'] as $row) {
                $postId = (int) ($row['post_id'] ?? 0);

                if (!isset($urls[$postId])) {
                    $post = $posts->find($postId);
                    $urls[$postId] = $post === null ? '' : $post->url;
                }

                if ($urls[$postId] === '') {
                    continue;
                }

                $items[] = '<li><a href="' . self::esc($urls[$postId]) . '">' . self::esc(self::authorOf($row))
                    . '</a><span>' . self::esc(self::summary((string) ($row['content'] ?? ''))) . '</span></li>';
            }

            return self::listHtml($items);
        };
    }

    /** 搜索框:action 由路由名 search 现算,不硬编码 /search */
    private static function search(?Router $router): Closure
    {
        return static function (array $ctx) use ($router): string {
            if ($router === null) {
                return '';
            }

            return '<form class="wg-search" method="get" action="' . self::esc($router->path('search')) . '">'
                . '<input type="search" name="q" placeholder="搜索…" aria-label="搜索">'
                . '<button type="submit">搜索</button></form>';
        };
    }

    /**
     * 文章目录:从当前那篇文章的正文里抽 h2/h3 的纯文本,h3 缩进一级。
     * 当前不是单篇(上下文里没有文章)时返回空串。
     */
    private static function toc(): Closure
    {
        return static function (array $ctx): string {
            $post = $ctx['post'] ?? null;

            if (!$post instanceof Post) {
                return '';
            }

            preg_match_all('#<h([23])\b[^>]*>(.*?)</h\1>#is', (string) $post->content, $matches, PREG_SET_ORDER);

            $items = [];

            foreach ($matches as $match) {
                // 正文是渲染过的 HTML,小标题里可能带标记与实体;先还原成纯文本,拼 HTML 时再转义
                $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($text !== '') {
                    $items[] = ['level' => (int) $match[1], 'text' => $text];
                }
            }

            return self::tocHtml($items);
        };
    }

    /**
     * @param list<array{level: int, text: string}> $items
     */
    private static function tocHtml(array $items): string
    {
        $html = '';
        $topOpen = false;
        $subOpen = false;

        foreach ($items as $item) {
            $text = self::esc($item['text']);

            // h3 归到上一枚 h2 名下,用嵌套 ol 表达一级缩进
            if ($item['level'] === 3 && $topOpen) {
                if (!$subOpen) {
                    $html .= '<ol>';
                    $subOpen = true;
                }

                $html .= '<li>' . $text . '</li>';

                continue;
            }

            if ($subOpen) {
                $html .= '</ol>';
                $subOpen = false;
            }

            if ($topOpen) {
                $html .= '</li>';
            }

            $html .= '<li>' . $text;
            $topOpen = true;
        }

        if ($subOpen) {
            $html .= '</ol>';
        }

        if ($topOpen) {
            $html .= '</li>';
        }

        return $html === '' ? '' : '<ol class="wg-toc">' . $html . '</ol>';
    }

    /**
     * @param list<string> $items
     */
    private static function listHtml(array $items): string
    {
        return $items === [] ? '' : '<ul class="wg-list">' . implode('', $items) . '</ul>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function authorOf(array $row): string
    {
        $name = trim((string) ($row['display_name'] ?? ''));

        return $name === '' ? trim((string) ($row['author_name'] ?? '')) : $name;
    }

    /** 摘要:去标签、压平空白、按字数截断 —— 转义交给拼 HTML 那一层 */
    private static function summary(string $content): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($content)));

        if ($text === '') {
            return '';
        }

        return mb_strlen($text, 'UTF-8') > self::SUMMARY_LIMIT
            ? mb_substr($text, 0, self::SUMMARY_LIMIT, 'UTF-8') . '…'
            : $text;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
