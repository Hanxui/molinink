<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Backup\Backup;
use Molin\Backup\Progress;
use Molin\Backup\Restore;
use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Kernel;
use Molin\Logger\Audit;
use Molin\Logger\Log;
use Molin\Migrations\Migrator;
use Molin\Option\Options;
use Molin\Plugin\Manifest;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Update\Feed;
use Molin\Update\Installer;
use Molin\Update\Maintenance;
use Molin\Update\Package;
use Molin\User\Auth;
use Molin\User\User;

/**
 * 后台内核更新(《93-后台更新实现规格》)。
 *
 * 三态:可更新(清单里有更新版本)/ 当前最新(清单里没有)/ 体检未过(八项里有一项 ✗)。
 * 进页 `update`(GET);扣扳机 `update/run`(POST,带 CSRF)。
 * 哪条通道在「设置 → 更新」里选(2026-10-11 作者定:本页不再放第二处切换器),
 * 本页只读它、并据此拉清单与显示通道名。
 *
 * 更新清单取自带签名的更新源(《29》§13、§14):两条通道各一个源,超出官方源地址一律拒绝。
 * 九步链(《29》§3 第 3–9 步)在 `Molin\Update\Installer` 里,这里只负责装配依赖与报回执;
 * `update/progress` 是给前端那根进度条轮询用的(JSON),不是给浏览器直接看的页。
 * 门控能力名 `update.core`(《32》,已登记);本屏不新增能力名。
 */
final class UpdateController
{
    /** 两条通道(《93》§6.1;2026-10-10 作者定:去掉预发版) —— 一通道一源,值只用来查表与写库 */
    private const CHANNELS = [
        'stable' => ['label' => '正式版'],
        'dev' => ['label' => '开发版'],
    ];

    /** 自定义源地址(私有部署)。留空就用内置的官方源 */
    private const OPT_SOURCE = 'update_source';

    /** 新内核要求的最低 PHP(《29》§9 第 1 项) */
    private const MIN_PHP = '8.2.0';

    /** 体检第 2 项要求的扩展(《29》§9、《51》) */
    private const REQUIRED_EXT = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo'];

    /**
     * 九步名称与说明(《29》§3,《93》§6.3)。
     * 2026-10-13 作者定:第 ③ 步不再强制 —— 更新页默认不勾「升级前先做一次备份」,
     * 所以名称与说明跟着改口径(改前是「强制备份 / DB 全量 + 全部文件」)。
     */
    private const STEPS = [
        ['检查更新', '拉取更新清单，比对版本'],
        ['前置体检', '环境 / 扩展 / 磁盘 / PHP 版本 / 待执行迁移'],
        ['备份', 'DB 全量 + 全部文件（默认跳过，可勾选）'],
        ['进入维护模式', '对外 503，仅管理员可访问'],
        ['下载+校验', '下载包 → 核对 sha256 →（可选）验签'],
        ['替换文件', '旧文件冗余保留为 .bak，不直接删'],
        ['执行迁移', '逐条跑 core/Migrations/*，记录到 db_version'],
        ['体检+清缓存', '跑契约测试、清全部缓存、重建 hookmap / route'],
        ['退出维护模式', '失败则回滚文件 + 还原 DB → 报错退出'],
    ];

    private readonly Feed $feed;

