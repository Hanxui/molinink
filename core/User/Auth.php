<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Exception\RateLimitException;
use Molin\Exception\UnauthorizedException;
use Molin\Logger\Audit;
use Molin\Security\Csrf;
use Molin\Security\LoginThrottle;
use Molin\Security\Password;

/**
 * 登录与会话(既有规范–2.6 节)。
 *
 * 登录成功后一定换一个全新会话 id(防会话固定),旧 id 直接作废;
 * 改密靠 usermeta.session_version 递增让手上的旧令牌一起失效。
 */
final class Auth
{
    /** 会话 cookie 名。既有规范没给名字,这里定一个并当对外口径使用 */
    public const COOKIE = 'molin_session';

    private static ?User $current = null;

    private static ?Session $session = null;

    /**
     * 这一趟请求里要补发给浏览器的 cookie(登录签发的记住我、自动登录换来的新会话)。
     * 路由拿到响应后才能贴上去,所以先记在这儿。
     *
     * @var list<array{name:string, value:string, maxAge:int}>
     */
    private static array $cookies = [];

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionStore $sessions,
        private readonly LoginThrottle $throttle,
        private readonly RememberTokens $tokens
    ) {
    }

    /**
     * @return list<array{name:string, value:string, maxAge:int}>
     */
    public static function pendingCookies(): array
    {
        return self::$cookies;
    }

    /**
     * 请求开始时把 cookie 里的会话挂上来。找不到、过期、版本对不上都等于没登录。
     *
     * 会话没了但带着有效的「记住我」令牌 —— 用令牌换一条新会话回来,令牌本身用一次换一枚。
     */
    public function attach(?string $sessionId, ?string $rememberCookie = null): void
    {
        self::$current = null;
        self::$session = null;
        self::$cookies = [];

        if ($sessionId !== null && $sessionId !== '') {
            $session = $this->sessions->read($sessionId);

            if ($session !== null) {
                $this->bind($session);

                return;
            }
        }

        $this->autoLogin($rememberCookie, $sessionId);
    }

    private function autoLogin(?string $rememberCookie, ?string $sessionId): void
    {
        if ($rememberCookie === null || $rememberCookie === '') {
            return;
        }

        $valid = $this->tokens->verify($rememberCookie);

        if ($valid === null) {
            return;
        }

        $user = $this->users->find($valid['user_id']);

        if ($user === null || !$user->isUsable()) {
            $this->tokens->revokeAll($valid['user_id']);

            return;
        }

        $session = $this->sessions->create($user->id, '', '', [
            'session_version' => $user->sessionVersion,
            'csrf_token' => bin2hex(random_bytes(32)),
        ]);

        self::$current = $user;
        self::$session = $session;
        self::$cookies[] = ['name' => self::COOKIE, 'value' => $session->id, 'maxAge' => $this->sessions->idleTtl()];
        self::$cookies[] = ['name' => RememberTokens::COOKIE, 'value' => $valid['cookie'], 'maxAge' => RememberTokens::TTL];

        Audit::record('user.login', 'user:' . $user->id, true, null, 'user:' . $user->id);

        if ($sessionId !== null && $sessionId !== '') {
            // 旧会话已经读不出来了,但别把它留在表里等过期
            $this->sessions->destroy($sessionId);
        }
    }

    /**
     * 把一条读得出的会话认成"当前是谁":访客会话只挂令牌不算身份,版本对不上当场销毁。
     */
    private function bind(Session $session): void
    {
        // user_id=0 是访客会话:只用来挂防跨站令牌(既有规范登录页也要有令牌),
        // 不代表身份。查不到用户不等于会话无效。
        if ($session->userId === 0) {
            self::$session = $session;

            return;
        }

        $user = $this->users->find($session->userId);

        if ($user === null || !$user->isUsable()) {
            $this->sessions->destroy($session->id);

            return;
        }

        // 改过密码的用户,旧会话一律作废
        if ((int) ($session->data['session_version'] ?? 0) !== $user->sessionVersion) {
            $this->sessions->destroy($session->id);

            return;
        }

        // 滑动续期要落库,不然有效期到了还是会被踢
        $this->sessions->save($session);

        self::$session = $session;
        self::$current = $user;
    }

    /**
     * 只校验账号口令,不发会话也不发 cookie —— App 登录拿它换令牌,
     * 后台登录在其上再补会话。限速、防枚举、失败钩子与成功后的重哈希/touchLogin 都在这里,
     * 两种入口的安全行为因此一致。
     *
     * @return User 登录成功后的用户;失败抛 UnauthorizedException,被限速抛 RateLimitException
     */
    public function verifyCredentials(string $account, string $password, string $ip = ''): User
    {
        $account = trim($account);

        // before 是 filter —— 可归一化账号,也可抛异常直接中止(强制验证码)
        $attempt = apply_filters('user/login/before', ['account' => $account], $ip);

        if (is_array($attempt) && isset($attempt['account']) && (string) $attempt['account'] !== '') {
            $account = trim((string) $attempt['account']);
        }

        $key = $this->throttle->key($ip, $account);
        $lockedUntil = $this->throttle->lockedUntil($key);

        if ($lockedUntil !== null) {
            Audit::record('user.login', 'account:' . $account, false, null, 'anonymous');

            throw new RateLimitException('失败次数过多,请稍后再试', max(1, $lockedUntil - time()));
        }

        $user = $this->users->findByUsername($account) ?? $this->users->findByEmail($account);
        $hash = $user === null ? '' : $this->users->hashOf($user->id);

        // 用户不存在时也要跑一次哈希比对,别让"账号存在与否"从响应时间上泄露出来
        $ok = Password::verify($password, $hash === '' ? Password::hash('molin-timing-equalizer') : $hash);

        if ($user === null || !$ok) {
            $this->throttle->noteFailure($key);
            Audit::record('user.login', 'account:' . $account, false, null, 'anonymous');
            do_action('user/login/failed', $account, $ip, $this->throttle->remaining($key));

            throw new UnauthorizedException('账号或密码不正确');
        }

        if (!$user->isUsable()) {
            Audit::record('user.login', 'user:' . $user->id, false, null, 'anonymous');

            throw new UnauthorizedException($user->status === User::PENDING ? '该账号尚未通过验证' : '该账号已被禁用');
        }

        $this->throttle->clear($key);

        // 参数升级过就顺手重哈希
        if (Password::needsRehash($hash)) {
            $this->users->storeHash($user->id, Password::hash($password));
        }

        $this->users->touchLogin($user->id, $ip);

        return $user;
    }

    /**
     * @return User 登录成功后的用户;失败抛 UnauthorizedException,被限速抛 RateLimitException
     */
    public function login(string $account, string $password, string $ip = '', string $ua = '', bool $remember = false): User
    {
        $user = $this->verifyCredentials($account, $password, $ip);

        $session = $this->sessions->create($user->id, $ip, $ua, [
            'session_version' => $user->sessionVersion,
            'csrf_token' => bin2hex(random_bytes(32)),
        ]);

        self::$current = $user;
        self::$session = $session;

        // 「记住我（30 天）」签的是独立令牌:库里只存哈希,cookie 用一次换一枚
        if ($remember) {
            self::$cookies[] = [
                'name' => RememberTokens::COOKIE,
                'value' => $this->tokens->issue($user->id),
                'maxAge' => RememberTokens::TTL,
            ];
        }

        Audit::record('user.login', 'user:' . $user->id, true, null, 'user:' . $user->id);
        do_action('user/login/after', $user, $ip);

        return $user;
    }

    /**
     * 登出:服务端销毁。带着「记住我」cookie 来的,顺手撤掉这一台设备的令牌。
     */
    public function logout(?string $rememberCookie = null): void
    {
        $this->tokens->revoke($rememberCookie);

        if (self::$session !== null) {
            $who = self::$current;
            $userId = $who === null ? 0 : $who->id;
            $this->sessions->destroy(self::$session->id);
            Audit::record('user.logout', 'user:' . $userId, true, null, 'user:' . $userId);
            self::$session = null;
            self::$current = null;

            if ($who !== null) {
                do_action('user/logout/after', $who);
            }
        }
    }

    /**
     * 改密之后要重新登录:当前这条会话当场作废(版本号已由仓储 +1,长期令牌也由仓储一并撤)。
     */
    public function afterPasswordChange(int $userId): void
    {
        if (self::$session !== null) {
            $this->sessions->destroy(self::$session->id);
            self::$session = null;
            self::$current = null;
        }

        Audit::record('user.password.change', 'user:' . $userId, true, null, 'user:' . $userId);
    }

    public static function user(): ?User
    {
        return self::$current;
    }

    public static function sessionId(): ?string
    {
        return self::$session?->id;
    }

    public static function session(): ?Session
    {
        return self::$session;
    }

    public static function id(): int
    {
        return self::$current === null ? 0 : self::$current->id;
    }

    /**
     * 给当前会话取(或首次生成)CSRF 令牌。
     */
    public static function csrfToken(): string
    {
        $session = self::$session;

        if ($session === null) {
            return '';
        }

        return Csrf::token($session);
    }

    /**
     * 测试与进程内复位用。
     */
    public static function reset(): void
    {
        self::$current = null;
        self::$session = null;
        self::$cookies = [];
    }
}
