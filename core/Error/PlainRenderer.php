<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Error;

use Molin\Http\Response;

/**
 * 内核内置的友好错误页。
 *
 * 内置于内核、**不依赖主题** —— 触发 500 的可能正是主题自己,再拿它渲染等于用坏零件修自己。
 * 所以这一页不读容器、不读路由、不查数据库、不加载任何外链资源:CSS 内联在 `<style>` 里,
 * 字体只列系统字族栈;页面只说人话,不出现路径、SQL、表名、类名、行号、PHP 版本、异常原文。
 *
 * 版式照界面设计稿逐项落地(既有规范/既有规范/既有规范含 768px 断点):
 * 三个状态共用同一个壳,只差文案与链接目标;401 / 403 按设计稿不出错误 ID(那是它俩的设计稿明写的)。
 * 没出过设计稿的状态(如 429 走到这里)沿用同一个壳 + 通用状态行,不另立版式。
 *
 * 类名是历史遗留:它最初只产出纯结构(那时还没有错误页设计稿),现在渲染的是设计稿版式。
 * 名字不再改,免得牵动一堆引用 —— 认名字以本注释为准。
 */
final class PlainRenderer
{
    /**
     * 有设计稿的三个状态:文案逐字照抄,不得改写、不得增删。
     *
     * @var array<int, array{title:string, status:string, h1:string, msg:string, links:list<array{0:string,1:string}>, errid:bool}>
     */
    private const PAGES = [
        401 => [
            'title' => '401 · 未登录',
            'status' => '401 未登录',
            'h1' => '这一页要登录后才能看。',
            'msg' => '会话已过期，或你还没登录。登录后会回到你刚才要的页面。',
            'links' => [['← 去登录', '/login'], ['回到首页', '/']],
            'errid' => false,
        ],
        403 => [
            'title' => '403 · 无权访问',
            'status' => '403 无权访问',
            'h1' => '这一页需要更高的权限。',
            'msg' => '当前账号没有查看这一页所需的能力。如果认为是误判，请联系站长核对角色。',
            'links' => [['← 返回登录', '/login'], ['回到首页', '/']],
            'errid' => false,
        ],
        500 => [
            'title' => '500 · 服务器出错',
            'status' => '500 服务器出错',
            'h1' => '服务暂时出了点问题。',
            'msg' => '这次请求没能完成，问题已经记下来了。稍后重试，或者联系站长。',
            'links' => [['← 返回首页', '/']],
            'errid' => true,
        ],
    ];

    /**
     * 版式 CSS。逐值与既有规范/§6 抄自设计稿源件:五枚令牌、一条暖赭短线、无图标无插画无红色。
     * 768px 断点用既有规范之前唯一的移动端口径。
     */
    private const CSS = <<<'CSS'
    :root{
      --paper:#FAFAF8; --ink:#1B1C1E; --muted:#85837E; --rule:#E5E3DE; --accent:#B96A2E;
      --font-serif:"Noto Serif SC","Source Han Serif SC","Songti SC",SimSun,Georgia,serif;
      --font-sans:"Inter","Helvetica Neue",Arial,"Microsoft YaHei",sans-serif;
      --font-mono:"JetBrains Mono","SFMono-Regular",Consolas,monospace;
    }
    *{margin:0;padding:0;box-sizing:border-box;}
    html,body{background:var(--paper);}
    body{font-family:var(--font-serif);color:var(--ink);-webkit-font-smoothing:antialiased;}
    .page{max-width:1480px;margin:0 auto;padding:62px 96px 72px;}
    .box{max-width:720px;margin:0 auto;}

