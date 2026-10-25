<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Update;

use Molin\Backup\Backup;
use Molin\Backup\Progress;
use Molin\Backup\Restore;
use Molin\Logger\Log;
use Molin\Migrations\Migrator;
use Molin\Support\Files;
use Throwable;

/**
 * 升级执行链(《29》§3、《93》第 3–9 步):备份 → 维护模式 → 下载校验 → 替换 → 迁移 → 自检 → 退出。
 *
 * **"失败不影响当前版本"是靠三段做到的**:
 *   ① 动文件之前(备份、下载、校验)出的错 —— 退维护模式就完事,一个字节没改;
 *   ② 动文件之后(替换、迁移、自检)出的错 —— 文件按换出去的清单**原样挪回来**,数据库从刚才那份备份还原;
 *   ③ 中途断了(进程被杀 / 断电) —— 锁里记着停在第几步、备份文件叫什么,下次进更新页能接着收尾。
 *
 * **只换内核自带的那几个路径**:`plugins/`、`themes/`、`config.php`、`storage/` 是站点自己的东西,
 * 一个字节都不碰 —— 升级不该把站长装的插件和换的主题一起冲掉。这条是写死的,不靠调用方记得。
 * 唯一的收窄口子是官方默认主题:它随内核演进,包里新增的模板/资源会被补齐,但**只增不改**,
 * 见 fillThemeAdditions()。
 */
final class Installer
{
    /** 替换范围(内核自带的部分) */
    public const REPLACE = ['core', 'admin', 'assets', 'vendor', 'index.php', 'molin', 'composer.json', 'composer.lock'];

    /**
     * 明令不许碰的(写死:防手滑,也防哪天有人往 REPLACE 里加东西)。
     *
     * `themes` 仍然"升级不覆盖" —— 站点上已存在的文件一个字节都不动。官方默认主题因此还得走一条
     * **更窄的通道:只增不改** —— 换完内核后,把包里它还没有的文件补到站点上(见 fillThemeAdditions)。
     * 不然官方主题在包里新增的模板/资源永远送不到老站点,功能代码有了、配套模板没有,用户看不到。
     */
    public const UNTOUCHABLE = ['config.php', 'storage', 'plugins', 'themes', 'install'];

    public const LOCK = '/storage/trash/update/.lock.json';

    /** 换出来的旧文件放这儿(同级盘,rename 是原子的) */
    public const ROLLBACK_DIR = '/storage/trash/update/rollback';

    /** 进度槽位:与备份/恢复分开,两边互不打扰 */
    public const PROGRESS = '/storage/trash/update/.progress.json';

    public const JOB = 'update';

    /** 九步(《93》§4) */
    public const TOTAL = 9;

    public function __construct(
        private readonly string $root,
        private readonly Package $package,
        private readonly Maintenance $maintenance,
        private readonly Backup $backup,
        private readonly Restore $restore,
        private readonly Migrator $migrator,
        private readonly Progress $progress
    ) {
    }

