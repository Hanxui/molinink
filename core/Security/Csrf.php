<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Security;

use Closure;
use Molin\User\Session;

/**
 * CSRF 令牌(既有规范–4.3 节)。
 *
 * 令牌绑在会话里(不是 cookie 独立存),所以未登录时也有 —— 注册/登录表单同样要防。
 * 轮换时机定在"登录成功、登出、改密"三处;不每次请求都换,否则用户开两个表单
 * 或点后退就会自己把自己挡掉,那种"偶尔抽风"的 bug 最难查。
 */
final class Csrf
{
    /** 表单字段名。既有规范没给名字,这里定一个并当作对外口径来用 */
    public const FIELD = 'molin_csrf';

    /** 接口/ AJAX 用的头 */
    public const HEADER = 'X-Molin-CSRF';

    /**
     * 落库回调(通常是 SessionStore::save)。会话是按请求从库里读出来的对象,
     * 只在内存里换令牌不写回,下一次请求就查不到 —— 表单发的令牌永远对不上,
     * 表现为"不管怎么刷新都提示会话失效"。启动时由 Bootstrap 注入。
     *
     * @var (Closure(Session): mixed)|null
     */
    private static ?Closure $persister = null;

    /**
     * @param (callable(Session): mixed)|null $persister
     */
    public static function boot(?callable $persister): void
    {
        self::$persister = $persister === null ? null : Closure::fromCallable($persister);
    }

    public static function token(Session $session): string
    {
        $existing = $session->csrfToken();

        if ($existing !== null) {
            return $existing;
        }

        return self::rotate($session);
    }

    public static function rotate(Session $session): string
    {
        $token = bin2hex(random_bytes(32));
        $session->data['csrf_token'] = $token;

        if (self::$persister !== null) {
            (self::$persister)($session);
        }

        return $token;
    }

    public static function check(Session $session, ?string $given): bool
    {
        $expected = $session->csrfToken();

        if ($expected === null || $given === null || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }
}
