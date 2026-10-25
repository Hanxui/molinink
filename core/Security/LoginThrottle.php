<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Security;

/**
 * 登录限速(《61》第 2.2 节、《32》第 8 节:同 IP 或同账号失败默认 5 次 / 锁 15 分钟)。
 *
 * 状态暂存本地文件,而不是直接建表或请 Redis:《21》的表清单里没有"登录尝试"表,
 * 自建一张就是自创契约;缓存层(《24》)要到第 16 轮才落地。所以这里是一块**临时落脚地**,
 * 缓存层到位后本类的存储换掉、对外方法签名不动。
 *
 * 并发口径:读-改-写非原子,极端情况下可能少计一次。博客量级下可接受,
 * 但它不是分布式限流器 —— 别按那个标准信它。
 */
final class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 900;

    private readonly string $file;

    public function __construct(
        string $root,
        private readonly int $maxAttempts = self::MAX_ATTEMPTS,
        private readonly int $lockSeconds = self::LOCK_SECONDS
    ) {
        // 计数是读-改-写整份文件:与常驻开发服务(或另一路测试进程)共用一份时,
        // 两边的写会互相覆盖,失败次数就数不满。测试用 MOLIN_STATE_ROOT 分一份自己的。
        $state = getenv('MOLIN_STATE_ROOT');
        $root = is_string($state) && $state !== '' ? $state : $root;

        $this->file = rtrim($root, '/\\') . '/storage/cache/data/login-throttle.json';
    }

    /**
     * IP + 账号合并按哈希处理:文件里不留明文凭据与账号原名。
     */
    public function key(string $ip, string $username): string
    {
        return hash('sha256', $ip . '|' . mb_strtolower(trim($username), 'UTF-8'));
    }

    /**
     * 还有多少次尝试可用;已被锁定时返回 0。
     */
    public function remaining(string $key): int
    {
        $locked = $this->lockedUntil($key);

        if ($locked !== null) {
            return 0;
        }

        $entry = $this->read()[$key] ?? null;

        return max(0, $this->maxAttempts - (int) ($entry['fails'] ?? 0));
    }

    public function lockedUntil(string $key): ?int
    {
        $entry = $this->read()[$key] ?? null;
        $until = (int) ($entry['locked_until'] ?? 0);

        if ($until > time()) {
            return $until;
        }

        return null;
    }

    /**
     * 记一次失败,达到上限就锁。返回锁定到的时间戳(0 表示未锁)。
     */
    public function noteFailure(string $key): int
    {
        $all = $this->read();
        $fails = (int) ($all[$key]['fails'] ?? 0) + 1;
        $lockedUntil = $fails >= $this->maxAttempts ? time() + $this->lockSeconds : 0;

        $all[$key] = ['fails' => $fails, 'locked_until' => $lockedUntil, 'seen_at' => time()];
        $this->write($this->prune($all));

        return $lockedUntil;
    }

    public function clear(string $key): void
    {
        $all = $this->read();
        unset($all[$key]);
        $this->write($all);
    }

    /**
     * @return array<string, array{fails:int, locked_until:int, seen_at?:int}>
     */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $all
     */
    private function write(array $all): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $temp = $this->file . '.' . bin2hex(random_bytes(4));
        $json = (string) json_encode($all, JSON_UNESCAPED_SLASHES);

        if (file_put_contents($temp, $json) === false) {
            return;
        }

        @chmod($temp, 0o600);
        rename($temp, $this->file);
    }

    /**
     * 过期条目顺手清掉,免得文件无限长。
     *
     * @param array<string, mixed> $all
     * @return array<string, mixed>
     */
    private function prune(array $all): array
    {
        $now = time();

        foreach ($all as $key => $entry) {
            $lockedUntil = (int) ($entry['locked_until'] ?? 0);
            $seenAt = (int) ($entry['seen_at'] ?? 0);

            if ($lockedUntil < $now && $seenAt + $this->lockSeconds * 4 < $now) {
                unset($all[$key]);
            }
        }

        return $all;
    }
}