    /**
     * 跑完整条链。
     *
     * @param array<string, mixed> $manifest 已验过签名的清单
     * @param bool $preflightOk 前置体检八项是否全过(调用方算;不在这里重算)
     * @param bool $backup 动文件前要不要先做一份备份。默认做;更新页"不勾备份"时由调用方传 false
     *                     (2026-10-13 作者定:不再强制备份,但跳过后失败时数据库无法自动还原)
     * @return array{ok:bool, step:int, message:string}
     */
    public function run(array $manifest, string $manifestUrl, string $current, bool $preflightOk, bool $backup = true): array
    {
        if ($this->locked()) {
            return ['ok' => false, 'step' => 0, 'message' => '上一次升级还没收尾,先把它处理掉。'];
        }

        $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '';
        $channel = is_string($manifest['channel'] ?? null) ? $manifest['channel'] : '';

        if ($version === '' || !Feed::usable($manifest, $channel, $manifestUrl)) {
            return ['ok' => false, 'step' => 1, 'message' => '这份清单不合规,不动手。'];
        }

        // 降级不在本链的能力范围内(《29》§7.2、《93》§5.8):本链只做"升到更新版本"。
        // 旧版代码配上被新版本迁移过的库结构会出事,而本链没有"把库退回旧结构"的能力(第 7 步只往前跑迁移),
        // 所以在这里直接拒绝,不换文件、不碰库 —— 这与"升级失败自动回滚"是两件事。
        // 少了这道闸,任何人拿一份旧版清单进来都会得到"新库旧代码",比没有回滚按钮更糟。
        if (version_compare($version, $current, '<')) {
            return [
                'ok' => false,
                'step' => 1,
                'message' => '这份清单的版本(' . $version . ')比当前(' . $current . ')低,降级不在升级链的能力范围内,不动手。',
            ];
        }

        if (!$preflightOk) {
            return ['ok' => false, 'step' => 2, 'message' => '前置体检没过,不动手。'];
        }

        $lock = [
            'version' => $version,
            'step' => 2,
            'keep' => '',
            'backup' => '',
            // 第 ③ 步跳过备份时立这个标记:界面据此把第 3 步显示成「跳过备份」,不印假的
            'skipped_backup' => false,
            'migrated' => false,
            'started_at' => time(),
            'cancel' => false,
        ];

        $this->writeLock($lock);
        $this->progress->begin(self::JOB);
        $this->progress->advance(2, self::TOTAL, '体检八项全过', 'check');

        // ---- ③ 备份(动文件之前;做不做由调用方定 —— 2026-10-13 作者定:更新页默认不备份) ----
        // 不做时锁里不留 backup,后面"失败还原数据库"那两处会照旧跳过(文件级回滚不受影响),
        // 页面上第 ③ 步也会如实显示「跳过备份」,不撒谎。
        $this->mark($lock, 3);

        if ($backup) {
            // 不让备份自己的百分比盖掉九步的总进度,所以这里不把进度槽递给它
            $this->progress->advance(3, self::TOTAL, '正在备份数据库与文件', 'backup');

            try {
                $created = $this->backup->create($current, 'pre');
            } catch (Throwable $e) {
                return $this->abort($lock, 3, '备份没做成,没动任何文件:' . $e->getMessage());
            }

            $lock['backup'] = (string) ($created['name'] ?? '');
            $this->writeLock($lock);

            if ($lock['backup'] === '') {
                return $this->abort($lock, 3, '备份没留下文件名,不敢往下走。');
            }
        } else {
            $lock['skipped_backup'] = true;
            $this->writeLock($lock);
            $this->progress->advance(3, self::TOTAL, '已跳过备份（按你的选择）', 'backup');
        }

        if ($this->cancelRequested()) {
            return $this->abort($lock, 3, '已取消,没有改动任何文件。');
        }

        // ---- ④ 进维护模式 ----
        $this->mark($lock, 4);
        $this->progress->advance(4, self::TOTAL, '进入维护模式(访客 503)', 'maintenance');
        $this->maintenance->on();

        // ---- ⑤ 下载 + 校验(还是不碰现有文件) ----
        $this->mark($lock, 5);
        $this->progress->advance(5, self::TOTAL, '下载安装包并校验', 'fetch');
        $stage = $this->package->stage($manifest, $manifestUrl);

        if ($stage['ok'] === false) {
            return $this->abort($lock, 5, '包没验过(' . $stage['reason'] . '):' . $stage['detail']);
        }

        // ---- ⑥ 替换文件(从这里开始才动现有东西,每一步都能退) ----
        $this->mark($lock, 6);
        $this->progress->advance(6, self::TOTAL, '替换程序文件(旧的留档)', 'swap');
        $swap = $this->swap($stage['dir']);

        // 先落档再判成败:换到一半垮掉的也算数,不然那半截文件没人收得回去
        $lock['keep'] = $swap['keep'];
        $this->writeLock($lock);

        if ($swap['ok'] === false) {
            return $this->rollback($lock, 6, '替换文件没做成:' . $swap['message']);
        }

        if ($this->cancelRequested()) {
            return $this->rollback($lock, 6, '已取消,已把文件与会话退回升级前。');
        }

        // ---- ⑦ 执行迁移 ----
        $this->mark($lock, 7);
        $this->progress->advance(7, self::TOTAL, '执行数据库迁移', 'migrate');

        // 标记打在做之前:死在迁移半路也算"数据库动过",回退时才会拿备份还原
        $lock['migrated'] = true;
        $this->writeLock($lock);

        try {
            $this->migrator->migrate();
        } catch (Throwable $e) {
            return $this->rollback($lock, 7, '迁移没过:' . $e->getMessage());
        }

        // ---- ⑧ 清缓存 + 自检 ----
        $this->mark($lock, 8);
        $this->progress->advance(8, self::TOTAL, '清缓存并自检', 'verify');
        $this->flush();

        $check = $this->selfCheck($version);

        if ($check !== '') {
            return $this->rollback($lock, 8, '自检没过:' . $check);
        }

        // ---- ⑨ 退出维护模式 ----
        $this->mark($lock, 9);
        $this->maintenance->off();
        $this->clearLock();
        $this->progress->advance(self::TOTAL, self::TOTAL, '升级完成', 'done');
        $this->progress->finish(true, '已升到 ' . $version . '。');

        return ['ok' => true, 'step' => 9, 'message' => '已升到 ' . $version . '。'];
    }

