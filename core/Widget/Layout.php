<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Widget;

/**
 * 挂件排布的默认骨架(《108》空库那四件)。
 *
 * 后台挂件屏与前台 `widget_area()` **共用这一份** —— 免得出现"后台看到四件、前台一件都没有"。
 * 一旦存过一次排布,前台一切以存的为准(哪怕某区存成空);这与前台菜单那条口径一致。
 */
final class Layout
{
    /** 区 => list<[type, 备注]>,行序即默认顺序 */
    public const DEFAULTS = [
        'sidebar' => [['search', '放在最上面，读者一眼能找到'], ['toc', ''], ['comments', '']],
        'footer' => [['categories', '']],
    ];

    /**
     * 该区的默认行,形状与 option 里存的一致(标题取注册表里的名称)。
     *
     * @return list<array{type: string, title: string, sub: string, from: string}>
     */
    public static function defaultRows(string $area): array
    {
        $known = Registry::instance()->all();
        $rows = [];

        foreach (self::DEFAULTS[$area] ?? [] as [$type, $sub]) {
            $rows[] = [
                'type' => $type,
                'title' => (string) ($known[$type]['name'] ?? $type),
                'sub' => $sub,
                'from' => '主题自带',
            ];
        }

        return $rows;
    }
}
