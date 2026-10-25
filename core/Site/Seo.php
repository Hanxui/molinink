<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Site;

/**
 * 页面头部的「给人看之外」那一层(2026-10-17 加):
 * canonical + Open Graph + Twitter Card + JSON-LD。
 *
 * 为什么放在内核而不是主题里:这三样东西全靠"这一篇是什么"决定 —— 标题、摘要、封面、
 * 发布时间、正版地址。主题查不了库,也不知道当前是哪一篇(《13》§6.3 的道理),
 * 让它自己拼必然各处不一致。内核算好一段可信 HTML,主题照旧只放 `head_output`。
 *
 * 与 `Feed` / `Sitemap` / `Robots` 同一套做法:只做字符串,不连库、不读全局 ——
 * 站点名这几样由调用方给进来(纯函数才好单独测)。
 */
final class Seo
{
    /**
     * @param bool $public 站点是否允许搜索引擎收录(`blog_public`)
     */
    public function __construct(
        private readonly string $siteName = '',
        private readonly string $siteDescription = '',
        private readonly bool $public = true
    ) {
    }

    /**
     * @param array{
     *     type?: string,          // article | website
     *     title?: string,         // 这一页的标题(不含站点名)
     *     description?: string,
     *     canonical?: string,     // 正版地址(绝对);空串表示不输出 canonical
     *     image?: string,         // 封面图绝对地址
     *     published?: string,     // ISO 或 Y-m-d H:i:s(UTC)
     *     modified?: string,
     *     author?: string,
     *     section?: string        // 分类名(article 用)
     * } $page
     */
    public function head(array $page): string
    {
        $titleRaw = trim((string) ($page['title'] ?? ''));
        // 没写摘要的退回站点简介:分享出去至少有一句话,不是一条光秃秃的链接
        $rawDescription = trim((string) ($page['description'] ?? ''));

        if ($rawDescription === '') {
            $rawDescription = $this->siteDescription;
        }

        $descriptionRaw = $this->trim($rawDescription, 160);
        $canonicalRaw = $this->urlRaw((string) ($page['canonical'] ?? ''));
        $imageRaw = $this->urlRaw((string) ($page['image'] ?? ''));
        $type = ($page['type'] ?? 'website') === 'article' ? 'article' : 'website';

        // structured() 沿用改造前的口径:它拿的一直是转义后的串,JSON-LD 才能逐字节不变
        $siteName = $this->text($this->siteName);
        $title = $this->text($titleRaw);
        $description = $this->text($descriptionRaw);
        $canonical = $this->text($canonicalRaw);
        $image = $this->text($imageRaw);

        // 站点没开「搜索引擎可见」:整页 noindex。主题里也有各自的一份判断,
        // 这里只在"主题没写"时才补(tag 重复出现在 head 里不算错,但能少一条就少一条)
        $public = $this->public;

        $tags = [];

        if ($canonicalRaw !== '') {
            $tags[] = ['tag' => 'link', 'attrs' => ['rel' => 'canonical', 'href' => $canonicalRaw]];
        }

        $tags[] = ['tag' => 'meta', 'attrs' => ['property' => 'og:site_name', 'content' => $this->siteName]];
        $tags[] = ['tag' => 'meta', 'attrs' => ['property' => 'og:type', 'content' => $type]];

        if ($titleRaw !== '') {
            $tags[] = ['tag' => 'meta', 'attrs' => ['property' => 'og:title', 'content' => $titleRaw]];
        }

        if ($descriptionRaw !== '') {
            $tags[] = ['tag' => 'meta', 'attrs' => ['property' => 'og:description', 'content' => $descriptionRaw]];
        }

        if ($canonicalRaw !== '') {
            $tags[] = ['tag' => 'meta', 'attrs' => ['property' => 'og:url', 'content' => $canonicalRaw]];
        }

        if ($imageRaw !== '') {
            $tags[] = ['tag' => 'meta', 'attrs' => ['property' => 'og:image', 'content' => $imageRaw]];
            $tags[] = ['tag' => 'meta', 'attrs' => ['name' => 'twitter:card', 'content' => 'summary_large_image']];
            $tags[] = ['tag' => 'meta', 'attrs' => ['name' => 'twitter:image', 'content' => $imageRaw]];
        } else {
            $tags[] = ['tag' => 'meta', 'attrs' => ['name' => 'twitter:card', 'content' => 'summary']];
        }

        if ($titleRaw !== '') {
            $tags[] = ['tag' => 'meta', 'attrs' => ['name' => 'twitter:title', 'content' => $titleRaw]];
        }

        if ($descriptionRaw !== '') {
            $tags[] = ['tag' => 'meta', 'attrs' => ['name' => 'twitter:description', 'content' => $descriptionRaw]];
        }

        if (!$public) {
            $tags[] = ['tag' => 'meta', 'attrs' => ['name' => 'robots', 'content' => 'noindex,nofollow']];
        }

        // JSON-LD:结构化的那一份(搜索结果里的面包屑/作者/时间靠它)。只出这两种,不铺大网
        $ld = $this->structured($page, $siteName, $title, $description, $canonical, $image, $type);

        if ($ld !== '') {
            $tags[] = ['tag' => 'script', 'attrs' => ['type' => 'application/ld+json'], 'content' => $ld];
        }

        $filtered = apply_filters('site/seo/replace', $tags);
        // 插件返回非数组按"没改"处理:head 不能因为一个坏回调整段消失
        $tags = self::normalizeTags(is_array($filtered) ? $filtered : $tags);

        return $this->renderTags($tags);
    }

