<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Comment;

use Molin\Http\Response;

/**
 * 访客评论身份的记忆 cookie(2026-10-31 增)。
 *
 * 原来访客身份只活在前端 localStorage:服务端渲染评论框时根本不认识这个人,
 * 于是「评论框左侧显示自己的头像」「后台能看到来访者是谁」这类都得靠前端补。
 * 这里补上服务端那一半 —— 发出去的是一条 HttpOnly 记忆 cookie,模板拿到 `commenter` 全局
 * 就能直接把人画出来,不必等 JS。localStorage 仍然保留(它负责即时回填表单,无往返延迟),
 * 两边存同一份 `{author, mail, url}`,以 cookie 为准。
 *
 * 定位是「记住你上次评论用的昵称/邮箱」,不是身份认证:里面没有令牌、不参与任何授权判断。
 * 评论能不能发、发出来算谁的,一律仍由会话与 Submitter 决定。
 */
final class CommenterCookie
{
    /** cookie 名。改这个等于让所有访客的记名失效一次,非必要不动 */
    public const COOKIE = 'molin_commenter';

    /** 记住 30 天。与主题前端 localStorage 的 TTL 对齐,两边同时过期 */
    public const TTL = 2592000;

    /** 三个字段的长度上限,与 comments 表列宽一致,超了截断而不是整条丢弃 */
    private const LIMITS = ['author' => 120, 'mail' => 191, 'url' => 255];

    /**
     * 把 cookie 原始值解析成三个字段。解析不出来就给全空串,不抛异常 ——
     * 一条被改坏的 cookie 不该让页面 500。
     *
     * @return array{author: string, mail: string, url: string}
     */
    public static function parse(?string $raw): array
    {
        $empty = ['author' => '', 'mail' => '', 'url' => ''];

        if ($raw === null || $raw === '') {
            return $empty;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return $empty;
        }

        $out = $empty;

        foreach (self::LIMITS as $key => $limit) {
            $value = $data[$key] ?? '';

            if (is_string($value)) {
                $out[$key] = self::clamp(trim($value), $limit);
            }
        }

        return $out;
    }

    /**
     * 当前请求的访客身份。渲染层用它注入 `commenter` 全局。
     *
     * 直接读 `$_COOKIE`:渲染器手里没有 Request(它按页面上下文构造),而内核在
     * index.php 里判会话 cookie 也是这个读法,口径一致。
     *
     * @return array{author: string, mail: string, url: string}
     */
    public static function current(): array
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;

        return self::parse(is_string($raw) ? $raw : null);
    }

    /**
     * 把访客身份挂到响应上。只存非空字段,全空就当作「顺带清掉」。
     *
     * @param array{author?: string, mail?: string, url?: string} $identity
     */
    public static function attach(Response $response, array $identity, bool $https): Response
    {
        $clean = self::identity($identity);

        if ($clean['author'] === '') {
            return self::clear($response, $https);
        }

        return $response->withCookie(
            self::COOKIE,
            (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::TTL,
            $https
        );
    }

    /**
     * 清掉记忆(Max-Age=0)。主题的「清除你的个人信息」按钮打到这个口。
     */
    public static function clear(Response $response, bool $https): Response
    {
        return $response->withCookie(self::COOKIE, '', 0, $https);
    }

    /**
     * 归一化:去空白、按列宽截断、扔掉空字段。
     *
     * @param array{author?: string, mail?: string, url?: string} $identity
     * @return array{author: string, mail: string, url: string}
     */
    public static function identity(array $identity): array
    {
        $out = ['author' => '', 'mail' => '', 'url' => ''];

        foreach (self::LIMITS as $key => $limit) {
            $value = $identity[$key] ?? '';

            if (is_string($value)) {
                $out[$key] = self::clamp(trim($value), $limit);
            }
        }

        // 邮箱明显不是邮箱就别记了,省得主题拿去拼头像拼出一条死链
        if ($out['mail'] !== '' && filter_var($out['mail'], FILTER_VALIDATE_EMAIL) === false) {
            $out['mail'] = '';
        }

        // 只留 http(s) 与站内相对地址,`javascript:` 这类一律丢掉
        if ($out['url'] !== '' && preg_match('#\A(https?://|/)#i', $out['url']) !== 1) {
            $out['url'] = '';
        }

        return $out;
    }

    private static function clamp(string $value, int $limit): string
    {
        if ($value === '') {
            return '';
        }

        // mb_substr 按字符截,避免把汉字截成半个
        return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
    }
}
