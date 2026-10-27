<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Plugin;

use Molin\Admin\Menu\MenuBuilder;
use Molin\Admin\View;
use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Hook\HookBus;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Logger\Log;
use Molin\Option\Options;
use Molin\Plugin\AdminPageContext;
use Molin\Plugin\AdminPageController;
use Molin\Plugin\Loader;
use Molin\Plugin\Plugin;
use Molin\Plugin\Settings;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\Capabilities;
use Molin\User\User;
use ReflectionClass;
use Throwable;

/**
 * 插件屏与插件后台页(既有规范/§14):启停删除、离线上传、回执,以及插件声明的后台页。
 */
final class PluginPages
{
    /** 插件启停与删除的回执:一句话说清结果,细节进日志 */
    private const PLUGIN_NOTICES = [
        'activated' => '插件已启用。',
        'deactivated' => '插件已停用。',
        'failed' => '这一步没有完成:插件清单坏了或依赖不够,详情见运行日志。',
        'deleted' => '插件已删除,目录一并清除,不可恢复。',
        'delete_active' => '启用中的插件不能删除,先停用。',
        'delete_unknown' => '没有这个插件,或它已经不在了。',
        'delete_broken' => '这个插件装不上（清单读不出来），删不了；请用下方「装不上的」表里的「清理」。',
        'delete_failed' => '删除没有完成:目录或数据库出了岔子,详情见运行日志。',
        'purged' => '装不上的插件已清理:目录与残留的注册记录一并删除。',
        'purge_failed' => '清理没有完成:目录或数据库出了岔子,详情见运行日志。',
        'not_writable' => '删不掉:plugins/ 目录不可写 —— 它的属主应该是运行网站的用户(一般是 www),请让服务器管理员改一下属主再试。',
        'expired' => '页面已过期,这一下什么都没改;刷新后再点一次。',
    ];

    /**
     * @param array<string, array{title:string, capabilities:list<string>, template:string, menu:string|null}> $pages
     */
    public function __construct(
        private readonly View $view,
        private readonly Options $options,
        private readonly Database $db,
        private readonly Loader $plugins,
        private readonly Authorizer $can,
        private readonly Router $router,
        private readonly MenuBuilder $menuBuilder,
        private readonly array $pages
    ) {
    }

    /**
     * 插件启停与删除。能力已由 handle 按「插件」页校验(plugin.activate),
     * 这里只认动作与 CSRF;启停的审计与钩子由 Loader 自己播报,删除照本文件备份那套
     * 带请求上下文记一条 plugin.delete。
     */
    public function pluginsAction(Request $request, string $path, User $user): Response
    {
        $session = Auth::session();

        if (!$request->isPost()) {
            return Response::redirect($this->router->path('admin', ['path' => 'plugins']));
        }

        // 令牌过期 / 会话没了:此前是静默回列表,看着像"点了没反应" —— 给一句为什么
        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($this->router->path('admin', ['path' => 'plugins']) . '?done=expired');
        }

        // 离线上传 zip动作最重,单开一条私有方法
        if ($path === 'plugins/upload') {
            return $this->uploadPlugin($request, $user);
        }

        $slug = $request->input('slug');
        $done = '';

        try {
            if ($path === 'plugins/activate') {
                $this->plugins->activate($slug);
                $done = 'activated';
            } elseif ($path === 'plugins/deactivate') {
                $this->plugins->deactivate($slug);
                $done = 'deactivated';
            } elseif ($path === 'plugins/delete') {
                if (in_array($slug, $this->plugins->activeNames(), true)) {
                    // 启用中的不能删(口径同主题屏):回一句为什么,磁盘与表都不动
                    $done = 'delete_active';
                } elseif (isset($this->plugins->broken()[$slug])) {
                    // 清单都读不出来的没有 Plugin 对象,delete() 无从下手 —— 指到「清理」那条路
                    $done = 'delete_broken';
                } elseif (!isset($this->plugins->available()[$slug])) {
                    // 名字先对到真实插件才谈得上删 —— 对不上就不认,别拿请求参数拼路径
                    $done = 'delete_unknown';
                } else {
                    $this->plugins->delete($slug);
                    Audit::record('plugin.delete', $slug, true, $request, 'user:' . (string) Auth::id());
                    $done = 'deleted';
                }
            } elseif ($path === 'plugins/purge') {
                // 「装不上的」那一栏的清理:名字认不认得出、能不能动文件,全在 Loader::purge 里判
                $dir = $request->input('dir');
                $this->plugins->purge($dir);
                Audit::record('plugin.purge', $dir, true, $request, 'user:' . (string) Auth::id());
                $done = 'purged';
            } else {
                return Response::redirect($this->router->path('admin', ['path' => 'plugins']));
            }
        } catch (Throwable $e) {
            // 目录不可写时给一句能对上现象的话(否则只剩"出了岔子",得去翻日志才知道是权限)
            $blocked = !$this->plugins->writable();

            if ($path === 'plugins/delete') {
                // 删不动(目录被占、磁盘报错):审计记 denied,细节进日志
                Log::channel('app')->warning('plugin.delete_failed', ['slug' => $slug, 'error' => $e->getMessage()]);
                Audit::record('plugin.delete', $slug, false, $request, 'user:' . (string) Auth::id());
                $done = $blocked ? 'not_writable' : 'delete_failed';
            } elseif ($path === 'plugins/purge') {
                Log::channel('app')->warning('plugin.purge_failed', ['dir' => $request->input('dir'), 'error' => $e->getMessage()]);
                Audit::record('plugin.purge', $request->input('dir'), false, $request, 'user:' . (string) Auth::id());
                $done = $blocked ? 'not_writable' : 'purge_failed';
            } else {
                // 启不起来(清单坏、依赖缺)不甩半张错误页:回列表给一句人话,细节进日志
                Log::channel('app')->warning('plugin.toggle_failed', ['slug' => $slug, 'error' => $e->getMessage()]);
                $done = 'failed';
            }
        }

