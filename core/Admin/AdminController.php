<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Admin\Menu\MenuBuilder;
use Molin\Admin\Plugin\PluginPages;
use Molin\Admin\Settings\SettingsScreen;
use Molin\Admin\Tools\CacheTools;
use Molin\Backup\Backup;
use Molin\Backup\Cipher;
use Molin\Backup\DownloadTokens;
use Molin\Backup\Progress;
use Molin\Backup\Remote;
use Molin\Backup\Restore;
use Molin\Cache\Purger;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Option\Options;
use Molin\Plugin\Loader;
use Molin\Router\Router;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\Roles;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 后台外壳与分发。
 *
 * 两条硬规矩:① 未登录一律跳登录页;② 菜单按能力过滤**只是体验**,
 * 每个页面进来还要再校验一次能力(不能只藏菜单)。
 */
final class AdminController
{
    /** @var array<string, array{title:string, capabilities:list<string>, template:string, menu:string|null}> */
    private readonly array $pages;

    private readonly MenuBuilder $menuBuilder;

    private readonly SettingsScreen $settingsScreen;

    private readonly PluginPages $pluginPages;

    private readonly CacheTools $cacheTools;

    public function __construct(
        private readonly View $view,
        private readonly Options $options,
        private readonly Authorizer $can,
        private readonly Loader $plugins,
        private readonly UserRepository $users,
        private readonly Router $router,
        private readonly Database $db,
        private readonly PostsController $postScreen,
        private readonly MediaController $mediaScreen,
        private readonly Dashboard $dashboard,
        private readonly Purger $purger,
        private readonly CommentsController $commentScreen,
        private readonly Backup $backup,
        private readonly Restore $restore,
        private readonly DownloadTokens $tokens,
        private readonly Remote $remote,
        private readonly AppearanceController $appearanceScreen,
        private readonly PostsController $pageScreen,
        private readonly ProfileController $profileScreen,
        private readonly LogsController $logScreen,
        private readonly HealthController $healthScreen,
        private readonly UserEditController $userEditScreen,
        private readonly CategoriesController $categoriesScreen,
        private readonly RolesController $rolesScreen,
        private readonly RevisionController $revisionScreen,
        private readonly MenuController $menuScreen,
        private readonly ImportExportController $importExportScreen,
        private readonly UpdateController $updateScreen,
        private readonly TermAssigner $terms
    ) {
        /** @var array<string, array{title:string, capabilities:list<string>, template:string, menu:string|null}> $pages */
        $pages = require MOLIN_ROOT . '/core/Admin/pages.php';
        $this->pages = $pages;
        $this->menuBuilder = new MenuBuilder($this->pages, $this->can, $this->router, $this->dashboard);
        $this->settingsScreen = new SettingsScreen($this->view, $this->options, $this->router, $this->pageScreen, $this->terms);
        $this->pluginPages = new PluginPages($this->view, $this->options, $this->db, $this->plugins, $this->can, $this->router, $this->menuBuilder, $this->pages);
        $this->cacheTools = new CacheTools($this->router, $this->backup, $this->restore, $this->tokens, $this->remote, $this->purger, $this->progress());
    }

