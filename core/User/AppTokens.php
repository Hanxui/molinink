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
 * App 登录令牌仓储。
 *
 * 一对不透明随机串:access 短命、refresh 长命,库里只存 sha256 ——
 * 明文只在签发时返回一次,翻库也拿不到可用凭据。access 与 refresh 共用 series,
 * 登出按 series 连坐;检出已作废的 refresh 被再次使用,视为凭据泄露,当场作废整条 series。
 */
final class AppTokens
{
    /** 前缀便于日志脱敏规则识别与粘贴辨认 */
    public const PREFIX = 'mlt_';

    /** 24 字节随机 → 48 位十六进制,足够抗穷举 */
    private const TOKEN_BYTES = 24;

    public const ACCESS_TTL = 3600;

    public const REFRESH_TTL = 2592000;

    public const REFRESH_TTL_SHORT = 604800;

    public const KIND_ACCESS = 'access';

    public const KIND_REFRESH = 'refresh';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 登录成功签发一对令牌。`remember` 决定 refresh 的寿命(App 默认长效)。
     *
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, refresh_expires_in:int}
     */
    public function issue(int $userId, string $device = '', bool $remember = true): array
    {
        return $this->issueSeries($userId, bin2hex(random_bytes(16)), $device, $remember);
    }

    /**
     * 认 access 令牌是谁的。作废、过期、格式不对一律 null;认出来顺手补记使用时间。
     */
    public function authenticateAccess(string $token): ?int
    {
        $token = trim($token);

        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');

        $row = $this->db->selectOne(
            'SELECT id, user_id FROM ' . $this->db->table('app_tokens')
            . ' WHERE token_hash = :h AND kind = :kind AND revoked_at IS NULL AND expires_at > :now LIMIT 1',
            ['h' => hash('sha256', $token), 'kind' => self::KIND_ACCESS, 'now' => $now]
        );

        if ($row === null) {
            return null;
        }

        $this->db->update(
            'app_tokens',
            ['last_used_at' => $now],
            'id = :id',
            ['id' => (int) $row['id']]
        );

        return (int) $row['user_id'];
    }

    /**
     * 用 refresh 换一对新令牌。作废/过期的 refresh 换不回;已经作废过又被拿出来用,
     * 当泄露处理 —— 整条 series 连坐作废。
     *
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, refresh_expires_in:int}|null
     */
    public function rotate(string $refreshToken): ?array
    {
        $refreshToken = trim($refreshToken);

        if (!str_starts_with($refreshToken, self::PREFIX)) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT id, user_id, series, device, revoked_at, expires_at FROM ' . $this->db->table('app_tokens')
            . ' WHERE token_hash = :h AND kind = :kind LIMIT 1',
            ['h' => hash('sha256', $refreshToken), 'kind' => self::KIND_REFRESH]
        );

        if ($row === null) {
            return null;
        }

        $series = (string) $row['series'];

        // 已经作废的 refresh 再出现,只能是被抄走重放了
        if ($row['revoked_at'] !== null) {
            $this->revokeSeries($series);

            return null;
        }

        $expires = (string) $row['expires_at'];

        if ($expires <= gmdate('Y-m-d H:i:s')) {
            return null;
        }

        $remember = $expires > gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL_SHORT);

        // 旧的一对先作废,再在同一 series 里签发新的(用一次换一枚)
        $this->revokeSeries($series);

        return $this->issueSeries((int) $row['user_id'], $series, (string) $row['device'], $remember);
    }

    /**
     * 撤掉这个令牌所在的整条 series(登出用)。不存在或早已作废返回 false。
     */
    public function revoke(string $token): bool
    {
        $token = trim($token);

        if (!str_starts_with($token, self::PREFIX)) {
            return false;
        }

        $row = $this->db->selectOne(
            'SELECT series FROM ' . $this->db->table('app_tokens') . ' WHERE token_hash = :h LIMIT 1',
            ['h' => hash('sha256', $token)]
        );

        if ($row === null) {
            return false;
        }

        $this->revokeSeries((string) $row['series']);

        return true;
    }

    /**
     * 封号/改密把某人的全部令牌一起带走。
     */
    public function revokeAllForUser(int $userId): void
    {
        $this->db->update(
            'app_tokens',
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            'user_id = :uid AND revoked_at IS NULL',
            ['uid' => $userId]
        );
    }

    /**
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, refresh_expires_in:int}
     */
    private function issueSeries(int $userId, string $series, string $device, bool $remember): array
    {
        $now = time();
        $access = self::PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
        $refresh = self::PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
        $refreshTtl = $remember ? self::REFRESH_TTL : self::REFRESH_TTL_SHORT;

        $this->db->insert('app_tokens', [
            'user_id' => $userId,
            'series' => $series,
            'kind' => self::KIND_ACCESS,
            'token_hash' => hash('sha256', $access),
            'device' => self::device($device),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + self::ACCESS_TTL),
        ]);

        $this->db->insert('app_tokens', [
            'user_id' => $userId,
            'series' => $series,
            'kind' => self::KIND_REFRESH,
            'token_hash' => hash('sha256', $refresh),
            'device' => self::device($device),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + $refreshTtl),
        ]);

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    private function revokeSeries(string $series): void
    {
        $this->db->update(
            'app_tokens',
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            'series = :series AND revoked_at IS NULL',
            ['series' => $series]
        );
    }

    private static function device(string $device): string
    {
        $device = trim($device);

        return function_exists('mb_substr') ? mb_substr($device, 0, 120) : substr($device, 0, 120);
    }
}
