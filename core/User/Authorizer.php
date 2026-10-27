<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

/**
 * 授权判定(既有规范默认拒绝)。
 *
 * 三条规则按顺序生效:
 * 1) 没登录、被封、待验证 —— 一律拒绝;
 * 2) 能力名没注册过 —— 拒绝(而不是"未知就放过");
 * 3) 带 `.own` 的能力且给了对象 —— 还要验"这件东西是不是他的"。
 */
final class Authorizer
{
    public function can(?User $user, string $capability, ?object $target = null): bool
    {
        if ($user === null || !$user->isUsable() || !Capabilities::isKnown($capability)) {
            return false;
        }

        $allow = $user->holds($capability);

        // own 类能力在带对象时另判归属,角色里有也不代表能动别人的
        if ($allow && str_ends_with($capability, '.own') && $target !== null) {
            $allow = $this->isOwner($user, $target);
        }

        // 插件收紧/放宽的入口(既有规范示例的形状)
        $allow = apply_filters('user/capability/validate', $allow, $capability, $user, $target);

        return $allow === true;
    }

    /**
     * 一次判多个能力,后台每页"要哪些权限"用它自检。
     *
     * @param list<string> $capabilities
     */
    public function canAll(?User $user, array $capabilities, ?object $target = null): bool
    {
        foreach ($capabilities as $capability) {
            if (!$this->can($user, $capability, $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 任一能力通过即放行。既有规范的 `post.*` / `media.*` 就是这个意思:
     * 能新建文章的人就该看到"文章"菜单,不必同时握有全部六个 post.* 。
     * 表里没写全的名字一律拒绝(默认拒绝,不因拼错而放过)。
     *
     * @param list<string> $capabilities
     */
    public function canAny(?User $user, array $capabilities, ?object $target = null): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($user, $capability, $target)) {
                return true;
            }
        }

        return false;
    }

    private function isOwner(User $user, object $target): bool
    {
        if (property_exists($target, 'author_id')) {
            return (int) $target->author_id === $user->id;
        }

        if (property_exists($target, 'user_id')) {
            return (int) $target->user_id === $user->id;
        }

        return false;
    }
}
