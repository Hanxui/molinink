<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Content\PostRepository;
use Molin\Content\StatusMachine;
use Molin\Content\TermAssigner;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;
use Molin\User\User;
use Throwable;

/**
 * 后台菜单编辑器(后台菜单编辑器-设计稿桌面 / 移动端两张)。
 *
 * 一页一菜单:顶部「菜单位」选当前要编的那一套(primary / footer),
 * 左栏是「可选项目」骨架(页面 / 分类 / 自定义链接 / 搜索),右栏是当前菜单的可编辑项树。
 * 整页只是一张表单 —— 改名称、改顺序、改层级、切菜单位都要一次保存,故底部固定保存栏。
 *
 * 数据模型(以本页为准先钉死,供作者追认):
 * - `nav_menus` option 存 JSON:list<menu>,menu = ['id'=>string,'name'=>string,'slug'=>string,'items'=>list<item>];
 * - item = ['id'=>string,'label'=>string,'url'=>string,'children'=>list<item>],**最多两级**(子项不再带 children);
 * - 只存名称与地址;顺序即数组序,层级即 children 归属(`parent` 字段是否存在本版用嵌套数组表达);
 * - 当前位 slug 即 menu.slug,与顶栏 select 选中值同源,不二次硬编码。
 *
 * 左栏「可选项目」读站点真数据:页面取已发布的 page,分类取 category 词条(既有规范要求实装读数据);
 * 「自定义链接」由用户在左栏自己填,「搜索」指向内核的 /search 路由。
 *
 * 既有规范共 17 条未定点,本版逐条自决,清单见交接说明;凡未拍板者一律只按"能开、能存、能读回"实现,不当作契约:
 * - §11.1/2 数据字段 → 见上;不引入 target/rel/aria/class/可见性/图标。
 * - §11.3 两级上限 → 保留(源件写死),子项不渲染第三层。
 * - §11.4 左栏四分组 → 保留源件四组,数据接真(页面 / 分类读库,自定义链接与搜索固定)。
 * - §11.5/6 无钩子、无能力 → 不挂任何 hook,不新增能力名(本页由外观门守卫,能力名沿用 theme.switch,)。
 * - §11.7 构建过程 → 不在本页做,本页只读写 JSON。
 * - §11.8 两枚「加入菜单」职责 → 页脚那枚收左栏勾中的项,分组里那枚收自定义链接那两个输入框(2026-10-16 定)。
 * - §11.9 折叠 → `.phead` 的箭头可折叠(2026-10-16 补)。
 * - §11.10 拖拽 → 按住三条短横可拖动改顺序、拖到下半截收作子项(2026-10-16 补,鼠标与触屏同一条路)。
 * - §11.11 左列层级 → 左列平铺不显层级;右列以 `.child` 缩进 + 更浅把手表达。
 * - §11.12 footer 无设计稿 → 同一结构套用,层级上限同为两级。
 * - §11.13 空态 → 右栏仍出标题与 `.loc`,无行时不出 `.mhd`,出 `.tip`。
 * - §11.14 两端 tip 不一致 → 逐端照既有规范各出一份(桌面带「名称随你改，」,移动不带)。
 * - §11.15 移动漏焦点环 → 全局 `.cb:focus` 已覆盖,两端一致。
 * - §11.16 首屏放不下 → 照设计稿不动,属改设计稿的事。
 * - §11.17 缓存失效 → 不触发(未定,不自造)。
 */
final class MenuController
{
    /** 菜单数据的 option 名 */
    private const OPTION = 'nav_menus';

    /** 上次保存时间(§11 未定,本版另存一枚旁路 option 供保存栏显示) */
    private const OPTION_UPDATED = 'nav_menus_updated';

    /** 左栏最多列多少项(页面多的站点不至于把整页拖垮;PostQuery 自己封顶 200) */
    private const PICK_LIMIT = 200;

