<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use FilesystemIterator;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Theme\Theme;
use Molin\User\Auth;
use Molin\User\User;
use Molin\Widget\Builtin;
use Molin\Widget\Layout;
use Molin\Widget\Registry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * 后台挂件屏(《108-后台挂件实现规格》,基准 后台挂件-定稿 / 后台挂件-移动端-定稿)。
 *
 * 挂件布局 = "哪个区、放哪些挂件、什么顺序"。本页只决定排布,不管显隐
 * (显隐归主题模板)。整页不滚动,`.content` 是唯一滚动区,底部一条保存栏。
 *
 * ── 数据落点与结构(§11-1 / §11-2 未定项,此处自决并登记)──
 * 《21》§7 的内置 option 名表里没有挂件布局这一项,本内核先落在 option `theme_widgets`(JSON):
 *   {
 *     "updated": "Y-m-d H:i:s",                 // 上次写入时间(UTC),给保存栏用
 *     "areas": [
 *       { "id": "sidebar", "widgets": [
 *           { "type": "search", "title": "搜索框", "sub": "放在最上面，读者一眼能找到", "from": "主题自带" },
 *           ...
 *       ] },
 *       { "id": "footer", "widgets": [ ... ] }
 *     ]
 *   }
 * 同区同 type 不重复、跨区可重复(照基准图:左栏全集可反复加,区内去重)。
 *
 * ── 左栏「可用挂件」清单(§11-5 注册 API)──
 * 2026-10-16:注册口已落地 —— 内核在 Bootstrap 里登记五个内置挂件(`Molin\Widget\Builtin`),
 * 主题与插件经全局函数 `register_widget()` 往上加;左栏读的就是 `Molin\Widget\Registry` 这一份
 * 登记表,与前台 `widget_area()` 助手同源(名称、说明、行序全从那里来)。
 * 本屏未走 Bootstrap 的路径(测试、CLI)在构造函数里补登记一次,已登记过的不动。
 *
 * ── 无表单控件的取舍(§9「全页无输入框/下拉/复选框」)──
 * 页面可见部分确实零控件(无焦点环);但布局要在无 JS 下也能增删并保存,
 * 故在 `<form>` 内放一组 `widgets[区][序][字段]` 隐藏域当数据通道(不可见、不产生焦点环),
 * 纯按钮提交:右栏每行的「移除」、左栏每项的「加到侧栏 / 加到页脚」、保存栏的「保存布局」。
 * 增删只改内存排布后原样回屏(不落库),「保存布局」才写 option 并留痕 —— 与基准「改完再保存」一致。
 *
 * 其余未定项:拖拽顺序(§11-8)、实例配置(§11-6)、`.empty` 空态(§11-7)、footer 收集钩子(§11-3)
 * 本轮都不实装,保持与基准图一致,不臆造交互。
 */
final class WidgetController
{
    /** 挂件布局落点:option 名(《21》§7 未登记,本内核自定,见类注释) */
    private const OPTION = 'theme_widgets';

    /** 属性名:仅本屏动作路径与页面路径用 */
    private const PAGE = 'widgets';

    /**
     * 挂件区:目前只有 sidebar / footer 两个(《13》§5 supports.widgets 口径)。
     * cn = 中文区名;add = 左栏按钮文案;drop/tip 分桌面与移动两版(§7 逐字,移动侧栏 tip 少末句)。
     */
    private const AREAS = [
        'sidebar' => [
            'cn' => '侧栏区',
            'add' => '加到侧栏',
            'drop' => '拖到这里，或从左边「加到侧栏」',
            'drop_m' => '拖到这里，或从上面「加到侧栏」',
            'tip' => '侧栏在哪些页面出现、要不要显示，由主题模板决定，这里只管排什么。',
            'tip_m' => '侧栏在哪些页面出现、要不要显示，由主题模板决定。',
        ],
        'footer' => [
            'cn' => '页脚区',
            'add' => '加到页脚',
            'drop' => '拖到这里，或从左边「加到页脚」',
            'drop_m' => '拖到这里，或从上面「加到页脚」',
            'tip' => '页脚区在两条版权之上；版权位是程序固定的，挂件盖不住、也删不掉。',
            'tip_m' => '页脚区在两条版权之上；版权位是程序固定的，挂件盖不住、也删不掉。',
        ],
    ];

    /** 空库骨架搬去 `Molin\Widget\Layout`(前台 widget_area() 也读同一份),这里不再留副本 */

    public function __construct(
        private readonly View $view,
        private readonly Options $options,
        private readonly Router $router
    ) {
        // 左栏清单与排布校验都源自注册表;Bootstrap 之外的路径(测试、CLI)在这里补登记一次,
        // 已登记过的(含插件注册的)不动 —— Registry 同名第一次赢。
        Builtin::register(Registry::instance());
    }

