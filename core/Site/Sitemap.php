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
 * 站点地图(《23》§4:`/sitemap.xml`)。
 *
 * 收什么(2026-10-04 自定,记录在案):首页 + 已发布文章 + 已发布独立页面 + 归档页。
 * 归档页 = 分类 / 标签 / 作者,口径是"只收有已发布内容的归档"(哪些算有内容由调用方过滤后传进来);
 * 作者只收真出过内容的人(UserRepository::authorsWithPublishedPosts)。
 * 附件页仍不收 —— 《117》§11 第 11 条"附件是否进 sitemap"仍挂账,先不收。
 * 单次上限 200 条(PostQuery 的天花板),更大的站分页收录留待后续。
 */
final class Sitemap
{
    /** 每类内容单次最多收录条数 */
    public const LIMIT = 200;

    /** sitemap 协议允许的 changefreq 取值 */
    private const CHANGEFREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * @param list<Post>   $posts    文章
     * @param list<Post>   $pages    独立页面
     * @param list<string> $archives 归档页的绝对 URL,由调用方用路由名生成(Sitemap 不认识 Router)
     */
    public function xml(array $posts, array $pages, array $archives = []): string
    {
        $base = rtrim((string) $this->options->get('site_url', ''), '/');
        $urls = [['loc' => $base . '/']];

        foreach ([...$posts, ...$pages] as $post) {
            $entry = ['loc' => $post->url];
            $stamp = strtotime($post->updated_at . ' UTC');

            if ($stamp !== false) {
                $entry['lastmod'] = gmdate('Y-m-d\TH:i:s+00:00', $stamp);
            }

            $urls[] = $entry;
        }

        foreach ($archives as $url) {
            $urls[] = ['loc' => $url];
        }

        $filtered = apply_filters('site/sitemap/collect', $urls);
        // 插件返回非数组按"没改"处理:一个坏回调不该把整张地图清空
        $urls = self::normalize(is_array($filtered) ? $filtered : $urls);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . self::render($urls)
            . "</urlset>\n";
    }

    /**
     * 逐条校验:只认数组条目与 http(s) 绝对地址,坏形状整条丢弃。
     * 可选字段按 sitemap 协议收窄(changefreq 词表、priority 0~1),免得插件把 XML 写坏。
     *
     * @param array<array-key, mixed> $urls
     * @return list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string}>
     */
    private static function normalize(array $urls): array
    {
        $out = [];

        foreach ($urls as $url) {
            if (!is_array($url)) {
                continue;
            }

            $loc = $url['loc'] ?? null;

            if (!is_string($loc) || preg_match('#\Ahttps?://#i', trim($loc)) !== 1) {
                continue;
            }

            $entry = ['loc' => trim($loc)];

            foreach (['lastmod', 'changefreq', 'priority'] as $key) {
                $value = $url[$key] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                if (!is_scalar($value)) {
                    continue 2;
                }

                $entry[$key] = trim((string) $value);
            }

            if (isset($entry['changefreq']) && !in_array(strtolower($entry['changefreq']), self::CHANGEFREQ, true)) {
                continue;
            }

            if (isset($entry['priority']) && (preg_match('#\A\d+(?:\.\d+)?\z#', $entry['priority']) !== 1 || (float) $entry['priority'] > 1.0)) {
                continue;
            }

            $out[] = $entry;
        }

        /** @var list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string}> $out */
        return $out;
    }

    /**
     * @param list<array{loc: string, lastmod?: string, changefreq?: string, priority?: string}> $urls
     */
    private static function render(array $urls): string
    {
        $out = '';

        foreach ($urls as $url) {
            $out .= "  <url>\n    <loc>" . self::esc($url['loc']) . '</loc>';

            foreach (['lastmod', 'changefreq', 'priority'] as $key) {
                if (isset($url[$key])) {
                    $out .= "\n    <{$key}>" . self::esc($url[$key]) . "</{$key}>";
                }
            }

            $out .= "\n  </url>\n";
        }

        return $out;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
