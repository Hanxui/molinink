<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Database\Database;
use Molin\Router\Router;

/**
 * 一次性密码重置令牌(《32》第 6.3 节 ①③、《61》第 2.8 节)。
 *
 * 令牌存 usermeta、只存哈希,明文那一次都不落库 —— 库里翻不到可用的凭据。
 * 有效期 1 小时、用过即废;重复申请直接换新的(旧的那条当场作废)。
 *
 * 发信不在内核里做(《33》/《62》口径:SMTP 属插件),本类只负责"生成 + 校验 + 作废",
 * 并把链接该怎么拼留在 link() 一处;签发后发 `user/lostpassword/after`(user, link),
 * 发信插件接上即寄出,链接格式不必猜(2026-10-04 与 mail-smtp 插件同轮落地)。
 */
final class PasswordReset
{
    /** 《32》§6.3 ①:令牌 1 小时有效 */
    public const TTL = 3600;

    /** 同一账号两次申请的最小间隔。《123》§11 第 2 条说这数值文档没给,先钉 60 秒,别让人拿这页刷邮件 */
    public const COOLDOWN = 60;

    private const KEY_TOKEN = 'reset_token';

    private const KEY_SENT = 'reset_sent_at';

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly Router $router
    ) {
    }

    /**
     * 为某个邮箱生成令牌。账号不存在、或刚发过 —— 都返回 null,调用方给的反馈必须一模一样。
     */
    public function issue(string $email): ?string
    {
        $user = $this->users->findByEmail(trim($email));

        if ($user === null || $this->cooling($user->id)) {
            return null;
        }

        $raw = bin2hex(random_bytes(32));

        $this->users->setMeta($user->id, self::KEY_TOKEN, hash('sha256', $raw));
        $this->users->setMeta($user->id, self::KEY_SENT, (string) time());

        // 令牌签发即交棒(《32》§6.3 ②):发信插件(mail-smtp)监听这里把链接寄出去
        do_action('user/lostpassword/after', $user, $this->link($raw));

        return $raw;
    }

    /**
     * 邮件里那条链接。绝对地址:它要在别人的邮件客户端里点得开。
     */
    public function link(string $token): string
    {
        return $this->router->generate('resetpassword') . '?token=' . rawurlencode($token);
    }

    /**
     * 令牌换账号。过期、用过、乱填的都返回 null —— 三种情况页面长得一样,不给人试探的余地。
     */
    public function resolve(string $token): ?User
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $meta = $this->db->table('usermeta');
        $rows = $this->db->select(
            'SELECT user_id, meta_value FROM ' . $meta . ' WHERE meta_key = :k ORDER BY meta_id DESC',
            ['k' => self::KEY_TOKEN]
        );

        foreach ($rows as $row) {
            if (!hash_equals((string) $row['meta_value'], $hash)) {
                continue;
            }

            $userId = (int) $row['user_id'];

            if (!$this->stillValid($userId)) {
                return null;
            }

            return $this->users->find($userId);
        }

        return null;
    }

    /**
     * 用完就作废:先清令牌,再改密码(改密码会把 session_version+1,别的设备当场掉线)。
     */
    public function complete(User $user, string $newPassword): User
    {
        $updated = $this->users->changePassword($user->id, $newPassword);

        $this->drop($user->id);

        return $updated;
    }

    private function cooling(int $userId): bool
    {
        $sent = $this->metaOf($userId, self::KEY_SENT);

        return $sent !== null && time() - (int) $sent < self::COOLDOWN;
    }

    private function stillValid(int $userId): bool
    {
        $sent = $this->metaOf($userId, self::KEY_SENT);

        return $sent !== null && time() - (int) $sent < self::TTL;
    }

    private function drop(int $userId): void
    {
        $this->db->delete('usermeta', 'user_id = :uid AND meta_key IN (:t, :s)', [
            'uid' => $userId,
            't' => self::KEY_TOKEN,
            's' => self::KEY_SENT,
        ]);
    }

    private function metaOf(int $userId, string $key): ?string
    {
        $row = $this->db->selectOne(
            'SELECT meta_value FROM ' . $this->db->table('usermeta')
            . ' WHERE user_id = :uid AND meta_key = :k ORDER BY meta_id DESC LIMIT 1',
            ['uid' => $userId, 'k' => $key]
        );

        return $row === null ? null : (string) $row['meta_value'];
    }
}
