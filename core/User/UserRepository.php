<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Database\Database;
use Molin\Exception\ConfigException;
use Molin\Exception\ValidationException;
use Molin\Security\Password;

/**
 * 用户仓储(既有规范users / usermeta)。
 *
 * 一个用户的完整面貌 = users 一行 + 若干 usermeta 内置键,所以读取一律两查合一,
 * 并且 meta 用一条 IN 查询批量取,不做"取一个键查一次"。
 */
final class UserRepository
{
    /** 既有规范列出的内置 meta 键 */
    private const BUILT_IN_META = ['role', 'capabilities', 'first_name', 'last_name', 'bio', 'avatar', 'gravatar', 'locale', 'session_version', 'avatar_source', 'avatar_source_url'];

    public function __construct(
        private readonly Database $db,
        private readonly RememberTokens $tokens
    ) {
    }

    /**
     * 建用户。密码在这里就地哈希,调用方不许自己传哈希或明文。
     */
    public function create(string $username, string $email, string $password, string $role = Roles::SUBSCRIBER, string $nickname = ''): User
    {
        $this->assertValidRegistration($username, $email, $password);

        if (!Roles::exists($role)) {
            throw new ValidationException('没有这个角色', ['role' => ['可用:' . implode(', ', Roles::all())]]);
        }

        // 注册页的「昵称」留空时照旧用用户名顶上(昵称无星号,是可选项)
        $who = trim($nickname) === '' ? $username : trim($nickname);
        $now = gmdate('Y-m-d H:i:s');

        $id = $this->db->insert('users', [
            'username'      => $username,
            'email'         => $email,
            'password'      => Password::hash($password),
            'display_name'  => $who,
            'nickname'      => $who,
            'status'        => User::ACTIVE,
            'registered_at' => $now,
        ]);

        $this->setMeta($id, 'role', $role);
        $this->setMeta($id, 'session_version', '1');

        $created = $this->find($id);

        if ($created === null) {
            throw new ConfigException('用户写入后读不回来,数据层出问题了');
        }

        return $created;
    }

    public function find(int $id): ?User
    {
        $row = $this->db->selectOne(
            'SELECT id, username, email, display_name, status FROM ' . $this->db->table('users') . ' WHERE id = :id',
            ['id' => $id]
        );

        return $row === null ? null : User::fromRows($row, $this->meta($id));
    }

    public function findByUsername(string $username): ?User
    {
        return $this->loadOne('username', $username);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->loadOne('email', $email);
    }

    /**
     * @return list<User>
     */
    public function paginate(int $page = 1, int $perPage = 20, ?string $role = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        if ($role !== null) {
            $rows = $this->db->select(
                'SELECT u.id, u.username, u.email, u.display_name, u.status FROM ' . $this->db->table('users') . ' u
                 JOIN ' . $this->db->table('usermeta') . ' m ON m.user_id = u.id
                 WHERE m.meta_key = :rk AND m.meta_value = :role
                 ORDER BY u.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
                ['rk' => 'role', 'role' => $role]
            );
        } else {
            $rows = $this->db->select(
                'SELECT id, username, email, display_name, status FROM ' . $this->db->table('users')
                . ' ORDER BY id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
            );
        }

        $users = [];

        foreach ($rows as $row) {
            $users[] = User::fromRows($row, $this->meta((int) $row['id']));
        }

        return $users;
    }

