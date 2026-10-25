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
 * API 密钥仓储(《25-接口规范》第 2 节)。
 *
 * 明文 key 只在 `issue()` 返回一次;库里存 sha256,认证按哈希查行 ——
 * 不用明文比对,翻库也拿不到可用凭据。
 */
final class ApiKeys
{
    /** 前缀让凭据在日志/粘贴里一眼可辨,也方便日志脱敏规则按前缀匹配 */
    public const PREFIX = 'mlk_';

    /** 前缀 + 40 位十六进制(160 位随机),够强也好复制 */
    private const KEY_BYTES = 20;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 发一把新钥匙。返回的 `key` 是明文,只此一次,不落库。
     *
     * @return array{id:int, key:string}
     */
    public function issue(int $userId, string $name): array
    {
        $plain = self::PREFIX . bin2hex(random_bytes(self::KEY_BYTES));

        $id = $this->db->insert('api_keys', [
            'user_id' => $userId,
            'name' => trim($name),
            'key_hash' => hash('sha256', $plain),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'key' => $plain];
    }

    /**
     * 认这把钥匙是谁的。撤回的、格式不对的一律 null;认出来顺手补记使用时间。
     */
    public function authenticate(string $token): ?int
    {
        $token = trim($token);

        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT id, user_id FROM ' . $this->db->table('api_keys')
            . ' WHERE key_hash = :h AND revoked_at IS NULL LIMIT 1',
            ['h' => hash('sha256', $token)]
        );

        if ($row === null) {
            return null;
        }

        $this->db->update(
            'api_keys',
            ['last_used_at' => gmdate('Y-m-d H:i:s')],
            'id = :id',
            ['id' => (int) $row['id']]
        );

        return (int) $row['user_id'];
    }

    /**
     * @return list<array{id:int, user_id:int, username:string, name:string, created_at:string, last_used_at:?string, revoked_at:?string}>
     */
    public function list(): array
    {
        $rows = $this->db->select(
            'SELECT k.id, k.user_id, k.name, k.created_at, k.last_used_at, k.revoked_at, u.username'
            . ' FROM ' . $this->db->table('api_keys') . ' k'
            . ' LEFT JOIN ' . $this->db->table('users') . ' u ON u.id = k.user_id'
            . ' ORDER BY k.id DESC'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'username' => (string) ($row['username'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'last_used_at' => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
            'revoked_at' => isset($row['revoked_at']) ? (string) $row['revoked_at'] : null,
        ], $rows);
    }

    /**
     * 撤回(不删行,留痕)。已经撤回或不存在的返回 false。
     */
    public function revoke(int $id): bool
    {
        $row = $this->db->selectOne(
            'SELECT id FROM ' . $this->db->table('api_keys')
            . ' WHERE id = :id AND revoked_at IS NULL LIMIT 1',
            ['id' => $id]
        );

        if ($row === null) {
            return false;
        }

        $this->db->update(
            'api_keys',
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $id]
        );

        return true;
    }
}
