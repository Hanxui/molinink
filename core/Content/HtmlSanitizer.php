<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

/**
 * 正文白名单过滤。
 *
 * 富文本编辑器和区块存的都是 HTML,主题模板节用 |raw 输出,
 * 所以入库前必须过一次白名单 —— 安全基调排第一位,这里没有"信任作者"这条路。
 *
 * 做法是解析成 DOM 后按白名单**重建**字符串,而不是把不合规矩的节点删掉:
 * 重建意味着没列进来的东西(事件属性、`<script>` 的正文、注释、CDATA、奇异嵌套)
 * 天然到不了输出,不必追着攻击样本补黑名单。
 */
final class HtmlSanitizer
{
    /**
     * 标签白名单。既有规范那七枚是行内底子;段落、列表、标题、代码块
     * 是既有规范"富文本"路线和既有规范工具条自己要产出的结构,
     * 不含它们等于把编辑器打出来的稿子拆散。
     */
    private const TAGS = [
        'p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'del', 's', 'a', 'code', 'pre', 'blockquote',
        'ul', 'ol', 'li', 'h2', 'h3', 'img', 'figure', 'figcaption',
    ];

    /** 评论区自己的白名单:比正文窄 —— 没有图、没有标题、没有列表 */
    public const COMMENT_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'a', 'code', 'blockquote'];

    /** 出现即整段丢弃的标签:留着正文等于把脚本内容当文章显示 */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'textarea', 'title', 'template', 'head',
        'iframe', 'object', 'embed', 'link', 'meta', 'base', 'svg', 'math',
    ];

    /** 没有闭合标签的几枚,重建时不补 </…> */
    private const VOID = ['br', 'hr', 'img'];

    /** 属性白名单:键是标签,* 表示所有放行标签 */
    private const ATTRS = [
        '*' => ['title', 'class'],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
    ];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * 过滤一段正文。空正文返回空串,不补外壳。
     *
     * @param list<string>|null $tags 白名单;评论等窄场景给自己的清单,不给就用正文这套
     */
    public static function clean(string $html, ?array $tags = null): string
    {
        if (trim($html) === '') {
            return '';
        }

        // libxml 默认按 ISO-8859-1 读片段,中文会被拆成乱码字节;先转数字实体再解析
        $packed = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x10FFFF], 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<div>' . $packed . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $dom->documentElement === null) {
            // 解析不动的东西最不好说,按最保守的来:整段当纯文本
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return self::walk($dom->documentElement->childNodes, $tags);
    }

    /**
     * @param \DOMNodeList<\DOMNode> $nodes
     * @param list<string>|null $tags
     */
    private static function walk(\DOMNodeList $nodes, ?array $tags): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if ($node instanceof \DOMText) {
                $out .= htmlspecialchars($node->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                continue;
            }

            if (!$node instanceof \DOMElement) {
                // 注释、CDATA、处理指令:一律不留
                continue;
            }

            $tag = strtolower($node->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            $inner = self::walk($node->childNodes, $tags);

            if (!in_array($tag, $tags ?? self::TAGS, true)) {
                $out .= $inner;

                continue;
            }

            $attrs = self::attrs($node, $tag);

            if (in_array($tag, self::VOID, true)) {
                $out .= '<' . $tag . $attrs . '>';

                continue;
            }

            $out .= '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
        }

        return $out;
    }

    private static function attrs(\DOMElement $node, string $tag): string
    {
        $allowed = array_unique(array_merge(self::ATTRS['*'], self::ATTRS[$tag] ?? []));
        $out = '';

        foreach ($allowed as $name) {
            if (!$node->hasAttribute($name)) {
                continue;
            }

            $value = $node->getAttribute($name);

            if (($name === 'href' || $name === 'src') && !self::urlOk($value)) {
                continue;
            }

            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $out;
    }

    /**
     * 站内相对路径、#锚点放行;带协议的要落在白名单里。`javascript:`、`data:`、
     * 以及拿空白和-control字符伪装成别的写法的,全部挡掉。
     */
    private static function urlOk(string $url): bool
    {
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');

        if ($probe === '') {
            return false;
        }

        if ($probe[0] === '/' || $probe[0] === '#') {
            return true;
        }

        $scheme = parse_url($probe, PHP_URL_SCHEME);

        if (!is_string($scheme)) {
            return true;
        }

        return in_array($scheme, self::SAFE_SCHEMES, true);
    }
}
