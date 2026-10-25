<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Transfer;

use Molin\Admin\View;
use Molin\Database\Database;
use Molin\Http\Response;
use Molin\Router\Router;

/**
 * 导入与导出屏的渲染(模板 `import-export.twig`)。导出成功、导入结果、报错、删除后重定向的落地页
 * 都走这里 —— 变量集只此一份,免得两栏各写各的再慢慢跑偏。
 */
final class TransferPage
{
    public function __construct(
        private readonly View $view,
        private readonly Database $db,
        private readonly Router $router,
        private readonly ExportStore $store
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     * @param array<string, mixed> $extra
     */
    public function render(array $menu, array $extra = [], int $status = 200): Response
    {
        $vars = [
            'page_kind' => 'import-export',
            'page_title' => __('导入与导出'),
            'error' => '',
            'export_url' => $this->router->path('admin', ['path' => 'import-export/export']),
            'import_url' => $this->router->path('admin', ['path' => 'import-export/import']),
            'scopes' => [
                ['value' => 'all', 'label' => __('全部内容（文章与页面）'), 'sel' => true],
                ['value' => 'post', 'label' => __('仅文章'), 'sel' => false],
                ['value' => 'page', 'label' => __('仅页面'), 'sel' => false],
            ],
            'media_choices' => [
                ['value' => 'content', 'label' => __('仅内容'), 'checked' => true],
                // 说真话(2026-10-17):这一项只写媒体引用,不打包文件 —— 标签里就别再暗示"带媒体走"
                ['value' => 'with', 'label' => __('内容 + 媒体引用'), 'checked' => false],
            ],
            'formats' => [
                ['value' => 'json', 'label' => 'JSON', 'sub' => __('默认'), 'checked' => true],
                ['value' => 'xml', 'label' => 'XML', 'sub' => '', 'checked' => false],
            ],
            'include_comments' => true,
            'strategies' => [
                ['value' => 'create', 'label' => __('新建'), 'checked' => true],
                ['value' => 'overwrite', 'label' => __('覆盖'), 'checked' => false],
                ['value' => 'skip', 'label' => __('跳过'), 'checked' => false],
                ['value' => 'merge', 'label' => __('合并'), 'checked' => false],
            ],
            'authors' => $this->authorOptions(),
            'export_result' => null,
            // 最近导出(2026-10-17 加):能重下、能删
            'exports' => $this->store->exportList(),
            'notice' => '',
            'picked' => null,
            'import_result' => null,
        ];

        return $this->view->render('import-export.twig', array_merge($vars, $extra), $menu, $status);
    }

    /**
     * 作者映射下拉:本站每个用户一条「原名 → 本站名」,首条默认选中;没人时兜底项给当前管理员。
     *
     * @return list<array{value:string, label:string, sel:bool}>
     */
    private function authorOptions(): array
    {
        $options = [];

        foreach ($this->db->select('SELECT username, display_name FROM ' . $this->db->table('users') . ' ORDER BY id') as $row) {
            $name = (string) $row['username'];
            $display = trim((string) ($row['display_name'] ?? ''));

            if ($display === '') {
                $display = (string) $row['username'];
            }

            $options[] = [
                'value' => $name,
                'label' => $name . __('（原站）→ ') . $display . __('（本站）'),
                'sel' => $options === [],
            ];
        }

        if ($options === []) {
            $options[] = ['value' => 'current', 'label' => __('无法匹配时归给当前管理员'), 'sel' => true];
        }

        return $options;
    }
}
