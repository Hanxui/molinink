<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Database\Database;
use Molin\Http\Request;
use Molin\Option\Options;
use Molin\User\User;

/**
 * 插件后台页的一次调用上下文。
 *
 * 内核构造、插件只读。以后要往里加东西就往这里加属性 —— 控制器接口的签名不动,
 * 已经发布的控制器不用跟着改。
 */
final class AdminPageContext
{
    public function __construct(
        public readonly Request $request,
        public readonly User $user,
        /** 站点配置(只读用途:站点地址、语言这类)。改站点设置不是插件的事 —— 插件自己的配置走 $settings */
        public readonly Options $options,
        /** 内核那一份数据库连接。谈数据库就在清单里声明 `db.direct` —— 别再自己读 config.php 连一个 */
        public readonly Database $db,
        /** 本插件自己的配置读写(键前缀 `plugin.{插件名}.`) */
        public readonly Settings $settings,
        public readonly string $plugin,
        public readonly string $route,
        /** 本页地址;表单 action 与保存后的重定向都用它,别自己拼 */
        public readonly string $url
    ) {
    }

    /** 这一趟是写动作(POST)还是看页面(GET)。 */
    public function isPost(): bool
    {
        return $this->request->isPost();
    }
}
