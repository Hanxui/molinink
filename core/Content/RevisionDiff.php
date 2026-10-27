<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

/**
 * 修订比对渲染(左列 deletion、右列 insertion)。
 *
 * 库里的正文是过白名单的 HTML(见 HtmlSanitizer),直接按字符比对会把标签拆碎、还会
 * 把 HTML 原样输出成 XSS。这里的做法:先把正文拆成"块"(段落 / 标题),块内取**纯文本**,
 * 再逐块配对、逐字比对,最后统一转义输出。任何来源的正文都被转义,转义后不可能出标记 ——
 * 安全基调第一位。
 *
 * 粒度取舍(§11-1 未定,此处自决并登记):按块 + 块内逐字。块用最小 LCS 配对,配对上的块
 * 再做字符级 LCS,得到行内 `<ins>` / `<del>`;配不上的整块标记。字符级比对前先掐掉公共
 * 前后缀,再对剩下的"真正改动段"做 LCS —— 常见的小改动因此只在小段上算,不会为整段建表。
 * 两道上限兜底:块数或改动段过大时退化为整块标记,保证 O(n·m) 不失控。
 */
final class RevisionDiff
{
    /** 块级 LCS 的规模上限(行×列),超过就整段替换,不建表 */
    private const MAX_BLOCK_CELLS = 250000;

    /** 字符级 LCS 的规模上限(改动段字数之积),超过就整段标记 */
    private const MAX_CHAR_CELLS = 200000;

    /** 输出时保留的块标签;不在表内的一律按段落处理 */
    private const BLOCK_TAGS = ['p', 'h2', 'h3', 'blockquote', 'li', 'pre', 'figcaption'];

    /**
     * @return array{left:string, right:string, left_paragraphs:int, right_paragraphs:int, changes:int}
     */
    public static function render(string $old, string $new): array
    {
        $left = self::blocks($old);
        $right = self::blocks($new);

        $leftOut = '';
        $rightOut = '';
        $changes = 0;

        foreach (self::gapGroups($left, $right) as $group) {
            if ($group['type'] === 'eq') {
                /** @var array{tag:string, text:string} $l */
                $l = $group['left'];
                $leftOut .= self::block($l['tag'], $l['text']);
                $rightOut .= self::block($l['tag'], $l['text']);

                continue;
            }

            $dels = $group['dels'];
            $inss = $group['inss'];
            $paired = min(count($dels), count($inss));

            for ($k = 0; $k < $paired; $k++) {
                $diff = self::inline(self::textOf($dels[$k]), self::textOf($inss[$k]));
                $leftOut .= self::block($dels[$k]['tag'], $diff['left'], true);
                $rightOut .= self::block($inss[$k]['tag'], $diff['right'], true);
                $changes += $diff['count'];
            }

            for ($k = $paired; $k < count($dels); $k++) {
                $leftOut .= self::block($dels[$k]['tag'], self::escape(self::textOf($dels[$k])), true, 'del');
                $changes++;
            }

            for ($k = $paired; $k < count($inss); $k++) {
                $rightOut .= self::block($inss[$k]['tag'], self::escape(self::textOf($inss[$k])), true, 'ins');
                $changes++;
            }
        }

        return [
            'left' => $leftOut,
            'right' => $rightOut,
            'left_paragraphs' => count($left),
            'right_paragraphs' => count($right),
            'changes' => $changes,
        ];
    }

    /**
     * 正文拆块:含块级标签按 HTML 解析,否则按换行分。只留标签白名单,块内取纯文本。
     *
     * @return list<array{tag:string, text:string}>
     */
    private static function blocks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        if (preg_match('/<(p|h2|h3|blockquote|li|pre|figcaption|ul|ol|div|figure)\b/i', $html) !== 1) {
            return self::plainBlocks($html);
        }

