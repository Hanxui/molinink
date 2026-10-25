<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Exception\ForbiddenException;
use Molin\Logger\Audit;
use Molin\Option\Options;

/**
 * 开放注册(《32-用户与权限模型》第 5、6 节)。
 *
 * 与 `UserRepository::create()` 的分工:仓储只负责"把一条用户写对",
 * 这里负责"能不能写、写完通知谁"。后台建用户走仓储,前台注册走本类 ——
 * 否则很容易忘了检查注册开关,或让表单指定角色(那是提权)。
 */
final class Registrar
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Options $options
    ) {
    }

    /**
     * 开关 `users_can_register`(《32》第 6 节第 ① 步)。默认关 —— 没人替你做决定时不开放。
     */
    public function isOpen(): bool
    {
        $value = $this->options->get('users_can_register', '0');

        return (string) $value === '1';
    }

    /**
     * @param array{username?: string, email?: string, password?: string, nickname?: string} $data
     */
    public function register(array $data, string $ip = ''): User
    {
        if (!$this->isOpen()) {
            throw new ForbiddenException('站点当前未开放注册');
        }

        $payload = apply_filters('user/register/before', [
            'username' => (string) ($data['username'] ?? ''),
            'email'    => (string) ($data['email'] ?? ''),
            'password' => (string) ($data['password'] ?? ''),
            'nickname' => (string) ($data['nickname'] ?? ''),
        ], $ip);

        $payload = is_array($payload) ? $payload : [];

        // 新账号一律订阅者(《61》第 3.6 节:非管理员不得把任何人提成管理员,包括自己)
        $user = $this->users->create(
            (string) ($payload['username'] ?? ''),
            (string) ($payload['email'] ?? ''),
            (string) ($payload['password'] ?? ''),
            Roles::SUBSCRIBER,
            (string) ($payload['nickname'] ?? '')
        );

        Audit::record('user.create', 'user:' . $user->id, true, null, 'anonymous');
        do_action('user/register/after', $user);

        return $user;
    }
}