    /**
     * 锁里那一趟没跑完:报出来让人决定接着收尾还是回滚。
     *
     * @return array<string, mixed>|null
     */
    public function lock(): ?array
    {
        $path = $this->root . self::LOCK;

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public function locked(): bool
    {
        return $this->lock() !== null;
    }

    /**
     * 按下取消:任务在**步骤之间**看到就停在安全点。
     *
     * 不谎称"立刻停" —— 一条请求正在跑,能做的只是别开始下一步。
     */
    public function requestCancel(): void
    {
        $lock = $this->lock();

        if ($lock === null) {
            return;
        }

        $lock['cancel'] = true;
        $this->writeLock($lock);
    }

    /**
     * 把上一次没跑完的那趟收回原地(文件 + 数据库),然后清锁。
     *
     * @return array{ok:bool, message:string}
     */
    public function recover(): array
    {
        $lock = $this->lock();

        if ($lock === null) {
            return ['ok' => true, 'message' => '没有需要收尾的升级。'];
        }

        $keep = is_string($lock['keep'] ?? null) ? $lock['keep'] : '';

        if ($keep !== '' && is_dir($keep)) {
            // 留档目录在、清单却不在 = 死在换文件途中。这时无法自动判断哪些已经换过,
            // 就不能报"已回到升级前"(那是假话),得把判断权交回人手里。
            if ($this->readKeep($keep) === []) {
                return [
                    'ok' => false,
                    'message' => '上次升级死在换文件途中，自动收尾判断不了已经换过哪些文件；请用升级前的备份恢复，或手工核对 ' . $keep,
                ];
            }

            $back = $this->unswap($keep);

            if ($back !== '') {
                return ['ok' => false, 'message' => '文件没收回去:' . $back];
            }
        }

        $backup = is_string($lock['backup'] ?? null) ? $lock['backup'] : '';

        if ($backup !== '' && $this->touchedDatabase($lock)) {
            try {
                $this->restore->restore($backup, (string) $lock['version']);
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => '数据库没还原回去:' . $e->getMessage()];
            }
        }

        $this->maintenance->off();
        $this->clearLock();
        $this->progress->clear();

        return ['ok' => true, 'message' => '已回到升级前的状态。'];
    }

