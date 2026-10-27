<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Menu;

use Molin\Admin\Dashboard;
use Molin\Content\PostTypeRegistry;
use Molin\Router\Router;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\Capabilities;
use Molin\User\User;

/**
 * 后台一级菜单的组装:内核页 + 自定义内容类型 + 插件加菜单。
 */
final class MenuBuilder
{
    /** @param array<string, array{title:string, capabilities:list<string>, template:string, menu:string|null}> $pages */
    public function __construct(
        private readonly array $pages,
        private readonly Authorizer $can,
        private readonly Router $router,
        private readonly Dashboard $dashboard
    ) {
    }

    /**
     * 一级菜单(按能力过滤,既有规范)。`$active` 是这一屏的路径首段,
     * 侧栏要靠它画出那条 2px 暖赭竖条 —— 设计稿里"当前项"就是这个样子。
     *
     * @return list<array{title:string, path:string, active:bool, badge?:string, icon?:string}>
     */
    public function menu(string $active = ''): array
    {
        $activeTitle = $this->pages[$active]['menu'] ?? $this->pages[$active]['title'] ?? null;
        $user = Auth::user();
        // 待审数量同时驱动侧栏徽标;零待审就不打扰
        $pending = $user !== null && $this->can->can($user, 'comment.moderate') ? $this->dashboard->pendingCount() : 0;
        $items = [];

        foreach ($this->pages as $path => $page) {
            if ($page['menu'] === null || !$this->can->canAny($user, $page['capabilities'])) {
                continue;
            }

            // URL 由路由名生成(既有规范条:禁止拼字符串)。
            // 根路径的键是空串,菜单指向 dashboard —— 同一屏,两个入口。
            // 菜单标题在这里才过 __():active 判定用原文比,不受语言影响
            $item = [
                'title' => __($page['menu']),
                'path' => $this->router->path('admin', ['path' => $path === '' ? 'dashboard' : $path]),
                'active' => $page['menu'] === $activeTitle,
            ];

            if ($path === 'comments' && $pending > 0) {
                $item['badge'] = (string) $pending;
            }

            $items[] = $item;
        }

        // 自定义内容类型各占一条侧栏项,排在「页面」之后(内容类聚在一处);
        // 「页面」不可见时退到菜单末尾。图标名一并带上,图标能力未落地时渲染层安全回落。
        $items = $this->spliceCptMenu($items, $user, $active);

        return $this->collectMenu($items);
    }

    /**
     * 自定义内容类型的后台入口:只认公开且非内置的已登记类型,一段一个。
     * 返回它的能力清单 —— 用类型的 capability_type 映射到既有能力,未登记的前缀回落 post.*。
     *
     * @return array{label:string, capabilities:list<string>}|null
     */
    public function cptPage(string $slug): ?array
    {
        $type = PostTypeRegistry::instance()->get($slug);

        if ($type === null || !$type['public']) {
            return null;
        }

        return ['label' => $type['label'], 'capabilities' => $this->typeCapabilities($slug)];
    }

    /**
     * 一个 CPT 的后台门控能力:按 capability_type 拼六个动作能力,没登记的前缀回落内核 post.*
     * (既有规范是插件申报词表,后台门控用内核已登记名;不新造能力名)。
     *
     * @return list<string>
     */
    private function typeCapabilities(string $slug): array
    {
        $prefix = PostTypeRegistry::instance()->capabilityType($slug);
        $caps = [];

        foreach (['create', 'edit.own', 'edit.others', 'publish', 'delete.own', 'delete.others'] as $action) {
            $candidate = $prefix . '.' . $action;
            $caps[] = $prefix !== 'post' && Capabilities::isKnown($candidate) ? $candidate : 'post.' . $action;
        }

        return $caps;
    }

    /**
     * 侧栏里的 CPT 项:公开且非内置、且当前用户至少握有一项动作能力,才出现在菜单里。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string, icon?:string}> $items
     * @return list<array{title:string, path:string, active:bool, badge?:string, icon?:string}>
     */
    private function spliceCptMenu(array $items, ?User $user, string $active): array
    {
        $cpt = [];

        foreach (PostTypeRegistry::instance()->all() as $slug => $type) {
            if (!$type['public'] || !$this->can->canAny($user, $this->typeCapabilities($slug))) {
                continue;
            }

            /** @var array{title:string, path:string, active:bool, icon?:string} $item */
            $item = [
                'title' => $type['label'],
                'path' => $this->router->path('admin', ['path' => $slug]),
                'active' => $slug === $active,
            ];
            $icon = PostTypeRegistry::instance()->menuIcon($slug);

            if ($icon !== '') {
                $item['icon'] = $icon;
            }

            $cpt[] = $item;
        }

        if ($cpt === []) {
            return $items;
        }

        // 插在「页面」那条之后;找不到「页面」(没权限)就退到末尾
        $pagesPath = $this->router->path('admin', ['path' => 'pages']);
        $at = null;

        foreach ($items as $index => $item) {
            if ($item['path'] === $pagesPath) {
                $at = $index;

                break;
            }
        }

        if ($at === null) {
            return array_merge($items, $cpt);
        }

        array_splice($items, $at + 1, 0, $cpt);

        return array_values($items);
    }

    /**
     * 插件加菜单(既有规范admin/menu/collect):逐条归一,形状不合的丢。
     * 钩子层的类型校验只在开发环境跑,生产环境全靠这道闸 —— 一条坏数据不能把侧栏打崩。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string, icon?:string}> $items
     * @return list<array{title:string, path:string, active:bool, badge?:string, icon?:string}>
     */
    private function collectMenu(array $items): array
    {
        $out = [];

        foreach ((array) apply_filters('admin/menu/collect', $items) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = $item['title'] ?? null;
            $path = $item['path'] ?? null;

            if (!is_string($title) || !is_string($path) || $title === '' || $path === '') {
                continue;
            }

            /** @var array<string, mixed> $item */
            $clean = ['title' => $title, 'path' => $path, 'active' => (bool) ($item['active'] ?? false)];
            $badge = $item['badge'] ?? null;

            if (is_string($badge) && $badge !== '') {
                $clean['badge'] = $badge;
            }

            // 图标名照原样带上(CPT 的 menu_icon);图标能力尚未落地时模板只当个属性写,不查表不报错
            $icon = $item['icon'] ?? null;

            if (is_string($icon) && $icon !== '') {
                $clean['icon'] = $icon;
            }

            $out[] = $clean;
        }

        return $out;
    }
}
