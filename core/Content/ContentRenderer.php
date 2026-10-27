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
use Molin\Kernel;
use Throwable;

/**
 * 正文渲染链:库里的原始正文 → 空白段自动成段 → 过 content/render/before|after → 模板可 |raw 的 HTML。
 *
 * 既有规范规定模板看到的 `post.content` 是"渲染后 HTML",所以这一步必须发生在
 * 交给主题之前;放在仓储层会把列表页每一行都过一遍钩子,故由展示层按模式显式调用。
 */
final class ContentRenderer
{
    /**
     * 已经是块级结构、不需要再折段的标签白名单。
     * 有这个就整段原样放过 —— 主题与插件都按"渲染后 HTML"接的,多包一层会坏版。
     */
    private const BLOCK_TAG = '#<(?:p|div|h[1-6]|ul|ol|li|blockquote|pre|table|thead|tbody|tr|td|th|figure|figcaption|hr|section|article|aside|header|footer|nav|iframe|video|audio|img|picture|dl|dt|dd|details|summary|canvas|form)\b#i';

    private readonly CacheManager $cache;

    /**
     * @param CacheManager|null        $cache         不注入时用装配线那一份;没 boot 过就是 no-op
     * @param (Closure(): string)|null $fingerprint   插件指纹(启用的插件名@版本),取不到退回内核版本
     * @param bool                     $cacheInDebug  开发模式默认不缓存,便于改插件后立刻见效;测试与排障可开
     */
    public function __construct(
        ?CacheManager $cache = null,
        private readonly ?Closure $fingerprint = null,
        private readonly bool $cacheInDebug = false
    ) {
        $this->cache = $cache ?? CacheManager::instance();
    }

    public function render(Post $post, string $mode, bool $listing): Post
    {
        return $post->withContent($this->cached($post, $mode, $listing));
    }

    /**
     * 渲染结果缓存:折段是正则,两条 filter 要跑全部插件回调 —— 列表页同一篇会在每次翻页/切分类
     * 时重跑一遍。缓存值就是最终 HTML。
     *
     * 不用 posts.content_filtered:那里只有一列,装不下 mode 与插件指纹,而且全项目只写 null。
     * 失效走 CacheManager 的 `post` 标签(Bootstrap 的内容事件已经挂了 invalidateTags)。
     */
    private function cached(Post $post, string $mode, bool $listing): string
    {
        if (!$this->cacheable()) {
            return $this->renderHtml($post, $mode, $listing);
        }

        $key = $this->key($post, $mode, $listing);
        // 回调里写、catch 里读:这份临时状态要跨闭包边界,用属性承载
        $state = new class () {
            public ?string $html = null;

            public bool $started = false;
        };

        try {
            $value = $this->cache->remember($key, null, function () use ($state, $post, $mode, $listing): string {
                $state->started = true;

                return $state->html = $this->renderHtml($post, $mode, $listing);
            }, ['post']);
        } catch (Throwable $e) {
            // 渲染本身抛的照旧往上抛;只是缓存层读写失败就回落本次现算的结果
            if ($state->html !== null) {
                return $state->html;
            }

            if ($state->started) {
                throw $e;
            }

            return $this->renderHtml($post, $mode, $listing);
        }

        // 缓存里躺着非字符串(旧版本 / 手改坏了)时别把脏值交给模板,重算一份
        return is_string($value) ? $value : ($state->html ?? $this->renderHtml($post, $mode, $listing));
    }

    private function cacheable(): bool
    {
        if (!$this->cache->enabled()) {
            return false;
        }

        return $this->cacheInDebug || !defined('APP_DEBUG') || !APP_DEBUG;
    }

    /**
     * 键带原文与插件指纹:正文经取数钩子改过、插件升级但没走启停时,updated_at 不变也不会串味。
     * listing 也要进键 —— 同一个 mode 在列表与单篇里的上下文不同,插件可以按它分支。
     */
    private function key(Post $post, string $mode, bool $listing): string
    {
        return 'post:content:' . $post->id . ':' . hash('sha256', implode("\0", [
            $post->updated_at,
            $mode,
            $listing ? 'listing' : 'single',
            hash('sha256', (string) $post->content),
            $this->fingerprint(),
        ]));
    }

    /**
     * 插件指纹:启用的插件名 + 版本。懒加载下"此刻已注册回调"的插件只是一部分,
     * 用启用清单才不会漏掉尚未加载、一加载就改输出的插件。
     */
    private function fingerprint(): string
    {
        $value = '';

        if ($this->fingerprint !== null) {
            try {
                $value = trim((string) ($this->fingerprint)());
            } catch (Throwable) {
                $value = '';
            }
        }

        return $value === '' ? 'core:' . Kernel::VERSION : $value;
    }

