<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Install;

/**
 * 装配前守卫页:缺 vendor/ 与站点未安装两张静态提示页。
 *
 * 它们跑在 autoload / 容器 / Twig 都不可用的阶段,样式只能内联、不引任何外链资源
 * —— 那时连页面上的 <link> 都可能二次失败。与 index.php 解耦是为了让入口保持
 * 装配层(EntryScriptContractTest 钉着 500 行上限),这两段纯文案 HTML 没资格住进去。
 */
final class BootstrapPages
{
    /** 无法继续的守卫页都直接发响应并结束:此时没有任何框架设施可用。 */
    public static function vendorMissing(): never
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
<!doctype html><html lang="zh-Hans"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>缺少 vendor/ · 墨林</title>
<style>
 body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#FAFAF8;color:#1B1C1E;
      font:16px/1.7 "Noto Serif SC","Songti SC",Georgia,serif}
 .card{max-width:640px;padding:40px 36px;background:#fff;border:1px solid #E5E3DE;border-radius:6px}
 h1{margin:0 0 12px;font-size:26px;font-weight:400}
 p{margin:0 0 14px;color:#3A3A38}
 ol{margin:0 0 18px;padding-left:1.2em;color:#3A3A38}
 code{font-family:Consolas,monospace;background:#F3F2EE;padding:1px 5px;border-radius:3px;font-size:14px}
 pre{margin:0 0 14px;padding:12px 14px;background:#F3F2EE;border-radius:4px;overflow-x:auto;
     font-family:Consolas,monospace;font-size:13px;color:#1B1C1E}
 .sub{color:#85837E;font-size:13px;margin-top:18px}
</style></head><body>
<div class="card">
  <h1>缺了 vendor/，差一步就能装</h1>
  <p>当前目录里没有 <code>vendor/autoload.php</code>。程序本身没坏，是从仓库下成了<strong>源码包</strong>——
     依赖由 Composer 管理，仓库不提交它们。</p>
  <p>两条路，选一条：</p>
  <ol>
    <li><strong>换成发布包（推荐）</strong>：到 Releases 下 <code>molin-x.y.z.zip</code>，它自带 <code>vendor/</code>，
        解压到站点根即可，不需要 Composer。</li>
    <li><strong>就在这台服务器上补依赖</strong>：在站点根目录执行
        <pre>composer install --no-dev</pre>
        没有全局 composer 的话，用 <code>php composer.phar install --no-dev</code>（PHP 8.2 上跑）。</li>
  </ol>
  <p class="sub">已经跑过 <code>composer install</code> 还是这一页？确认命令是在<strong>站点根</strong>执行的
     —— <code>vendor/</code> 应该和 <code>index.php</code> 并排；再确认 PHP 不低于 8.2。</p>
</div></body></html>
HTML;

        exit;
    }

    public static function notInstalled(): never
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<'HTML'
<!doctype html><html lang="zh-Hans"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>站点还没装好 · 墨林</title>
<style>
 body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#FAFAF8;color:#1B1C1E;
      font:16px/1.7 "Noto Serif SC","Songti SC",Georgia,serif}
 .card{max-width:600px;padding:40px 36px;background:#fff;border:1px solid #E5E3DE;border-radius:6px}
 h1{margin:0 0 12px;font-size:26px;font-weight:400}
 p{margin:0 0 14px;color:#3A3A38}
 ol{margin:0 0 18px;padding-left:1.2em;color:#3A3A38}
 code{font-family:Consolas,monospace;background:#F3F2EE;padding:1px 5px;border-radius:3px;font-size:14px}
 a.btn{display:inline-block;padding:10px 22px;background:#1B1C1E;color:#FAFAF8;text-decoration:none;border-radius:4px}
 .sub{color:#85837E;font-size:13px;margin-top:18px}
</style></head><body>
<div class="card">
  <h1>站点还没装好</h1>
  <p>这台站还没有配置文件，先跑一遍安装向导。三步：</p>
  <ol>
    <li>打开 <a href="/install/">/install/</a>，按提示填数据库与管理员账号；</li>
    <li>装完后删掉服务器上的 <code>install/</code> 目录；</li>
    <li>按安装目录里 <code>服务器配置.txt</code> 加两条服务器规则（图片显示、备份防下载）。</li>
  </ol>
  <p><a class="btn" href="/install/">开始安装 →</a></p>
  <p class="sub">已经在装、但这里还是这一页？检查站点根目录下有没有 <code>config.php</code>；也可以把 <code>config.example.php</code> 复制成 <code>config.php</code> 手填。</p>
</div></body></html>
HTML;

        exit;
    }
}
