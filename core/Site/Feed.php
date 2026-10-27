<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Site;

use Molin\Content\Post;
use Molin\Option\Options;

/**
 * 订阅输出(《23》§4:`/feed` `/feed/rss` `/feed/atom`)。
 *
 * 口径(2026-10-05 自定,记录在案):条数走内核选项 `posts_per_rss`(默认 10);
 * 摘要 / 全文走 `rss_use_excerpt`(`'1'` = 摘要,取 `excerpt`,空则正文去标签截 160 字;
 * 其它值 = 全文,输出原始 HTML 作正文)。后台设置屏的入口留后续轮次。
 * `ContentContext::MODE_FEED` 的接线同样留给后续轮次。
 */
final class Feed
{
    /**
     * 订阅默认条数。仅作兜底,实际条数走 {@see self::limit()}(读 `posts_per_rss`)。
     */
    public const LIMIT = 10;

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * 订阅条数:内核选项 `posts_per_rss`,默认 10;后台设置屏的入口留后续轮次。
     */
    public static function limit(Options $options): int
    {
        return max(1, (int) $options->get('posts_per_rss', '10'));
    }

    /**
     * RSS 2.0。
     *
     * @param list<Post> $posts
     */
    public function rss(array $posts): string
    {
        $site = $this->site();
        $items = '';

        foreach ($this->collect($posts) as $post) {
            $items .= "    <item>\n"
                . '      <title>' . self::xml($post->title) . "</title>\n"
                . '      <link>' . self::xml($post->url) . "</link>\n"
                . '      <guid isPermaLink="true">' . self::xml($post->url) . "</guid>\n"
                . '      <pubDate>' . self::rfc822($post->published_at) . "</pubDate>\n"
                . '      <description>' . self::xml($this->summary($post)) . "</description>\n"
                . "    </item>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "  <channel>\n"
            . '    <title>' . self::xml($site['name']) . "</title>\n"
            . '    <link>' . self::xml($site['url']) . "</link>\n"
            . '    <description>' . self::xml($site['description']) . "</description>\n"
            . '    <lastBuildDate>' . self::rfc822(gmdate('Y-m-d H:i:s')) . "</lastBuildDate>\n"
            . '    <atom:link href="' . self::xml($site['url'] . 'feed/rss') . '" rel="self" type="application/rss+xml"/>' . "\n"
            . $items
            . "  </channel>\n"
            . "</rss>\n";
    }

    /**
     * Atom 1.0。
     *
     * @param list<Post> $posts
     */
    public function atom(array $posts): string
    {
        $site = $this->site();
        $entries = '';

        foreach ($this->collect($posts) as $post) {
            $entries .= "  <entry>\n"
                . '    <title>' . self::xml($post->title) . "</title>\n"
                . '    <link href="' . self::xml($post->url) . "\"/>\n"
                . '    <id>' . self::xml($post->url) . "</id>\n"
                . '    <published>' . self::iso8601($post->published_at) . "</published>\n"
                . '    <updated>' . self::iso8601($post->updated_at) . "</updated>\n"
                . '    <summary>' . self::xml($this->summary($post)) . "</summary>\n"
                . "  </entry>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n"
            . '  <title>' . self::xml($site['name']) . "</title>\n"
            . '  <subtitle>' . self::xml($site['description']) . "</subtitle>\n"
            . '  <id>' . self::xml($site['url']) . "</id>\n"
            . '  <link href="' . self::xml($site['url']) . "\"/>\n"
            . '  <link rel="self" href="' . self::xml($site['url'] . 'feed/atom') . "\"/>\n"
            . '  <updated>' . gmdate('Y-m-d\TH:i:s\Z') . "</updated>\n"
            . $entries
            . "</feed>\n";
    }

    /**
     * 条目收集口:插件可增删改,非 Post 的返回值一律丢掉 ——
     * 后面每条都要读 Post 的字段,混进数组会直接白屏。
     *
     * @param list<Post> $posts
     * @return list<Post>
     */
    private function collect(array $posts): array
    {
        $filtered = apply_filters('site/feed/collect', $posts);

        // 插件返回非数组按"没改"处理:一个坏回调不该让订阅变成空 feed
        $posts = is_array($filtered) ? $filtered : $posts;

        return array_values(array_filter(
            $posts,
            static fn (mixed $post): bool => $post instanceof Post
        ));
    }

    /**
     * @return array{name:string, description:string, url:string}
     */
    private function site(): array
    {
        $url = rtrim((string) $this->options->get('site_url', ''), '/');

        return [
            'name' => (string) $this->options->get('site_name', ''),
            'description' => (string) $this->options->get('site_description', ''),
            // 末尾恒带斜杠,给 self 链接拼接用;站点没配地址时退回站内相对
            'url' => $url === '' ? '/' : $url . '/',
        ];
    }

    /**
     * 条目摘要:全文模式(`rss_use_excerpt` 非 `'1'`)直接给原始 HTML;摘要模式取 `excerpt`,
     * 空则正文去标签截 160 字。转义交给调用处的 self::xml()。
     */
    private function summary(Post $post): string
    {
        if ($this->options->get('rss_use_excerpt', '1') !== '1') {
            return (string) $post->content;
        }

        $excerpt = trim((string) $post->excerpt);

        if ($excerpt !== '') {
            return $excerpt;
        }

        $plain = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags((string) $post->content), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        return mb_strlen($plain) > 160 ? mb_substr($plain, 0, 160) . '…' : $plain;
    }

    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function rfc822(?string $at): string
    {
        $stamp = strtotime(((string) $at) . ' UTC');

        return gmdate('D, d M Y H:i:s \G\M\T', $stamp === false ? time() : $stamp);
    }

    private static function iso8601(?string $at): string
    {
        $stamp = strtotime(((string) $at) . ' UTC');

        return gmdate('Y-m-d\TH:i:s\Z', $stamp === false ? time() : $stamp);
    }
}
