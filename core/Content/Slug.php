<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

/**
 * slug 生成。
 *
 * 只做纯字符串这一段:清洗、兜底、加后缀。"同 type 内查重"要连库,归 PostWriter。
 * 中文不转拼音也不剔除 —— 转成 ASCII 会把标题压成一串横线,反而更好看的 URL 丢了。
 */
final class Slug
{
    public static function slugify(string $text): string
    {
        $cleaned = mb_strtolower(trim($text), 'UTF-8');
        $cleaned = preg_replace('/[^\p{L}\p{N}]+/u', '-', $cleaned) ?? '';

        return trim($cleaned, '-');
    }

    /**
     * 手填优先 → 标题 → 兜底 slug。空串是非法输入,由调用方在写库前拦住。
     */
    public static function fromInput(string $provided, string $title, string $type, ?string $stamp = null): string
    {
        $candidate = self::slugify($provided !== '' ? $provided : $title);

        if ($candidate !== '') {
            return $candidate;
        }

        $stamp ??= date('Ymd-His');

        return self::slugify($type) . '-' . $stamp;
    }

    /**
     * 第 ④ 步:同 type 撞名时追加 -2 / -3 …
     */
    public static function withSuffix(string $slug, int $occurrence): string
    {
        return $occurrence <= 1 ? $slug : $slug . '-' . $occurrence;
    }
}
