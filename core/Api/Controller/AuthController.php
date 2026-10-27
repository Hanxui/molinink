<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api\Controller;

use Molin\Api\ApiSupport;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\AppTokens;
use Molin\User\Auth;
use Molin\User\User;

/**
 * App 令牌端点:账号口令换令牌、refresh 轮换、登出撤销。
 */
final class AuthController extends ApiSupport
{
    public function __construct(
        Options $options,
        Router $router,
        private readonly AppTokens $appTokens,
        private readonly Auth $auth,
        private readonly ProfileController $profileController
    ) {
        parent::__construct($options, $router);
    }

    /**
     * POST /api/v1/auth/login —— 账号口令换一对令牌。
     *
     * 只看凭据,不落会话 cookie:限速、防枚举、失败钩子全在 Auth::verifyCredentials 里,
     * 与后台登录同一套安全行为。口令不记日志、不回显。
     */
    public function authLogin(Request $request): Response
    {
        $body = $request->json();
        $account = trim((string) ($body['username'] ?? $body['account'] ?? $body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($account === '' || $password === '') {
            $details = [];

            if ($account === '') {
                $details['username'] = ['不能为空'];
            }

            if ($password === '') {
                $details['password'] = ['不能为空'];
            }

            return $this->fail('validation_failed', '请填写账号与密码', 422, $details);
        }

        $user = $this->auth->verifyCredentials($account, $password, $request->ip());

        // App 是长期客户端,默认发长效 refresh;显式传 remember=false 才缩短
        $remember = !array_key_exists('remember', $body) || filter_var($body['remember'], FILTER_VALIDATE_BOOL);

        $tokens = $this->appTokens->issue($user->id, $request->userAgent(), $remember);

        Audit::record('user.login', 'user:' . $user->id . ' via:app', true, $request, 'user:' . $user->id);
        do_action('user/login/after', $user, $request->ip());

        return $this->ok(['data' => $tokens + ['user' => $this->profileController->profile($user)]]);
    }

    /**
     * POST /api/v1/auth/refresh —— 用 refresh 换新的一对。
     * 令牌在请求体里,不走 Authorization(过期的 access 不该挡住刷新)。
     */
    public function authRefresh(Request $request): Response
    {
        $token = trim((string) ($request->json()['refresh_token'] ?? ''));

        if ($token === '') {
            return $this->fail('validation_failed', '缺少 refresh_token', 422, ['refresh_token' => ['不能为空']]);
        }

        $fresh = $this->appTokens->rotate($token);

        if ($fresh === null) {
            return $this->fail('unauthorized', '刷新令牌无效或已过期', 401);
        }

        return $this->ok(['data' => $fresh]);
    }

    /**
     * POST /api/v1/auth/logout —— 服务端撤销整条令牌 series(含配对的 refresh)。
     */
    public function authLogout(Request $request, ?User $user, bool $viaSession): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        $token = (string) $request->header('X-Api-Key');

        if ($token === '') {
            $token = $this->bearer($request) ?? '';
        }

        if ($token !== '' && $this->appTokens->revoke($token)) {
            Audit::record('user.logout', 'user:' . $user->id . ' via:app', true, $request, 'user:' . $user->id);

            return $this->ok(['data' => ['logged_out' => true]]);
        }

        // 会话路径的登出(后台前端沿用老的 cookie 流程)
        if ($viaSession) {
            $this->auth->logout();

            return $this->ok(['data' => ['logged_out' => true]]);
        }

        return $this->ok(['data' => ['logged_out' => true]]);
    }
}
