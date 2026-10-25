<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Transfer;

/**
 * 导出件的下载令牌:一次性、十分钟、只存哈希。
 * 明文只回给签发方一次,落在 storage/cache/data/export-downloads.json(落盘走临时文件 + rename)。
 */
final class DownloadTokens
{
    /** 下载令牌形状 */
    public const TOKEN_PATTERN = '#\A[0-9a-f]{64}\z#';

    /** 下载令牌有效期(秒) */
    public const TOKEN_TTL = 600;

    public function issueToken(string $name): string
    {
        $token = bin2hex(random_bytes(32));
        $all = $this->readTokens();

        // 顺手扔掉过期的(2026-10-17:列表页每次会为每份文件现签一枚,不清会越攒越多)
        $now = time();
        $all = array_filter($all, static fn (array $row): bool => $row['expires_at'] > $now);

        $all[hash('sha256', $token)] = ['name' => $name, 'expires_at' => $now + self::TOKEN_TTL];
        $this->writeTokens($all);

        return $token;
    }

    public function consumeToken(string $token): ?string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $key = hash('sha256', $token);
        $all = $this->readTokens();
        $entry = $all[$key] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        unset($all[$key]);
        $this->writeTokens($all);

        if ((int) ($entry['expires_at'] ?? 0) < time()) {
            return null;
        }

        $name = (string) ($entry['name'] ?? '');

        return preg_match(Toolkit::EXPORT_FILENAME, $name) === 1 ? $name : null;
    }

    private function tokenFile(): string
    {
        return Toolkit::stateRoot() . '/storage/cache/data/export-downloads.json';
    }

    /**
     * @return array<string, array{name:string, expires_at:int}>
     */
    private function readTokens(): array
    {
        $file = $this->tokenFile();

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array{name:string, expires_at:int}> $all
     */
    private function writeTokens(array $all): void
    {
        $now = time();

        foreach ($all as $key => $entry) {
            if ((int) ($entry['expires_at'] ?? 0) < $now) {
                unset($all[$key]);
            }
        }

        $file = $this->tokenFile();
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $temp = $file . '.' . bin2hex(random_bytes(4));

        if (file_put_contents($temp, (string) json_encode($all, JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }

        @chmod($temp, 0o600);
        rename($temp, $file);
    }
}
