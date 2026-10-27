<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Database\Database;

/**
 * 「记住我」的独立登录令牌(既有规范记住我 30 天)。
 *
 * cookie 里放 `selector:verifier` 两截:selector 是公开定位用的,verifier 只以 sha256 落库。
 * 于是 —— 库里翻不出可用凭据;校验用 hash_equals 比哈希,不拿明文当索引;
 * 每次凭 cookie 自动登录成功就换一枚新的(旧的那条当场删),被抄走的 cookie 活不过一次使用。
 */
final class RememberTokens
{
    /** 记住我 30 天 */
    public const TTL = 2_592_000;

    /** 与 molin_session 分开一枚:会话是会话,长期凭据是长期凭据,撤销互不牵连 */
    public const COOKIE = 'molin_remember';

    public function __construct(
        private readonly Database $db,
        private readonly int $ttl = self::TTL
    ) {
    }

    /**
     * 签发一枚,返回要写进 cookie 的原文(库里只存它的哈希)。
     */
    public function issue(int $userId): string
    {
        $selector = bin2hex(random_bytes(6));
        $verifier = bin2hex(random_bytes(16));
        $now = time();

        $this->db->insert('user_tokens', [
            'user_id'    => $userId,
            'selector'   => $selector,
            'token_hash' => hash('sha256', $verifier),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + $this->ttl),
        ]);

        return $selector . ':' . $verifier;
    }

    /**
     * 拿 cookie 换账号,并顺手轮换:返回新 cookie,调用方必须把它发回浏览器。
     *
     * @return array{user_id:int, cookie:string}|null
     */
    public function verify(string $cookie): ?array
    {
        $parts = $this->split($cookie);

        if ($parts === null) {
            return null;
        }

        [$selector, $verifier] = $parts;

        $row = $this->db->selectOne(
            'SELECT id, user_id, token_hash, expires_at FROM ' . $this->table() . ' WHERE selector = :s LIMIT 1',
            ['s' => $selector]
        );

        if ($row === null || !hash_equals((string) $row['token_hash'], hash('sha256', $verifier))) {
            return null;
        }

        $expires = strtotime((string) $row['expires_at'] . ' UTC');

        if ($expires === false || $expires <= time()) {
            $this->db->delete('user_tokens', 'id = :id', ['id' => (int) $row['id']]);

            return null;
        }

        $userId = (int) $row['user_id'];
        $this->db->delete('user_tokens', 'id = :id', ['id' => (int) $row['id']]);

        return ['user_id' => $userId, 'cookie' => $this->issue($userId)];
    }

    /**
     * 登出只撤这一台设备的令牌,别把人家手机上也踢掉。
     */
    public function revoke(?string $cookie): void
    {
        $parts = $this->split((string) $cookie);

        if ($parts === null) {
            return;
        }

        $this->db->delete('user_tokens', 'selector = :s', ['s' => $parts[0]]);
    }

    /**
     * 改密码 = 所有设备的长期凭据一起作废(既有规范session_version 的同一条语义)。
     */
    public function revokeAll(int $userId): void
    {
        $this->db->delete('user_tokens', 'user_id = :uid', ['uid' => $userId]);
    }

    /**
     * 清掉过期令牌,交给 cron(与媒体物理清理同一条路,既有规范)。
     */
    public function purgeExpired(): int
    {
        return $this->db->delete(
            'user_tokens',
            'expires_at < :now',
            ['now' => gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * 只数不删,给定时任务的 dry-run 用。
     */
    public function countExpired(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('user_tokens') . ' WHERE expires_at < :now',
            ['now' => gmdate('Y-m-d H:i:s')]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return array{0:string, 1:string}|null
     */
    private function split(string $cookie): ?array
    {
        if (preg_match('#\A([0-9a-f]{12}):([0-9a-f]{32})\z#', $cookie, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    private function table(): string
    {
        return $this->db->table('user_tokens');
    }
}
