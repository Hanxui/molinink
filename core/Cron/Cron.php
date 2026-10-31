<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cron;

use Molin\Logger\Log;
use Molin\Site\VisitRetention;
use Molin\User\RememberTokens;
use Molin\User\SessionStore;
use Throwable;

/**
 * 定时任务。cron:run 只跑到期的,cron:list 只列不动。
 *
 * 内建任务写死在本类,插件任务经 `system/cron/collect` 收集。同名时内建优先 ——
 * 插件不能顶掉内核自己维护的清理任务,否则升级后行为会跟着插件清单漂移。
 *
 * 「上次跑过」记在 storage/cache/data/cron.json —— 与登录限速、接口限速同一套落盘方式。
 * 不借 options 表:Options::set 每次都写一条 setting.update 审计,定时任务不该往审计里灌垃圾。
 */
final class Cron
{
    /** 任务名一旦发布不改;秒数 = 多久该跑一次。 */
    private const JOBS = [
        'cleanup-sessions' => ['seconds' => 86400, 'note' => '清过期会话'],
        'cleanup-tokens'   => ['seconds' => 86400, 'note' => '清过期登录令牌'],
        // 访问计数只增不删就是无界增长,保留策略见 Molin\Site\VisitRetention(默认留 400 天)
        'cleanup-visits'   => ['seconds' => 86400, 'note' => '剪掉过保留期的访问计数'],
    ];

    /** 任务名:小写字母开头,允许数字、冒号、下划线、点与连字符(插件用 `插件名:任务` 区分) */
    private const JOB_PATTERN = '#\A[a-z][a-z0-9:._-]*\z#';

    private readonly string $stateFile;

    /** @var array<string, array{seconds: int, note: string, run: callable|null, source: string}>|null */
    private ?array $merged = null;

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly RememberTokens $tokens,
        string $root,
        // 访问计数的保留策略(2026-10-31 增)。可选:最小装配(只跑内建任务、不带站点库的
        // CLI 测试容器)里没有它 —— 那种情况下任务照常在册,但一行不动,与其余内建任务
        // 「依赖没接上就返回 0」同一口径。真正的站点装配在 Bootstrap 里一定注进来。
        private readonly ?VisitRetention $visits = null
    ) {
        $state = getenv('MOLIN_STATE_ROOT');
        $root = is_string($state) && $state !== '' ? $state : $root;

        $this->stateFile = rtrim($root, '/\\') . '/storage/cache/data/cron.json';
    }

    /**
     * 内建 + 插件任务。插件 collect 只返回声明,真正干活在 run 回调里。
     *
     * @return array<string, array{seconds: int, note: string, run: callable|null, source: string}>
     */
    public function jobs(): array
    {
        if ($this->merged !== null) {
            return $this->merged;
        }

        $jobs = [];

        foreach (self::JOBS as $name => $job) {
            $jobs[$name] = [
                'seconds' => $job['seconds'],
                'note'    => $job['note'],
                'run'     => null,
                'source'  => 'builtin',
            ];
        }

        $declared = apply_filters('system/cron/collect', []);

        if (!is_array($declared)) {
            Log::channel('cron')->warning('cron.collect_invalid', ['type' => get_debug_type($declared)]);

            return $this->merged = $jobs;
        }

        foreach ($declared as $name => $job) {
            if (!is_string($name) || preg_match(self::JOB_PATTERN, $name) !== 1) {
                Log::channel('cron')->warning('cron.job_invalid_name', ['job' => $name]);

                continue;
            }

            if (
                !is_array($job)
                || !is_int($job['seconds'] ?? null)
                || $job['seconds'] <= 0
                || !is_string($job['note'] ?? null)
                || !is_callable($job['run'] ?? null)
            ) {
                Log::channel('cron')->warning('cron.job_invalid_shape', ['job' => $name]);

                continue;
            }

            // 先到先得:内建已经占位,插件同名任务只记一笔,绝不覆盖内核任务
            if (isset($jobs[$name])) {
                Log::channel('cron')->warning('cron.job_duplicate', ['job' => $name]);

                continue;
            }

            $jobs[$name] = [
                'seconds' => $job['seconds'],
                'note'    => $job['note'],
                'run'     => $job['run'],
                'source'  => 'plugin',
            ];
        }

        return $this->merged = $jobs;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->jobs());
    }

    public function seconds(string $job): int
    {
        return $this->jobs()[$job]['seconds'] ?? 0;
    }

    public function note(string $job): string
    {
        return $this->jobs()[$job]['note'] ?? '';
    }

    /** builtin|plugin;未知任务返回空串 */
    public function source(string $job): string
    {
        return $this->jobs()[$job]['source'] ?? '';
    }

    public function has(string $job): bool
    {
        return array_key_exists($job, $this->jobs());
    }

    /** @return array<string, int> 任务名 => 上次跑的时间戳(0 = 从没跑过) */
    public function lastRuns(): array
    {
        $all = $this->read();
        $out = [];

        foreach ($this->names() as $job) {
            $out[$job] = (int) ($all[$job] ?? 0);
        }

        return $out;
    }

    /** @return list<string> 到点该跑的 */
    public function due(int $now): array
    {
        $due = [];
        $state = $this->read();

        foreach ($this->jobs() as $job => $spec) {
            $last = (int) ($state[$job] ?? 0);

            if ($last + $spec['seconds'] <= $now) {
                $due[] = $job;
            }
        }

        return $due;
    }

    /**
     * 跑一个任务,返回动了几条。dry-run 只数不删,也不记「跑过了」——
     * 演练过就当跑过,真到点反而不跑,那才是坑。
     *
     * 插件回调失败只记日志、不写 stamp:这一轮当没跑过,下一轮还会再试;
     * 异常留在 Cron 内部消化,CLI 的其余任务照常执行。
     */
    public function run(string $job, int $now, bool $dryRun = false): int
    {
        $jobs = $this->jobs();

        if (!isset($jobs[$job])) {
            return 0;
        }

        $spec = $jobs[$job];

        if ($spec['run'] !== null) {
            try {
                $count = (int) ($spec['run'])($now, $dryRun);
            } catch (Throwable $e) {
                Log::channel('cron')->error('cron.job_failed', [
                    'job'       => $job,
                    'dry_run'   => $dryRun,
                    'exception' => $e::class,
                    'error'     => $e->getMessage(),
                ]);

                return 0;
            }
        } else {
            $count = match ($job) {
                'cleanup-sessions' => $dryRun ? $this->sessions->countExpired() : $this->sessions->purgeExpired(),
                'cleanup-tokens'   => $dryRun ? $this->tokens->countExpired() : $this->tokens->purgeExpired(),
                'cleanup-visits'   => $this->visits === null ? 0 : $this->visits->prune($now, $dryRun),
                default => 0,
            };
        }

        if (!$dryRun) {
            $this->stamp($job, $now);
        }

        return $count;
    }

    private function stamp(string $job, int $now): void
    {
        $all = $this->read();
        $all[$job] = $now;
        $this->write($all);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->stateFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $all
     */
    private function write(array $all): void
    {
        $dir = dirname($this->stateFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $temp = $this->stateFile . '.' . bin2hex(random_bytes(4));

        if (file_put_contents($temp, (string) json_encode($all, JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }

        @chmod($temp, 0o600);
        rename($temp, $this->stateFile);
    }
}