        return Response::redirect($this->router->path('admin', ['path' => 'plugins']) . '?done=' . $done);
    }

    /**
     * 插件屏的「上传插件」(既有规范离线链):新装只解包落盘、不启用;同名已存在则按升级走
     * (自动停用 → 换代码 → 恢复原启用状态,详见 Loader::upgrade)。
     *
     * 校验、解压、备份、失败回滚全在 ZipInstaller + Loader,这里只管权限、回执与审计。
     */
    private function uploadPlugin(Request $request, User $user): Response
    {
        // 藏菜单不算防护:进页与上传都在这道闸上各校验一次
        if (!$this->can->can($user, 'plugin.activate')) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __('插件'),
                'capability' => 'plugin.activate',
            ], $this->menuBuilder->menu('plugins'), 403);
        }

        // 字段名与 plugins.twig 的 <input type="file" name="file"> 对齐
        $files = $request->uploads('file');

        if ($files === []) {
            return $this->pluginsRedirect('install_no_file', '');
        }

        try {
            $result = $this->plugins->install($files[0], replace: true, pluginsRoot: MOLIN_ROOT . '/plugins');
        } catch (Throwable $e) {
            // 包坏了 / 名不合规 / 越界 / 超限 / 版本不合:一句人话回列表,细节进日志
            Log::channel('app')->warning('plugin.upload_failed', ['error' => $e->getMessage()]);

            return $this->pluginsRedirect('install_failed', $e->getMessage());
        }

        $slug = $result['slug'];
        Audit::record('plugin.upload', $slug, true, $request, 'user:' . (string) Auth::id());

        if ($result['action'] === 'upgraded') {
            $msg = sprintf('%s %s → %s', $slug, $result['old_version'], $result['new_version']);

            if ($result['warning'] !== '') {
                $msg .= '；' . $result['warning'];
            }

            return $this->pluginsRedirect('upgraded', $msg);
        }

        return $this->pluginsRedirect('installed', $slug);
    }

    private function pluginsRedirect(string $done, string $msg): Response
    {
        $url = $this->router->path('admin', ['path' => 'plugins']) . '?done=' . $done;

        if ($msg !== '') {
            $url .= '&msg=' . rawurlencode($msg);
        }

        return Response::redirect($url);
    }

    /**
     * 插件屏的回执文案:结果一句话说清,细节进日志。
     * msg 是服务端自己写的结果细节(插件名 / 失败原因),按主题屏口径截到 300 字。
     */
    public function pluginFlash(Request $request): string
    {
        $done = $request->input('done');
        $msg = mb_substr($request->input('msg'), 0, 300);

        return match ($done) {
            'installed' => $msg === ''
                ? __('插件已装入，出现在列表里，还没启用。')
                : __('插件「%s」已装入，还没启用 —— 想用就按它那一行的「启用」。', 'molin', $msg),
            'install_failed' => __('安装没成功：%s', 'molin', $msg),
            'install_no_file' => __('没有收到 zip 文件，请重新选一次。'),
            'upgraded' => $msg === ''
                ? __('插件已升级。')
                : __('插件已升级：%s', 'molin', $msg),
            default => __(self::PLUGIN_NOTICES[$done] ?? ''),
        };
    }

    /**
     * 插件后台页解析(既有规范)。四条规矩:
     * 1) 只认**已启用**插件声明的 route —— 停用即 404,不留后门;
     * 2) route 不许占用内核已有路径(整条,或第一段与内核页重名 —— 内核优先);
     * 3) 视图缺文件就当作没声明(404),不让一个漏打包的插件把后台打成 500;
     * 4) 能力名必须是**已注册**的真能力(插件可在 bootstrap 里 register_capability()),
     *    否则退回 setting.manage —— 免得插件声明一个谁都不可能被授权的名字,页面永远进不去。
     */
    public function pluginPage(Request $request, string $path, User $user): Response
    {
        if ($path === '' || str_contains($path, '..')) {
            throw new NotFoundException('后台没有这个页面:' . $path);
        }

        $active = $this->plugins->activeNames();

        foreach ($this->plugins->ordered() as $plugin) {
            if (!in_array($plugin->name(), $active, true)) {
                continue;
            }

            foreach ($plugin->manifest->adminPages() as $declared) {
                if ($declared['route'] !== $path || $this->kernelOwns($declared['route'])) {
                    continue;
                }

                $views = $plugin->manifest->directory() . '/views';

                if (!is_file($views . '/' . $declared['template'])) {
                    continue;
                }

                return $this->servePluginPage($request, $user, $plugin, $declared, $views);
            }
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }

    /** 内核页优先:整条路径或它第一段已经是内核的,插件盖不住。 */
    private function kernelOwns(string $route): bool
    {
        $first = strtok($route, '/');

        return isset($this->pages[$route]) || ($first !== false && isset($this->pages[$first]));
    }

    /**
     * 渲染(或分发)一个插件后台页。
     *
     * @param array{route: string, title: string, capability: string, template: string, controller: string|null} $declared
     */
    private function servePluginPage(Request $request, User $user, Plugin $plugin, array $declared, string $views): Response
    {
        $title = $declared['title'] !== '' ? $declared['title'] : $plugin->manifest->title();
        $capability = $declared['capability'] !== '' && Capabilities::isKnown($declared['capability'])
            ? $declared['capability']
            : 'setting.manage';

        if (!$this->can->canAny($user, [$capability])) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __($title),
                'capability' => $capability,
            ], $this->menuBuilder->menu(''), 403);
        }

        // 视图命名空间要在控制器之前挂上:控制器回来的数据就是给这个模板用的
        $this->view->addPluginViews($plugin->name(), $views);

        $template = '@plugin-' . $plugin->name() . '/' . $declared['template'];
        $vars = ['page_title' => __($title), 'page_kind' => $declared['route']];
        $url = $this->router->path('admin', ['path' => $declared['route']]);
        $controller = $this->pluginController($plugin, $declared['controller']);

        // 没声明(或接不上)控制器 = 这一页没有写动作:POST 当作不存在的页面,
        // 免得页面上看着有个保存键、点了却什么都没发生
        if ($controller === null) {
            if ($request->isPost()) {
                throw new NotFoundException('后台没有这个页面:' . $declared['route']);
            }

            return $this->view->render($template, $vars, $this->menuBuilder->menu(''));
        }

        // 写动作的门由内核统一把住:会话 + CSRF。插件不必自己查,也不该另开一个免令牌的口子。
        // 令牌不对就一个字都不动,回本页 —— 与工具页那批写动作同一套口径
        if ($request->isPost()) {
            $session = Auth::session();

            if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
                return Response::redirect($url);
            }
        }

        // 控制器不在钩子回调/懒加载上下文里 —— 用 withPlugin 划出归属:
        // 插件在这一路上才认得出自己的清单,能力门卫才有把手。
        $result = HookBus::instance()->withPlugin(
            $plugin->name(),
            fn (): array|Response => $controller->handle(new AdminPageContext(
                $request,
                $user,
                $this->options,
                $this->db,
                Settings::for($plugin->name()),
                $plugin->name(),
                $declared['route'],
                $url
            ))
        );

        // 控制器要把这一趟接过去(重定向 / JSON / 发文件),原样回
        if ($result instanceof Response) {
            return $result;
        }

        return $this->view->render($template, $result + $vars, $this->menuBuilder->menu(''));
    }

    /**
     * 取插件后台页的控制器实例。三道运行期检查:类在、实现了接口、能无参构造。
     *
     * 任何一道不过都按"这一页没有控制器"办 —— 一个写动作没接好,不该把整页弄没。
     * 但也不静默:记一条插件日志,排查时看得见是谁、差在哪。
     */
    private function pluginController(Plugin $plugin, ?string $class): ?AdminPageController
    {
        if ($class === null) {
            return null;
        }

        if (!class_exists($class)) {
            $this->warnController($plugin, $class, '类不存在或没被自动加载');

            return null;
        }

        if (!is_subclass_of($class, AdminPageController::class)) {
            $this->warnController($plugin, $class, '没有实现 AdminPageController 接口');

            return null;
        }

        $reflection = new ReflectionClass($class);

        // 内核不往控制器里注依赖(也没给服务定位器),所以只认能无参构造的类
        if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            $this->warnController($plugin, $class, '不能无参构造(抽象类,或构造函数要参数)');

            return null;
        }

        $instance = $reflection->newInstance();

        return $instance instanceof AdminPageController ? $instance : null;
    }

    private function warnController(Plugin $plugin, string $class, string $why): void
    {
        Log::channel('plugin')->warning('plugin.admin_page.controller_unusable', [
            'plugin' => $plugin->name(),
            'controller' => $class,
            'reason' => $why,
        ]);
    }
}
