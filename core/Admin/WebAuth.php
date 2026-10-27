<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Exception\RateLimitException;
use Molin\Exception\UnauthorizedException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Security\Csrf;
use Molin\Security\LoginThrottle;
use Molin\User\Auth;
use Molin\User\PasswordReset;
use Molin\User\Registrar;
use Molin\User\RememberTokens;
use Molin\User\Session;
use Molin\User\SessionStore;
use Molin\User\User;

/**
 * 登录 / 注册 / 登出三个屏(《32》第 5–8 节、《61》第 2、4 节)。
 *
 * 访客也要有会话才带得动防跨站令牌:进页面先发一条 user_id=0 的匿名会话;
 * 登录成功后**换成全新 id**、旧的当场删掉(《61》第 2.3 节防会话固定)。
 */
final class WebAuth
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Registrar $registrar,
        private readonly SessionStore $sessions,
        private readonly PasswordReset $reset
    ) {
    }

    public function login(Request $request): Response
    {
        // 已经登录就不再给登录屏:否则重复提交会把自己那条在用会话当成访客会话销毁
        if (Auth::user() !== null) {
            return Response::redirect('/admin');
        }

        [$session, $cookie] = $this->guest($request);

        if (!$request->isPost()) {
            // 会话是怎么没的,后台那边只带一个 ?expired=1 过来(《121》§6.3 的载体未冻结,§11 第 6 条)
            $expired = $request->input('expired') === '1';

            // 下载中心这类入口未登录时把回跳地址带在登录链接上,而这一屏以前只认表单字段 POST 上来,
            // GET 带来的地址压根到不了表单 —— 登录完一律掉回后台首页。校验过的值回填进表单才接得上。
            $back = $this->returnPath($this->backPath($request));

            return $this->with(
                $this->loginPage(['notice' => $expired ? __(self::EXPIRED) : '', 'redirect_to' => $back ?? '']),
                $cookie,
                $request
            );
        }

        $identity = $request->input('identity');
        // 前台登录弹窗会带这一个隐藏字段：登录成功/失败都回原页面（原版 WP 的 redirect_to 行为）；
        // 后台登录不带，行为保持原样（成功去 /admin，失败渲染登录屏）
        $back = $this->returnPath($this->backPath($request));

        if (!Csrf::check($session, $request->input(Csrf::FIELD))) {
            if ($back !== null) {
                return $this->with(Response::redirect($this->withFlag($back, 'login=stale'), 303), $cookie, $request);
            }

            return $this->with($this->loginPage(['notice' => __(self::STALE), 'identity' => $identity]), $cookie, $request, 403);
        }

        try {
            $this->auth->login(
                $identity,
                $request->input('password'),
                $request->ip(),
                $request->userAgent(),
                $request->input('remember') === '1'
            );
        } catch (UnauthorizedException $e) {
            if ($back !== null) {
                return $this->with(Response::redirect($this->withFlag($back, 'login=failed'), 303), $cookie, $request);
            }

            return $this->with($this->loginPage(['notice' => __($e->getMessage()), 'identity' => $identity]), $cookie, $request, 401);
        } catch (RateLimitException $e) {
            if ($back !== null) {
                return $this->with(Response::redirect($this->withFlag($back, 'login=locked'), 303), $cookie, $request);
            }

            // 锁定优先:这一态不显示警示条,整张表单禁用,只留 .note 那两行(《121》§6.2)
            return $this->with($this->loginPage(['locked' => true, 'identity' => $identity]), $cookie, $request, 429);
        }

        $this->sessions->destroy($session->id);

        // 勾了「记住我（30 天）」,签发的长期令牌由 Auth 排队、入口统一补发(《61》2.7);
        // 会话 cookie 本身不延长 —— 它是会话,不是凭据,过期了靠令牌换回来就是了
        return $this->with(Response::redirect($back ?? '/admin'), (string) Auth::sessionId(), $request);
    }

    /**
     * 回跳地址的规范名是 redirect_to;线上在用的主题与插件还传着老参数名 redirect,一并认。
     * 两个名字都只是"候选值",安不安全一律由 returnPath() 裁决。
     */
    private function backPath(Request $request): string
    {
        $path = $request->input('redirect_to', '');

        return $path !== '' ? $path : $request->input('redirect', '');
    }

    /**
     * 只接受站内绝对路径：拒绝 //evil.com、http(s)://、带换行的值，避免登录后被带去外站。
     * 反斜杠一并拒:`/\evil.com` 被浏览器按 `//evil.com` 归一化,是同一类外跳。
     */
    private function returnPath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')
            || str_contains($path, '\\') || str_contains($path, '://')
            || str_contains($path, "\n") || str_contains($path, "\r")) {
            return null;
        }

        return $path;
    }

    /** 拼查询参数：原路径已有查询串就用 &，否则用 ? */
    private function withFlag(string $path, string $flag): string
    {
        return $path . (str_contains($path, '?') ? '&' : '?') . $flag;
    }

    public function register(Request $request): Response
    {
        // 关闭注册不是错误:照基准出一张"本站未开放注册"的卡,表单整块不渲染(《122》§6.2)
        if (!$this->registrar->isOpen()) {
            return $this->view->render('register.twig', ['open' => false]);
        }

        [$session, $cookie] = $this->guest($request);

        if (!$request->isPost()) {
            return $this->with($this->registerPage([]), $cookie, $request);
        }

        $fields = [
            'username' => $request->input('username'),
            'email'    => $request->input('email'),
            'nickname' => $request->input('nickname'),
        ];

        if (!Csrf::check($session, $request->input(Csrf::FIELD))) {
            return $this->with($this->registerPage($fields + ['notice' => __(self::STALE)]), $cookie, $request, 403);
        }

        try {
            $this->registrar->register($fields + ['password' => $request->input('password')], $request->ip());
        } catch (ValidationException $e) {
            return $this->with($this->registerPage($fields + ['notice' => $this->explain($e)]), $cookie, $request, 422);
        }

        return $this->with(Response::redirect('/login'), $cookie, $request);
    }

    /**
     * 找回密码(《123》§6.1 / §6.2):提交邮箱 → 生成一次性令牌 → 一律回同一句"邮件已发出"。
     *
     * 内核不发信(作者 2026-10-01 定:发信交插件),这一屏只把令牌与链接备好;
     * 签发后发 `user/lostpassword/after`(user, link)交棒(见 core/User/PasswordReset.php),
     * 随包插件 plugins/mail-smtp 正监听它真发信 —— 已闭环
     * (2026-10-12 更正旧注释"寄出去那一步还没人做":发信那步已由插件接上)。
     */
    public function lostpassword(Request $request): Response
    {
        [$session, $cookie] = $this->guest($request);

        if (!$request->isPost()) {
            return $this->with($this->recoverPage([]), $cookie, $request);
        }

        $email = $request->input('email');

        if (!Csrf::check($session, $request->input(Csrf::FIELD))) {
            return $this->with($this->recoverPage(['email' => $email, 'notice' => __(self::STALE)]), $cookie, $request, 403);
        }

        // 账号在不在、发没发出去,反馈都得一样 —— 《123》§7 那句"不透露注册情况"是安全口径,不是客套
        //
        // 但"发没发出去"得说实话(2026-10-17 作者定):内核不发信,发信靠插件接 `user/lostpassword/after`。
        // 没人接棒还回"邮件已发出"就是把站长骗在门外 —— 没接棒时干脆不签发令牌(签发也没人送),
        // 两种账号仍给同一句,不透露注册情况的口径不变。
        $mailable = has_action('user/lostpassword/after');

        if ($mailable) {
            $this->reset->issue($email);
        }

        return $this->with($this->recoverPage(['email' => $email, 'sent' => $mailable]), $cookie, $request);
    }

    /**
     * 重置密码(《123》§6.3 / §6.4):渲染哪一态完全由令牌校验结果决定。
     */
    public function resetpassword(Request $request): Response
    {
        [$session, $cookie] = $this->guest($request);
        $token = $request->input('token');
        $user = $this->reset->resolve($token);

        if ($user === null) {
            return $this->view->render('resetpassword.twig', [
                'valid' => false,
                'ttl_hours' => intdiv(PasswordReset::TTL, 3600),
            ]);
        }

        if (!$request->isPost()) {
            return $this->with($this->resetPage($user, $token, ''), $cookie, $request);
        }

        if (!Csrf::check($session, $request->input(Csrf::FIELD))) {
            return $this->with($this->resetPage($user, $token, __(self::STALE)), $cookie, $request, 403);
        }

        $password = $request->input('password');

        if ($password !== $request->input('password2')) {
            return $this->with($this->resetPage($user, $token, __('两次输入的密码不一致。')), $cookie, $request, 422);
        }

        try {
            $this->reset->complete($user, $password);
        } catch (ValidationException $e) {
            return $this->with($this->resetPage($user, $token, $this->explain($e)), $cookie, $request, 422);
        }

        Audit::record('user.password.reset', 'user:' . $user->id, true, $request, 'user:' . $user->id);

        // 改完密码这台设备也不算登录着(session_version 已 +1),让人拿新密码重新进(《32》§8)
        return $this->with(Response::redirect('/login'), $cookie, $request);
    }

    public function logout(Request $request): Response
    {
        $session = Auth::session();

        // 前台浮层登出后回站内原页(与 login 同一把尺子);后台登出不带这个字段,照旧回 /login
        $back = $this->returnPath($request->input('redirect_to', ''));

        // 登出必须是 POST + 有效令牌,否则一张外链图片就能把人的登录状态打掉
        if ($request->isPost() && $session !== null && Csrf::check($session, $request->input(Csrf::FIELD))) {
            $this->auth->logout($request->cookie(RememberTokens::COOKIE));

            // 两枚一起抹:只抹会话不抹令牌,下次进来又被自动登录,登出等于没登;
            // 只撤令牌不抹 cookie,浏览器还揣着一条已作废的凭据
            return Response::redirect($back ?? '/login')
                ->withCookie(Auth::COOKIE, '', 0, $request->isSecure())
                ->withCookie(RememberTokens::COOKIE, '', 0, $request->isSecure());
        }

        // 守卫没过 = 这一趟不算登出,浏览器上的凭据一枚都不许动。
        // 前台留在他原来那一页(别把人踢去登录屏),后台仍回 /login
        return Response::redirect($back ?? '/login');
    }

    /** 令牌对不上 = 页面是旧的,不是有人在攻击;提示要往"重新提交"引,别吓到正常人 */
    public const STALE = '页面放得太久了,请重新提交。';

    /** 会话失效那句照《121》§7 逐字(全角标点),不改写成开发方的口味 */
    public const EXPIRED = '登录状态已失效，请重新登录。';

    /**
     * @return array{0: Session, 1: string}
     */
    private function guest(Request $request): array
    {
        $session = Auth::session();

        if ($session !== null) {
            return [$session, $session->id];
        }

        $session = $this->sessions->create(0, $request->ip(), $request->userAgent(), [
            'csrf_token' => bin2hex(random_bytes(32)),
        ]);

        $this->auth->attach($session->id);

        return [$session, $session->id];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function loginPage(array $state = []): Response
    {
        return $this->view->render('login.twig', $state + [
            'notice' => '',
            'identity' => '',
            'locked' => false,
            // 回跳地址的载体:模板里那个隐藏字段,空串 = 不带(登录后照旧回 /admin)
            'redirect_to' => '',
            // 三个数字都跟着配置走,不写死(《121》§10);天数现在取自令牌寿命,不再是会话上限
            'remember_days' => intdiv(RememberTokens::TTL, 86400),
            'lock_minutes' => (int) round(LoginThrottle::LOCK_SECONDS / 60),
            'max_attempts' => LoginThrottle::MAX_ATTEMPTS,
            // 开放注册时登录页才给「注册新账号」这一枚入口(《121》§13 改定)
            'can_register' => $this->registrar->isOpen(),
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function registerPage(array $state = []): Response
    {
        return $this->view->render('register.twig', $state + [
            'open' => true,
            'notice' => '',
            'username' => '',
            'email' => '',
            'nickname' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recoverPage(array $state = []): Response
    {
        return $this->view->render('lostpassword.twig', $state + [
            'email' => '',
            'sent' => false,
            // 没人接 `user/lostpassword/after` 就是"这台站发不出邮件",页面得先说实话
            'no_mailer' => !has_action('user/lostpassword/after'),
            'notice' => '',
            'ttl_hours' => intdiv(PasswordReset::TTL, 3600),
        ]);
    }

    private function resetPage(User $user, string $token, string $notice): Response
    {
        return $this->view->render('resetpassword.twig', [
            'valid' => true,
            'account' => $user->display_name === '' ? $user->username : $user->display_name,
            'token' => $token,
            'notice' => $notice,
            'ttl_hours' => intdiv(PasswordReset::TTL, 3600),
        ]);
    }

    private function with(Response $response, string $cookie, Request $request, ?int $status = null): Response
    {
        if ($status !== null) {
            $response = $response->withStatus($status);
        }

        return $response->withCookie(Auth::COOKIE, $cookie, SessionStore::IDLE_TTL, $request->isSecure());
    }

    private function explain(ValidationException $e): string
    {
        $parts = [];

        foreach ($e->errors() as $messages) {
            foreach ($messages as $message) {
                // 字段文案已自足(含顶层文案)就直接用它,不放「这个邮箱已注册过:…」这样的重复
                if (str_contains($message, $e->getMessage())) {
                    return __($message);
                }
            }

            $parts[] = implode(';', array_map(static fn (string $message): string => __($message), $messages));
        }

        if ($parts === []) {
            return __($e->getMessage());
        }

        return __($e->getMessage()) . ':' . implode(' / ', $parts);
    }
}
