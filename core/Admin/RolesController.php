<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Plugin\Loader;
use Molin\Router\Router;
use Molin\User\Capabilities;
use Molin\User\Roles;
use Molin\User\User;

/**
 * 角色能力矩阵(《105-后台角色能力矩阵实现规格》)。只读屏:五列角色 × 内置/插件两组能力,
 * 实心墨块 = 有该能力,空心灰描边 = 无。无表单、无按钮、无保存栏,矩阵格不可点。
 *
 * 数据全走登记表:Roles::matrix() 出角色与格子,Capabilities 出能力名与说明;
 * 「插件注册」一组与「来源:xx」认已启用插件在 plugin.json 里的申报 —— register_capability()
 * 不带来源记录,清单申报才是可查的出处(《12》§能力声明)。
 *
 * 三处《105》没画到的交互取最小推定,已挂待办清单待作者追认:
 * - 列只画内置五角色(§11 第 5 条未定;脚注同句也声明自定义角色不进五列);
 * - 五个内置角色的职责说明落在这里取数 —— Roles 的注册 API 只有 label 没有描述栏,《105》§10 那句按现状兑不开;
 * - 插件能力按名称序进第二组;无插件能力时该组仍出「0 项」(§10:计数实时统计)。
 */
final class RolesController
{
    /** 五个内置角色的职责说明(《105》§7 表头;仅内置五角色,插件角色不进本表) */
    private const ROLE_NOTES = [
        Roles::ADMINISTRATOR => '全部能力',
        Roles::EDITOR => '内容全权，不管用户、插件、主题、设置',
        Roles::AUTHOR => '自己的内容，可发布',
        Roles::CONTRIBUTOR => '自己的内容，不能发布',
        Roles::SUBSCRIBER => '只读',
    ];

    /** 列序固定(《105》§7):管理员 / 编辑 / 作者 / 贡献者 / 订阅者 */
    private const COLUMNS = [
        Roles::ADMINISTRATOR,
        Roles::EDITOR,
        Roles::AUTHOR,
        Roles::CONTRIBUTOR,
        Roles::SUBSCRIBER,
    ];

    public function __construct(
        private readonly View $view,
        private readonly Loader $plugins,
        private readonly Router $router
    ) {
    }

    /**
     * @param list<array{title: string, path: string, active: bool, badge?: string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if ($path !== 'roles') {
            throw new NotFoundException('后台没有这个页面:' . $path);
        }

        $builtInNames = Capabilities::builtIn();
        $matrix = Roles::matrix();

        $roles = [];

        foreach (self::COLUMNS as $slug) {
            $roles[] = [
                'slug' => $slug,
                'label' => __(Roles::label($slug)),
                'note' => __(self::ROLE_NOTES[$slug]),
            ];
        }

        $builtIn = [];

        foreach ($builtInNames as $name) {
            $builtIn[] = ['name' => $name, 'cells' => $this->cells($matrix, $name)];
        }

        $registered = array_values(array_diff(Capabilities::names(), $builtInNames));
        sort($registered, SORT_STRING);

        $pluginRows = [];

        foreach ($registered as $name) {
            $label = Capabilities::label($name);

            $pluginRows[] = [
                'name' => $name,
                'label' => $label === $name ? '' : __($label),
                'cells' => $this->cells($matrix, $name),
            ];
        }

        $pluginRole = $this->firstPluginRole();

        return $this->view->render('roles.twig', [
            'page_kind' => 'roles',
            'page_title' => __('角色能力矩阵'),
            'roles' => $roles,
            'built_in' => $builtIn,
            'plugin_rows' => $pluginRows,
            'plugin_source' => $this->pluginSources(),
            'plugin_role' => $pluginRole,
            'plugins_url' => $this->router->path('admin', ['path' => 'plugins']),
            'users_url' => $this->router->path('admin', ['path' => 'users']),
        ], $menu);
    }

    /**
     * 一格一行:五列按固定列序各给一个实心/空心。
     *
     * @param array<string, list<string>> $matrix
     * @return list<bool>
     */
    private function cells(array $matrix, string $capability): array
    {
        $cells = [];

        foreach (self::COLUMNS as $slug) {
            $cells[] = in_array($capability, $matrix[$slug] ?? [], true);
        }

        return $cells;
    }

    /**
     * 脚注里的自定义角色示例 = 注册过的第一个插件角色(《105》§10;无则该句不渲染)。
     *
     * @return array{label: string, slug: string}|null
     */
    private function firstPluginRole(): ?array
    {
        foreach (array_keys(Roles::matrix()) as $slug) {
            if (in_array($slug, self::COLUMNS, true)) {
                continue;
            }

            return ['label' => __(Roles::label($slug)), 'slug' => $slug];
        }

        return null;
    }

    /**
     * 「来源:」的名单 = 已启用插件里、plugin.json 申报过插件能力的那些插件的标题(《12》)。
     *
     * @return list<string>
     */
    private function pluginSources(): array
    {
        $extra = array_diff(Capabilities::names(), Capabilities::builtIn());

        if ($extra === []) {
            return [];
        }

        $active = $this->plugins->activeNames();
        $titles = [];

        foreach ($this->plugins->ordered() as $plugin) {
            if (!in_array($plugin->name(), $active, true)) {
                continue;
            }

            if (array_intersect($plugin->manifest->capabilities(), $extra) === []) {
                continue;
            }

            $title = $plugin->manifest->title();

            if ($title !== '' && !in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }
}