    private function renderHtml(Post $post, string $mode, bool $listing): string
    {
        $context = new ContentContext($post, $post->type, $mode, $listing);
        // 2026-10-17:导入与手写的正文常常是一大段裸文本(空行分段),浏览器不认空行,
        // 整篇会挤成一行、首行还会掉出主题的内容列 —— 在钩子之前先折成 <p>。
        $raw = self::paragraphs($post->content ?? '');
        $html = (string) apply_filters('content/render/before', $raw, $context);
        $html = (string) apply_filters('content/render/after', $html, $context);

        // 钩子跑完之后再补:插件/主题自己写的 loading 已经在了,这里只填空的,不会覆盖
        return self::lazyImages($html);
    }

    /**
     * 正文图片的原生懒加载:首张真实图片抢首屏(eager + high),其余一律 lazy。
     *
     * 只往 `<img ...>` 标签里插属性:已有 loading/decoding/fetchpriority 一律不覆盖,
     * 属性顺序、引号、" />" 的写法以及标签外的内容全原样保留。
     * `src` 是 data: 的内联图不算"真实图片",不占首图名额。
     */
    private static function lazyImages(string $html): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $firstSeen = false;

        $result = preg_replace_callback(
            // 属性值里可能带 ">"(如 alt="a > b"),所以引号内的内容单独配对
            '#<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
            // 箭头函数按值捕获,$firstSeen 必须显式按引用带进去,否则每张图都会当成首图
            static function (array $match) use (&$firstSeen): string {
                return self::lazyImage($match[0], $firstSeen);
            },
            $html
        );

        return is_string($result) ? $result : $html;
    }

    /**
     * @param bool $firstSeen 首张真实图片已出现过则置 true(跨标签累计)
     */
    private static function lazyImage(string $tag, bool &$firstSeen): string
    {
        $real = !str_starts_with(strtolower(trim(self::attrValue($tag, 'src'))), 'data:');
        $isFirst = $real && !$firstSeen;

        if ($real) {
            $firstSeen = true;
        }

        $add = [];

        if (!self::hasAttr($tag, 'loading')) {
            $add['loading'] = $isFirst ? 'eager' : 'lazy';
        }

        if (!self::hasAttr($tag, 'decoding')) {
            $add['decoding'] = 'async';
        }

        // 已经声明 lazy 的图不该再抢优先级
        if ($isFirst && !self::hasAttr($tag, 'fetchpriority')
            && strtolower(trim(self::attrValue($tag, 'loading'))) !== 'lazy') {
            $add['fetchpriority'] = 'high';
        }

        if ($add === []) {
            return $tag;
        }

        // 掐掉收尾的 ">" 与 "/>"(连同 "/" 前的空白),新属性插在它们前头
        $body = substr($tag, 0, -1);
        $i = strlen($body);

        while ($i > 0 && ctype_space($body[$i - 1])) {
            $i--;
        }

        if ($i > 0 && $body[$i - 1] === '/') {
            $i--;

            while ($i > 0 && ctype_space($body[$i - 1])) {
                $i--;
            }
        }

        $tail = substr($body, $i);
        $out = substr($body, 0, $i);

        foreach ($add as $name => $value) {
            $out .= ' ' . $name . '="' . $value . '"';
        }

        return $out . $tail . '>';
    }

    private static function hasAttr(string $tag, string $name): bool
    {
        return preg_match('#[\s/]' . preg_quote($name, '#') . '(?:\s*=|[\s/>])#i', $tag) === 1;
    }

    /**
     * 取属性值,单/双引号与不带引号三种写法都认;没有该属性返回空串。
     */
    private static function attrValue(string $tag, string $name): string
    {
        $pattern = '#[\s/]' . preg_quote($name, '#') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*))#i';

        if (preg_match($pattern, $tag, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return '';
        }

        foreach ([$match[1] ?? null, $match[2] ?? null, $match[3] ?? null] as $value) {
            if (is_string($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * 裸文本折段:空行分段、段内换行成 <br>,已有块级标签的正文原样放过。
     */
    private static function paragraphs(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (preg_match(self::BLOCK_TAG, $text) === 1) {
            return $text;
        }

        // 必须带 u:不带 UTF-8 模式时 \R 会把多字节汉字里的 0x85 当成换行拆开
        // (「配」= E9 85 8D),正文里就会冒出 U+FFFD 乱码 —— 2026-10-17 真踩到。
        $blocks = preg_split('#\R\s*\R#u', $text);

        if ($blocks === false) {
            return $text;
        }

        $out = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $out[] = '<p>' . (string) preg_replace('#\R#u', "<br>\n", $block) . '</p>';
        }

        return implode("\n", $out);
    }
}