    /**
     * 站点地图要列作者归档,只列真出过内容的作者。逐行查就是 N+1,
     * 用 EXISTS 一次查全(有已发布 post/page 的作者,按用户名升序)。
     *
     * @return list<User>
     */
    public function authorsWithPublishedPosts(int $limit = 200): array
    {
        $limit = min(500, max(1, $limit));

        $rows = $this->db->select(
            'SELECT u.id, u.username, u.email, u.display_name, u.status FROM ' . $this->db->table('users') . ' u
             WHERE EXISTS (SELECT 1 FROM ' . $this->db->table('posts') . " p WHERE p.author_id = u.id AND p.status = 'publish' AND p.deleted_at IS NULL AND p.type IN ('post', 'page'))
             ORDER BY u.username LIMIT " . $limit
        );

        $users = [];

        foreach ($rows as $row) {
            $users[] = User::fromRows($row, $this->meta((int) $row['id']));
        }

        return $users;
    }

    /**
     * 列表页要给每行标作者,逐行查就是 N+1。一次 IN 查询拿全。
     *
     * @param list<int> $ids
     * @return array<int, string>  user_id => 显示名
     */
    public function namesFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $placeholders[] = ':id' . $index;
            $params['id' . $index] = $id;
        }

        $rows = $this->db->select(
            'SELECT id, display_name FROM ' . $this->db->table('users')
            . ' WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) ($row['display_name'] ?? '');
        }