    /**
     * 插件替换后的形状校验:只认 {tag: string, attrs: array, content?: string}。
     * 坏条目丢弃,属性名与标签名都按 HTML 标识符收窄,免得插件把 head 拼坏。
     *
     * @param array<array-key, mixed> $tags
     * @return list<array{tag: string, attrs: array<string, string>, content?: string}>
     */
    private static function normalizeTags(array $tags): array
    {
        $out = [];

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $name = $tag['tag'] ?? null;

            if (!is_string($name) || preg_match('#\A[a-zA-Z][a-zA-Z0-9-]*\z#', $name) !== 1) {
                continue;
            }

            $attrs = $tag['attrs'] ?? [];

            if (!is_array($attrs)) {
                continue;
            }

            $clean = [];
            $bad = false;

            foreach ($attrs as $attr => $value) {
                if (
                    !is_string($attr)
                    || preg_match('#\A[a-zA-Z_:][a-zA-Z0-9_:.-]*\z#', $attr) !== 1
                    || !is_scalar($value)
                ) {
                    $bad = true;

                    break;
                }

                $clean[$attr] = (string) $value;
            }

            if ($bad) {
                continue;
            }

            $entry = ['tag' => strtolower($name), 'attrs' => $clean];

            if (array_key_exists('content', $tag)) {
                if (!is_scalar($tag['content'])) {
                    continue;
                }

                $entry['content'] = (string) $tag['content'];
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param list<array{tag: string, attrs: array<string, string>, content?: string}> $tags
     */
    private function renderTags(array $tags): string
    {
        $lines = [];

        foreach ($tags as $tag) {
            $html = '<' . $tag['tag'];

            foreach ($tag['attrs'] as $name => $value) {
                $html .= ' ' . $name . '="' . $this->text($value) . '"';
            }

            if (array_key_exists('content', $tag)) {
                // script / style 里放的是原始片段(JSON-LD、统计脚本),其余按文本转义
                $raw = in_array($tag['tag'], ['script', 'style'], true);
                $html .= '>' . ($raw ? $tag['content'] : $this->text($tag['content'])) . '</' . $tag['tag'] . '>';
            } else {
                $html .= '>';
            }

            $lines[] = $html;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $page
     */
    private function structured(
        array $page,
        string $siteName,
        string $title,
        string $description,
        string $canonical,
        string $image,
        string $type
    ): string {
        if ($canonical === '') {
            return '';
        }

        $data = $type === 'article'
            ? array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $this->plain($title),
                'description' => $this->plain($description),
                'mainEntityOfPage' => $canonical,
                'url' => $canonical,
                'datePublished' => $this->iso((string) ($page['published'] ?? '')),
                'dateModified' => $this->iso((string) ($page['modified'] ?? '')),
                'articleSection' => $this->plain((string) ($page['section'] ?? '')),
                'author' => (string) ($page['author'] ?? '') === ''
                    ? null
                    : ['@type' => 'Person', 'name' => $this->plain((string) $page['author'])],
                'image' => $image === '' ? null : [$image],
                'publisher' => ['@type' => 'Organization', 'name' => $this->plain($siteName)],
            ], static fn (mixed $v): bool => $v !== null && $v !== '')
            : array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $this->plain($siteName),
                'url' => $canonical,
                'description' => $this->plain($description),
            ], static fn (string $v): bool => $v !== '');

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

        return $json === false ? '' : $json;
    }

    /** 属性里的值:HTML 转义,并挡掉换行(属性里的换行会把标签撑坏) */
    private function text(string $value): string
    {
        return htmlspecialchars(str_replace(["\r", "\n"], ' ', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 地址只放 http(s) 绝对地址;其余一律丢掉(别把 javascript: 之类塞进 head) */
    private function urlRaw(string $value): string
    {
        $value = trim($value);

        return $value !== '' && preg_match('#\Ahttps?://#i', $value) === 1 ? $value : '';
    }

    private function plain(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function trim(string $value, int $limit): string
    {
        $value = trim((string) preg_replace('#\s+#u', ' ', strip_tags($value)));

        if ($value === '' || mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . '…';
    }

    /** 库里是 UTC 的 `Y-m-d H:i:s`;JSON-LD 要 ISO 8601 */
    private function iso(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $stamp = strtotime($value . ' UTC');

        return $stamp === false ? '' : gmdate('c', $stamp);
    }
}
