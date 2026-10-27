<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Install;

use Molin\Exception\ConfigException;
use Molin\Exception\InstallFailedException;
use Molin\Exception\ValidationException;
use Molin\Kernel;
use Molin\Security\Csrf;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * 网页版安装向导(既有规范–5 节)。设计稿是六步,作者 2026-10-01 定为维持五步:
 * 原"安全收尾"合并进完成页(既有规范的已确认偏离)。
 *
 * 装什么、怎么装只有一套:`Installer`(与 `molin install` 共用同一个类)。这里只收字段、
 * 分步报错、把结果讲给人听 —— 不建表、不写配置、不碰哈希。
 *
 * 外观整份烤在 install/templates/layout.twig —— 向导跑在 config.php 之前,没有静态资源管线;
 * 第 3 步的口令强度条是模板内联的一小段脚本,判分口径与注册页一致。
 */
final class Wizard
{
    /** 步骤名照既有规范的步骤条逐字(五步版) */
    private const TITLES = [
        1 => '环境与欢迎',
        2 => '数据库',
        3 => '站点与管理员',
        4 => '写入与建表',
        5 => '完成',
    ];

    private const STALE = '页面放得太久了,请重新提交。';

    private const LAST_STEP = 5;

    private const DB_DEFAULTS = ['host' => '127.0.0.1', 'port' => '3306', 'prefix' => 'molin_', 'charset' => 'utf8mb4'];

    /** 第 4 步七项执行清单(既有规范;顺序与 Installer::install 前七步一一对应) */
    private const STAGES = [
        ['生成配置文件', '写入安全密钥与数据库连接信息'],
        ['创建数据表', '按顺序建好程序需要的全部数据表'],
        ['写入初始设置', '站点信息、默认设置、当前主题'],
        ['创建管理员', '密码加密后保存，不保留明文'],
        ['创建默认分类与欢迎内容', '「未分类」兜底分类，一篇欢迎文章和一条示例评论'],
        ['生成链接与缓存', '站内链接与页面缓存'],
        ['准备运行目录', '缓存、日志、上传等运行所需目录'],
    ];

    private Environment $twig;

    /** @var array{ok: bool, rows?: list<array{k: string, v: string}>, reason?: string, elapsed: string}|null 第 2 步的连接反馈(成功四行 / 失败原因),给 .feed 渲染 */
    private ?array $dbFeedback = null;

    /** @var array{row: int, reason: string, trouble: string}|null 第 4 步的执行失败(第几项、原因、回滚瑕疵),给失败屏渲染 */
    private ?array $runFailure = null;

    /** @var list<string> 装成功后 Installer 回报的步骤,只给完成页看 */
    private array $doneSteps = [];

    /**
     * 装完瞬间的输入。状态文件在 run() 里就删了(不留口令),完成页若被刷新,
     * 地址、库名、前缀从这里兜底 —— 不读配置文件,也不落任何缓存。
     *
     * @var array{db: array<string, mixed>, site: array<string, string>, admin: array<string, string>}
     */
    private array $done = ['db' => [], 'site' => [], 'admin' => []];

