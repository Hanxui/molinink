<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Security;

/**
 * 接口限速(未认证按 IP 60 次/分,认证按用户 600 次/分)。
 *
 * 与 LoginThrottle 同款落脚地:本地文件、读-改-写。博客量级够用,
 * 它同样不是分布式限流器 —— 上多机时这份状态要换成共享缓存。
 */
final class RateLimiter
{
    public const WINDOW_SECONDS = 60;

    private readonly string $file;

    public function __construct(
        string $root,
        private readonly int $anonymousLimit = 60,
        private readonly int $userLimit = 600
    ) {
        $state = getenv('MOLIN_STATE_ROOT');
        $root = is_string($state) && $state !== '' ? $state : $root;

        $this->file = rtrim($root, '/\\') . '/storage/cache/data/api-rate.json';
    }

    public function anonymousLimit(): int
    {
        return $this->anonymousLimit;
    }

    public function userLimit(): int
    {
        return $this->userLimit;
    }

    /**
     * 记一次请求。放行返回 null;超限返回距离窗口重置的秒数(给 Retry-After)。
     */
    public function hit(string $key, int $limit): ?int
    {
        $now = time();
        $all = $this->read();
        $entry = $all[$key] ?? null;
        $start = (int) ($entry['start'] ?? 0);
        $count = (int) ($entry['count'] ?? 0);

        if ($entry === null || $now - $start >= self::WINDOW_SECONDS) {
            $start = $now;
            $count = 0;
        }

        ++$count;
        $all[$key] = ['start' => $start, 'count' => $count];
        $this->write($this->prune($all, $now));

        if ($count <= $limit) {
            return null;
        }

        return max(1, $start + self::WINDOW_SECONDS - $now);
    }

    /**
     * @return array<string, array{start:int, count:int}>
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
     * 过窗的条目顺手清掉,文件不无限长。
     *
     * @param array<string, mixed> $all
     * @return array<string, mixed>
     */
    private function prune(array $all, int $now): array
    {
        foreach ($all as $key => $entry) {
            if ($now - (int) ($entry['start'] ?? 0) >= self::WINDOW_SECONDS) {
                unset($all[$key]);
            }
        }

        return $all;
    }
}
