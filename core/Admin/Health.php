<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use FilesystemIterator;
use Molin\Backup\Backup;
use Molin\Cache\CacheManager;
use Molin\Cache\PageCache;
use Molin\Database\Database;
use Molin\Kernel;
use Molin\Logger\LogReader;
use Molin\Migrations\Migrator;
use Molin\Router\Router;
use Molin\Support\Performance\RequestSamples;
use Molin\Update\Feed;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 站点健康的读模型。18 项检查分五组 6/3/3/2/4,只读不写。
 *
 * 判据尽量与既有规范的安装检测同源(PHP 版本 / 扩展 / ini 阈值 / 目录可写 / 伪静态观察法)。
 * 尚未接线的指标如实说"未接…"(既有规范同一条诚实口径),不自造阈值、不写死数字。
 * 输出形状对齐模板:三档状态(ok/tip/bad)、当下值(词级分段:code/dim 与纯文本)、判定标准、修复链接。
 * `fix.url` 只有路由表里真有的才给(/admin/logs),没落地的只有文案、没有 href(§11-10 不自造路由)。
 */
final class Health
{
    private const MIN_PHP = '8.2.0';

    private const REQUIRED_EXT = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo'];

    /** 推荐扩展里除掉图片这一对之外的名字(既有规范表) */
    private const EXTRA_EXT = ['intl', 'zip', 'curl'];

    public function __construct(
        private readonly Database $db,
        private readonly Migrator $migrator,
        private readonly Backup $backup,
        private readonly LogReader $reader,
        private readonly Router $router,
        // 「更新」页检查后留下清单,这里只读它的缓存(Feed::cachedStatus,不联网);没有就指路「更新」页
        private readonly ?Feed $feed = null,
        // 请求耗时样本(ErrorHandler 收尾时落的盘,storage/cache/data/perf-samples.json);
        // 测试可注入自己的采样根,线上缺省读站点状态根
        private readonly ?RequestSamples $samples = null
    ) {
    }

