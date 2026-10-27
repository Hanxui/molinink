<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Backup;

/**
 * 备份下载的一次性限时令牌(下载链接一次性、限时;不得暴露真实路径)。
 *
 * 与 LoginThrottle / RateLimiter 同款落脚地:本地文件、读-改-写。
 * 文件里只存令牌的哈希 —— 就算 storage 被看光,也复现不出可用链接。
 * 默认 10 分钟有效,用过即焚;过期条目每次写盘顺手清掉。
 */
final class DownloadTokens
{
    public const TTL_SECONDS = 600;

    public const TOKEN_PATTERN = '#\A[0-9a-f]{64}\z#';

    private readonly string $file;

    public function __construct(string $root)
    {
        $state = getenv('MOLIN_STATE_ROOT');
        $root = is_string($state) && $state !== '' ? $state : $root;

        $this->file = rtrim($root, '/\\') . '/storage/cache/data/backup-downloads.json';
    }

    /**
     * 签发一枚令牌;同一份备份重复点「下载」就多枚并存,旧的过期自然消失。
     */
    public function issue(string $name, int $ttl = self::TTL_SECONDS): string
    {
        $token = bin2hex(random_bytes(32));
        $all = $this->read();
        $all[hash('sha256', $token)] = ['name' => $name, 'expires_at' => time() + max(60, $ttl)];
        $this->write($this->prune($all, time()));

        return $token;
    }

    /**
     * 取件:有效就返回备份名并立刻作废(一次性);过期、伪造、用过的都返回 null。
     */
    public function consume(string $token): ?string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $key = hash('sha256', $token);
        $all = $this->read();
        $entry = $all[$key] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        unset($all[$key]);
        $this->write($this->prune($all, time()));

        if ((int) ($entry['expires_at'] ?? 0) < time()) {
            return null;
        }

        $name = (string) ($entry['name'] ?? '');

        return preg_match(Backup::FILENAME, $name) === 1 ? $name : null;
    }

    /**
     * @return array<string, array{name:string, expires_at:int}>
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
     * @param array<string, array{name:string, expires_at:int}> $all
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
     * @param array<string, array{name:string, expires_at:int}> $all
     * @return array<string, array{name:string, expires_at:int}>
     */
    private function prune(array $all, int $now): array
    {
        foreach ($all as $key => $entry) {
            if ((int) ($entry['expires_at'] ?? 0) < $now) {
                unset($all[$key]);
            }
        }

        return $all;
    }
}