        // 与 HtmlSanitizer 同一口径:先转数字实体,免得 libxml 把中文读成乱码字节
        $packed = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x10FFFF], 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<div>' . $packed . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $dom->documentElement === null) {
            return self::plainBlocks($html);
        }

        $out = [];
        self::collect($dom->documentElement->childNodes, $out);

        return $out;
    }

    /**
     * @param \DOMNodeList<\DOMNode> $nodes
     * @param list<array{tag:string, text:string}> $out
     */
    private static function collect(\DOMNodeList $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof \DOMText) {
                $text = self::squash($node->data);

                if ($text !== '') {
                    $out[] = ['tag' => 'p', 'text' => $text];
                }

                continue;
            }

            if (!$node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->nodeName);

            // 列表容器/图集不自己成段,拆到子块里;段落级标签自己成块
            if (in_array($tag, ['ul', 'ol', 'div', 'figure'], true)) {
                self::collect($node->childNodes, $out);

                continue;
            }

            $text = self::squash($node->textContent);

            if ($text === '') {
                continue;
            }

            $out[] = [
                'tag' => in_array($tag, self::BLOCK_TAGS, true) ? $tag : 'p',
                'text' => $text,
            ];
        }
    }

    /**
     * 纯文本正文:按换行分块。
     *
     * @return list<array{tag:string, text:string}>
     */
    private static function plainBlocks(string $text): array
    {
        $out = [];
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\R/u', $decoded);

        foreach ($lines === false ? [] : $lines as $line) {
            $clean = self::squash(strip_tags($line));

            if ($clean !== '') {
                $out[] = ['tag' => 'p', 'text' => $clean];
            }
        }

        return $out;
    }

    /**
     * 把块序列对齐成 eq / gap 两组:eq 是逐字相同的块,gap 是"这中间删了什么、加了什么"。
     *
     * @param list<array{tag:string, text:string}> $a
     * @param list<array{tag:string, text:string}> $b
     * @return list<array{type:'eq', left:array{tag:string, text:string}}|array{type:'gap', dels:list<array{tag:string, text:string}>, inss:list<array{tag:string, text:string}>}>
     */
    private static function gapGroups(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if (($n + 1) * ($m + 1) > self::MAX_BLOCK_CELLS) {
            return [[
                'type' => 'gap',
                'dels' => $a,
                'inss' => $b,
            ]];
        }

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1]['text'] === $b[$j - 1]['text']
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        // 回溯成 eq / 缺口序列,再按缺口把连续的 del / ins 收成一组
        $steps = [];
        $i = $n;
        $j = $m;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1]['text'] === $b[$j - 1]['text']) {
                $steps[] = ['eq', $i - 1, $j - 1];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                $steps[] = ['ins', null, $j - 1];
                $j--;
            } else {
                $steps[] = ['del', $i - 1, null];
                $i--;
            }
        }

        $steps = array_reverse($steps);
        $groups = [];
        /** @var list<array{tag:string, text:string}> $dels */
        $dels = [];
        /** @var list<array{tag:string, text:string}> $inss */
        $inss = [];

        $flush = static function () use (&$groups, &$dels, &$inss): void {
            if ($dels !== [] || $inss !== []) {
                $groups[] = ['type' => 'gap', 'dels' => $dels, 'inss' => $inss];
                $dels = [];
                $inss = [];
            }
        };

        foreach ($steps as [$type, $ai, $bi]) {
            if ($type === 'eq') {
                $flush();
                $groups[] = ['type' => 'eq', 'left' => $a[$ai]];
            } elseif ($type === 'del') {
                $dels[] = $a[$ai];
            } else {
                $inss[] = $b[$bi];
            }
        }

        $flush();

        return $groups;
    }

    /**
     * 块内逐字比对:先掐公共前后缀,再对中间段做字符级 LCS。
     *
     * @return array{left:string, right:string, count:int}
     */
    private static function inline(string $a, string $b): array
    {
        if ($a === $b) {
            return ['left' => self::escape($a), 'right' => self::escape($b), 'count' => 0];
        }

        $ac = mb_str_split($a);
        $bc = mb_str_split($b);
        $n = count($ac);
        $m = count($bc);

        $pre = 0;
        $max = min($n, $m);

        while ($pre < $max && $ac[$pre] === $bc[$pre]) {
            $pre++;
        }

        $suf = 0;

        while ($suf < $max - $pre && $ac[$n - 1 - $suf] === $bc[$m - 1 - $suf]) {
            $suf++;
        }

        $prefix = implode('', array_slice($ac, 0, $pre));
        $suffix = $suf > 0 ? implode('', array_slice($ac, $n - $suf)) : '';
        $midA = array_slice($ac, $pre, $n - $pre - $suf);
        $midB = array_slice($bc, $pre, $m - $pre - $suf);

        $leftMid = '';
        $rightMid = '';
        $count = 0;

        if ($midA !== [] && $midB !== [] && count($midA) * count($midB) <= self::MAX_CHAR_CELLS) {
            [$leftMid, $rightMid, $count] = self::charLcs($midA, $midB);
        } else {
            if ($midA !== []) {
                $leftMid = '<del>' . self::escape(implode('', $midA)) . '</del>';
                $count++;
            }

            if ($midB !== []) {
                $rightMid = '<ins>' . self::escape(implode('', $midB)) . '</ins>';
                $count++;
            }
        }

        return [
            'left' => self::escape($prefix) . $leftMid . self::escape($suffix),
            'right' => self::escape($prefix) . $rightMid . self::escape($suffix),
            'count' => $count,
        ];
    }

    /**
     * 字符级 LCS,输出两侧的行内标记。返回 [左片段, 右片段, 标记处数]。
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return array{0:string, 1:string, 2:int}
     */
    private static function charLcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        $ops = [];
        $i = $n;
        $j = $m;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $ops[] = ['eq', $a[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                $ops[] = ['ins', $b[$j - 1]];
                $j--;
            } else {
                $ops[] = ['del', $a[$i - 1]];
                $i--;
            }
        }

        $ops = array_reverse($ops);
        $left = '';
        $right = '';
        $count = 0;
        /** @var 'eq'|'del'|'ins'|null $run */
        $run = null;
        $buf = '';

        $emit = static function () use (&$left, &$right, &$count, &$run, &$buf): void {
            if ($run === null) {
                return;
            }

            $text = self::escape($buf);

            if ($run === 'eq') {
                $left .= $text;
                $right .= $text;
            } elseif ($run === 'del') {
                $left .= '<del>' . $text . '</del>';
                $count++;
            } else {
                $right .= '<ins>' . $text . '</ins>';
                $count++;
            }

            $buf = '';
            $run = null;
        };

        foreach ($ops as [$type, $chr]) {
            if ($type !== $run) {
                $emit();
                $run = $type;
            }

            $buf .= $chr;
        }

        $emit();

        return [$left, $right, $count];
    }

    /**
     * @param array{tag:string, text:string} $block
     */
    private static function textOf(array $block): string
    {
        return $block['text'];
    }

    /**
     * 输出一个块。$inner 已含标记(或已转义),不再二次转义。
     */
    private static function block(string $tag, string $inner, bool $trusted = false, ?string $whole = null): string
    {
        $tag = in_array($tag, self::BLOCK_TAGS, true) ? $tag : 'p';
        $body = $trusted ? $inner : self::escape($inner);

        if ($whole !== null) {
            $body = '<' . $whole . '>' . $body . '</' . $whole . '>';
        }

        return '<' . $tag . '>' . $body . '</' . $tag . '>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 折空白(含不换行空格),段落内不留多余空白 */
    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }
}