    /**
     * @param array<string, mixed> $server 透传给环境检测(内核不碰裸超全局)
     */
    public function __construct(
        private readonly Installer $installer,
        private readonly string $root,
        private readonly array $server = []
    ) {
        // 装到一半时 config.php 还没有,APP_DEBUG 也不存在 —— 这里的 Twig 只能自带一套确定参数
        $this->twig = new Environment(new FilesystemLoader($root . '/install/templates'), [
            'cache' => $root . '/storage/cache/twig',
            'autoescape' => 'html',
            'debug' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $post
     */
    public function handle(string $method, int $step, array $post, WizardState $state): string
    {
        $target = max(1, min(self::LAST_STEP, $step));
        $notice = '';

        if ($method === 'POST') {
            if (!$state->matches((string) ($post[Csrf::FIELD] ?? ''))) {
                return $this->screen($target, $state, self::STALE);
            }

            [$target, $notice] = $this->advance($target, $post, $state);
        }

        // 装过的站拦在门口:只给一个要显式勾选的重装入口
        if ($target === 1 && $this->installer->isInstalled() && !$state->wantsReinstall()) {
            return $this->render('installed.twig', $this->base(1, $state, $notice) + ['all_done' => true]);
        }

        return $this->screen($target, $state, $notice);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0:int, 1:string} 要显示的屏幕序号 + 提示或错误
     */
    private function advance(int $step, array $post, WizardState $state): array
    {
        $action = (string) ($post['action'] ?? 'next');

        if ($action === 'reinstall') {
            if (($post['confirm'] ?? null) !== 'yes') {
                return [1, '要重装必须勾上"我已备份,确认重装"。'];
            }

            $state->allowReinstall();

            return [2, ''];
        }

        if ($step === 1 || $action === 'start') {
            return [2, ''];
        }

        if ($action === 'back') {
            return [max(1, $step - 1), ''];
        }

        if ($action === 'test') {
            $this->probe($post, $state);

            return [2, ''];
        }

        return match ($step) {
            2 => $this->takeDatabase($post, $state),
            3 => $this->takeSiteAndAdmin($post, $state),
            4 => $this->run($state),
            default => [$step, ''],
        };
    }

    /**
     * 既有规范的"测试连接"展示层:探成功记四行回执、失败记原因行 —— 都落给 `.feed`。
     *
     * @param array<string, mixed> $post
     * @return array{0:bool, 1:array<string, mixed>} 是否连上、这套数据库参数
     */
    private function probe(array $post, WizardState $state): array
    {
        $db = $this->databaseFields($post, $state->db());
        // 测试连接也要把已填参数留住,免得用户重填一遍(2026-10-05 作者反馈)
        $state->setDb($db);
        $started = microtime(true);

        if (trim((string) $db['name']) === '') {
            $this->dbFeedback = ['ok' => false, 'reason' => '数据库名不能为空。', 'elapsed' => $this->elapsed($started)];

            return [false, $db];
        }

        $facts = null;

        try {
            $steps = [];
            $this->installer->testConnection($db, $steps, $facts);
        } catch (Throwable $e) {
            $this->dbFeedback = ['ok' => false, 'reason' => $this->reasonText($e->getMessage()), 'elapsed' => $this->elapsed($started)];

            return [false, $db];
        }

        $this->dbFeedback = [
            'ok' => true,
            'rows' => $this->connectionRows($db, $facts),
            'elapsed' => $this->elapsed($started),
        ];

        return [true, $db];
    }

    private function elapsed(float $started): string
    {
        return number_format(microtime(true) - $started, 2);
    }

    /**
     * 把错误原文削成"给用户看的一句"(既有规范的原因行):剥掉自家与 Database 层的包装前缀、
     * SQLSTATE 壳,错误码收到句尾写成「（错误码 N）」,尾注换成 .note 的"常见原因"。
     */
    private function reasonText(string $message): string
    {
        $noise = ['数据库连不上:', '安装失败:', 'SQL 执行失败:', '数据库连接失败:'];

        do {
            $stripped = false;

            foreach ($noise as $prefix) {
                if (str_starts_with($message, $prefix)) {
                    $message = ltrim(substr($message, strlen($prefix)));
                    $stripped = true;
                }
            }
        } while ($stripped);

        $code = '';

        if (preg_match('/SQLSTATE\[[^\]]*\]\s*\[(\d{1,5})\]/', $message, $m) === 1) {
            $code = '（错误码 ' . $m[1] . '）';
            $message = str_replace($m[0], '', $message);
        } elseif (preg_match('/\[(\d{1,5})\]/', $message, $m) === 1) {
            $code = '（错误码 ' . $m[1] . '）';
            $message = str_replace($m[0], '', $message);
        }

        $message = str_replace('(检查主机、端口、账号与口令)', '', $message);
        $message = (string) preg_replace('/[（(]既有规范[^）)]*[）)]\s*$/u', '', $message);
        $message = trim($message);

        return $message === '' ? '没有走通,原因见下。' : $message . $code;
    }

    /** .why 是嵌在描述里的一句话,按设计稿句式以句号收尾(既有规范示例) */
    private function sentence(string $text): string
    {
        return preg_match('/[。！？!?]\z/u', $text) === 1 ? $text : $text . '。';
    }

    /**
     * 成功回执四行:服务器 / 当前用户 / 数据库 / 建表权限。
     *
     * @param array<string, mixed> $db
     * @param array<string, mixed> $facts Installer::testConnection() 的结构化回报
     * @return list<array{k: string, v: string}>
     */
    private function connectionRows(array $db, array $facts): array
    {
        $needed = ['CREATE', 'ALTER', 'INDEX', 'DROP'];
        $privileges = array_values(array_filter((array) ($facts['privileges'] ?? []), 'is_string'));
        $missing = array_values(array_diff($needed, $privileges));

        if ($missing === []) {
            $privilegeText = '具备（' . implode(' / ', $privileges) . '）';
        } else {
            $privilegeText = '不完整（缺 ' . implode(' / ', $missing) . '）';
        }

        $created = (bool) ($facts['database_created'] ?? false);

        $rows = [
            ['k' => '服务器', 'v' => (string) ($facts['server_version'] ?? '')],
            ['k' => '当前用户', 'v' => (string) ($facts['current_user'] ?? '')],
            ['k' => '数据库', 'v' => (string) $db['name'] . ($created ? '（不存在，本次已建）' : '（已存在）')],
            ['k' => '建表权限', 'v' => $privilegeText],
        ];

        $compat = (string) ($facts['compat'] ?? '');

        if ($compat !== '') {
            $rows[] = ['k' => '兼容提示', 'v' => $compat];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0:int, 1:string}
     */
    private function takeDatabase(array $post, WizardState $state): array
    {
        [$ok, $db] = $this->probe($post, $state);

        if (!$ok) {
            return [2, ''];
        }

        $state->setDb($db);

        return [3, ''];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0:int, 1:string}
     */
    private function takeSiteAndAdmin(array $post, WizardState $state): array
    {
        // 复选框不勾就不提交:缺席 = 不要欢迎内容(既有规范默认勾上)。
        // 先记下再校验 —— 这一步折回去改字时,勾选不该跟着复位
        $state->setWelcome(($post['site_welcome'] ?? '') === '1');

        $site = [
            'name' => trim((string) ($post['site_name'] ?? '')),
            'description' => trim((string) ($post['site_description'] ?? '')),
            'url' => trim((string) ($post['site_url'] ?? '')),
            // 复选框不勾就不提交:缺席 = 关闭(既有规范的开关,默认开着)
            'visible' => ($post['site_visible'] ?? '') === '1' ? '1' : '0',
        ];
        $admin = $this->adminFields($post, $state->admin());

        try {
            $this->installer->validateSite($site);
            $this->installer->validateAdmin($admin);
        } catch (Throwable $e) {
            return [3, $this->explain($e)];
        }

        // 口令留空 = 沿用暂存的那份,此时不必再比两次输入
        if ((string) ($post['admin_pass'] ?? '') !== '' && (string) $post['admin_pass'] !== (string) ($post['admin_pass2'] ?? '')) {
            return [3, '两次输入的口令不一致。'];
        }

        $state->setSiteAndAdmin($site, $admin);

        return [4, ''];
    }

    /**
     * @return array{0:int, 1:string}
     */
    private function run(WizardState $state): array
    {
        $plan = [
            'database' => $state->db(),
            'site' => $state->site(),
            'admin' => $state->admin(),
            'force' => $state->wantsReinstall(),
            'welcome' => $state->welcome(),
        ];

        try {
            $this->doneSteps = $this->installer->install($plan);
        } catch (InstallFailedException $e) {
            // 表与 config.php 已由 Installer 回滚;会话**留着**(含暂存口令,至多两小时),
            // 失败屏的「重新写入」直接重跑,口令不必重填
            $this->runFailure = [
                'row' => $e->completedSteps() + 1,
                'reason' => $this->sentence($this->reasonText($e->reason())),
                'trouble' => $e->rollbackTrouble(),
            ];

            return [4, ''];
        } catch (Throwable $e) {
            // 装之前的拦阻(环境不满足 / 已安装 / 连不上):退回上一步改参数,会话即弃
            $state->destroy();

            return [3, $this->explain($e)];
        }

        $this->done = ['db' => $state->db(), 'site' => $state->site(), 'admin' => $state->admin()];
        $state->destroy();

        return [5, ''];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function databaseFields(array $post, array $stored): array
    {
        $host = trim((string) ($post['db_host'] ?? ''));
        $prefix = trim((string) ($post['db_prefix'] ?? ''));
        $socket = trim((string) ($post['db_socket'] ?? ''));
        $given = (string) ($post['db_pass'] ?? '');
        $port = (int) ($post['db_port'] ?? self::DB_DEFAULTS['port']);

        return [
            'host' => $host === '' ? self::DB_DEFAULTS['host'] : $host,
            'port' => $port >= 1 && $port <= 65535 ? $port : (int) self::DB_DEFAULTS['port'],
            'name' => trim((string) ($post['db_name'] ?? '')),
            'user' => trim((string) ($post['db_user'] ?? '')),
            // 留空表示沿用暂存的口令,不许用空串把已存的覆盖掉
            'password' => $given === '' ? (string) ($stored['password'] ?? '') : $given,
            'prefix' => $prefix === '' ? self::DB_DEFAULTS['prefix'] : $prefix,
            'charset' => self::DB_DEFAULTS['charset'],
            'socket' => $socket === '' ? null : $socket,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $stored
     * @return array<string, string>
     */
    private function adminFields(array $post, array $stored): array
    {
        $given = (string) ($post['admin_pass'] ?? '');

        return [
            'username' => trim((string) ($post['admin_user'] ?? '')),
            'email' => trim((string) ($post['admin_email'] ?? '')),
            'password' => $given === '' ? (string) ($stored['password'] ?? '') : $given,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(int $step, WizardState $state, string $notice): array
    {
        return [
            'step' => $step,
            'titles' => self::TITLES,
            'token' => $state->token(),
            'field' => Csrf::FIELD,
            'notice' => $notice,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function values(WizardState $state): array
    {
        $db = $state->db() + self::DB_DEFAULTS;

        // 永不回显口令:表单留空即沿用暂存的那份
        unset($db['password']);

        $site = $state->site() + ['url' => $this->origin()];

        return [
            'db' => $db,
            'site' => $site,
            'admin' => $state->admin(),
            'welcome' => $state->welcome(),
            'saved_db_pass' => (string) ($state->db()['password'] ?? '') !== '',
            'saved_admin_pass' => (string) ($state->admin()['password'] ?? '') !== '',
        ];
    }

    private function screen(int $step, WizardState $state, string $notice): string
    {
        $vars = $this->base($step, $state, $notice) + $this->values($state);

        if ($step === 1) {
            $requirements = (new EnvironmentChecker($this->root, $this->server))->check();

            return $this->render('env.twig', $vars + [
                'rows' => $this->mapRequirements($requirements),
                'blocked' => EnvironmentChecker::hasBlocker($requirements),
            ]);
        }

        if ($step === self::LAST_STEP) {
            $db = $state->db();

            if ($db === []) {
                $db = $this->done['db'];
            }

            $db += self::DB_DEFAULTS;
            unset($db['password']);

            $site = $state->site();

            if ($site === []) {
                $site = $this->done['site'];
            }

            $admin = $state->admin();

            if ($admin === []) {
                $admin = $this->done['admin'];
            }

            return $this->render('done.twig', $vars + [
                'db' => $db,
                'site' => $site,
                'admin' => $admin,
                'version' => Kernel::VERSION,
                'done_steps' => $this->doneSteps,
                'admin_url' => $this->adminUrl($state),
            ]);
        }

        if ($step === 4) {
            $db = $state->db();
            $site = $state->site();
            $admin = $state->admin();

            return $this->render('run.twig', $vars + [
                'stages' => self::STAGES,
                'failure' => $this->runFailure,
                'summary' => [
                    '数据库' => $db['host'] . ':' . $db['port'] . ' / ' . $db['name'] . '(前缀 ' . $db['prefix'] . ')',
                    '站点名称' => $site['name'],
                    '站点地址' => $site['url'],
                    '管理员' => $admin['username'] . ' <' . $admin['email'] . '>',
                    '是否重装' => $state->wantsReinstall() ? '是(已二次确认)' : '否',
                ],
            ]);
        }

        if ($step === 2) {
            return $this->render('db.twig', $vars + ['feedback' => $this->dbFeedback]);
        }

        return $this->render('site.twig', $vars);
    }

    /**
     * 检测器的九项结果拼成设计稿的六行:可写多项并成一行,图片扩展并入推荐扩展。
     * 「要求」列是既有规范的固定阈值文案,照既有规范逐字;状态词里设计稿没画 fail,
     * 失败一律说「需配置」(s.fail)。
     *
     * @param list<Requirement> $items
     * @return list<array{label: string, required: string, current: string, class: string, word: string}>
     */
    private function mapRequirements(array $items): array
    {
        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item->key] = $item;
        }

        $missingNames = static function (Requirement $item): string {
            $parts = array_map(static fn (string $part): string => trim($part), explode(',', str_replace('缺少:', '', $item->detail)));

            return implode('、', $parts);
        };

        $missing = [];
        if ($byKey['ext_image']->state !== Requirement::OK) {
            $missing[] = 'gd、imagick';
        }
        if ($byKey['ext_recommended']->state !== Requirement::OK) {
            $missing[] = $missingNames($byKey['ext_recommended']);
        }

        $writables = array_filter($items, static fn (Requirement $item): bool => str_starts_with($item->key, 'writable:'));
        $broken = array_values(array_filter($writables, static fn (Requirement $item): bool => $item->state !== Requirement::OK));

        $memory = $byKey['memory_limit'];
        $upload = $byKey['upload_max_filesize'];

        $recState = $missing === [] ? Requirement::OK : Requirement::WARN;
        $writableState = $broken === [] ? Requirement::OK : Requirement::FAIL;
        $iniState = $memory->state === Requirement::OK && $upload->state === Requirement::OK ? Requirement::OK : Requirement::WARN;

        $word = static fn (string $state, bool $needsSetup = false): string => match ($state) {
            Requirement::OK => '通过',
            Requirement::FAIL => '需配置',
            default => $needsSetup ? '需配置' : '警告',
        };
        $class = static fn (string $state): string => $state === Requirement::OK ? 'ok' : ($state === Requirement::FAIL ? 'fail' : 'warn');

        $row = static function (string $label, string $required, string $current, string $state, string $word) use ($class): array {
            return ['label' => $label, 'required' => $required, 'current' => $current, 'class' => $class($state), 'word' => $word];
        };

        $required = $byKey['ext_required'];
        $rewrite = $byKey['rewrite'];

        return [
            $row('PHP 版本', '≥ 8.2', $byKey['php']->detail, $byKey['php']->state, $word($byKey['php']->state)),
            $row(
                '必需扩展',
                'pdo_mysql、mbstring、json、openssl、fileinfo',
                $required->state === Requirement::OK ? '全部已加载' : '缺少 ' . $missingNames($required),
                $required->state,
                $word($required->state)
            ),
            $row(
                '推荐扩展',
                'gd 或 imagick、intl、zip、curl、opcache',
                $missing === [] ? '全部已加载' : '缺少 ' . implode('、', $missing),
                $recState,
                $word($recState)
            ),
            $row(
                '目录可写',
                '网站根目录及其中的存储目录',
                $broken === [] ? '均可写' : implode('; ', array_map(static fn (Requirement $item): string => $item->detail, $broken)),
                $writableState,
                $word($writableState)
            ),
            $row('PHP 配置', 'memory_limit ≥128M、upload_max_filesize ≥2M', $memory->detail . ' / ' . $upload->detail, $iniState, $word($iniState)),
            $row('伪静态', '检测 Nginx / Apache rewrite', $rewrite->detail, $rewrite->state, $word($rewrite->state, true)),
        ];
    }

    /**
     * 站点地址默认取当前访问地址:装的人不必手抄一遍。
     */
    private function origin(): string
    {
        $host = (string) ($this->server['HTTP_HOST'] ?? '');

        if ($host === '') {
            return '';
        }

        $https = (string) ($this->server['HTTPS'] ?? '');

        return (($https !== '' && $https !== 'off') ? 'https' : 'http') . '://' . $host;
    }

    private function adminUrl(WizardState $state): string
    {
        $site = $state->site();
        $url = rtrim((string) ($site['url'] ?? ''), '/');

        if ($url !== '') {
            return $url . '/admin';
        }

        $host = (string) ($this->server['HTTP_HOST'] ?? '');

        return $host === '' ? '/admin' : '//' . $host . '/admin';
    }

    /**
     * 只给人看得懂、能照着做的一句话;堆栈不进页面。
     */
    private function explain(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $fields = [];

            foreach ($e->errors() as $messages) {
                $fields[] = implode(';', $messages);
            }

            return $fields === [] ? $e->getMessage() : $e->getMessage() . ':' . implode(' / ', $fields);
        }

        if ($e instanceof ConfigException) {
            return $e->getMessage();
        }

        return '没走通:' . $e->getMessage();
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $template, array $vars): string
    {
        return $this->twig->render($template, $vars);
    }
}
