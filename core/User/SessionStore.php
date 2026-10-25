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
 * 会话存取(《21-数据模型》第 10 节 sessions 表、《32》第 8 节、《61》第 2.3–2.6 节)。
 *
 * 两条过期一起把关:空闲超时(滑动续期)与绝对上限 —— 只有一条的话,
 * "一直刷新就连不上有效期"或者"三天没动却被踢"总会摊上一个。
 */
final class SessionStore
{
    /** 常规有效期 14 天(《32》第 8 节) */
    public const IDLE_TTL = 1_209_600;

    /** 绝对上限 30 天(《32》第 8 节"记住我 30 天",这里当作任何会话的最长寿命) */
    public const ABSOLUTE_TTL = 2_592_000;

    public function __construct(
        private readonly Database $db,
        private readonly int $idleTtl = self::IDLE_TTL,
        private readonly int $absoluteTtl = self::ABSOLUTE_TTL
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $userId, string $ip, string $ua, array $data = [], ?int $now = null): Session
    {
        $now ??= time();
        $id = bin2hex(random_bytes(32));

        $session = new Session($id, $userId, $data, $ip, $ua, $now, $now + $this->idleTtl);

        $this->db->insert('sessions', [
            'id'         => $session->id,
            'user_id'    => $userId,
            'data'       => $this->encode($session->data),
            'ip'         => $ip === '' ? null : $ip,
            'ua'         => $ua === '' ? null : mb_substr($ua, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $session->expiresAt),
        ]);

        return $session;
    }

    /**
     * 读会话。过期或超绝对上限的行当场清掉,并当作"没登录"。
     */
    public function read(string $id): ?Session
    {
        if ($id === '' || preg_match('/\A[0-9a-f]{64}\z/', $id) !== 1) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT id, user_id, data, ip, ua, created_at, expires_at FROM ' . $this->db->table('sessions') . ' WHERE id = :id',
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        $session = $this->hydrate($row);
        $now = time();

        if ($session->isExpired($now) || $now - $session->createdAt > $this->absoluteTtl) {
            $this->destroy($session->id);

            return null;
        }

        return $session;
    }

    /**
     * 写回 data 并滑动续期。
     */
    public function save(Session $session, bool $touch = true): void
    {
        if ($touch) {
            $session->touch($this->idleTtl);

            // 绝对上限优先:续期不能超过出生时间 + ABSOLUTE_TTL
            $ceiling = $session->createdAt + $this->absoluteTtl;

            if ($session->expiresAt > $ceiling) {
                $session->expiresAt = $ceiling;
            }
        }

        $this->db->update(
            'sessions',
            [
                'data'       => $this->encode($session->data),
                'expires_at' => gmdate('Y-m-d H:i:s', $session->expiresAt),
            ],
            'id = :id',
            ['id' => $session->id]
        );
    }

    /**
     * 服务端销毁(《61》第 2.6 节)。
     */
    public function destroy(string $id): void
    {
        $this->db->delete('sessions', 'id = :id', ['id' => $id]);
    }

    /**
     * 改密后踢掉这个用户的所有会话(《32》第 8 节)。
     */
    public function destroyAllForUser(int $userId): void
    {
        $this->db->delete('sessions', 'user_id = :id', ['id' => $userId]);
    }

    /**
     * 清过期行,返回清掉的条数(后台维护与 cron 用)。
     */
    public function purgeExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->db->table('sessions') . ' WHERE expires_at < :now',
            ['now' => gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * 只数不删,给定时任务的 dry-run 用。
     */
    public function countExpired(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('sessions') . ' WHERE expires_at < :now',
            ['now' => gmdate('Y-m-d H:i:s')]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function idleTtl(): int
    {
        return $this->idleTtl;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Session
    {
        $decoded = json_decode((string) ($row['data'] ?? '{}'), true);

        return new Session(
            (string) $row['id'],
            (int) $row['user_id'],
            is_array($decoded) ? $decoded : [],
            (string) ($row['ip'] ?? ''),
            (string) ($row['ua'] ?? ''),
            self::stamp((string) $row['created_at']),
            self::stamp((string) $row['expires_at'])
        );
    }

    private static function stamp(string $databaseTime): int
    {
        // 库里是 UTC(gmdate 写入),不标 UTC 会被当成站点本地时间,会话寿命整体错几小时
        $parsed = strtotime($databaseTime . ' UTC');

        return $parsed === false ? time() : $parsed;
    }
}
