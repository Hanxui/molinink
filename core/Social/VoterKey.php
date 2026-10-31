<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Social;

use Molin\User\Auth;

/**
 * 投票标识(2026-10-31 增)。
 *
 * `post_ratings` 用 `voter_key` 区分「谁投的票」:登录用户是 `u{用户编号}`(换设备也认得出),
 * 游客是 `g{浏览器里那串随机 hex}`。这张口径原来只写在 SocialController::rate() 里,
 * 现在列表页也要算「这一条我自己投没投」(mine),两边必须同一个算法 ——
 * 两处各写一遍,迟早在正则或前缀上漂开,表现就是心形永远描边。所以收到这里一处。
 *
 * 它不是身份认证:里面没有令牌,也不参与任何授权判断,只用来把同一张票认回同一个投票者。
 */
final class VoterKey
{
    /** 投票 cookie 名。改它等于让所有游客的票认不回来,非必要不动 */
    public const COOKIE = 'molin_voter';

    /** 游客那串随机串的形状(16–64 位 URL 安全字符)。cookie 被改坏就不认,免得拼出脏 voter_key */
    private const PATTERN = '#\A[A-Za-z0-9_-]{16,64}\z#';

    /**
     * 拼一个投票标识。用户编号优先 —— 登录了就按人认,不看浏览器。
     *
     * @param int $userId 0 = 游客
     * @param string $raw 游客那串随机串(cookie 或请求体里带的),登录用户可传空
     * @return string 认不出来就是空串(调用方据此只显示计数、不带 mine)
     */
    public static function compose(int $userId, string $raw): string
    {
        if ($userId > 0) {
            return 'u' . $userId;
        }

        $raw = trim($raw);

        return self::valid($raw) ? 'g' . $raw : '';
    }

    /** 这串随机串能不能当投票标识用 */
    public static function valid(string $raw): bool
    {
        return preg_match(self::PATTERN, $raw) === 1;
    }

    /**
     * 当前请求的投票标识。渲染层(列表组项)用它算 `mine`。
     *
     * 直接读 `$_COOKIE`:ListSource 手里没有 Request(它按页面上下文构造),
     * 与 CommenterCookie::current() 同一个读法、同一个理由。
     */
    public static function current(): string
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;

        return self::compose(Auth::id(), is_string($raw) ? $raw : '');
    }
}
