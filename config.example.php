<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

/*
 * 配置模板:复制为 config.php 并填真实值。
 *
 * config.php 含数据库凭据,已在 .gitignore 中排除,任何情况下不得入库。
 * 字段依据《42-安装向导》第 3 节;安装流程还会往 config.php 写入随机
 * SECRET_KEY 与盐(《42》第 5 节),那部分等安装向导增量落地时补。
 */

return [
    // 《26-错误处理与日志规范》第 3 节:唯一的调试开关
    'debug' => true,

    // 站点时区:库里的时间戳一律按 UTC 存,展示与"今天/昨天"按这里算。改成自己的时区即可
    'timezone' => 'Asia/Shanghai',

    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'molin',
        'user'     => 'molin',
        'password' => '',
        'prefix'   => 'molin_',
        'charset'  => 'utf8mb4',
        // 填了 socket 就走 unix_socket,忽略 host/port
        'socket'   => null,
    ],
];