    public function handle(Request $request, string $path = ''): Response
    {
        $user = Auth::user();

        if ($user === null) {
            // 带着会话 cookie 却认不出人 = 会话过期 / 改过密码 / 被强制下线,回登录页要说一句
            $stale = $request->cookie(Auth::COOKIE) !== null ? '?expired=1' : '';

            return Response::redirect('/login' . $stale);
        }

        $path = trim($path, '/');
        $first = strtok($path, '/');
        $root = $first === false ? '' : $first;

        // 子路径(posts/7/edit 这类)按第一段认领这一栏,具体动作的更细能力由各屏自己再判一次
        $page = $this->pages[$path] ?? $this->pages[$root] ?? null;

        // 自定义内容类型(CPT)的后台屏:公开且非内置的已登记类型各占一段
        // `/admin/{type}`。内核页优先(上一句已判),CTP 再优先于插件页。
        if ($page === null) {
            $cpt = $this->menuBuilder->cptPage($root);

            if ($cpt !== null) {
                if (!$this->can->canAny($user, $cpt['capabilities'])) {
                    return $this->view->render('forbidden.twig', [
                        'page_title' => $cpt['label'],
                        'capability' => implode(' / ', $cpt['capabilities']),
                    ], $this->menu($root), 403);
                }

                // 复用文章屏那一套(列表 / 编辑 / 回收站),只把 managedType 换成这个类型
                return $this->postScreen->forType($root)->handle($request, $path, $user, $this->menu($root));
            }
        }

        // 插件后台页(既有规范):内核表里没有的路径交给已启用插件认领,认领不了就是 404
        if ($page === null) {
            return $this->pluginPages->pluginPage($request, $path, $user);
        }

        if (!$this->can->canAny($user, $page['capabilities'])) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __($page['title']),
                'capability' => implode(' / ', $page['capabilities']),
            ], $this->menu($root), 403);
        }

        if ($root === 'posts') {
            return $this->postScreen->handle($request, $path, $user, $this->menu($root));
        }

        if ($root === 'media') {
            return $this->mediaScreen->handle($request, $path, $user, $this->menu($root));
        }

        if ($root === 'cache') {
            return $this->cacheTools->clearCache($request, $path);
        }

        if ($root === 'tools') {
            // 备份 / 恢复的服务端进度:长任务在另一条请求里跑,浏览器轮这条问"到哪了"
            if ($path === 'tools/progress') {
                return Response::json($this->progress()->read());
            }

            // 远端凭据「测试连接」:就地探一次真写入,结果回 JSON,不跳页
            if ($path === 'tools/remote/test' && $request->isPost()) {
                return $this->cacheTools->remoteTest($request);
            }

            if ($request->isPost()) {
                return $this->cacheTools->toolsAction($request, $path);
            }

            $download = $this->cacheTools->toolsDownload($request, $path);

            if ($download !== null) {
                return $download;
            }
        }

        if ($root === 'pages') {
            return $this->pageScreen->handle($request, $path, $user, $this->menu($root));
        }

        if ($root === 'appearance') {
            return $this->appearanceScreen->handle($request, $path, $user, $this->menu($root));
        }

        if ($root === 'comments') {
            return $this->commentScreen->handle($request, $path, $user, $this->menu($root));
        }

        // 个人资料属「用户」组:侧栏当前项借 users 的位
        if ($root === 'profile') {
            return $this->profileScreen->handle($request, $path, $user, $this->menu('users'));
        }

        // 日志查看器属「工具」组:侧栏当前项借 tools 的位(既有规范结构树)
        if ($root === 'logs') {
            return $this->logScreen->handle($request, $path, $user, $this->menu('tools'));
        }

        // 站点健康同属「工具」组(既有规范结构树)
        if ($root === 'health') {
            return $this->healthScreen->handle($request, $path, $user, $this->menu('tools'));
        }

        // 用户新建与编辑:`users` 列表之外的两条路归这一屏,侧栏当前项仍是「用户」。
        // 进页能力由这一屏自判(保守档 user.create),不借列表页的 user.list
        if ($root === 'users' && $path !== 'users') {
            return $this->userEditScreen->handle($request, $path, $user, $this->menu('users'));
        }

        // 分类与标签:属「文章」组,紧排在文章之后;2026-10-11 起它有自己的侧栏项,
        // 所以当前项就是它自己(此前 menu:null,借的是 posts 的位)
        if ($root === 'categories') {
            return $this->categoriesScreen->handle($request, $path, $user, $this->menu('categories'));
        }

        // 角色能力矩阵:属「用户」组,侧栏当前项借 users 的位
        if ($root === 'roles') {
            return $this->rolesScreen->handle($request, $path, $user, $this->menu('users'));
        }

        // 修订版本比对:属「文章」组,侧栏当前项借 posts 的位
        if ($root === 'revisions') {
            return $this->revisionScreen->handle($request, $path, $user, $this->menu('posts'));
        }

        // 菜单编辑器:属「外观」组,侧栏当前项借 appearance 的位
        if ($root === 'menus') {
            return $this->menuScreen->handle($request, $path, $user, $this->menu('appearance'));
        }

        // 导入与导出:属「工具」组,侧栏当前项借 tools 的位
        if ($root === 'import-export') {
            return $this->importExportScreen->handle($request, $path, $user, $this->menu('tools'));
        }

        // 插件启停:两下 POST 动作归这一处,侧栏当前项仍是「插件」
        if ($root === 'plugins' && $path !== 'plugins') {
            return $this->pluginPages->pluginsAction($request, $path, $user);
        }

        // 内核更新:属「设置」组,侧栏当前项借 settings 的位
        if ($root === 'update') {
            return $this->updateScreen->handle($request, $path, $user, $this->menu('settings'));
        }

        if ($path === '' || $path === 'dashboard') {
            return $this->showDashboard($request, $user);
        }

        if ($request->isPost() && $path === 'settings') {
            return $this->settingsScreen->saveSettings($request, $this->data($request, 'settings'), $this->menu('settings'));
        }

        return $this->view->render(
            $page['template'],
            $this->data($request, $path) + ['page_title' => __($page['title']), 'page_kind' => $root],
            $this->menu($root)
        );
    }

    /**
     * 仪表盘。数据取数全在 Dashboard 这一处,这里只管"谁进得来、看到哪些动作"。
     */
    private function showDashboard(Request $request, User $user): Response
    {
        $notice = '';

        // 清理缓存那句反馈跟着重定向回来,数字是自己人写的,仍然按整数收一遍
        if ($request->input('done') === 'cache') {
            $notice = __('已清理编译缓存 %d 份。', 'molin', max(0, (int) $request->input('n', '0')));
        }

        return $this->view->render(
            'dashboard.twig',
            $this->dashboard->payload(
                $this->can->can($user, 'comment.moderate'),
                $this->can->can($user, 'cache.manage')
            ) + ['page_title' => __('仪表盘'), 'notice' => $notice],
            $this->menu('')
        );
    }

    public function siteName(): string
    {
        return $this->settingsScreen->siteName();
    }

    /**
     * 备份 / 恢复的服务端进度槽。单槽位,落在 storage/backups/ 下。
     */
    private function progress(): Progress
    {
        return new Progress(MOLIN_ROOT);
    }

    /**
     * 一级菜单(按能力过滤,既有规范)。`$active` 是这一屏的路径首段,
     * 侧栏要靠它画出那条 2px 暖赭竖条 —— 设计稿里"当前项"就是这个样子。
     *
     * @return list<array{title:string, path:string, active:bool, badge?:string, icon?:string}>
     */
    public function menu(string $active = ''): array
    {
        return $this->menuBuilder->menu($active);
    }

    /**
     * 备份列表的展示形状:大小与时间在这里折算好,模板不做算术。
     * 时间照设计稿做相对显示:今天 / 昨天 / 更早给日期。
     *
     * @return list<array<string, string|bool>>
     */
    private function backupRows(): array
    {
        $rows = [];

        foreach ($this->backup->list() as $backup) {
            $bytes = (int) $backup['size'];
            $name = (string) $backup['name'];
            $rows[] = [
                'name' => $name,
                'size_text' => $bytes >= 1048576
                    ? round($bytes / 1048576, 1) . ' MB'
                    : round($bytes / 1024, 1) . ' KB',
                'time_text' => $this->timeText((int) $backup['created_at']),
                'source' => __((string) ($backup['source'] ?? '手动')),
                'encrypted' => (bool) ($backup['encrypted'] ?? false),
                'download_url' => $this->router->path('admin', ['path' => 'tools']) . '?download=' . rawurlencode($name),
            ];
        }

        return $rows;
    }

    /**
     * 列表里有没有明文备份。有,就把"拿到文件=读到整站"这层后果说出来 ——
     * 只是不显示「已加密」标签等于什么都没说,不能指望操作者自己反推。
     *
     * @param list<array<string, string|bool>> $rows
     */
    private function hasUnencrypted(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['encrypted'] ?? false) === false) {
                return true;
            }
        }

        return false;
    }

    private function timeText(int $timestamp): string
    {
        $today = (int) strtotime('today');

        if ($timestamp >= $today) {
            return __('今天 %s', 'molin', date('H:i', $timestamp));
        }

        if ($timestamp >= $today - 86400) {
            return __('昨天 %s', 'molin', date('H:i', $timestamp));
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Request $request, string $path): array
    {
        $restore = $request->input('restore');
        // 只有过得了文件名正则的才配进确认卡:外部随便填一个不该被回显
        $target = preg_match(Backup::FILENAME, $restore) === 1 ? $restore : '';
        $targetFile = $target === '' ? null : $this->backup->path($target);
        $targetEncrypted = $targetFile !== null && Cipher::isEncryptedFile($targetFile);
        // 只给工具页读备份目录:别的屏不需要为一次 glob 付账
        $backups = $path === 'tools' ? $this->backupRows() : [];

        // 远端凭据对话框:?remote=驱动打开,标签页走服务端切换
        $remoteParam = $request->input('remote');
        $remoteOpen = in_array($remoteParam, Remote::DRIVERS, true);
        $remoteTab = $remoteOpen ? $remoteParam : 's3';

        // 插件页每次进入都重扫:有人直接 FTP 换过目录时,mtime/size 可能还是旧的,缓存认不出来
        if ($path === 'plugins') {
            $this->plugins->scan(true);
        }

        return match ($path) {
            'tools' => [
                'page_kind' => 'tools',
                'notice' => $this->cacheTools->toolNotice($request),
                'backups' => $backups,
                // 只要列表里有一份是明文,工具页就把"拿得到文件=读得到整站"这句话摆出来
                'has_unencrypted' => $this->hasUnencrypted($backups),
                'tools_url' => $this->router->path('admin', ['path' => 'tools']),
                'create_url' => $this->router->path('admin', ['path' => 'tools/backups/create']),
                'delete_url' => $this->router->path('admin', ['path' => 'tools/backups/delete']),
                'restore_url' => $this->router->path('admin', ['path' => 'tools/backups/restore']),
                'target' => $target,
                'target_encrypted' => $targetEncrypted,
                'dialog_open' => $remoteOpen,
                'remote_tab' => $remoteTab,
                'remote_configured' => $this->remote->configured($remoteTab),
                'remote_values' => $this->remote->prefill($remoteTab),
                'remote_save_url' => $this->router->path('admin', ['path' => 'tools/remote/save']),
                'remote_clear_url' => $this->router->path('admin', ['path' => 'tools/remote/clear']),
                'remote_test_url' => $this->router->path('admin', ['path' => 'tools/remote/test']),
                'remote_any' => $this->remote->readAll() !== [],
                // 备份 / 恢复进度轮询的端点
                'progress_url' => $this->router->path('admin', ['path' => 'tools/progress']),
            ],
            'plugins' => [
                'page_kind' => 'plugins',
                'notice' => $this->pluginPages->pluginFlash($request),
                'available' => $this->plugins->available(),
                'active' => $this->plugins->activeNames(),
                'broken' => $this->plugins->broken(),
                // 启用名单里有、但本趟没能加载的(目录被删/清单坏了):站点照常起,这里如实报
                'skipped' => $this->plugins->skipped(),
                // 离线上传 zip:本地装包,只装入不启用
                'upload_url' => $this->router->path('admin', ['path' => 'plugins/upload']),
            ],
            // page_kind 供外观用:用户组两屏的二级导航样式挂在 .page-users 上(2026-10-17 补)
            'users' => ['page_kind' => 'users', 'users' => $this->users->paginate(), 'roles' => Roles::all()],
            'settings' => $this->settingsScreen->viewVars($request) + ['cache_status' => $this->cacheTools->cacheStatus()],
            default => [],
        };
    }

}