    /**
     * 换文件:旧的整份挪进留档目录,新的从暂存挪进来 —— 同盘 rename,毫秒级。
     *
     * @return array{ok:bool, message:string, keep:string}
     */
    private function swap(string $tree): array
    {
        $stamp = date('Ymd-His');
        $keep = $this->root . self::ROLLBACK_DIR . '/' . $stamp;

        if (!mkdir($keep, 0o755, true)) {
            return ['ok' => false, 'message' => '建不出留档目录', 'keep' => ''];
        }

        // 先把留档目录写进锁,再动文件(2026-10-17):进程若在换文件途中被杀,recover() 才有据可查。
        // 此前 keep 是 swap 全跑完才写进去的,断电后锁里 keep 仍为空 —— recover() 会报"已回到升级前的状态",
        // 而文件其实半新半旧(那是句会让站长放心的假话)。
        $this->rememberKeep($keep);

        $moved = [];
        $added = [];

        // REPLACE 与 UNTOUCHABLE 必须不相交,这条有契约测试守着(见 InstallerTest)
        foreach (self::REPLACE as $rel) {
            $new = $tree . '/' . $rel;

            // 包里没有这一项就不动它 —— 升级不该顺手删东西
            if (!file_exists($new)) {
                continue;
            }

            $cur = $this->root . '/' . $rel;

            if (file_exists($cur)) {
                if (!@rename($cur, $keep . '/' . $rel)) {
                    $this->recordKeep($keep, $moved, $added);

                    $why = $this->whyUnmovable($rel);
                    Log::channel('app')->warning($why);

                    return ['ok' => false, 'message' => $why, 'keep' => $keep];
                }

                $moved[] = $rel;
            } else {
                $added[] = $rel;
            }

            if (!@rename($new, $cur)) {
                $this->recordKeep($keep, $moved, $added);

                return ['ok' => false, 'message' => '放不进新的 ' . $rel, 'keep' => $keep];
            }
        }

        // 内核换完,再顺带把官方主题这趟新增的文件补上(themes 整体仍不覆盖,见 UNTOUCHABLE 注释)
        $this->fillThemeAdditions($tree, $added);

        $this->recordKeep($keep, $moved, $added);

        return ['ok' => true, 'message' => '', 'keep' => $keep];
    }

    /**
     * 补齐官方主题这趟新增的文件 —— themes 整体"升级不覆盖"下开的一条窄缝:**只增不改**。
     *
     * 站点上有该主题目录才动它(不替用户装主题);逐文件比,缺的补、已有的跳过,已存在的一个字节都不改。
     * 补进去的路径记进 $added,这样失败回滚时 unswap() 会把"这趟新加的"删掉,"文件已退回升级前"才成立。
     *
     * @param list<string> $added
     */
    private function fillThemeAdditions(string $tree, array &$added): void
    {
        $themes = $tree . '/themes';

        if (!is_dir($themes)) {
            return;
        }

        $slugs = scandir($themes);
        $filled = [];

        foreach ($slugs === false ? [] : $slugs as $slug) {
            if ($slug === '.' || $slug === '..') {
                continue;
            }

            // 站点上没有这个主题目录就不管 —— 不替用户装主题
            if (!is_dir($themes . '/' . $slug) || !is_dir($this->root . '/themes/' . $slug)) {
                continue;
            }

            foreach (self::listFiles($themes . '/' . $slug) as $rel) {
                $target = $this->root . '/themes/' . $slug . '/' . $rel;

                // 只增不改:站点上已有的文件一律跳过,绝不覆盖
                if (file_exists($target)) {
                    continue;
                }

                $parent = dirname($target);

                if (!is_dir($parent)) {
                    mkdir($parent, 0o755, true);
                }

                if (!@copy($themes . '/' . $slug . '/' . $rel, $target)) {
                    Log::channel('app')->warning('补齐官方主题文件失败:' . 'themes/' . $slug . '/' . $rel);

                    continue;
                }

                @chmod($target, 0o644);
                $added[] = 'themes/' . $slug . '/' . $rel;
                $filled[] = 'themes/' . $slug . '/' . $rel;
            }
        }

        if ($filled !== []) {
            Log::channel('app')->info('升级补齐官方主题新增文件 ' . count($filled) . ' 个', ['files' => $filled]);
        }
    }