    public function __construct(
        private readonly View $view,
        private readonly Options $options,
        private readonly Router $router,
        private readonly PostRepository $posts,
        private readonly TermAssigner $terms
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if ($path === 'menus/save') {
            if ($request->isPost()) {
                return $this->save($request, $user, $menu);
            }

            throw new NotFoundException('后台没有这个页面:' . $path);
        }

        if ($path === 'menus') {
            return $this->render($request, '', $menu);
        }

        if (str_starts_with($path, 'menus/')) {
            $id = substr($path, strlen('menus/'));

            if ($id !== '') {
                return $this->render($request, $id, $menu);
            }
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }

    /**
     * 写:整棵菜单树一次落库。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function save(Request $request, User $user, array $menu): Response
    {
        if (!$this->csrfOk($request)) {
            return $this->render($request, $request->input('menu'), $menu, __('表单已过期，请刷新后重试。'), 403);
        }

        $lists = $this->menus();
        $index = $this->indexOf($lists, $request->input('menu'));

        $inputs = $request->inputs();
        $items = $this->normalizeItems($inputs['items'] ?? [], true);
        $lists[$index]['items'] = $items;

        try {
            $this->options->set(self::OPTION, (string) json_encode($lists, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->options->set(self::OPTION_UPDATED, gmdate('Y-m-d H:i:s'));
        } catch (Throwable) {
            // 写不进去(库只读 / 磁盘满)只报一条人话,不让整屏崩掉
            return $this->render($request, $lists[$index]['slug'], $menu, __('保存没成功，请稍后再试。'), 500);
        }

        Audit::record('menu.update', 'menu:' . $lists[$index]['slug'], true, $request, 'user:' . $user->id);

        // 导航在每一页上,菜单一改整片缓存就都过期了 —— 与主题启停 / 主题设置同一条广播
        do_action('cache/invalidate/after', 'menu.update');

        return Response::redirect($this->url($lists[$index]['slug']));
    }

    /**
     * 读:渲染编辑器。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function render(Request $request, string $id, array $menu, string $error = '', int $status = 200): Response
    {
        $lists = $this->menus();
        $want = $id !== '' ? $id : $request->input('menu');
        $index = $this->indexOf($lists, $want);
        $current = $lists[$index];

        // 右栏行:父项在前,子项紧随其后(缩进表达层级);每行带自己的表单字段前缀,服务端照前缀重建树
        $rows = [];

        foreach ($current['items'] as $i => $item) {
            $rows[] = $this->row($item, 'items[' . $i . ']', false);

            foreach ($item['children'] as $j => $child) {
                $rows[] = $this->row($child, 'items[' . $i . '][children][' . $j . ']', true);
            }
        }

        // 菜单位下拉:文案 = slug（name）,全角括号
        $options = [];

        foreach ($lists as $m) {
            $options[] = [
                'slug' => $m['slug'],
                'label' => $m['slug'] . '（' . $m['name'] . '）',
                'selected' => $m['slug'] === $current['slug'],
            ];
        }

        return $this->view->render('menus.twig', [
            'page_kind' => 'menus',
            'page_title' => __('菜单'),
            'menu_options' => $options,
            'current' => $current['slug'],
            'current_name' => $current['name'],
            'groups' => $this->groups(),
            'rows' => $rows,
            'pick' => $this->pickKinds($request),
            'updated' => (string) ($this->options->get(self::OPTION_UPDATED, '') ?? ''),
            'save_url' => $this->router->path('admin', ['path' => 'menus/save']),
            'switch_url' => $this->router->path('admin', ['path' => 'menus']),
            'cancel_url' => $this->url($current['slug']),
            'error' => $error,
        ], $menu, $status);
    }

    /**
     * 一行菜单项(含表单字段前缀)。$item 只认 id / label / url 三键,children 等其余键不看。
     *
     * @param array<string, mixed> $item
     * @return array{field:string, id:string, label:string, url:string, child:bool}
     */
    private function row(array $item, string $field, bool $child): array
    {
        return [
            'field' => $field,
            'id' => (string) ($item['id'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'child' => $child,
        ];
    }

    /**
     * 读 `nav_menus`:能解出非空 list 就用,否则给骨架。
     *
     * @return list<array{id:string, name:string, slug:string, items:list<array<string, mixed>>}>
     */
    private function menus(): array
    {
        $raw = $this->options->get(self::OPTION);
        $list = [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $id = trim((string) ($entry['id'] ?? $entry['slug'] ?? ''));

                    if ($id === '') {
                        continue;
                    }

                    $name = trim((string) ($entry['name'] ?? $id));
                    $slug = trim((string) ($entry['slug'] ?? $id));

                    $list[] = [
                        'id' => $id,
                        'name' => $name === '' ? $id : $name,
                        'slug' => $slug === '' ? $id : $slug,
                        'items' => $this->normalizeItems($entry['items'] ?? []),
                    ];
                }
            }
        }

        return $list === [] ? $this->defaultMenus() : $list;
    }

    /**
     * 新站骨架:两项菜单位(primary / footer),items 都留空。
     * primary 留空 —— 顶部导航默认由前台自动列已发布页面(见 Renderer::pagesProvider);
     * 手动配置并保存后以保存值为准。
     *
     * @return list<array{id:string, name:string, slug:string, items:list<array<string, mixed>>}>
     */
    private function defaultMenus(): array
    {
        return [
            [
                'id' => 'primary',
                'name' => '主导航',
                'slug' => 'primary',
                'items' => [],
            ],
            [
                'id' => 'footer',
                'name' => '页脚',
                'slug' => 'footer',
                'items' => [],
            ],
        ];
    }

    /**
     * 归一化一棵菜单树:丢空名、最多两级、按需校验地址。
     *
     * @return list<array{id:string, label:string, url:string, children:list<array<string, mixed>>}>
     */
    private function normalizeItems(mixed $raw, bool $validateUrl = false): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $node) {
            if (!is_array($node)) {
                continue;
            }

            $label = trim((string) ($node['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $url = trim((string) ($node['url'] ?? ''));

            if ($validateUrl && !$this->plausibleUrl($url)) {
                continue;
            }

            $children = [];
            $rawChildren = $node['children'] ?? [];

            if (is_array($rawChildren)) {
                foreach ($rawChildren as $child) {
                    if (!is_array($child)) {
                        continue;
                    }

                    $childLabel = trim((string) ($child['label'] ?? ''));

                    if ($childLabel === '') {
                        continue;
                    }

                    $childUrl = trim((string) ($child['url'] ?? ''));

                    if ($validateUrl && !$this->plausibleUrl($childUrl)) {
                        continue;
                    }

                    // 第三层起丢弃:最多两级(§11.3)
                    $children[] = [
                        'id' => $this->idOf($child),
                        'label' => $childLabel,
                        'url' => $childUrl,
                        'children' => [],
                    ];
                }
            }

            $out[] = [
                'id' => $this->idOf($node),
                'label' => $label,
                'url' => $url,
                'children' => $children,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function idOf(array $node): string
    {
        $id = trim((string) ($node['id'] ?? ''));

        return $id !== '' ? $id : 'menu-' . bin2hex(random_bytes(4));
    }

    /**
     * 地址宽松校验:站内相对(/ 或 # 开头)或 http(s) 绝对地址视为合法,空视为"待填"。
     */
    private function plausibleUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }

        if ($url[0] === '/' || $url[0] === '#') {
            return true;
        }

        return preg_match('#\Ahttps?://\S+\z#i', $url) === 1;
    }

    /**
     * 左栏「可选项目」四分组:页面与分类读站点当前内容(只取已发布的页面),自定义链接与搜索是固定项。
     *
     * @return list<array<string, mixed>>
     */
    private function groups(): array
    {
        $pages = [];

        // 与前台自动列页面的口径一致(Renderer::pagesProvider):只取已发布的页面,按「序号」排
        $query = $this->posts->query()->type('page')->status(StatusMachine::PUBLISH)->orderBy('menu_order', 'asc', 'asc')->limit(self::PICK_LIMIT);

        foreach ($query->get() as $page) {
            $pages[] = ['label' => $page->title, 'url' => $page->url];
        }

        $cats = [];

        foreach ($this->terms->listAll(TermAssigner::CATEGORY) as $term) {
            // 「未分类」是内核给没归类的内容兜底的,不该被摆进导航
            if ((string) ($term['slug'] ?? '') === TermAssigner::UNCATEGORIZED) {
                continue;
            }

            $cats[] = [
                'label' => (string) ($term['name'] ?? ''),
                'url' => $this->router->path('category', ['slug' => (string) ($term['slug'] ?? '')]),
            ];
        }

        return [
            ['key' => 'pages', 'name' => __('页面'), 'items' => $pages],
            ['key' => 'categories', 'name' => __('分类'), 'items' => $cats],
            ['key' => 'custom', 'name' => __('自定义链接'), 'form' => true],
            ['key' => 'search', 'name' => __('搜索'), 'items' => [
                ['label' => __('搜索页'), 'url' => $this->router->path('search')],
            ]],
        ];
    }

    /**
     * 「链接到」下拉(可选):`?pick=链接` 时才出,列目标类型(文章 / 页面 / 分类 / 自定义链接)。
     *
     * @return array{enabled:bool, options:list<array{value:string, label:string}>}
     */
    private function pickKinds(Request $request): array
    {
        $pick = $request->input('pick');

        if ($pick !== '链接' && $pick !== 'link') {
            return ['enabled' => false, 'options' => []];
        }

        return ['enabled' => true, 'options' => [
            ['value' => 'article', 'label' => __('文章')],
            ['value' => 'page', 'label' => __('页面')],
            ['value' => 'category', 'label' => __('分类')],
            ['value' => 'custom', 'label' => __('自定义链接')],
        ]];
    }

    /**
     * @param list<array{id:string, name:string, slug:string, items:list<array<string, mixed>>}> $lists
     */
    private function indexOf(array $lists, string $want): int
    {
        foreach ($lists as $i => $m) {
            if ($want !== '' && ($m['slug'] === $want || $m['id'] === $want)) {
                return $i;
            }
        }

        return 0;
    }

    private function csrfOk(Request $request): bool
    {
        $session = Auth::session();

        return $session !== null && Csrf::check($session, $request->input(Csrf::FIELD));
    }

    /**
     * 页内地址:默认位不带 query,其余带 ?menu=。
     */
    private function url(string $slug): string
    {
        $base = $this->router->path('admin', ['path' => 'menus']);

        return $slug === '' || $slug === 'primary' ? $base : $base . '?menu=' . rawurlencode($slug);
    }
}
