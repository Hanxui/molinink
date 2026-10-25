<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Plugin\CapabilityGate;

/**
 * 用户值对象(《21-数据模型》第 5 节 users + usermeta 内置键)。
 *
 * 角色与额外能力都装在这里:role 存 usermeta.role,
 * capabilities 存 usermeta.capabilities(《32》第 4.1 节)。
 *
 * fromRows() 是 `user.read_email` 能力的拦截面(《62》第 6 节):邮箱随行数据一起装进对象,
 * 这一处是"用户对象取邮箱"的唯一入口,门卫落在这里。内核自身(无插件上下文)照常装载。
 */
final class User
{
    public const ACTIVE = 'active';
    public const PENDING = 'pending';
    public const BANNED = 'banned';

    /**
     * @param list<string> $extraCapabilities
     */
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $display_name,
        public readonly string $status = self::ACTIVE,
        public readonly string $role = Roles::SUBSCRIBER,
        public readonly array $extraCapabilities = [],
        public readonly int $sessionVersion = 1
    ) {
    }

    /**
     * @param array<string, mixed> $row  users 表的一行
     * @param array<string, string> $meta usermeta 的键值(内置键见《32》第 9 节)
     */
    public static function fromRows(array $row, array $meta): self
    {
        // 邮箱在这一处随行数据一起进对象 —— user.read_email 的拦截面就在这儿。
        // 调用方是插件且未声明即被拦下;内核自身(无插件上下文)照常装载(登录、找回密码、
        // 后台用户列表、API 都走这条路,它们没有插件上下文,一律放行)。
        CapabilityGate::assert('user.read_email');

        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['username'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['display_name'] ?? ($row['username'] ?? '')),
            (string) ($row['status'] ?? self::ACTIVE),
            (string) ($meta['role'] ?? Roles::SUBSCRIBER),
            self::decodeCaps($meta['capabilities'] ?? null),
            (int) ($meta['session_version'] ?? 1)
        );
    }

    /**
     * 这个角色有什么 + 这个人额外有什么。
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return array_values(array_unique([...Roles::capabilitiesOf($this->role), ...$this->extraCapabilities]));
    }

    /**
     * 只看能力名,不做"自己的内容"这类对象级判断 —— 那层在 Authorizer。
     */
    public function holds(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @param list<string> $capabilities
     */
    public function withExtraCapabilities(array $capabilities): self
    {
        return new self(
            $this->id,
            $this->username,
            $this->email,
            $this->display_name,
            $this->status,
            $this->role,
            array_values(array_unique($capabilities)),
            $this->sessionVersion
        );
    }

    public function withRole(string $role): self
    {
        return new self(
            $this->id,
            $this->username,
            $this->email,
            $this->display_name,
            $this->status,
            $role,
            $this->extraCapabilities,
            $this->sessionVersion
        );
    }

    /**
     * @return list<string>
     */
    private static function decodeCaps(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(strval(...), $decoded), static fn (string $cap): bool => $cap !== ''));
    }
}