    /**
     * @param string $serverSoftware 入口脚本收口进来的 SERVER_SOFTWARE(既有规范伪静态观察法)
     * @return array{groups: list<array{name:string, note:string, rows:list<array<string,mixed>>}>, sum: array<string, array{n:int, label:string}>, when: string}
     */
    public function payload(string $serverSoftware): array
    {
        $groups = [
            $this->environmentGroup($serverSoftware),
            $this->databaseGroup(),
            $this->cacheGroup(),
            $this->backupGroup(),
            $this->performanceGroup(),
        ];

        $tally = ['ok' => 0, 'tip' => 0, 'bad' => 0];

        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $tally[$row['state']]++;
            }
        }

        return [
            'groups' => $groups,
            'sum' => [
                'ok' => ['n' => $tally['ok'], 'label' => __('项通过')],
                'tip' => ['n' => $tally['tip'], 'label' => __('项建议')],
                'bad' => ['n' => $tally['bad'], 'label' => __('项严重')],
            ],
            // 检测就在本次渲染里跑完,"上次检测"即此刻(检测结果没有单独缓存,不自造)
            'when' => __('上次检测：今天 %s', 'molin', date('H:i')),
        ];
    }

    /**
     * 升级域(UpdateController)依赖的运行时事实,独立于健康屏 18 项的展示形态。
     * 四项都带"读不到 → 明确失败"的约定,调用方据此显示「无法评测」而不是写死 ok。
     */

    /**
     * 站点所在磁盘的空余字节;读不到回 -1(disk_free_space 可能失败)。
     */
    public function diskFreeBytes(): int
    {
        $free = @disk_free_space(MOLIN_ROOT);

        return $free === false ? -1 : (int) $free;
    }

    /**
     * 全站体积(整棵目录树,含 storage/ 与 vendor);算不出回 -1。
     */
    public function siteSizeBytes(): int
    {
        try {
            return self::dirSize(MOLIN_ROOT);
        } catch (\Throwable) {
            return -1;
        }
    }

    /**
     * 待执行迁移条数;查不到回 -1。
     */
    public function pendingMigrationCount(): int
    {
        try {
            return count($this->migrator->pending());
        } catch (\Throwable) {
            return -1;
        }
    }

    /**
     * 最近一次备份的元信息;从未备份过或读不到回 null。
     *
     * @return array{name:string, size:int, created_at:int}|null
     */
    public function latestBackup(): ?array
    {
        try {
            $all = $this->backup->list();
        } catch (\Throwable) {
            return null;
        }

        if ($all === []) {
            return null;
        }

        $latest = $all[0];

        return [
            'name' => (string) ($latest['name'] ?? ''),
            'size' => (int) ($latest['size'] ?? 0),
            'created_at' => (int) ($latest['created_at'] ?? 0),
        ];
    }

    /**
     * 运行网站的用户(uid 与名字)。修复命令里要用它,判"属主对不对"也要用它。
     *
     * 非 POSIX(Windows 等没有 posix_*)、或拿不到有效用户就回 null;调用方据此把属主检查
     * 判成"不适用",而不是"不合格"—— 这是环境的差异,不是站点的毛病。
     *
     * @return array{uid:int, name:string}|null
     */
    public static function runningUser(): ?array
    {
        // posix_* 在 Windows 上根本不存在;function_exists 这道闸也是在替 phpstan 收窄类型
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
            return null;
        }

        $uid = posix_geteuid();
        $info = posix_getpwuid($uid);

        // posix_getpwuid 拿不到这个 uid 的名字时回 false
        if ($info === false || $info['name'] === '') {
            return null;
        }

        return ['uid' => $uid, 'name' => $info['name']];
    }

    /**
     * 单个路径的属主判定 —— 升级第 6 步"替换程序文件"踩过的坑:rename() 一个目录要求进程
     * **拥有这个目录本身**(不是"父目录可写"就行),属主不对不影响日常运行,只在升级时才暴露。
     * 属主与期望 uid 不一致 → 返回一句可直接照做的修复说明(修复命令 + 不改的后果);一致、
     * 或读不到属主(fileowner 失败)→ null。
     *
     * 抽成静态小函数是为了可单测:测试里没法真去 chown 换属主,只能靠传入不同的期望 uid
     * 覆盖"属主不一致"这条分支。非 POSIX 的"不适用"由调用方先看 runningUser() 决定,
     * 不在这里伪造成"不合格"。
     *
     * @param string $path 该项在站点上的实际路径(展示与修复命令都取它的 basename)
     * @param int    $expectedUid 运行网站的用户 uid(runningUser() 的 uid)
     * @param array{uid:int, name:string} $user 运行网站的用户(runningUser() 的结果)
     */
    public static function ownershipProblem(string $path, int $expectedUid, array $user): ?string
    {
        $owner = @fileowner($path);

        if ($owner === false || $owner === $expectedUid) {
            return null;
        }

        $name = basename($path);

        return __('%s 的属主不是运行网站的用户 %s；不改会导致升级在第 6 步失败。修复：', 'molin', $name, $user['name'])
            . 'chown -R ' . $user['name'] . ':' . $user['name'] . ' ' . $name;
    }

    /**
     * @return array{name:string, note:string, rows:list<array<string,mixed>>}
     */
    private function environmentGroup(string $software): array
    {
        $rows = [];

        $rows[] = $this->row(
            version_compare(PHP_VERSION, self::MIN_PHP, '<') ? 'bad' : 'ok',
            __('PHP 版本'),
            [self::code(PHP_VERSION)],
            __('≥ 8.2')
        );

        $missing = array_values(array_filter(self::REQUIRED_EXT, static fn (string $name): bool => !extension_loaded($name)));
        $rows[] = $this->row(
            $missing === [] ? 'ok' : 'bad',
            __('必需扩展'),
            $missing === []
                ? $this->names(self::REQUIRED_EXT, __('全部就绪'))
                : [self::text(__('缺少:') . implode('、', $missing))],
            __('五项全装')
        );

        $rows[] = $this->recommendedRow();
        $rows[] = $this->phpConfigRow();
        $rows[] = $this->writableRow();
        $rows[] = $this->rewriteRow($software);

        return $this->group(__('运行环境'), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function recommendedRow(): array
    {
        $missing = [];

        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $missing[] = 'gd / imagick';
        } elseif (!extension_loaded('imagick')) {
            $missing[] = 'imagick';
        }

        foreach (self::EXTRA_EXT as $name) {
            if (!extension_loaded($name)) {
                $missing[] = $name;
            }
        }

        if (!self::opcacheLoaded()) {
            $missing[] = 'opcache';
        }

        if ($missing === []) {
            return $this->row('ok', __('推荐扩展'), [self::text(__('推荐项全部就绪'))], __('建议开启，可继续'));
        }

        $cv = [self::text(__('缺') . ' '), self::code(implode('、', $missing))];

        // gd 在岗、只缺 imagick:缩略图不受影响,与设计稿同一句把话说完
        if ($missing === ['imagick'] && extension_loaded('gd')) {
            $cv[] = self::dim(__('（已用 gd 生成缩略图）'));
        }

        return $this->row('tip', __('推荐扩展'), $cv, __('建议开启，可继续'), ['label' => __('如何开启')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function phpConfigRow(): array
    {
        $memory = trim((string) ini_get('memory_limit'));
        $upload = trim((string) ini_get('upload_max_filesize'));
        $memoryOk = self::bytes($memory) === -1 || self::bytes($memory) >= self::bytes('128M');
        $uploadOk = self::bytes($upload) === -1 || self::bytes($upload) >= self::bytes('2M');

        return $this->row(
            $memoryOk && $uploadOk ? 'ok' : 'tip',
            __('PHP 配置'),
            [
                self::code('memory_limit ' . $memory),
                self::text(' '), self::dim('·'), self::text(' '),
                self::code('upload_max_filesize ' . $upload),
            ],
            __('≥ 128M / ≥ 2M')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writableRow(): array
    {
        $paths = [
            'storage/' => MOLIN_ROOT . '/storage',
            '根目录' => MOLIN_ROOT,
            'config.php' => MOLIN_ROOT . '/config.php',
        ];

        $blocked = [];

        foreach ($paths as $label => $path) {
            if (!is_writable($path)) {
                $blocked[] = $label;
            }
        }

        if ($blocked !== []) {
            return $this->row('bad', __('目录可写'), [self::text(__('不可写:') . implode('、', $blocked))], __('全部可写'));
        }

        return $this->row('ok', __('目录可写'), [
            self::code('storage/'),
            self::text(' '), self::dim('·'), self::text(' '),
            self::text(__('根目录')),
            self::text(' '), self::dim('·'), self::text(' '),
            self::code('config.php'),
            self::text(__(' 均可写')),
        ], __('全部可写'));
    }

    /**
     * 伪静态只能观察:PHP 看不见 nginx/apache 的配置(既有规范同款判据)。
     *
     * @return array<string, mixed>
     */
    private function rewriteRow(string $software): array
    {
        $lower = strtolower(trim($software));

        if ($lower === '') {
            return $this->row('tip', __('伪静态'), [self::text(__('当前不是网页请求，读不到服务器信息'))], __('Nginx / Apache 二选一'));
        }

        if (str_contains($lower, 'nginx')) {
            return $this->row('ok', __('伪静态'), [self::text(__('Nginx rewrite 生效'))], __('Nginx / Apache 二选一'));
        }

        if (str_contains($lower, 'apache')) {
            $loaded = function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules(), true);

            return $loaded
                ? $this->row('ok', __('伪静态'), [self::text(__('伪静态规则已就绪'))], __('Nginx / Apache 二选一'))
                : $this->row('tip', __('伪静态'), [self::text(__('Apache（无法确认伪静态规则）'))], __('Nginx / Apache 二选一'));
        }

        if (str_contains($lower, 'php')) {
            return $this->row('tip', __('伪静态'), [self::text(__('PHP 内置服务器（无法确认改写规则）'))], __('Nginx / Apache 二选一'));
        }

        return $this->row('tip', __('伪静态'), [self::text(__('无法确认改写规则') . '：' . $software)], __('Nginx / Apache 二选一'));
    }

    /**
     * @return array{name:string, note:string, rows:list<array<string,mixed>>}
     */
    private function databaseGroup(): array
    {
        $rows = [];

        $version = $this->scalar('SELECT VERSION()');
        $charset = $this->scalar('SELECT @@character_set_connection');
        $engine = $this->scalar(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
            ['name' => $this->db->table('posts')]
        );

        $ok = version_compare($version, '8.0', '>=')
            && str_starts_with($charset, 'utf8mb4')
            && strcasecmp($engine, 'InnoDB') === 0;

        $rows[] = $this->row($ok ? 'ok' : 'bad', __('连接与字符集'), [
            self::code('MySQL ' . $version),
            self::text(' '), self::dim('·'), self::text(' '),
            self::text($charset === '' ? __('读不到') : $charset),
            self::text(' '), self::dim('·'), self::text(' '),
            self::text($engine === '' ? __('未建表') : $engine),
        ], __('MySQL 8 / utf8mb4'));

        $current = $this->migrator->version();
        $pending = $this->migrator->pending();

        if ($current === '') {
            $rows[] = $this->row('bad', __('结构版本'), [self::text(__('结构未建立（数据库还没升级过）'))], __('数据库升级全部执行'));
        } elseif ($pending !== []) {
            $rows[] = $this->row('bad', __('结构版本'), [self::text(__('尚有 %d 项数据库升级待执行', 'molin', count($pending)))], __('数据库升级全部执行'));
        } else {
            $rows[] = $this->row('ok', __('结构版本'), [self::text(__('最新')), self::text(__(' 与升级清单一致'))], __('数据库升级全部执行'));
        }

        $threshold = (int) Database::SLOW_QUERY_MS;
        $slow = $this->reader->countSince('db', 'slow_query', time() - 86400);

        $rows[] = $this->row(
            $slow > 0 ? 'tip' : 'ok',
            __('慢查询'),
            [self::text(__('24 小时内 %d 条超过 %d ms', 'molin', $slow, $threshold))],
            __('单查询 ≤ %d ms', 'molin', $threshold),
            $slow > 0 ? ['label' => __('看日志'), 'url' => $this->router->path('admin', ['path' => 'logs'])] : null
        );

        return $this->group(__('数据库'), $rows);
    }

    /**
     * @return array{name:string, note:string, rows:list<array<string,mixed>>}
     */
    private function cacheGroup(): array
    {
        $rows = [];

        // 既有规范的缓存适配器已接线:显示**实际生效**的后端(配置写了 yac/redis 而不可用时如实降级为文件);
        // 未接装配线(测试手工装配)时才退回"仅编译缓存"的说法 —— 不说没发生的事
        $manager = CacheManager::instance();

        if ($manager->managed()) {
            $cv = [self::code(self::backendLabel($manager->backend()))];

            if ($manager->degraded()) {
                $cv[] = self::text(' ');
                $cv[] = self::dim(__('（不可用，已降级为文件）'));
            }

            $rows[] = $this->row($manager->degraded() ? 'tip' : 'ok', __('缓存后端'), $cv, __('文件、Redis 等任一种'));
        } else {
            $rows[] = $this->row('tip', __('缓存后端'), [self::code(__('文件')), self::dim(__('（编译缓存）'))], __('文件、Redis 等任一种'));
        }

        $rows[] = $this->opcacheRow();
        $rows[] = $this->pageCacheRow();

        return $this->group(__('缓存'), $rows);
    }

    /**
     * 第 3 层页面缓存的当前占用。命中计数尚未做,所以只报"缓存了多少页",
     * 报的是可核的事实,不编一个命中率。
     *
     * @return array<string, mixed>
     */
    private function pageCacheRow(): array
    {
        $manager = CacheManager::instance();

        if (!$manager->managed()) {
            return $this->row('tip', __('页面静态缓存'), [self::text(__('未接'))], __('无硬阈值'));
        }

        $stats = $this->pageCache()->stats();
        $text = $stats['files'] > 0
            ? __('已缓存 %d 页 · %s', 'molin', $stats['files'], self::sizeText($stats['bytes']))
            : __('尚未缓存任何页面');

        return $this->row($stats['files'] > 0 ? 'ok' : 'tip', __('页面静态缓存'), [self::text($text)], __('无硬阈值'));
    }

    /** 页面缓存目录与 Bootstrap / 设置页同一套换根口径(MOLIN_STATE_ROOT 优先) */
    private function pageCache(): PageCache
    {
        $state = getenv('MOLIN_STATE_ROOT');
        $root = is_string($state) && $state !== '' ? $state : MOLIN_ROOT;

        return new PageCache($root . '/storage/cache/pages');
    }

    private static function backendLabel(string $backend): string
    {
        return match ($backend) {
            'file' => __('文件'),
            'yac' => __('YAC'),
            'redis' => __('Redis'),
            default => __('已关闭'),
        };
    }

    /** 字节数报成人读的一档(KB 取整、MB 一位小数;与媒体库 / 设置页同一把尺) */
    private static function sizeText(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return (int) round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * @return array<string, mixed>
     */
    private function opcacheRow(): array
    {
        // OPcache 是 Zend 扩展:认不出就按运行态取 —— 本次请求里生效才算数
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        if (!is_array($status)) {
            return $this->row('tip', __('OPcache'), [self::text(__('未开启'))], __('建议开启'));
        }

        $cv = [self::text(__('已开启'))];
        $validate = trim((string) ini_get('opcache.validate_timestamps'));

        if ($validate !== '') {
            $cv[] = self::text(' ');
            $cv[] = self::dim('·');
            $cv[] = self::text(' ');
            $cv[] = self::code('validate_timestamps=' . $validate);
        }

        return $this->row('ok', __('OPcache'), $cv, __('建议开启'));
    }

    /**
     * @return array{name:string, note:string, rows:list<array<string,mixed>>}
     */
    private function backupGroup(): array
    {
        $rows = [];
        $latest = $this->backup->latest();

        if ($latest === null) {
            $rows[] = $this->row('bad', __('备份新鲜度'), [self::text(__('尚无备份'))], __('每日至少一份'), ['label' => __('去处理'), 'warn' => true]);
        } else {
            $age = max(0, time() - $latest);
            $days = intdiv($age, 86400);
            $cv = $days < 1 ? [self::text(__('最近一份在今天'))] : [self::text(__('最近一份在 %d 天前', 'molin', $days))];
            $fresh = $age <= 86400;

            $rows[] = $this->row($fresh ? 'ok' : 'bad', __('备份新鲜度'), $cv, __('每日至少一份'), $fresh ? null : ['label' => __('去处理'), 'warn' => true]);
        }

        // 既有规范的升级检查已落在后台「更新」页;这里只读那份检查留下的已验签清单(Feed::cachedStatus),
        // 不联网、不写 —— 健康屏只读。有新版据实报,没查过就指路「更新」页,不谎称"已是最新"。
        $update = $this->feed?->cachedStatus(Kernel::VERSION) ?? ['state' => 'unchecked', 'version' => ''];
        $updateText = match ($update['state']) {
            'available' => __('可更新到 %s', 'molin', $update['version']),
            'latest' => __('已是最新'),
            default => __('去「更新」页检查'),
        };
        $rows[] = $this->row('tip', __('更新可用'), [self::text($updateText)], __('比对更新清单'), ['label' => __('查看更新')]);

        return $this->group(__('备份与更新'), $rows);
    }

    /**
     * @return array{name:string, note:string, rows:list<array<string,mixed>>}
     */
    private function performanceGroup(): array
    {
        $rows = [];

        // 三条耗时读的是**真实访问**的采样(ErrorHandler 收尾时落进 storage/cache/data/perf-samples.json),
        // 不是打开本页现测一次。首页按页面静态缓存命中 / 未命中分桶,分别对既有规范的两条预算;
        // 采不到就如实说"尚未采到样本" —— 不写死数字、不外推。
        $samples = $this->samples ?? new RequestSamples();

        $cached = $samples->stats(RequestSamples::HOME_CACHED);
        $rows[] = $this->speedRow(__('首页 TTFB'), __('< 50 ms'), 50.0, $cached, 'median', __('缓存命中'));

        $uncached = $samples->stats(RequestSamples::HOME_UNCACHED);
        $rows[] = $this->speedRow(__('首页渲染'), __('< 300 ms'), 300.0, $uncached, 'max', __('缓存未命中'));

        // 「后台列表页」没有识别到"列表"这一层的口径,如实整类归为后台页面;计数报出来
        $admin = $samples->stats(RequestSamples::ADMIN);
        $rows[] = $this->speedRow(
            __('后台列表页'),
            __('< 500 ms'),
            500.0,
            $admin,
            'median',
            $admin === null ? '' : __('近 %d 次', 'molin', $admin['count'])
        );

        // 内核体积在既有规范没有判据,只报读数,不自造阈值(判定标准保持"预算待定")
        $bytes = self::dirSize(MOLIN_ROOT . '/core') + self::dirSize(MOLIN_ROOT . '/vendor');
        $rows[] = $this->row('tip', __('程序体积'), [
            self::text(number_format($bytes / 1048576, 1) . ' MB'),
            self::text(' '), self::dim('·'), self::text(' '),
            self::text('core + vendor'),
        ], __('预算待定'), ['label' => __('去设定')]);

        return $this->group(__('性能预算'), $rows);
    }

    /**
     * 性能预算组的一条耗时行:采样在手才出读数与三档,没采到就写"尚未采到样本"。
     * `$pick` 决定取哪个统计量 —— 命中档看头部缓存请求的中位(典型值),未命中的渲染看最慢一次
     * (缓存未命中才是要压的那趟),后台看中位。
     *
     * @param array{count:int, last:float, median:float, max:float, at:int}|null $stat
     * @return array<string, mixed>
     */
    private function speedRow(string $name, string $jd, float $limit, ?array $stat, string $pick, string $dim): array
    {
        if ($stat === null) {
            return $this->row('tip', $name, [self::text(__('尚未采到样本'))], $jd);
        }

        $ms = round($pick === 'max' ? $stat['max'] : $stat['median'], 1);

        return $this->row($ms <= $limit ? 'ok' : 'tip', $name, [
            self::code(self::msText($ms)),
            self::text(' '), self::dim('·'), self::text(' '),
            self::dim($dim),
        ], $jd);
    }

    /** 毫秒文案:≥ 10 取整(46 ms),不足 10 留一位小数(4.2 ms) */
    private static function msText(float $ms): string
    {
        return ($ms >= 10 ? (string) (int) round($ms) : number_format($ms, 1)) . ' ms';
    }

    /**
     * @param list<string> $names
     * @return list<array{t:string, x:string}>
     */
    private function names(array $names, string $suffix): array
    {
        $parts = [];

        foreach ($names as $i => $name) {
            if ($i > 0) {
                $parts[] = self::text(' ');
            }

            $parts[] = self::code($name);
        }

        $parts[] = self::text(' ' . $suffix);

        return $parts;
    }

    /**
     * @param list<array{t:string, x:string}> $cv
     * @param array{label:string, url?:string, warn?:bool}|null $fix
     * @return array<string, mixed>
     */
    private function row(string $state, string $name, array $cv, string $jd, ?array $fix = null): array
    {
        return [
            'state' => $state,
            'tag' => match ($state) {
                'ok' => __('通过'),
                'bad' => __('严重'),
                default => __('建议'),
            },
            'name' => $name,
            'cv' => $cv,
            'jd' => $jd,
            'fix' => $fix,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{name:string, note:string, rows:list<array<string,mixed>>}
     */
    private function group(string $name, array $rows): array
    {
        return ['name' => $name, 'note' => __('%d 项', 'molin', count($rows)), 'rows' => $rows];
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function scalar(string $sql, array $params = []): string
    {
        $row = $this->db->selectOne($sql, $params);

        if ($row === null) {
            return '';
        }

        $values = array_values($row);

        return $values === [] ? '' : (string) $values[0];
    }

    private static function opcacheLoaded(): bool
    {
        // OPcache 是 Zend 扩展:extension_loaded('opcache') 为假,要认 'Zend OPcache';这里只做推荐位判断
        return extension_loaded('Zend OPcache') && (string) ini_get('opcache.enable') !== '0';
    }

    /**
     * shorthand 阈值比较(K/M/G),与既有规范的安装检测同一口径;-1 表示不限。
     */
    private static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function dirSize(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $bytes = 0;

        // 体检只是量个参考值:量的时候别的进程正好删掉一个子目录(备份清理、恢复失败的回滚)
        // 不该把整项判成「无法评测」。CATCH_GET_CHILD 让消失的子树跳过去接着量。
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $file) {
            try {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $bytes += (int) @$file->getSize();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $bytes;
    }

    /**
     * @return array{t:string, x:string}
     */
    private static function text(string $value): array
    {
        return ['t' => 'x', 'x' => $value];
    }

    /**
     * @return array{t:string, x:string}
     */
    private static function code(string $value): array
    {
        return ['t' => 'c', 'x' => $value];
    }

    /**
     * @return array{t:string, x:string}
     */
    private static function dim(string $value): array
    {
        return ['t' => 'd', 'x' => $value];
    }
}