    /**
     * 递归列出目录下的文件(相对路径,正斜杠)。符号链接不跟 —— 与 Files::deleteTree 的口径一致,
     * 免得顺着链接把包外的东西拷进来。
     *
     * @return list<string>
     */
    private static function listFiles(string $dir): array
    {
        $out = [];
        $items = scandir($dir);

        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                foreach (self::listFiles($path) as $rel) {
                    $out[] = $item . '/' . $rel;
                }

                continue;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * rename 挪不动某个顶层项时,尽量把**真实原因**说出来(线上排查靠它)。
     *
     * 依据(2026-10-11 线上实测):rename 一个目录要求进程拥有**该目录本身**,父目录可写并不够 ——
     * 站点以 www 跑、`assets` 归 root 时,挪走 `assets` 必失败。所以拿属主与当前运行用户对照着写。
     *
     * 非 POSIX(Windows / 没装 posix 扩展)拿不到属主,退回原来那句短的,不许在这里崩。
     */
    private function whyUnmovable(string $rel): string
    {
        $short = '挪不走旧的 ' . $rel;

        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            return $short;
        }

        $ownerId = @fileowner($this->root . '/' . $rel);

        if ($ownerId === false) {
            return $short;
        }

        $owner = posix_getpwuid($ownerId);
        $runner = posix_getpwuid(posix_geteuid());

        if ($owner === false || $runner === false) {
            return $short;
        }

        return $short . '：它属于 ' . $owner['name'] . '，而运行网站的用户是 ' . $runner['name']
            . ' —— 请在服务器上执行 chown -R ' . $runner['name'] . ':' . $runner['name'] . ' ' . $rel;
    }

    /**
     * 换回去:留档目录里有什么就挪什么回来,这趟新加的(原本没有)删掉。
     *
     * @return string 空串 = 收好了;非空 = 没收回来的原因
     */
    private function unswap(string $keep): string
    {
        $record = $this->readKeep($keep);
        $moved = is_array($record['moved'] ?? null) ? $record['moved'] : [];
        $added = is_array($record['added'] ?? null) ? $record['added'] : [];

        foreach ($moved as $rel) {
            $old = $keep . '/' . (string) $rel;
            $cur = $this->root . '/' . (string) $rel;

            if (!file_exists($old)) {
                continue;
            }

            Files::deleteTree($cur);

            if (!@rename($old, $cur)) {
                return '挪不回 ' . (string) $rel;
            }
        }

        foreach ($added as $rel) {
            Files::deleteTree($this->root . '/' . (string) $rel);
        }

        return '';
    }

    /**
     * 动文件之后出了事:文件退回原地 + 数据库从刚才那份备份还原 + 退维护模式。
     *
     * @param array<string, mixed> $lock
     * @return array{ok:bool, step:int, message:string}
     */
    private function rollback(array $lock, int $step, string $why): array
    {
        $keep = is_string($lock['keep'] ?? null) ? $lock['keep'] : '';
        $notes = [$why];

        if ($keep !== '' && is_dir($keep)) {
            $back = $this->unswap($keep);

            $notes[] = $back === '' ? '文件已退回升级前' : '文件没收回去:' . $back;
        }

        $backup = is_string($lock['backup'] ?? null) ? $lock['backup'] : '';

        if ($backup !== '' && $this->touchedDatabase($lock)) {
            try {
                $this->restore->restore($backup, (string) $lock['version']);
                $notes[] = '数据库已还原';
            } catch (Throwable $e) {
                $notes[] = '数据库没还原回去:' . $e->getMessage();
            }
        }

        $this->maintenance->off();
        $this->clearLock();
        $this->progress->advance($step, self::TOTAL, '已回退', 'rollback');
        $this->progress->finish(false, implode(';', $notes));

        return ['ok' => false, 'step' => $step, 'message' => implode(';', $notes)];
    }