    public function __construct(
        private readonly View $view,
        private readonly Options $options,
        private readonly Router $router,
        // 体检八项是升级域自己的运行时探测(《93》§6.2),不复用健康屏本地化后的行;
        // Health 按外壳契约注入,读模型接入后在此取用(§12.5)
        private readonly Health $health,
        ?Feed $feed = null,
        private readonly ?Database $db = null
    ) {
        // 清单收取器自己造一个就够;留出注入位,测试才好拿假源比划
        $this->feed = $feed ?? new Feed($options);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        // 藏菜单不算防护:进页与扣扳机都在这道闸上再校验一次(《61》§3.1)
        if (!$user->holds('update.core')) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __('程序更新'),
                'capability' => 'update.core',
            ], $menu, 403);
        }

        if ($path === 'update' && !$request->isPost()) {
            return $this->render($request, $menu);
        }

        if ($path === 'update/run' && $request->isPost()) {
            return $this->run($request, $menu);
        }

        // 长任务在一条请求里跑完,浏览器另开一条来问"到哪了"(《35》§5 那一套)
        if ($path === 'update/progress' && !$request->isPost()) {
            return $this->progressJson();
        }

        // 收尾:上一次升级断在半路(锁还在)时,把文件与库收回升级前的样子再清锁。
        // 2026-10-17 接上:Installer::recover() 早写好了也测过,但全仓只有测试在调它 ——
        // 真断在半路时站长只能手工删 storage/trash/update/.lock.json。
        if ($path === 'update/recover' && $request->isPost()) {
            return $this->recover($request);
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }

    /**
     * 扣"收尾"这颗扳机:令牌不对不动手;装不起来就如实说,不假装成功。
     */
    private function recover(Request $request): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($this->url());
        }

        $installer = $this->installer();

        if ($installer === null) {
            Audit::record('core.update.recover', 'unavailable', false, $request, 'user:' . (string) Auth::id());

            return Response::redirect($this->url() . '?done=recover-unavailable');
        }

        $result = $installer->recover();

        Audit::record(
            'core.update.recover',
            'ok:' . ($result['ok'] ? '1' : '0'),
            $result['ok'],
            $request,
            'user:' . (string) Auth::id()
        );

        // 收不回去的那句要原样带给站长(它已经是一句人话),别只回一个"失败"
        return Response::redirect(
            $this->url() . '?done=' . ($result['ok'] ? 'recovered' : 'recover-failed')
            . ($result['ok'] ? '' : '&why=' . rawurlencode($result['message']))
        );
    }

    /**
     * 进度槽位的当下状态,给页面轮询用。只读,不碰任何东西。
     */
    private function progressJson(): Response
    {
        return new Response(
            (string) json_encode($this->jobState(), JSON_UNESCAPED_UNICODE),
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    /**
     * 扣扳机:令牌不对一律回本页,不动手(《26》§7:写操作带 CSRF 并记审计)。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function run(Request $request, array $menu): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($this->url());
        }

        // 取消:只在有锁(正在跑 / 断在半路)时有意义,落一个标志,任务在步骤之间看到就停
        if ($request->input('do') === 'cancel') {
            $this->installer()?->requestCancel();

            return Response::redirect($this->url() . '?done=cancelled');
        }

        return $this->upgrade($request);
    }

    /**
     * 真动手:把清单交给执行链(《29》§3 第 3–9 步)。走完/退回原地都在执行链里发生。
     */
    private function upgrade(Request $request): Response
    {
        $installer = $this->installer();
        $feed = $this->feed->check($this->channel(), $this->customSource());
        $available = $this->availableVersion($feed);

        // 没得升、或这台机器接不上执行链(没有数据库),如实说"没做任何改动"
        if ($installer === null || $available === null) {
            Audit::record('core.update', Kernel::VERSION . ' -> ?', false, $request, 'user:' . (string) Auth::id());

            return Response::redirect($this->url() . '?done=unavailable');
        }

        // 备份默认不做(2026-10-13 作者定):勾了「升级前先做一次备份」才做。
        // 不勾 = 要过二次确认 —— 页面提交前会问一句并把 ack 置 1;这里再校验一次,绕过页面也拦得住。
        $skipBackup = $request->input('do_backup') !== '1';

        if ($skipBackup && $request->input('ack') !== '1') {
            Audit::record('core.update', Kernel::VERSION . ' -> ' . $available['version'], false, $request, 'user:' . (string) Auth::id());

            return Response::redirect($this->url() . '?done=nobackup');
        }

        $result = $installer->run(
            $feed['manifest'],
            $feed['url'],
            Kernel::VERSION,
            !$this->hasFailure($this->checks()),
            !$skipBackup
        );

        Audit::record(
            'core.update',
            Kernel::VERSION . ' -> ' . $available['version'],
            $result['ok'],
            $request,
            'user:' . (string) Auth::id()
        );

        return Response::redirect($this->url() . '?done=' . ($result['ok'] ? 'updated' : 'failed'));
    }

    /**
     * 执行链:九步都在 Installer 里,这里只把依赖装配齐(装配不出来就返回 null,别装一半)。
     */
    private function installer(): ?Installer
    {
        if ($this->db === null) {
            return null;
        }

        $root = MOLIN_ROOT;
        $backup = new Backup($this->db, $root);

        return new Installer(
            $root,
            new Package($root),
            new Maintenance($this->options),
            $backup,
            new Restore($backup, $this->db, $root),
            new Migrator($this->db, $root . '/core/Migrations'),
            new Progress($root, Installer::PROGRESS)
        );
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function render(Request $request, array $menu): Response
    {
        $channel = $this->channel();
        // 开这一页就顺带检查一次(受 6 小时限频);`?check=1` 是强制一次(受 60 秒限频)
        $feed = $this->feed->check($channel, $this->customSource(), $request->input('check') === '1');
        $available = $this->availableVersion($feed);
        $checks = $this->checks();
        $blocked = $this->hasFailure($checks);
        $state = $blocked ? 'blocked' : ($available === null ? 'latest' : 'ready');
        // 作业状态读一次、用完即销,见 takeJobState
        $job = $this->takeJobState();

        $vars = [
            'page_kind' => 'update',
            'page_title' => __('程序更新'),
            'update_url' => $this->url(),
            'run_url' => $this->router->path('admin', ['path' => 'update/run']),
            'progress_url' => $this->router->path('admin', ['path' => 'update/progress']),
            'recover_url' => $this->router->path('admin', ['path' => 'update/recover']),
            'state' => $state,
            'current' => $this->currentCard($feed, $available),
            'available' => $available,
            'checks' => $checks,
            'alert' => $this->alert($blocked),
            'progress' => $this->progress($available, $job),
            'steps' => $this->steps(),
            'log' => $this->log($feed['manifest'], $available),
            'rollback' => $this->rollback($feed['manifest'], $feed['url']),
        ];

        $notice = $this->notice($request);

        if ($notice !== '') {
            $vars['notice'] = $notice;
        }

        // 取不到清单:界面只留「暂时无法检查」一句,原因写进运行日志(那里没有域名,只有一句人话)
        if (!$feed['ok'] && $feed['attempted']) {
            Log::channel('app')->warning(__('没能取到更新清单：') . $this->reasonText($feed['reason']));
        }

        return $this->view->render('update.twig', $vars, $menu);
    }

    /** 自定义源地址(空串 = 用内置的官方源) */
    private function customSource(): string
    {
        return trim((string) $this->options->get(self::OPT_SOURCE, ''));
    }

    /** 这一趟回来的口头回执(浮窗由 layout 统一摆) */
    private function notice(Request $request): string
    {
        return match ($request->input('done')) {
            'checked' => __('已检查更新。'),
            'updated' => __('已升级完成。'),
            'failed' => __('这次升级没成，已经退回升级前的状态（详情见运行日志）。'),
            'cancelled' => __('已请求取消：任务会在下一个安全点停下并退回。'),
            'nobackup' => __('没做二次确认，所以没有开始升级。要跳过备份，请在弹出来的确认里点确定。'),
            'recovered' => __('已恢复到升级前的状态。'),
            'recover-failed' => __('收尾没成功：') . $request->input('why', ''),
            'recover-unavailable' => __('这台机器接不上升级执行链（没有数据库），没有改动任何文件。'),
            'unavailable' => __('没有可用的新版本，没有改动任何文件。'),
            default => '',
        };
    }

    /** 把内部失败代号翻成一句人话 —— 不把 reason 直接摊到界面上 */
    private function reasonText(string $reason): string
    {
        return match (true) {
            $reason === 'bad_source' => __('更新源不是 https，已拒绝', 'molin'),
            $reason === 'no_http' => __('本机既没装 curl，也没开 allow_url_fopen，连不出去', 'molin'),
            $reason === 'timeout' => __('连更新源超时', 'molin'),
            $reason === 'unreachable' => __('连不上更新源（域名或端口不通）', 'molin'),
            $reason === 'tls' => __('更新源的 HTTPS 证书没通过校验', 'molin'),
            $reason === 'unsigned' => __('清单没有签名，已拒绝', 'molin'),
            $reason === 'bad_signature' => __('清单签名不对，已拒绝', 'molin'),
            $reason === 'bad_json' => __('清单不是合法 JSON', 'molin'),
            $reason === 'unusable' => __('清单与这条通道对不上，或包地址不合规', 'molin'),
            str_starts_with($reason, 'http_') => __('更新源回了 %s', 'molin', substr($reason, 5)),
            default => __('没能取到清单', 'molin'),
        };
    }

    /**
     * 可用新版本:清单里的版本比当前高才算「可更新」(《93》§8)。
     *
     * @param array{ok:bool, reason:string, from_cache:bool, attempted:bool, source:string, url:string, checked_at:int, manifest:array<string,mixed>} $feed
     * @return array{version:string, channel:string, label:string, date:string, notes:string}|null
     */
    private function availableVersion(array $feed): ?array
    {
        return Feed::newer($feed['manifest'], Kernel::VERSION, $this->channel(), $feed['url']);
    }

    /**
     * 当前版本卡的三行(《93》§5.1 / §6.1)。
     *
     * **不摆更新源地址** —— 那是内部接线,站长不需要知道,界面上也不该出现。
     * 「可用新版本」只说结果:有新版就报版本号,没有就说「暂无更新」,取不到清单就说「暂时无法检查」;
     * 取不到的原因记进运行日志,不摊到界面上。
     *
     * @param array{ok:bool, reason:string, from_cache:bool, attempted:bool, source:string, url:string, checked_at:int, manifest:array<string,mixed>} $feed
     * @param array{version:string, channel:string, label:string, date:string, notes:string}|null $available
     * @return array{version:string, released:string, contract:string, php:string, available:string}
     */
    private function currentCard(array $feed, ?array $available): array
    {
        $released = $feed['manifest']['release_date'] ?? '';
        $hasManifest = $feed['manifest'] !== [];

        return [
            'version' => Kernel::VERSION,
            'released' => is_string($released) ? $released : '',
            'contract' => $this->contractText(),
            'php' => PHP_VERSION,
            'available' => $available !== null
                ? $available['label'] . '（' . self::CHANNELS[$this->channel()]['label'] . '）'
                : ($hasManifest ? __('暂无更新') : __('暂时无法检查')),
        ];
    }

    private function channel(): string
    {
        $channel = $this->options->get('update_channel', 'dev');

        return is_string($channel) && isset(self::CHANNELS[$channel]) ? $channel : 'dev';
    }

    /**
     * 前置体检八项(《93》§6.2 的七项 + 2026-10-11 线上事故补的"程序目录属主")。
     * 第 1、2、7 项按运行时实测;第 3–6 项本轮由假数据改为真测:
     * 磁盘(disk_free_space + 全站体积)、待执行迁移(Migrator)、插件契约(plugin.json contract vs MOLIN_CONTRACT)、
     * 最近备份(storage/backups 最新一份)。任一项拿不到真数据就显示「无法评测」并中止 —— 不写死 ok。
     *
     * @return list<array{ok:bool, name:string, value:string, fix:string, advisory?:bool}>
     */
    private function checks(): array
    {
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $missing = array_values(array_filter(
            self::REQUIRED_EXT,
            static fn (string $name): bool => !extension_loaded($name)
        ));
        $extOk = $missing === [];
        $writable = is_writable(MOLIN_ROOT . '/storage') && is_writable(MOLIN_ROOT);

        return [
            [
                'ok' => $phpOk,
                'name' => __('PHP 版本满足新程序要求'),
                'value' => PHP_VERSION . ' ≥ 8.2',
                'fix' => $phpOk ? '' : __('升级 PHP 到 8.2 或更高，再重新体检。'),
            ],
            [
                'ok' => $extOk,
                'name' => __('必需扩展齐全'),
                'value' => $extOk ? implode(' · ', self::REQUIRED_EXT) : __('缺少：') . implode('、', $missing),
                'fix' => $extOk ? '' : __('在 php.ini 里开启缺失的扩展后，重启 PHP 再重新体检。'),
            ],
            $this->diskRow(),
            $this->migrationRow(),
            $this->pluginRow(),
            $this->backupRow(),
            [
                'ok' => $writable,
                'name' => __('目录可写（storage/、根目录）'),
                'value' => $writable ? __('均可写') : __('有目录不可写'),
                'fix' => $writable ? '' : __('检查 storage/ 与站点根目录的属主与权限，改好后重新体检。'),
            ],
            $this->ownershipRow(),
        ];
    }

    /**
     * 程序目录属主:第 6 步替换程序文件靠 rename() 把旧目录挪进留档区,而 rename() 一个目录
     * 要求进程拥有该目录本身(不是"父目录可写"就行)。属主不对(如 assets 归 root)不影响站点
     * 日常运行,只有升级到第 6 步才暴露,且报错不说原因 —— 2026-10-11 线上事故,所以在这里先摆出来。
     *
     * 只检 Installer::REPLACE 里在站点上存在的项;不存在的跳过(那是"缺失",不是"属主不对")。
     * 非 POSIX(Windows 等)拿不到运行用户 → 这一项判「不适用」,不报失败。
     *
     * @return array{ok:bool, name:string, value:string, fix:string}
     */
    private function ownershipRow(): array
    {
        $name = __('程序目录的属主属于运行网站的用户');
        $user = Health::runningUser();

        // 非 POSIX / 拿不到运行用户:环境的差异,如实说「不适用」,不拦升级
        if ($user === null) {
            return ['ok' => true, 'name' => $name, 'value' => __('不适用'), 'fix' => ''];
        }

        $bad = [];
        $fix = [];

        foreach (Installer::REPLACE as $rel) {
            $path = MOLIN_ROOT . '/' . $rel;

            if (!file_exists($path)) {
                continue;
            }

            $problem = Health::ownershipProblem($path, $user['uid'], $user);

            if ($problem !== null) {
                $bad[] = $rel;
                $fix[] = $problem;
            }
        }

        if ($bad === []) {
            return ['ok' => true, 'name' => $name, 'value' => __('均属于运行用户'), 'fix' => ''];
        }

        return [
            'ok' => false,
            'name' => $name,
            'value' => __('不属于运行用户：') . implode('、', $bad),
            'fix' => implode("\n", $fix),
        ];
    }

    /**
     * 磁盘:disk_free_space(站点根) 对比全站体积。读不到就「无法评测」并中止。
     *
     * @return array{ok:bool, name:string, value:string, fix:string}
     */
    private function diskRow(): array
    {
        $name = __('磁盘剩余空间 ≥ 2× 站点体积');
        $free = $this->health->diskFreeBytes();
        $site = $this->health->siteSizeBytes();

        if ($free < 0 || $site < 0) {
            return [
                'ok' => false,
                'name' => $name,
                'value' => __('无法评测'),
                'fix' => __('读不到磁盘空余或站点体积，确认磁盘与目录可访问后重新体检。'),
            ];
        }

        $ok = $free >= 2 * $site;

        return [
            'ok' => $ok,
            'name' => $name,
            'value' => __('剩余 %s GB ≥ 2× 站点体积 %s GB', 'molin', $this->gigabyte($free), $this->gigabyte($site)),
            'fix' => $ok ? '' : __('磁盘空余不足，清理文件或旧备份后重新体检。'),
        ];
    }

    /**
     * 迁移:真查 core/Migrations/Migrator 的待执行数。查不到就「无法评测」;有真冲突就中止。
     *
     * @return array{ok:bool, name:string, value:string, fix:string}
     */
    private function migrationRow(): array
    {
        $name = __('无未完成的事务 / 待执行的数据库升级冲突');
        $pending = $this->health->pendingMigrationCount();

        if ($pending < 0) {
            return [
                'ok' => false,
                'name' => $name,
                'value' => __('无法评测'),
                'fix' => __('查不到待执行的数据库升级，确认数据库可连接后重新体检。'),
            ];
        }

        if ($pending > 0) {
            return [
                'ok' => false,
                'name' => $name,
                'value' => __('尚有 %d 项数据库升级待执行', 'molin', $pending),
                'fix' => __('先跑完数据库升级（否则升级会撞结构冲突），再重新体检。'),
            ];
        }

        return [
            'ok' => true,
            'name' => $name,
            'value' => __('无待执行的数据库升级'),
            'fix' => '',
        ];
    }

    /**
     * 插件契约:对每个已启用插件跑 Manifest::load(它内部就按 MOLIN_CONTRACT 校验 contract)。
     * load 抛异常即该插件不兼容,列出来给用户看(《29》§9 第 5 项)。清单读不全按「无法评测」中止。
     *
     * @return array{ok:bool, name:string, value:string, fix:string}
     */
    private function pluginRow(): array
    {
        $name = __('所有插件与新程序兼容');
        $active = $this->activePlugins();

        if ($active === []) {
            return [
                'ok' => true,
                'name' => $name,
                'value' => __('无已启用插件'),
                'fix' => '',
            ];
        }

        $compatible = 0;
        $bad = [];

        foreach ($active as $slug) {
            try {
                Manifest::load(MOLIN_ROOT . '/plugins/' . $slug, MOLIN_CONTRACT);
                $compatible++;
            } catch (\Throwable) {
                // 目录缺、清单非法、contract 版本不符……都算不兼容,列名给用户拍板
                $bad[] = $slug;
            }
        }

        if ($bad !== []) {
            return [
                'ok' => false,
                'name' => $name,
                'value' => $compatible . ' / ' . count($active) . ' ' . __('兼容') . ' · ' . __('不兼容：') . implode('、', $bad),
                'fix' => __('停用或升级不兼容插件后重新体检。'),
            ];
        }

        return [
            'ok' => true,
            'name' => $name,
            'value' => $compatible . ' / ' . count($active) . ' ' . __('兼容'),
            'fix' => '',
        ];
    }

    /**
     * 备份:storage/backups 最近一份的文件时间与大小。没有就照实写「尚无备份」。
     *
     * 2026-10-13 作者定:这一项是 **advisory(只说事,不拦人)** —— 没有备份不再判体检未过,
     * 只是照实显示「尚无备份」,让作者自己决定要不要勾那一下备份。
     *
     * @return array{ok:bool, name:string, value:string, fix:string, advisory:bool}
     */
    private function backupRow(): array
    {
        $name = __('最近一次备份存在且可用');
        $latest = $this->health->latestBackup();

        if ($latest === null) {
            return [
                'ok' => false,
                'advisory' => true,
                'name' => $name,
                'value' => __('尚无备份'),
                'fix' => __('升级不再强求先备份：直接点「开始升级」会再问你一次。想要保险就先做一份备份。'),
            ];
        }

        return [
            'ok' => true,
            'advisory' => true,
            'name' => $name,
            'value' => gmdate('Y-m-d H:i', $latest['created_at']) . ' · ' . $this->humanBytes($latest['size']),
            'fix' => '',
        ];
    }

    /**
     * 启用的插件名(白名单外的只读派生缓存;升级前体检用足够)。
     *
     * @return list<string>
     */
    private function activePlugins(): array
    {
        $raw = $this->options->get('active_plugins', '[]');
        $names = json_decode((string) $raw, true);
        $list = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if (is_string($name) && $name !== '') {
                $list[] = $name;
            }
        }

        return $list;
    }

    private function gigabyte(int $bytes): string
    {
        return number_format($bytes / 1073741824, 1);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * 硬项里有没有失败。带 advisory 的行(2026-10-13 起的「最近一次备份」)只说事、不拦人。
     *
     * @param list<array{ok:bool, name:string, value:string, fix:string, advisory?:bool}> $checks
     */
    private function hasFailure(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['advisory'] ?? false) === true) {
                continue;
            }

            if (!$check['ok']) {
                return true;
            }
        }

        return false;
    }

    /**
     * 提示条(《93》§5.4 / §6.2 / §6.5):体检未过转「已中止」,否则是「失败自动回滚」。
     *
     * @return array{prefix:string, before:string, strong:string, after:string}
     */
    private function alert(bool $blocked): array
    {
        if ($blocked) {
            return [
                'prefix' => __('已中止'),
                'before' => __('体检未通过，升级'),
                'strong' => __('已中止'),
                'after' => __('：未下载、未改动任何文件，站点仍在原版本正常运行。清掉那一项后重新体检即可。'),
            ];
        }

        // 2026-10-13:备份改成可选之后,这句不能再笼统说"自动还原数据库"(不备份时做不到)。
        // 口径改成:文件一定回滚;数据库只有做了备份才还原 —— 界面说的是实话。
        return [
            'prefix' => __('失败自动回滚'),
            'before' => __('升级过程中任一步失败，程序会自动'),
            'strong' => __('回滚文件'),
            'after' => __('；若这一趟做了备份，数据库也一并还原。清缓存后退出维护模式，失败原因记进 storage/logs/upgrade-*.log。'),
        ];
    }

    /**
     * 进度区头与按钮(《93》§5.6 / §6.3 / §6.5)。
     * 升级任务状态未接线(《93》§11「九步进度 | 服务端任务」),无进度即「尚未开始」。
     *
     * @param array{version:string, channel:string, label:string}|null $available
     * @param array{job:string, phase:string, done:int, total:int, percent:int, label:string, ok:bool|null, running:bool, message:string, ts:int} $state 本次渲染取到的作业状态
     * @return array{sub:string, button:string, disabled:bool, cancel:bool}
     */
    private function progress(?array $available, array $state): array
    {
        $range = $available === null
            ? ''
            : Kernel::VERSION . ' → ' . $available['version'];

        $lock = $this->installer()?->lock();

        // 断在半路:如实说断在第几步,并把「恢复到升级前」那颗按钮摆出来(2026-10-17 接上 recover)
        if ($lock !== null) {
            return [
                'sub' => __('上一次升级停在第 %d 步，还没收尾', 'molin', max(0, (int) ($lock['step'] ?? 0))),
                'button' => __('开始升级'),
                'disabled' => true,
                'cancel' => false,
                'recover' => true,
                'pct' => (int) $state['percent'],
            ];
        }

        // 刚跑过一趟:九步的最后状态就摆在进度条上(跑完立刻跳回来,不靠 JS 记)。
        // 这条记录在 takeJobState() 里取完就销了,所以只亮这一次
        if ($state['job'] === Installer::JOB && $state['ts'] > 0) {
            return [
                'sub' => $state['message'] !== '' ? $state['message'] : $state['label'],
                'button' => __('开始升级'),
                'disabled' => $available === null,
                'cancel' => $state['running'],
                'recover' => false,
                'pct' => (int) $state['percent'],
            ];
        }

        return [
            'sub' => $range === '' ? '' : $range . ' · ' . __('尚未开始'),
            'button' => __('开始升级'),
            'disabled' => $available === null,
            'cancel' => false,
            'recover' => false,
            'pct' => 0,
        ];
    }

    /**
     * 进度槽位里的当下状态(《93》§5.6)。
     *
     * @return array{job:string, phase:string, done:int, total:int, percent:int, label:string, ok:bool|null, running:bool, message:string, ts:int}
     */
    private function jobState(): array
    {
        return (new Progress(MOLIN_ROOT, Installer::PROGRESS))->read();
    }

    /**
     * 页面渲染专用的作业状态:取一次,顺手把"已经跑完的那条"销掉。
     *
     * 一次性是刻意的。跑完的这条只该在**回来的那一页**上出现一次;留着它,刷新一下、或改天再进
     * 这一页,进度条上就永远挂着上次的失败 —— 而九步读的是锁、锁早被 abort() 清干净了,两处对不上,
     * 看着像站点卡在升级里出不来(2026-10-11 修:此前这条记录永不过期)。
     * 还在跑(`running`)时不销:同一个站点可能有另一个标签页正轮着它。轮询那条路走 jobState(),只读。
     *
     * @return array{job:string, phase:string, done:int, total:int, percent:int, label:string, ok:bool|null, running:bool, message:string, ts:int}
     */
    private function takeJobState(): array
    {
        $progress = new Progress(MOLIN_ROOT, Installer::PROGRESS);
        $state = $progress->read();

        if ($state['job'] === Installer::JOB && $state['ts'] > 0 && !$state['running']) {
            $progress->clear();
        }

        return $state;
    }

    /**
     * 九步(《93》§6.3)。无任务状态时九步全为「待执行」。
     *
     * @return list<array{no:int, name:string, desc:string, state:string, status:string}>
     */
    private function steps(): array
    {
        // 断在半路时:走过的标"完成"、停的那步标"进行中",别让九步全亮着"待执行"骗人
        $lock = $this->installer()?->lock();
        $at = $lock === null ? 0 : max(0, (int) ($lock['step'] ?? 0));
        // 这一趟跳过了备份:第 ③ 步按实情改成「跳过备份」,不印「DB 全量 + 全部文件」骗人
        $skipped = $lock !== null && ($lock['skipped_backup'] ?? false) === true;

        $steps = [];
        $no = 0;

        foreach (self::STEPS as [$name, $desc]) {
            $no++;

            if ($no === 3 && $skipped) {
                $name = __('跳过备份');
                $desc = __('按你的选择，这一趟没做备份');
            }

            $state = $at === 0 ? 'todo' : ($no < $at ? 'done' : ($no === $at ? 'cur' : 'todo'));

            $steps[] = [
                'no' => $no,
                'name' => $name,
                'desc' => $desc,
                'state' => $state,
                'status' => match ($state) {
                    'done' => __('完成'),
                    'cur' => __('进行中'),
                    default => __('待执行'),
                },
            ];
        }

        return $steps;
    }

    /**
     * 更新日志(《93》§5.7 / §6.4):凭清单里的 changelog 渲染,没有分条就用 notes 顶一句。
     *
     * @param array<string, mixed> $manifest
     * @param array{version:string, channel:string, label:string, date:string, notes:string}|null $available
     * @return array{show:bool, range:string, title:string, count:int, date:string, items:list<array{type:string, text:string}>}
     */
    private function log(array $manifest, ?array $available): array
    {
        $items = [];

        foreach (is_array($manifest['changelog'] ?? null) ? $manifest['changelog'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $items[] = [
                'type' => (string) ($entry['type'] ?? ''),
                'text' => (string) ($entry['text'] ?? ''),
            ];
        }

        // 只有一句 notes 的版本也得看得见 —— 空着的更新日志等于没写
        $notes = $manifest['notes'] ?? '';

        if ($items === [] && is_string($notes) && trim($notes) !== '') {
            $items[] = ['type' => '', 'text' => trim($notes)];
        }

        $released = $manifest['release_date'] ?? '';

        return [
            'show' => $available !== null && $items !== [],
            'range' => $available === null ? '' : Kernel::VERSION . ' → ' . $available['version'],
            'title' => '本次变更（' . count($items) . ' 条）',
            'count' => count($items),
            'date' => is_string($released) ? $released : '',
            'items' => $items,
        ];
    }

    /**
     * 手动回滚(《93》§5.8 / §6.4):清单里的 `previous` 给出上一版(源站保留最近 5 个版本包),
     * 这里把它**如实摆出来**,但不给一个按下去会动的入口。
     *
     * 为什么不接成"真能装旧版":《29》§7.2 定了这条边界 —— 九步链第 7 步只**往前**跑迁移,
     * 没有"把库退回旧结构"的能力。一键回滚旧代码会让站点变成"新库旧代码",比没有这个按钮更糟。
     * 所以这里只报事实(上一版是哪一版),把降级交给"用升级前备份整站还原"这条正路。
     *
     * `Feed::previous` 已经校验过形状(版本号/sha256/包地址/文件名);校验不过就当没有上一版,
     * 不编造更早的版本。
     *
     * @param array<string, mixed> $manifest
     * @return array{sub:string, has_target:bool, target:string}
     */
    private function rollback(array $manifest, string $manifestUrl): array
    {
        $previous = Feed::previous($manifest, $manifestUrl);
        $target = '';

        if ($previous !== null) {
            $target = $previous['version'];

            if ($previous['release_date'] !== '') {
                $target .= '（' . $previous['release_date'] . '）';
            }
        }

        return [
            'sub' => __('保留最近 5 个版本包'),
            'has_target' => $previous !== null,
            'target' => $target,
        ];
    }

    /**
     * 内核契约的展示形(1.0 → 1.x,《93》§5.1 / §6.1)。
     */
    private function contractText(): string
    {
        return explode('.', MOLIN_CONTRACT)[0] . '.x';
    }

    /**
     * 页内地址:一律回本页(《93》§8:进页即本址)。
     */
    private function url(): string
    {
        return $this->router->path('admin', ['path' => 'update']);
    }
}
