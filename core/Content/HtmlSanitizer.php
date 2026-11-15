<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

/**
 * 正文白名单过滤(编辑器区块设计第 9 节)。
 *
 * 富文本编辑器和区块存的都是 HTML,主题模板按模板引擎规范3.5 节用 |raw 输出,
 * 所以入库前必须过一次白名单 —— 安全基调排第一位,这里没有"信任作者"这条路。
 *
 * 做法是解析成 DOM 后按白名单**重建**字符串,而不是把不合规矩的节点删掉:
 * 重建意味着没列进来的东西(事件属性、`<script>` 的正文、注释、CDATA、奇异嵌套)
 * 天然到不了输出,不必追着攻击样本补黑名单。
 */
final class HtmlSanitizer
{
    /**
     * 标签白名单。编辑器区块设计第 9 节那七枚是行内底子;段落、列表、标题、代码块
     * 是后台信息架构第 6.2 节"富文本"路线和编辑页界面规格第 4.6 节工具条自己要产出的结构,
     * 不含它们等于把编辑器打出来的稿子拆散。
     */
    private const TAGS = [
        'p', 'br', 'hr', 'b', 'strong', 'i', 'em', 'del', 's', 'a', 'code', 'pre', 'blockquote',
        'ul', 'ol', 'li', 'h2', 'h3', 'img', 'figure', 'figcaption',
        // 编辑页的视频钮会插这一段(站内文件 `video`/`audio`,外链嵌入 `iframe`)
        'video', 'audio', 'source', 'iframe',
    ];

    /** 评论区自己的白名单(评论系统设计):比正文窄 —— 没有图、没有标题、没有列表 */
    public const COMMENT_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'a', 'code', 'blockquote'];

    /** 出现即整段丢弃的标签:留着正文等于把脚本内容当文章显示 */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'textarea', 'title', 'template', 'head',
        'object', 'embed', 'link', 'meta', 'base', 'svg', 'math',
    ];

    /** 没有闭合标签的几枚,重建时不补 </…> */
    private const VOID = ['br', 'hr', 'img', 'source'];

    /** 属性白名单:键是标签,* 表示所有放行标签 */
    private const ATTRS = [
        '*' => ['title', 'class'],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
        'video' => ['src', 'poster', 'controls', 'preload', 'loop', 'muted', 'playsinline', 'width', 'height'],
        'audio' => ['src', 'controls', 'preload', 'loop', 'muted'],
        'source' => ['src', 'type'],
        'iframe' => ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'frameborder'],
    ];

    /**
     * 外链嵌入只认这几家的播放器域名(编辑器区块设计第 9 节:YouTube / Bilibili / Vimeo / CodePen)。
     * 名单外的 `iframe` 整段丢掉 —— 宁可少一块内容,也不给正文开任意嵌入口子。
     */
    private const EMBED_HOSTS = [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com',
        'player.bilibili.com',
        'player.vimeo.com',
        'codepen.io',
    ];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * 过滤一段正文。空正文返回空串,不补外壳。
     *
     * @param list<string>|null $tags 白名单;评论等窄场景给自己的清单(评论系统设计),不给就用正文这套
     */
    public static function clean(string $html, ?array $tags = null): string
    {
        if (trim($html) === '') {
            return '';
        }

        // 正文里"比大小"的尖括号很常见(代码块、数学式),libxml 会把 `< $b` / `<n` / `a<b)` 当成标签开头,
        // 一路吞到下一个 `>` 甚至吞到结尾。分两步把"不像标签"的尖括号先转成文字。
        // ① 尖括号后面根本不是字母: `< 6`、`<$a`
        $html = (string) preg_replace('/<(?![a-zA-Z\/!?])/', '&lt;', $html);
        // ② 像标签开头、但名字后面不是空白 / `>` / `/` 的: `a<b)`、`i<n;`
        //    断言里带上字母数字,否则正则会回溯成 `<di` 把 `<div>` 这种正常标签也误伤
        $html = (string) preg_replace('/<([a-zA-Z][a-zA-Z0-9]*)(?![a-zA-Z0-9\s>\/])/', '&lt;$1', $html);
        // ③ 剩下的都是"长得像标签"的:白名单照旧,未登记的按老规矩"剥标签留文字",
        //    但前提是它真有闭合 —— 没有闭合的多半仍是正文里的尖括号
        $html = (string) preg_replace_callback(
            '/<(\/?)([a-zA-Z][a-zA-Z0-9]*)/',
            static function (array $m) use ($html): string {
                $name = strtolower($m[2]);

                if (in_array($name, self::TAGS, true) || in_array($name, self::DROP_WITH_CONTENT, true)) {
                    return $m[0];
                }

                return str_contains($html, '</' . $name . '>') ? $m[0] : '&lt;' . $m[1] . $m[2];
            },
            $html
        );

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
                // 文本节点只处理 & < >:ENT_QUOTES 会把引号写成 &quot;,
                // 而正文里的引号是作者写的内容(短代码属性、引语),转义了就得原样显示出来
                $out .= htmlspecialchars($node->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

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

            // 外链嵌入:域不在白名单的整个丢掉(连同一层子节点),不留空框
            if ($tag === 'iframe' && !self::embedOk($node->getAttribute('src'))) {
                continue;
            }

            $inner = self::walk($node->childNodes, $tags);

            if (!in_array($tag, $tags ?? self::TAGS, true)) {
                $out .= $inner;

                continue;
            }

            $attrs = self::attrs($node, $tag);

            if (in_array($tag, self::VOID, true)) {
                // libxml 不认 `video/source` 这些新标签,会把 `<source>` 当成带子节点的容器,
                // 连着两颗 `<source>` 时后一颗会变成前一颗的孩子 —— 收尾时不补闭合,但孩子要接着输出,
                // 否则第二颗源就静默丢了
                $out .= '<' . $tag . $attrs . '>' . $inner;

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

            if (($name === 'href' || $name === 'src' || $name === 'poster') && !self::urlOk($value)) {
                continue;
            }

            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $out;
    }

    /**
     * 外链嵌入的判定:必须 `https`,域必须落在白名单里。
     *
     * 只认 `parse_url` 认出来的主机名 —— `https://player.bilibili.com@evil.test/`
     * 这种把白名单域名塞进用户名的写法,主机名仍然是 `evil.test`,当场被挡。
     */
    private static function embedOk(string $url): bool
    {
        $probe = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');

        if (parse_url($probe, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        $host = parse_url($probe, PHP_URL_HOST);

        return is_string($host) && in_array($host, self::EMBED_HOSTS, true);
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
