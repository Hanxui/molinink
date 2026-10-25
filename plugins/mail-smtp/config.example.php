<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 *
 * SMTP 发信配置模板(插件 mail-smtp)
 *
 * **推荐:不用手改这个文件。** 启用插件后,后台「插件」列表里它有「管理」入口,
 * 点进去填服务器 / 账号 / 发件人,存一次即可(还能点一下「发一封试试」看通不通)。
 *
 * 这个模板留给两种人:① 想在服务器上直接改文件的人 ② 想看清每个字段含义的人。
 * 用法:把本文件复制到 storage/.secrets/mail.php,逐项填上邮箱服务商给的值。
 * storage/.secrets/ 不入仓库、不进安装包、不进备份包 —— 凭据放这儿不会漏。
 * 填错或没填 = 插件不发信(也不挂钩子),站点照常,找回密码页会如实说"还没启用邮件发送"。
 */

declare(strict_types=1);

return [
    // 发信服务器。常见值:QQ 邮箱 smtp.qq.com / 163 邮箱 smtp.163.com / 企业邮箱按服务商文档填
    'host'         => 'smtp.example.com',

    // 端口:ssl(隐式 TLS)一般 465,starttls 一般 587;不填会按加密方式给默认值
    'port'         => 465,

    // 加密方式:'ssl'(隐式 TLS)| 'starttls' | 'none'(内网中继才用)
    'encryption'   => 'ssl',

    // 登录账号:邮箱服务商的用户名,通常就是邮箱地址
    'username'     => 'you@example.com',

    // 密码:多数邮箱要的是"授权码 / 应用专用密码",不是网页登录密码(QQ、163 都是)
    'password'     => '',

    // 发件地址:收件人看到的发件人,一般与 username 相同
    'from_address' => 'you@example.com',

    // 发件人显示名:通常是站点名,收件人一眼知道是谁寄的
    'from_name'    => '我的墨林站点',
];
