<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Http\Response;

/**
 * 插件后台页的控制器(《12》§14)。
 *
 * 只在 `plugin.json` 的 `admin.menu[].children[].controller` 声明了实现本接口的类时,
 * 内核才会在渲染这一页时调它 —— 类必须落在插件自己的命名空间下
 * (`Molin\Plugins\{Studly}\...`),且能**无参构造**(内核不给它注依赖)。
 *
 * 三道闸由内核先过:登录会话、页面能力、POST 的 CSRF 令牌。控制器只管业务,
 * 不必也不该自己再查一遍 —— 尤其别绕开 CSRF 另开一个免令牌的口子。
 */
interface AdminPageController
{
    /**
     * @return array<string, mixed>|Response
     *   数组:并进模板上下文后渲染本页(键名随你取,`page_title` 可覆盖页头标题);
     *   Response:原样返回 —— 保存完重定向回本页(PRG)、回 JSON、发文件都走这条。
     */
    public function handle(AdminPageContext $context): array|Response;
}