        return $out;
    }

    /**
     * 列表页每个列表项要标作者(显示名 + 头像)。逐行查作者就是 N+1(既有规范红线),
     * 这里按 post id 一次 JOIN 批量取:用户名 / 显示名 / 邮箱。
     *
     * 按 post id 而不是按 user id 收口 —— 搜索结果(Searcher)只回 post id、不回 author_id,
     * 列表项那边能给的也正好是 post id,一个入口两条路都走。
     * 作者被禁用 / 已删除 / author_id=0 的行不返回,调用方据「取不到」落空串。
     *
     * @param list<int> $postIds
     * @return array<int, array{username: string, display_name: string, email: string}>  post_id => 作者身份
     */
    public function postAuthors(array $postIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $postIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $placeholders[] = ':p' . $index;
            $params['p' . $index] = $id;
        }

        $rows = $this->db->select(
            'SELECT p.id AS post_id, u.username, u.display_name, u.email FROM ' . $this->db->table('posts') . ' p'
            . ' JOIN ' . $this->db->table('users') . ' u ON u.id = p.author_id AND u.status = :active'
            . ' WHERE p.id IN (' . implode(', ', $placeholders) . ')',
            $params + ['active' => User::ACTIVE]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['post_id']] = [
                'username' => (string) ($row['username'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
            ];
        }

        return $out;
    }

    public function count(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS total FROM ' . $this->db->table('users'));

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array<string, string> 内置键一次取全
     */
    public function meta(int $userId): array
    {
        $placeholders = [];
        $params = ['uid' => $userId];

        foreach (self::BUILT_IN_META as $index => $key) {
            $placeholders[] = ':k' . $index;
            $params['k' . $index] = $key;
        }

        $rows = $this->db->select(
            'SELECT meta_key, meta_value FROM ' . $this->db->table('usermeta')
            . ' WHERE user_id = :uid AND meta_key IN (' . implode(', ', $placeholders) . ') ORDER BY meta_id',
            $params
        );

        $out = [];

        foreach ($rows as $row) {
            // 同键多行时取最后写入的一条(usermeta 无唯一键,既有规范)
            $out[(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
        }

        return $out;
    }

    public function setMeta(int $userId, string $key, string $value): void
    {
        $existing = $this->db->selectOne(
            'SELECT meta_id FROM ' . $this->db->table('usermeta') . ' WHERE user_id = :uid AND meta_key = :k ORDER BY meta_id DESC LIMIT 1',
            ['uid' => $userId, 'k' => $key]
        );

        if ($existing === null) {
            $this->db->insert('usermeta', ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);

            return;
        }

        $this->db->update(
            'usermeta',
            ['meta_value' => $value],
            'meta_id = :id',
            ['id' => (int) $existing['meta_id']]
        );
    }

    public function changeRole(int $userId, string $role): User
    {
        if (!Roles::exists($role)) {
            throw new ValidationException('没有这个角色', ['role' => ['可用:' . implode(', ', Roles::all())]]);
        }

        $this->setMeta($userId, 'role', $role);

        $user = $this->find($userId);

        if ($user === null) {
            throw new ValidationException('用户不存在', ['id' => [(string) $userId]]);
        }

        return $user;
    }

    /**
     * 额外能力。存 usermeta.capabilities 的 JSON 数组。
     *
     * @param list<string> $capabilities
     */
    public function grant(int $userId, array $capabilities): User
    {
        $user = $this->existing($userId);

        foreach ($capabilities as $capability) {
            if (!Capabilities::isKnown($capability)) {
                throw new ValidationException('能力未注册', ['capability' => [$capability]]);
            }
        }

        $merged = [...$user->extraCapabilities, ...$capabilities];
        sort($merged);

        $this->setMeta($userId, 'capabilities', (string) json_encode(array_values(array_unique($merged))));

        return $this->existing($userId);
    }

    /**
     * @param list<string> $capabilities
     */
    public function revoke(int $userId, array $capabilities): User
    {
        $user = $this->existing($userId);
        $left = array_values(array_diff($user->extraCapabilities, $capabilities));

        $this->setMeta($userId, 'capabilities', (string) json_encode($left));

        return $this->existing($userId);
    }

    /**
     * 改密码:重哈希 + session_version+1。
     * 旧会话不是"删掉",而是版本号对不上被拒 —— 这样即使有并发会话也不会误删新登录。
     *
     * 这里的令牌撤销是**唯一入口级**的:改密有网页重置、CLI、以后的资料页三条路,
     * 挂在调用方早晚有人忘;挂在必经的这一处,"改过密码还留着长期凭据"就构造不出来。
     */
    public function changePassword(int $userId, string $newPassword): User
    {
        $user = $this->existing($userId);
        $errors = Password::checkStrength($newPassword);

        if ($errors !== []) {
            throw new ValidationException('新密码不合要求', ['password' => $errors]);
        }

        $this->db->update(
            'users',
            ['password' => Password::hash($newPassword)],
            'id = :id',
            ['id' => $userId]
        );

        $this->setMeta($userId, 'session_version', (string) ($user->sessionVersion + 1));
        $this->tokens->revokeAll($userId);

        return $this->existing($userId);
    }

    /**
     * 登录成功后补记时间与 IP。
     */
    public function touchLogin(int $userId, string $ip): void
    {
        $this->db->update(
            'users',
            [
                'last_login_at' => gmdate('Y-m-d H:i:s'),
                'last_login_ip' => $ip === '' ? null : $ip,
            ],
            'id = :id',
            ['id' => $userId]
        );
    }

    /**
     * 个人资料保存:昵称、邮箱与几项用户自己的 meta 一次写完。
     *
     * 校验在这一处收口:网页屏、以后的接口都从这里过,格式与唯一性不会各判各的。
     *
     * @param array{display_name:string, email:string} $profile
     * @param array<string, string> $meta 只收内置键(user 域之外的键归插件)
     */
    public function updateProfile(int $userId, array $profile, array $meta): User
    {
        $this->existing($userId);

        $displayName = trim($profile['display_name']);
        $email = trim($profile['email']);
        $errors = [];

        if ($displayName === '' || mb_strlen($displayName) > 60) {
            $errors['display_name'] = ['昵称要有,且不超过 60 个字符'];
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = ['邮箱格式不对'];
        }

        if ($errors !== []) {
            throw new ValidationException('资料未通过校验', $errors);
        }

        $taken = $this->findByEmail($email);

        if ($taken !== null && $taken->id !== $userId) {
            throw new ValidationException('这个邮箱已注册过', ['email' => ['这个邮箱已注册过，换一个吧。']]);
        }

        foreach (array_keys($meta) as $key) {
            if (!in_array($key, self::BUILT_IN_META, true)) {
                throw new ValidationException('不是内置用户字段', ['meta' => [$key]]);
            }
        }

        $this->db->update(
            'users',
            [
                'display_name' => $displayName,
                'nickname' => $displayName,
                'email' => $email,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            'id = :id',
            ['id' => $userId]
        );

        foreach ($meta as $key => $value) {
            $this->setMeta($userId, $key, $value);
        }

        return $this->existing($userId);
    }

    /**
     * 头像这类单独落库的小动作也记进「上次保存于」。
     */
    public function touchProfile(int $userId): void
    {
        $this->db->update('users', ['updated_at' => gmdate('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
    }

    /**
     * 注册时间与上次保存时间(既有规范/4.7 节的「加入于」「上次保存于」)。
     *
     * @return array{registered_at:?string, updated_at:?string}
     */
    public function timestamps(int $userId): array
    {
        $row = $this->db->selectOne(
            'SELECT registered_at, updated_at FROM ' . $this->db->table('users') . ' WHERE id = :id',
            ['id' => $userId]
        );

        return [
            'registered_at' => isset($row['registered_at']) && is_string($row['registered_at']) ? $row['registered_at'] : null,
            'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
        ];
    }

    /**
     * 作者名下有多少篇文章(既有规范的「36 篇文章」)。只算 posts,页面与附件不算。
     */
    public function postCount(int $userId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM ' . $this->db->table('posts')
            . " WHERE author_id = :id AND type = :type AND status <> 'trash'",
            ['id' => $userId, 'type' => 'post']
        );

        return (int) ($row['total'] ?? 0);
    }

    public function storeHash(int $userId, string $hash): void
    {
        $this->db->update('users', ['password' => $hash], 'id = :id', ['id' => $userId]);
    }

    public function hashOf(int $userId): string
    {
        $row = $this->db->selectOne(
            'SELECT password FROM ' . $this->db->table('users') . ' WHERE id = :id',
            ['id' => $userId]
        );

        return (string) ($row['password'] ?? '');
    }

    public function setStatus(int $userId, string $status): User
    {
        if (!in_array($status, [User::ACTIVE, User::PENDING, User::BANNED], true)) {
            throw new ValidationException('没有这种用户状态', ['status' => [$status]]);
        }

        $this->db->update('users', ['status' => $status], 'id = :id', ['id' => $userId]);

        return $this->existing($userId);
    }

    private function loadOne(string $column, string $value): ?User
    {
        if ($value === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT id, username, email, display_name, status FROM ' . $this->db->table('users')
            . ' WHERE ' . $column . ' = :value LIMIT 1',
            ['value' => $value]
        );

        if ($row === null) {
            return null;
        }

        return User::fromRows($row, $this->meta((int) $row['id']));
    }

    private function existing(int $userId): User
    {
        $user = $this->find($userId);

        if ($user === null) {
            throw new ValidationException('用户不存在', ['id' => [(string) $userId]]);
        }

        return $user;
    }

    /**
     * 用户名格式:既有规范没给细则,这里定"字母数字开头,3–60 位,可含 . _ -"
     * (上限对齐 users.username 的 VARCHAR(60))。
     */
    private function assertValidRegistration(string $username, string $email, string $password): void
    {
        $errors = [];

        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{2,59}\z/i', $username) !== 1) {
            $errors['username'] = ['3–60 位,字母数字开头,可含 . _ -'];
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = ['邮箱格式不对'];
        }

        if (($strength = Password::checkStrength($password)) !== []) {
            $errors['password'] = $strength;
        }

        if ($errors !== []) {
            throw new ValidationException('注册信息未通过校验', $errors);
        }

        if ($this->findByUsername($username) !== null) {
            throw new ValidationException('这个用户名已被占用', ['username' => ['换一个']]);
        }

        if ($this->findByEmail($email) !== null) {
            throw new ValidationException('这个邮箱已注册过', ['email' => ['这个邮箱已注册过，换一个吧。']]);
        }
    }
}
