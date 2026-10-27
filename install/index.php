<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

/*
 * 安装向导入口。
 *
 * 全站只有一个入口 `index.php`(约定),这一处例外由既有规范明文要求:
 * 装之前没有 config.php、没有数据表、没有主题,内核起不来,只能给安装留一扇单独的门,
 * 并且**装完必须删掉这扇门** —— 写了 install/lock 之后,这里只输出"拒绝"。
 *
 * 装什么、怎么装一律交给 core\Install\Installer(与 `molin install` 同一个类),本文件只做三件事:
 * 认步序、发/认状态 cookie、发 HTML。它是内核之外的一层,所以在这里读超全局变量是合适的
 * —— 与 index.php 用 Request::fromGlobals() 收口是同一个角色(环境检测要看 SERVER_SOFTWARE,
 * 完成页要回显站点地址,这两样 Request 故意不带)。
 */

use Molin\Http\Response;
use Molin\Http\SecurityHeaders;
use Molin\Install\Installer;
use Molin\Install\Wizard;
use Molin\Install\WizardState;

$root = dirname(__DIR__);

// 与 index.php 同一组全局常量:内核里 HookMap / Logger 都读它们(装到"生成钩子索引"
// 或写审计那一步就会 Undefined constant 失败,真跑踩过两次)。
// 安装页是公开页面,APP_DEBUG 恒为 false —— 不把堆栈吐给浏览器。
define('MOLIN_ROOT', $root);
define('MOLIN_CONTRACT', '1.0');
define('APP_DEBUG', false);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo '缺 vendor/autoload.php:请先在程序根目录执行 composer install,再打开安装页。';

    return;
}

require $root . '/vendor/autoload.php';

$https = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
$installer = new Installer($root, $_SERVER);

// 装完(或被装过一半留下 lock)再来访问:只给拒绝页,不给任何"再装一次"的按钮
if ($installer->isLocked()) {
    WizardState::collectGarbage($root);

    Response::html(implode('', [
        '<!doctype html><html lang="zh-Hans"><head><meta charset="utf-8">',
        '<meta name="robots" content="noindex,nofollow"><title>安装已完成</title></head><body>',
        '<h1>安装已完成</h1>',
        '<p>这个站已经装好,安装页不再开放。请删除服务器上的 <code>install/</code> 目录。</p>',
        '<p>确要重装:先备份数据库,再删掉 <code>install/lock</code>(或整个 install 目录),然后重新打开本页。</p>',
        '</body></html>',
    ]), 403)->withHeaders(SecurityHeaders::apply([], $https))->send();

    return;
}

/**
 * 状态用一枚随机 id 的 HttpOnly cookie 认。内核不许用 PHP 原生 session,
 * 所以分步状态落在 storage 下的临时文件里:不对外、0600、两小时过期;装成功即删,
 * 写到一半失败时留两小时,给失败屏的「重新写入」直接重跑。
 */
$cookie = 'molin_install';
$given = isset($_COOKIE[$cookie]) ? (string) $_COOKIE[$cookie] : null;
$state = WizardState::load($root, $given);
$fresh = $state === null;

if ($fresh) {
    WizardState::collectGarbage($root);
    $state = WizardState::create($root);
}

try {
    $body = (new Wizard($installer, $root, $_SERVER))
        ->handle(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), (int) ($_GET['step'] ?? 1), $_POST, $state);
} catch (Throwable $e) {
    // 只给能照着做的一句话,堆栈进日志
    error_log('墨林安装向导:' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $body = '<!doctype html><html lang="zh-Hans"><head><meta charset="utf-8"><title>装不下去</title></head><body>'
        . '<h1>装不下去</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></body></html>';
}

$response = Response::html($body)->withHeaders(SecurityHeaders::apply([], $https));

if ($fresh) {
    $response = $response->withCookie($cookie, $state->id(), WizardState::TTL, $https);
}

$response->send();