    .status{font-family:var(--font-sans);font-size:13px;letter-spacing:.34em;color:var(--muted);}
    h1{margin-top:22px;font-size:40px;font-weight:400;line-height:1.35;letter-spacing:.01em;}
    .underline{margin-top:30px;width:96px;height:3px;background:var(--accent);}
    .msg{margin-top:28px;font-size:17px;line-height:1.9;color:var(--muted);letter-spacing:.01em;}
    .errid{margin-top:26px;display:flex;align-items:baseline;gap:12px;
      font-family:var(--font-sans);font-size:12px;letter-spacing:.18em;color:var(--muted);}
    .errid code{font-family:var(--font-mono);font-size:14px;letter-spacing:.02em;color:var(--ink);
      background:#F1EFEA;padding:.12em .38em;border-radius:2px;}
    .links{margin-top:38px;display:flex;align-items:baseline;gap:26px;}
    .back{display:inline-block;margin-top:38px;font-family:var(--font-sans);font-size:13px;
      letter-spacing:.16em;color:var(--ink);text-decoration:none;border-bottom:1px solid var(--rule);
      padding-bottom:6px;}
    /* 两条链接时上距归 .links 管,免得两条各带一段上距 */
    .links .back{margin-top:0;}
    .back:hover{color:var(--accent);border-color:var(--accent);}

    @media (max-width:768px){
      .page{max-width:none;padding:26px 22px 34px;}
      .status{font-size:10.5px;letter-spacing:.28em;}
      h1{margin-top:18px;font-size:26px;line-height:1.4;}
      .underline{margin-top:24px;width:56px;}
      .msg{margin-top:20px;font-size:14.5px;line-height:1.85;}
      .errid{margin-top:22px;gap:10px;font-size:10.5px;letter-spacing:.14em;}
      .errid code{font-size:12.5px;}
      .links{margin-top:28px;gap:18px;}
      .back{margin-top:28px;font-size:12px;letter-spacing:.14em;padding-bottom:5px;}
      .back:hover,.back:active{color:var(--accent);border-color:var(--accent);}
    }
    CSS;

    /**
     * @param string $errorId 既有规范生成的错误编号,只给 500 用(401 / 403 按设计稿不出)
     * @param string $message 未出设计稿的状态当正文用;401 / 403 / 500 有设计稿文案,传了也不认
     */
    public function render(string $errorId, int $status = 500, string $message = ''): Response
    {
        return Response::html($this->html($errorId, $status, $message), $status);
    }

    private function html(string $errorId, int $status, string $message): string
    {
        $page = self::PAGES[$status] ?? [
            'title' => $status . ' · 请求未完成',
            'status' => (string) $status,
            'h1' => '这次请求没能完成。',
            'msg' => $message === '' ? '站点暂时没能完成这个请求。' : $message,
            'links' => [['← 返回首页', '/']],
            'errid' => true,
        ];

        // 设计稿源件把首页链接写成 href="/",装在子目录里会指错(§11 第 9 条点名的源件缺陷)。
        // 异常路径上读不到站点设置(那要查库),所以按部署基路径补前缀 —— 子目录也不会跑偏。
        $base = self::basePath();

        $html = '<!DOCTYPE html>'
            . '<html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($page['title']) . '</title>'
            . '<style>' . self::CSS . '</style></head><body>'
            . '<div class="page"><div class="box">'
            . '<div class="status">' . self::e($page['status']) . '</div>'
            . '<h1>' . self::e($page['h1']) . '</h1>'
            . '<div class="underline"></div>'
            . '<p class="msg">' . self::e($page['msg']) . '</p>';

        if ($page['errid']) {
            $html .= '<div class="errid"><span>错误 ID</span><code>' . self::e($errorId) . '</code></div>';
        }

        $links = [];

        foreach ($page['links'] as [$label, $path]) {
            $links[] = '<a class="back" href="' . self::e($base . $path) . '">' . self::e($label) . '</a>';
        }

        // 单条链接是与 .box 平级的 a.back(500 的结构树如此,不得多包一层);两条才套 .links
        $html .= count($links) === 1
            ? $links[0]
            : '<div class="links">' . implode('', $links) . '</div>';

        return $html . '</div></div></body></html>';
    }

    /**
     * 部署基路径:入口在 /sub/index.php 时给 /sub,根目录给空串。
     *
     * 只认"以 / 开头的 SCRIPT_NAME" —— 那是 Web 前端的形状。CLI / PHPUnit 下 SCRIPT_NAME
     * 是 `vendor/bin/phpunit` 这类相对路径,拿它推基路径会推出垃圾(实装时被用例当场抓到)。
     */
    private static function basePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        if (!str_starts_with($script, '/')) {
            return '';
        }

        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $dir === '.' ? '' : $dir;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