    /**
     * 挂件登记表:名称 / 说明 / 取数口,行序即左栏行序。
     *
     * @return array<string, array{name: string, desc: string, body: callable|null}>
     */
    private function widgets(): array
    {
        return Registry::instance()->all();
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if ($path === self::PAGE && !$request->isPost()) {
            return $this->render($request, $menu);
        }

        if ($path === self::PAGE . '/save' && $request->isPost()) {
            return $this->save($request, $user, $menu);
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }

    /**
     * 写动作:① 增删按钮只改内存排布后原样回屏;② 「保存布局」才写 option 并留痕。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function save(Request $request, User $user, array $menu): Response
    {
        if (!$this->csrfOk($request)) {
            return $this->render($request, $menu, __(WebAuth::STALE), 403);
        }

        $inputs = $request->inputs();
        $layout = $this->layoutFrom($inputs['widgets'] ?? null);

        $do = trim((string) ($inputs['do'] ?? 'save'));
        $parts = explode(':', $do);

        // 增 / 删:只改内存,不落库(留到「保存布局」)
        if (($parts[0] === 'add' || $parts[0] === 'remove') && count($parts) === 3) {
            $layout = $this->apply($layout, $parts[0], $parts[1], $parts[2]);

            return $this->render($request, $menu, '', 200, $layout);
        }

        $payload = [
            'updated' => gmdate('Y-m-d H:i:s'),
            'areas' => $layout,
        ];

        $this->options->set(self::OPTION, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Audit::record('widget.update', self::OPTION, true, $request, 'user:' . $user->id);

        return Response::redirect($this->url());
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     * @param list<array{id:string, widgets:list<array{type:string, title:string, sub:string, from:string}>}>|null $layout
     */
    private function render(Request $request, array $menu, string $error = '', int $status = 200, ?array $layout = null): Response
    {
        $layout ??= $this->currentLayout();
        $updated = $this->updatedAt();

        $areas = [];

        foreach ($layout as $area) {
            $id = $area['id'];
            $meta = self::AREAS[$id];
            $rows = [];

            foreach ($area['widgets'] as $widget) {
                $rows[] = [
                    'type' => $widget['type'],
                    'title' => $widget['title'],
                    'sub' => $widget['sub'],
                    'from' => $widget['from'],
                ];
            }

            $areas[] = [
                'id' => $id,
                'cn' => $meta['cn'],
                'add' => $meta['add'],
                'drop' => $meta['drop'],
                'drop_m' => $meta['drop_m'],
                'tip' => $meta['tip'],
                'tip_m' => $meta['tip_m'],
                'count' => count($rows),
                'rows' => $rows,
            ];
        }

        $available = [];

        foreach ($this->widgets() as $type => $entry) {
            $buttons = [];

            foreach (self::AREAS as $areaId => $meta) {
                $buttons[] = [
                    'label' => $meta['add'],
                    'value' => 'add:' . $areaId . ':' . $type,
                ];
            }

            $available[] = [
                'type' => $type,
                'name' => $entry['name'],
                'desc' => $entry['desc'],
                'buttons' => $buttons,
            ];
        }

        return $this->view->render('widgets.twig', [
            'page_kind' => 'widgets',
            'page_title' => __('挂件'),
            // 当前主题到底画了哪几个挂件区(2026-10-17):没调 widget_area() 就等于"配了也看不见",
            // 这一屏得把实话说出来,而不是让站长对着一个不起作用的开关配置。
            'theme_name' => (string) $this->options->get('active_theme', 'molin-default'),
            'theme_used_areas' => $this->themeUsedAreas(),
            'areas' => $areas,
            'available' => $available,
            'save_url' => $this->router->path('admin', ['path' => self::PAGE . '/save']),
            'cancel_url' => $this->url(),
            'updated_date' => $updated === null ? '' : substr($updated, 0, 10),
            'updated_time' => $updated === null ? '' : substr($updated, 11, 5),
            'has_saved' => $updated !== null,
            'error' => $error,
        ], $menu, $status);
    }

    /**
     * 读库:解析 option,空库给默认骨架。
     *
     * @return list<array{id:string, widgets:list<array{type:string, title:string, sub:string, from:string}>}>
     */
    private function currentLayout(): array
    {
        $raw = $this->options->get(self::OPTION, '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded) || !isset($decoded['areas']) || !is_array($decoded['areas'])) {
            return $this->defaultLayout();
        }

        // 库里存的是规整后的次序(list<{id, widgets}>);换回 layoutFrom 吃的「区 → 行」形状再规整一次,
        // 否则整段会被当成未知形状丢掉(2026-10-04 批三收口真跑抓出:保存过的布局读回全空)。
        $map = [];

        foreach ($decoded['areas'] as $area) {
            if (is_array($area) && isset($area['id'], $area['widgets']) && is_array($area['widgets'])) {
                $map[(string) $area['id']] = $area['widgets'];
            }
        }

        return $this->layoutFrom($map);
    }

    /**
     * 上一趟写入时间(UTC 全字段);没保存过返回 null。
     */
    /**
     * 当前启用主题里真的调了哪些挂件区。
     *
     * 扫主题模板里的 `widget_area('xxx')` 字面量 —— 主题没调就等于"这一屏配了也看不见"。
     * 读不到主题(目录没了、theme.json 坏了)时返回空数组:宁可说"看不见",也不假装在显示。
     *
     * @return list<string>
     */
    private function themeUsedAreas(): array
    {
        $slug = (string) $this->options->get('active_theme', 'molin-default');

        try {
            $theme = Theme::load(MOLIN_ROOT . '/themes', $slug);
        } catch (Throwable) {
            return [];
        }

        $used = [];

        foreach ($theme->templateDirs() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $walker = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walker as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'twig') {
                    continue;
                }

                $source = (string) @file_get_contents($file->getPathname());

                if (preg_match_all('#widget_area\s*\(\s*[\'"]([a-z0-9_-]+)[\'"]#i', $source, $m) > 0) {
                    foreach ($m[1] as $area) {
                        $used[] = strtolower($area);
                    }
                }
            }
        }

