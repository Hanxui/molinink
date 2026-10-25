<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

/**
 * 一次登录态(《21-数据模型》第 10 节 sessions 行)。
 *
 * id 是 64 位十六进制随机值,只当句柄用,不含任何信息 —— 防会话固定靠的就是
 * "登录成功后换一个新 id"(《61》第 2.3 节)。
 */
final class Session
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public array $data = [],
        public readonly string $ip = '',
        public readonly string $ua = '',
        public readonly int $createdAt = 0,
        public int $expiresAt = 0
    ) {
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt <= ($now ?? time());
    }

    /**
     * 空闲续期(滑动过期)。绝对过期由 created_at + 上限另行把关。
     */
    public function touch(int $ttlSeconds, ?int $now = null): void
    {
        $this->expiresAt = ($now ?? time()) + $ttlSeconds;
    }

    public function csrfToken(): ?string
    {
        $token = $this->data['csrf_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