    /**
     * 还没动文件就出事:退维护模式、清锁,一个字节没改。
     *
     * @param array<string, mixed> $lock
     * @return array{ok:bool, step:int, message:string}
     */
    private function abort(array $lock, int $step, string $why): array
    {
        $this->maintenance->off();
        $this->clearLock();
        $this->progress->finish(false, $why);

        return ['ok' => false, 'step' => $step, 'message' => $why];
    }

    /**
     * 这趟到底动过数据库没有。
     *
     * 数据库只在第 7 步(迁移)被动过 —— 那之前的失败拿备份还原,会把备份之后站长的正常写入
     * 一起抹掉(备份到迁移之间站点还在开着后台)。所以只有迁移开过头,才认账。
     *
     * @param array<string, mixed> $lock
     */
    private function touchedDatabase(array $lock): bool
    {
        return ($lock['migrated'] ?? false) === true;
    }

    /**
     * 自检:换了文件之后这台机器还站得住吗。空串 = 通过。
     */
    private function selfCheck(string $version): string
    {
        $kernel = $this->root . '/core/Kernel.php';

        if (!is_file($kernel)) {
            return 'core/Kernel.php 不见了';
        }

        $source = (string) file_get_contents($kernel);

        if (preg_match("#const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)'#", $source, $m) !== 1) {
            return 'core/Kernel.php 读不出版本号';
        }

        if ($m[1] !== $version) {
            return '盘上是 ' . $m[1] . ',本该是 ' . $version;
        }

        foreach (['index.php', 'admin/templates/layout.twig', 'core/Update/Package.php'] as $rel) {
            if (!file_exists($this->root . '/' . $rel)) {
                return '缺 ' . $rel;
            }
        }

        return '';
    }

    /**
     * 清缓存:模板编译、数据缓存、页面缓存,以及 hookmap / 路由那类落盘的索引。
     *
     * opcache 也要清 —— `validate_timestamps=0` 的机器上,不清就是拿旧代码继续跑。
     */
    private function flush(): void
    {
        foreach (['twig', 'data', 'pages'] as $sub) {
            $dir = $this->root . '/storage/cache/' . $sub;

            if (is_dir($dir)) {
                Files::deleteTree($dir);
            }

            @mkdir($dir, 0o755, true);
        }

        $cached = glob($this->root . '/storage/cache/*.php');

        foreach ($cached === false ? [] : $cached as $file) {
            Files::delete($file);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * @param array<string, mixed> $lock
     */
    private function mark(array &$lock, int $step): void
    {
        $lock['step'] = $step;
        $this->writeLock($lock);
    }

    /**
     * 取消标志看在盘上的那份 —— 它可能是**另一个请求**刚写进来的,不能只看手里这份。
     */
    private function cancelRequested(): bool
    {
        $current = $this->lock();

        return $current !== null && ($current['cancel'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $lock
     */
    private function writeLock(array $lock): void
    {
        $dir = $this->root . '/storage/trash/update';

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        @file_put_contents($this->root . self::LOCK, (string) json_encode($lock, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function clearLock(): void
    {
        Files::delete($this->root . self::LOCK);
    }

    /**
     * @param list<string> $moved
     * @param list<string> $added
     */
    private function recordKeep(string $keep, array $moved, array $added): void
    {
        @file_put_contents($keep . '/.swapped.json', (string) json_encode(['moved' => $moved, 'added' => $added], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 把留档目录记进锁里(动文件之前),只改 keep 一项,别的不碰。
     */
    private function rememberKeep(string $keep): void
    {
        $lock = $this->lock();

        if ($lock === null) {
            return;
        }

        $lock['keep'] = $keep;
        $this->writeLock($lock);
    }

    /**
     * @return array<string, mixed>
     */
    private function readKeep(string $keep): array
    {
        $raw = @file_get_contents($keep . '/.swapped.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }
}
