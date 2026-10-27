<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Content\TermAssigner;
use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;
use Molin\User\User;

/**
 * 分类与标签管理。一页两态:页签切「分类 / 标签」,
 * 左右两栏结构不变,变的是字段集、列集与底注。
 *
 * 路子:列表 `categories`(GET,?tax= 切态、?edit= 回填、?del= 开确认)、
 * 写动作三条 POST(`categories/create` / `update` / `delete`),写完回列表。
 * 「增删改即时生效」,没有保存栏;删除的二次确认复用全站唯一模态件。
 *
 * 三处既有规范没画到的交互取最小推定,已挂待办清单待作者追认:
 * - 编辑 = 复用左栏表单回填(§6 给的二选一),标题改「编辑分类」、按钮改「保存修改」,多一枚「取消」;
 * - 父候选取「到第二层为止、非兜底、非自己与后代」(§6 文字口径;源件 options 只列了顶层三项);
 * - 「近义标签」只收不用 —— §10.6 第 3 条落点未定,照该条「只当表单上的一项提示」处理。
 */
final class CategoriesController
{
    public function __construct(
        private readonly View $view,
        private readonly TermAssigner $terms,
        private readonly Router $router
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if ($path === 'categories') {
            return $this->render($request, $menu);
        }

        if ($path === 'categories/create' && $request->isPost()) {
            return $this->create($request, $user, $menu);
        }

        if ($path === 'categories/update' && $request->isPost()) {
            return $this->update($request, $user, $menu);
        }

        if ($path === 'categories/delete' && $request->isPost()) {
            return $this->delete($request, $user, $menu);
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function create(Request $request, User $user, array $menu): Response
    {
        if (!$this->csrfOk($request)) {
            return $this->render($request, $menu, __(WebAuth::STALE), 403);
        }

        $tax = $this->taxOf($request);

        try {
            $termTaxonomyId = $this->terms->create(
                $this->taxonomyOf($tax),
                trim($request->input('name')),
                trim($request->input('slug')),
                (int) $request->input('parent', '0'),
                trim($request->input('description'))
            );
        } catch (ValidationException $e) {
            return $this->render($request, $menu, $this->firstError($e));
        }

        Audit::record('term.create', 'term:' . $termTaxonomyId, true, $request, 'user:' . $user->id);

        return Response::redirect($this->url(['tax' => $tax]));
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function update(Request $request, User $user, array $menu): Response
    {
        if (!$this->csrfOk($request)) {
            return $this->render($request, $menu, __(WebAuth::STALE), 403);
        }

        $tax = $this->taxOf($request);
        $target = $this->terms->findByTTid((int) $request->input('term', '0'));

        if ($target === null || $target['taxonomy'] !== $this->taxonomyOf($tax)) {
            throw new NotFoundException('这个词条不存在。');
        }

        try {
            $this->terms->update(
                $target['term_taxonomy_id'],
                trim($request->input('name')),
                trim($request->input('slug')),
                (int) $request->input('parent', '0'),
                trim($request->input('description'))
            );
        } catch (ValidationException $e) {
            return $this->render($request, $menu, $this->firstError($e));
        }

        Audit::record('term.update', 'term:' . $target['term_taxonomy_id'], true, $request, 'user:' . $user->id);

        return Response::redirect($this->url(['tax' => $tax]));
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function delete(Request $request, User $user, array $menu): Response
    {
        // 编辑器分类盒里那颗「×」走 XHR(不求导航、不离开没保存的稿子):成不成回 JSON,不回整页
        $ajax = strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';

        if (!$this->csrfOk($request)) {
            return $ajax
                ? Response::json(['ok' => false, 'error' => __(WebAuth::STALE)], 403)
                : $this->render($request, $menu, __(WebAuth::STALE), 403);
        }

        $tax = $this->taxOf($request);
        $target = $this->terms->findByTTid((int) $request->input('term', '0'));

        // 按钮不给未分类渲染;手造请求也在这里挡下 —— 网页静默回列表,XHR 得说明白
        if ($target === null || $target['taxonomy'] !== $this->taxonomyOf($tax) || $this->isUncategorized($target)) {
            return $ajax
                ? Response::json(['ok' => false, 'error' => __('这一项不能删除。')], 422)
                : Response::redirect($this->url(['tax' => $tax]));
        }

        $this->terms->remove($target['term_taxonomy_id']);
        Audit::record('term.delete', 'term:' . $target['term_taxonomy_id'], true, $request, 'user:' . $user->id);

        return $ajax
            ? Response::json(['ok' => true])
            : Response::redirect($this->url(['tax' => $tax]));
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function render(Request $request, array $menu, string $error = '', int $status = 200): Response
    {
        $tax = $this->taxOf($request);
        $taxonomy = $this->taxonomyOf($tax);
        $counts = $this->terms->counts();

        // 编辑态(?edit=):复用左栏表单回填;POST 失败时表单里本来就带着同一批字段
        $editing = null;
        $editId = (int) $request->input('edit', '0');

        if ($editId > 0) {
            $found = $this->terms->findByTTid($editId);

            if ($found !== null && $found['taxonomy'] === $taxonomy) {
                $editing = $found;
            }
        }

        // 表单值:POST 回显 > 编辑回填 > 空
        $values = ['name' => '', 'slug' => '', 'parent' => 0, 'description' => ''];

        if ($editing !== null) {
            $values = [
                'name' => $editing['name'],
                'slug' => $editing['slug'],
                'parent' => $editing['parent'],
                'description' => $editing['description'],
            ];
        }

        if ($request->isPost()) {
            $values = [
                'name' => trim($request->input('name')),
                'slug' => trim($request->input('slug')),
                'parent' => (int) $request->input('parent', '0'),
                'description' => trim($request->input('description')),
            ];
        }

        // 删除确认(?del=):复用全站唯一模态件
        $delete = null;
        $delId = (int) $request->input('del', '0');

        if ($delId > 0) {
            $found = $this->terms->findByTTid($delId);

            if ($found !== null && $found['taxonomy'] === $taxonomy && !$this->isUncategorized($found)) {
                $delete = [
                    'name' => $found['name'],
                    'term_id' => $found['term_taxonomy_id'],
                    'action_url' => $this->router->path('admin', ['path' => 'categories/delete']),
                    'cancel_url' => $this->url(['tax' => $tax]),
                ];
            }
        }

        $rows = [];

        foreach ($this->terms->listAll($taxonomy) as $item) {
            $rows[] = [
                'term_taxonomy_id' => $item['term_taxonomy_id'],
                'name' => $item['name'],
                'slug' => $item['slug'],
                'count' => $item['count'],
                'child' => $item['depth'] > 1,
                'view_url' => $this->router->path($tax === 'tag' ? 'tag' : 'category', ['slug' => $item['slug']]),
                'edit_url' => $this->url(['tax' => $tax, 'edit' => $item['term_taxonomy_id']]),
                'can_delete' => $item['slug'] !== TermAssigner::UNCATEGORIZED || $taxonomy !== TermAssigner::CATEGORY,
                'delete_url' => $this->url(['tax' => $tax, 'del' => $item['term_taxonomy_id']]),
            ];
        }

        $parents = [];

        if ($taxonomy === TermAssigner::CATEGORY) {
            foreach ($this->terms->parentChoices($editing['term_taxonomy_id'] ?? 0) as $choice) {
                $parents[] = [
                    'term_taxonomy_id' => $choice['term_taxonomy_id'],
                    'name' => $choice['name'],
                    'sel' => $choice['term_taxonomy_id'] === $values['parent'],
                ];
            }
        }

        return $this->view->render('categories.twig', [
            'page_kind' => 'categories',
            'page_title' => __('分类与标签'),
            'tax' => $tax,
            'tabs' => [
                ['tax' => 'category', 'label' => __('分类'), 'n' => $counts[TermAssigner::CATEGORY], 'url' => $this->url([]), 'active' => $tax === 'category'],
                ['tax' => 'tag', 'label' => __('标签'), 'n' => $counts[TermAssigner::TAG], 'url' => $this->url(['tax' => 'tag']), 'active' => $tax === 'tag'],
            ],
            'mode' => $editing === null ? 'add' : 'edit',
            'values' => $values,
            'parents' => $parents,
            'rows' => $rows,
            'create_url' => $this->router->path('admin', ['path' => 'categories/create']),
            'update_url' => $this->router->path('admin', ['path' => 'categories/update']),
            'cancel_url' => $this->url(['tax' => $tax]),
            'editing_id' => $editing['term_taxonomy_id'] ?? 0,
            'error' => $error,
            'delete' => $delete,
            'dialog_open' => $delete !== null,
        ], $menu, $status);
    }

    private function csrfOk(Request $request): bool
    {
        $session = Auth::session();

        return $session !== null && Csrf::check($session, $request->input(Csrf::FIELD));
    }

    private function taxOf(Request $request): string
    {
        return $request->input('tax') === 'tag' ? 'tag' : 'category';
    }

    private function taxonomyOf(string $tax): string
    {
        return $tax === 'tag' ? TermAssigner::TAG : TermAssigner::CATEGORY;
    }

    /**
     * @param array{taxonomy:string, slug:string} $row
     */
    private function isUncategorized(array $row): bool
    {
        return $row['taxonomy'] === TermAssigner::CATEGORY && $row['slug'] === TermAssigner::UNCATEGORIZED;
    }

    /**
     * 页内地址:缺省参数不进 URL(分类态、同屏动作路径都靠它拼)。
     *
     * @param array<string, string|int> $params
     */
    private function url(array $params): string
    {
        $clean = [];

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || $value === 0) {
                continue;
            }

            if ($key === 'tax' && $value === 'category') {
                continue;
            }

            $clean[$key] = (string) $value;
        }

        $base = $this->router->path('admin', ['path' => 'categories']);

        return $clean === [] ? $base : $base . '?' . http_build_query($clean);
    }

    private function firstError(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            if ($messages !== []) {
                return __($messages[0]);
            }
        }

        return __($e->getMessage());
    }
}