        return array_values(array_unique($used));
    }

    /**
     * 上一趟写入时间(UTC 全字段);没保存过返回 null。
     */
    private function updatedAt(): ?string
    {
        $raw = $this->options->get(self::OPTION, '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded) || !isset($decoded['updated']) || !is_string($decoded['updated'])) {
            return null;
        }

        $stamp = trim($decoded['updated']);

        return $stamp === '' ? null : $stamp;
    }

    /**
     * 把「区 → 行[]」形状(POST 的 widgets[区][序][字段],或库里 areas 换回来的映射)
     * 规整成标准排布:只认已知区、只认已知挂件、同区同 type 去重、区序照 AREAS。
     *
     * @param mixed $input
     * @return list<array{id:string, widgets:list<array{type:string, title:string, sub:string, from:string}>}>
     */
    private function layoutFrom(mixed $input): array
    {
        if (!is_array($input) || $input === []) {
            return $this->defaultLayout();
        }

        $out = [];
        $known = $this->widgets();

        foreach (array_keys(self::AREAS) as $areaId) {
            $widgets = [];
            $seen = [];
            $rawRows = $input[$areaId] ?? [];

            if (is_array($rawRows)) {
                foreach ($rawRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $type = trim((string) ($row['type'] ?? ''));
                    $entry = $known[$type] ?? null;

                    // 未登记的 type 或同区重复一律丢弃 —— 手造请求进不来
                    if ($entry === null || isset($seen[$type])) {
                        continue;
                    }

                    $seen[$type] = true;
                    $widgets[] = [
                        'type' => $type,
                        'title' => $this->text($row['title'] ?? '', $entry['name']),
                        'sub' => $this->text($row['sub'] ?? '', ''),
                        'from' => $this->text($row['from'] ?? '', '主题自带'),
                    ];
                }
            }

            $out[] = ['id' => $areaId, 'widgets' => $widgets];
        }

        return $out;
    }

    /**
     * 增 / 删:在规整后的排布上就地改。
     *
     * @param list<array{id:string, widgets:list<array{type:string, title:string, sub:string, from:string}>}> $layout
     * @return list<array{id:string, widgets:list<array{type:string, title:string, sub:string, from:string}>}>
     */
    private function apply(array $layout, string $verb, string $areaId, string $needle): array
    {
        if (!isset(self::AREAS[$areaId])) {
            return $layout;
        }

        $known = $this->widgets();

        foreach ($layout as $index => $area) {
            if ($area['id'] !== $areaId) {
                continue;
            }

            if ($verb === 'add' && isset($known[$needle])) {
                $exists = false;

                foreach ($area['widgets'] as $widget) {
                    if ($widget['type'] === $needle) {
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $layout[$index]['widgets'][] = [
                        'type' => $needle,
                        'title' => $known[$needle]['name'],
                        'sub' => '',
                        'from' => '主题自带',
                    ];
                }
            }

            if ($verb === 'remove' && ctype_digit($needle)) {
                $at = (int) $needle;
                $widgets = array_values($area['widgets']);

                if (isset($widgets[$at])) {
                    unset($widgets[$at]);
                    $layout[$index]['widgets'] = array_values($widgets);
                }
            }

            break;
        }

        return array_values($layout);
    }

    /**
     * 从没配过时的默认排布:与前台 `widget_area()` 共用 `Molin\Widget\Layout` 那一份骨架,
     * 免得出现"后台看到四件、前台一件都没有"。
     *
     * @return list<array{id:string, widgets:list<array{type:string, title:string, sub:string, from:string}>}>
     */
    private function defaultLayout(): array
    {
        $out = [];

        foreach (array_keys(self::AREAS) as $areaId) {
            $out[] = ['id' => $areaId, 'widgets' => Layout::defaultRows($areaId)];
        }

        return $out;
    }

    private function text(mixed $value, string $fallback): string
    {
        $text = trim((string) $value);

        return $text === '' ? $fallback : $text;
    }

    private function csrfOk(Request $request): bool
    {
        $session = Auth::session();

        return $session !== null && Csrf::check($session, $request->input(Csrf::FIELD));
    }

    private function url(): string
    {
        return $this->router->path('admin', ['path' => self::PAGE]);
    }
}
