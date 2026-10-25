<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Admin\Transfer\DownloadTokens;
use Molin\Admin\Transfer\Exporter;
use Molin\Admin\Transfer\ExportStore;
use Molin\Admin\Transfer\ImportReader;
use Molin\Admin\Transfer\TransferPage;
use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\Authorizer;
use Molin\User\User;

/**
 * 导入与导出(《110-后台导入与导出实现规格》)。工具组下一屏,左右两栏:导出 / 导入。
 *
 * 本类只剩入口与路径分派,活都在 `Molin\Admin\Transfer\`:
 * Exporter(导出、下载、删除导出件)、ImportReader(导入)、ExportStore(导出目录与保留策略)、
 * DownloadTokens(一次性下载令牌)、TransferPage(本屏渲染)。
 *
 * 导出:把 posts / categories / tags / comments 收成通用结构(`molin_export: 1`),
 * JSON 或 XML 落盘到 storage/exports/,签发一次性限时下载令牌。
 * 导入:只读文件数据,永不执行其中代码;校验扩展名与版本标记后按 100 条一批写入,
 * 同名按 slug 冲突策略处理,作者匹配不上归当前管理员,媒体路径含 `../` 一律拒绝。
 */
final class ImportExportController
{
    private readonly Exporter $exporter;

    private readonly ImportReader $importer;

    private readonly TransferPage $page;

    public function __construct(
        private readonly View $view,
        Database $db,
        Options $options,
        Router $router,
        private readonly Authorizer $can
    ) {
        $tokens = new DownloadTokens();
        $store = new ExportStore($router, $tokens);
        $this->page = new TransferPage($view, $db, $router, $store);
        $this->exporter = new Exporter($db, $options, $router, $store, $tokens, $this->page);
        $this->importer = new ImportReader($db, $options, $this->page);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        // 藏菜单不算防护:进页与扣扳机都在这道闸上再校验一次(《61》§3.1)。
        // 口径与 pages.php 的 import-export 条目同源(《110》§10 的 backup.manage 属《62》插件申报词表,
        // 不在用户权限登记表;同工具区挂 setting.manage,2026-10-04 作者定)。
        if (!$this->can->can($user, 'setting.manage')) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __('导入与导出'),
                'capability' => 'setting.manage',
            ], $menu, 403);
        }

        if ($path === 'import-export') {
            $done = trim($request->input('done'));

            return $this->page->render($menu, [
                'notice' => $done === 'deleted' ? __('已删除那份导出文件。') : '',
            ]);
        }

        if ($path === 'import-export/export' && $request->isPost()) {
            return $this->exporter->export($request, $user, $menu);
        }

        if ($path === 'import-export/import' && $request->isPost()) {
            return $this->importer->import($request, $user, $menu);
        }

        if (str_starts_with($path, 'import-export/download/')) {
            return $this->exporter->download($request, $user, substr($path, strlen('import-export/download/')));
        }

        // 删掉一份导出文件(2026-10-17):以前只能下载,过期后既下不了也删不掉
        if ($path === 'import-export/export/delete' && $request->isPost()) {
            return $this->exporter->deleteExport($request, $user, $menu);
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }
}
