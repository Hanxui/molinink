<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

/**
 * 文章状态机。
 *
 * 只放行文档画出来的跃迁。文档没写的路径(private 还原、draft 直接进回收站)一律拒绝 ——
 * 状态机松一寸,后台和插件就会各按各的想象写,最后连"这篇到底算不算发布"都说不清。
 */
final class StatusMachine
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const PUBLISH = 'publish';
    public const PRIVATE = 'private';
    public const TRASH = 'trash';
    public const INHERIT = 'inherit';

    /** 新建文章允许的初始状态(inherit 只属于附件,归媒体库那轮) */
    public const CREATABLE = [self::DRAFT, self::PENDING, self::PUBLISH, self::PRIVATE];

    /**
     * from => 允许的 to。两条"任何状态都能去"的(destatable)不在这张表里抄:
     * 设成 private、进回收站 —— 都单独写在 canGo()。
     */
    private const TRANSITIONS = [
        self::DRAFT => [self::PENDING, self::PUBLISH],
        self::PENDING => [self::DRAFT, self::PUBLISH],
        self::PUBLISH => [self::DRAFT],
        self::TRASH => [self::DRAFT, self::PUBLISH],
        // private 与 inherit 的去向文档未定:要改状态请走回草稿,别开第二条没人认识的路
        self::PRIVATE => [],
        self::INHERIT => [],
    ];

    private const ALL = [self::DRAFT, self::PENDING, self::PUBLISH, self::PRIVATE, self::TRASH, self::INHERIT];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canGo(string $from, string $to): bool
    {
        if (!self::isKnown($from) || !self::isKnown($to)) {
            return false;
        }

        if ($from === $to) {
            // 保存但不改状态,是最常见的一次写
            return true;
        }

        if ($to === self::PRIVATE) {
            // `publish/any ──设为私密──→ private`。这里的 any 不含附件 ——
            // 附件的可见性随父(`attachment → inherit`),给它单独设私密没有意义。
            return $from !== self::INHERIT;
        }

        if ($to === self::TRASH) {
            // 既有规范(2026-09-30 作者授权改定):草稿、待审、已发布、私密一样能进回收站,
            // 与既有规范"每行都有回收站"对齐。附件(inherit)不走这条路,删除归媒体库。
            return $from !== self::TRASH && $from !== self::INHERIT;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * @return list<string> 当前状态能去哪,后台据此渲染按钮
     */
    public static function nextStates(string $from): array
    {
        $options = array_values(array_filter(
            self::ALL,
            static fn (string $to): bool => $to !== $from && self::canGo($from, $to)
        ));

        sort($options);

        return $options;
    }
}
