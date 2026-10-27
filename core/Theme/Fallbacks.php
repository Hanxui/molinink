<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Theme;

use Molin\Exception\ThemeException;

/**
 * 模板回退顺序。
 *
 * 找不到就近的文件就依次向上游找,最终兜到 index.twig —— 主题只写它想改的那几个文件。
 * §3.3 未给 author / date / attachment 单独链条,此处按 §3 通则"就近 → 兜到 index"落地。
 */
final class Fallbacks
{
    /** 页型 => 候选模板,顺序即优先级 */
    public const CHAINS = [
        'home' => ['front-page.twig', 'home.twig', 'index.twig'],
        // 首页显示=静态页面:挑中的页面走独立模板,主题没做就退回普通页面壳
        'front-page' => ['front-page.twig', 'page.twig', 'index.twig'],
        'attachment' => ['attachment.twig', 'index.twig'],
        'single' => ['single-{type}-{slug}.twig', 'single-{type}.twig', 'single.twig', 'index.twig'],
        // 自定义内容类型的归档列表:主题可给 `archive-{type}.twig` 单独接管,
        // 没有就用通用归档壳 `archive.twig`
        'archive' => ['archive-{type}.twig', 'archive.twig', 'index.twig'],
        'page' => ['page-{slug}.twig', 'page-{id}.twig', 'page.twig', 'index.twig'],
        'category' => ['category-{slug}.twig', 'category-{id}.twig', 'category.twig', 'archive.twig', 'index.twig'],
        'tag' => ['tag-{slug}.twig', 'tag-{id}.twig', 'tag.twig', 'archive.twig', 'index.twig'],
        'search' => ['search.twig', 'archive.twig', 'index.twig'],
        // 列表片段(既有规范list.fragment):只吐列表 + 分页,不含整页骨架。
        // 契约要求每条链最终兜到 index.twig;真缺 `list-fragment.twig` 时
        // 端点先一步明确 404(见 ListFragmentController),不会把整页当片段吐出去。
        'list-fragment' => ['list-fragment.twig', 'index.twig'],
        // 通知列表片段（站内通知侧栏）：只吐 <li> 列表，不含整页骨架
        'notifications' => ['notifications.twig', 'index.twig'],
        'not-found' => ['404.twig', 'index.twig'],
        'author' => ['author.twig', 'index.twig'],
        'date' => ['date.twig', 'index.twig'],
    ];

    /**
     * @param array<string, string|int> $context 用于替换 {type} {slug} {id}
     * @return list<string>
     */
    public static function candidates(string $kind, array $context = []): array
    {
        $chain = self::CHAINS[$kind] ?? null;

        if ($chain === null) {
            throw new ThemeException("未知页型,没有回退链: {$kind}");
        }

        $names = [];

        foreach ($chain as $template) {
            $names[] = (string) preg_replace_callback(
                '#\{([a-z_]+)\}#',
                static function (array $m) use ($template, $context): string {
                    $key = $m[1];

                    if (!isset($context[$key])) {
                        throw new ThemeException("回退链 {$template} 缺少上下文参数: {$key}");
                    }

                    return (string) $context[$key];
                },
                $template
            );
        }

        return $names;
    }
}
