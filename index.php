<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

/*
 * 唯一入口(约定:所有请求走这里)。启动顺序依《52-自动加载与命名空间》第 8 节、
 * 请求生命周期依《23-路由规范》第 2 节;服务装配在 Molin\Bootstrap(与 CLI 共用)。
 * 前台路由见 Molin\Front\FrontRoutes,登录 / 后台 / 接口路由在本文件尾部接线。
 */

use Molin\Admin\AdminController;
use Molin\Admin\WebAuth;
use Molin\Api\ApiController;
use Molin\Bootstrap;
use Molin\Cache\CacheManager;
use Molin\Cache\PageCache;
use Molin\Front\FrontContext;
use Molin\Front\FrontRoutes;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Http\SecurityHeaders;
use Molin\Language\Translator;
use Molin\Plugin\Loader;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Support\Performance\RequestSamples;
use Molin\Update\Maintenance;
use Molin\User\Auth;
use Molin\User\RememberTokens;
use Molin\User\SessionStore;
use Molin\User\UserRepository;

define('MOLIN_ROOT', __DIR__);

// 契约版本随内核发布,与产品版本各自演进(《00》第 1 节、《56》第 1 节)
define('MOLIN_CONTRACT', '1.0');

/*
 * 缺 vendor/ 是「下错包」的典型症状:GitHub 仓库页 Code → Download ZIP 只打仓库内容,
 * vendor/ 在 .gitignore 里跟着走不了,一路跑到 require 就冒一条 include_path 的 Fatal,
 * 用户看不出该干什么。这里提前拦一道,直接把下一步说清楚(发布包自带 vendor,源码包要 composer install)。
 * 样式同样内联:这时 autoload 还没进来,Twig 与任何外链都用不上(《118》§0 同一条道理)。
 */
if (!is_file(MOLIN_ROOT . '/vendor/autoload.php')) {
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

    return;
}

/** @var Composer\Autoload\ClassLoader $autoloader */
$autoloader = require MOLIN_ROOT . '/vendor/autoload.php';

if (!Bootstrap::isConfigured(MOLIN_ROOT)) {
    // 第一次打开站点(还没装):别只回一行纯文本 —— 一个陌生用户不该靠猜才知道下一步是 /install/。
    // 样式内联、不引外部资源:这时容器还没起来,任何外链都可能二次失败(《118》§0 同一条道理)。
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

    return;
}

/*
 * 整页缓存快速通道(《24》§7 第 3 层)的装配前落点:无本站 cookie 的 GET 直接读盘吐 HTML,
 * 省掉的是 Bootstrap::start() 那一整段 —— autoload、容器、插件 boot、几十条路由注册和七次 SELECT。
 *
 * 判据全部是"不用问内核就能答"的:方法、cookie、维护标志、排除前缀、缓存文件。
 * 任何一步拿不准或读盘失败都静默回落 —— 快速通道只该更快,不该更脆。
 */
try {
    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        && !isset($_COOKIE['molin_session'])
        && !isset($_COOKIE['molin_remember'])
        && !Maintenance::isOn(MOLIN_ROOT)
    ) {
        /** @var array<string, mixed> $fastConfig */
        $fastConfig = require MOLIN_ROOT . '/config.php';
        $fastCacheConfig = is_array($fastConfig['cache'] ?? null) ? $fastConfig['cache'] : [];
        $fastBackend = strtolower(trim((string) ($fastCacheConfig['backend'] ?? 'file')));

        // backend=null 是"关掉缓存"的显式配置,盘上的旧文件不该在关掉之后还继续被吐出来
        if ($fastBackend !== 'null') {
            $fastState = getenv('MOLIN_STATE_ROOT');
            $fastRoot = is_string($fastState) && $fastState !== '' ? $fastState : MOLIN_ROOT;
            $fastTtl = (int) ($fastCacheConfig['ttl'] ?? 0);
            $fastCache = new PageCache(
                rtrim($fastRoot, '/\\') . '/storage/cache/pages',
                $fastTtl > 0 ? $fastTtl : CacheManager::DEFAULT_TTL
            );

            $fastPage = $fastCache->fastHit('GET', (string) ($_SERVER['REQUEST_URI'] ?? '/'));

            if ($fastPage !== null) {
                /*
                 * 快速通道在 ErrorHandler::boot() 之前就返回,首页性能采样不会自己发生 ——
                 * 这里补一份,免得健康屏的「缓存命中」读数从改版那天起断档。
                 * 只认精确的 `/`:采样口径按原始 URI 哈希,带查询串的首页会被它归错桶。
                 */
                if ((string) ($_SERVER['REQUEST_URI'] ?? '') === '/') {
                    register_shutdown_function(static function (float $startedAt, array $cacheConfig): void {
                        try {
                            CacheManager::boot(CacheManager::fromConfig($cacheConfig, MOLIN_ROOT));
                            (new RequestSamples())->record(
                                $startedAt,
                                (microtime(true) - $startedAt) * 1000,
                                'GET',
                                (string) ($_SERVER['REQUEST_URI'] ?? '/')
                            );
                        } catch (\Throwable) {
                            // 采样失败不影响已经发出去的页面
                        }
                    }, (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), $fastCacheConfig);
                }

                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
                header('ETag: ' . $fastPage['etag']);

                // 静态三项先铺底,sidecar 只负责补 CSP / HSTS —— 老缓存没 sidecar 也不会裸奔
                $https = (string) ($_SERVER['HTTPS'] ?? '') !== ''
                    && strtolower((string) $_SERVER['HTTPS']) !== 'off';
                $fastHeaders = SecurityHeaders::apply([], $https);

                foreach ($fastPage['headers'] as $fastName => $fastValue) {
                    $fastHeaders[$fastName] = $fastValue;
                }

                foreach ($fastHeaders as $fastName => $fastValue) {
                    header($fastName . ': ' . $fastValue, true);
                }

                if (PageCache::matchesEtag($_SERVER['HTTP_IF_NONE_MATCH'] ?? null, $fastPage['etag'])) {
                    http_response_code(304);

                    return;
                }

                echo $fastPage['html'];

                return;
            }
        }
    }
} catch (\Throwable) {
    // 快速通道坏了只是慢一点,不能因此白屏 —— 交给下面的正常流程
}

$container = Bootstrap::start(MOLIN_ROOT, $autoloader);

$front = FrontContext::fromContainer($container);
$frontRoutes = new FrontRoutes($front);
$frontRoutes->register($container->get(Router::class));
$options = $front->options();
$router = $container->get(Router::class);

// 登录 / 注册 / 登出(《41》:这些页面永远不挡维护模式,也不进整页缓存)
$webAuth = $container->get(WebAuth::class);
$admin = $container->get(AdminController::class);

foreach (['GET', 'POST'] as $verb) {
    $router->add('login', $verb, '/login', static fn (Request $request, array $vars = []): Response => $webAuth->login($request));
    $router->add('logout', $verb, '/logout', static fn (Request $request, array $vars = []): Response => $webAuth->logout($request));
    $router->add('register', $verb, '/register', static fn (Request $request, array $vars = []): Response => $webAuth->register($request));
    $router->add('lostpassword', $verb, '/lostpassword', static fn (Request $request, array $vars = []): Response => $webAuth->lostpassword($request));
    $router->add('resetpassword', $verb, '/resetpassword', static fn (Request $request, array $vars = []): Response => $webAuth->resetpassword($request));
}

/*
 * 后台与接口路由必须在插件路由之前登记 —— FastRoute 同路径先注册者优先,内核先占住门牌。
 * 收口前 index.php 就是这个次序(admin 在 1103、api 在 1131、plugins->registerRoutes 在 1139);
 * 一旦把这两块挪到插件后面,「插件不得占用内核路由」那条守卫就形同虚设(2026-10-22 修)。
 */
$adminPage = static fn (Request $request, array $vars = []): Response => $admin->handle($request, (string) ($vars['path'] ?? ''));
$router->add('admin', 'ALL', '/admin', $adminPage);
$router->add('admin', 'ALL', '/admin/', $adminPage);
$router->add('admin', 'ALL', '/admin/{path:.+}', $adminPage);

// REST(《25》):读端点 + 写端点齐;会话/cookie 走的写请求过 CSRF 令牌,API Key 直连
// `/api/v1/` 在 Router 分发前就归一成 `/api/v1`,别名只挂一条
$api = $container->get(ApiController::class);
$apiPage = static fn (Request $request, array $vars = []): Response => $api->handle($request, (string) ($vars['path'] ?? ''));
// 前台登录弹窗的防跨站令牌:访客默认没有会话,页面里的令牌只能是空串,弹窗一提交必判 stale。
// 改成"弹窗打开时才来取":建一条访客会话、把令牌发回去,响应里带上会话 cookie。
// 放在页面上不去建会话是_**有意的**_——匿名 GET 一旦发 Set-Cookie,整页缓存就再也存不下来(《24》§7)。
$router->add('session.csrf', 'GET', '/api/v1/csrf-token', static function (Request $request) use ($container): Response {
    $session = Auth::session();

    if ($session === null) {
        $session = $container->get(SessionStore::class)->create(0, $request->ip(), $request->userAgent(), [
            'csrf_token' => bin2hex(random_bytes(32)),
        ]);
        $container->get(Auth::class)->attach($session->id);

        return Response::json(['token' => Csrf::token($session)], 200)
            ->withCookie(Auth::COOKIE, $session->id, SessionStore::IDLE_TTL, $request->isSecure())
            ->withHeaders(['Cache-Control' => 'no-store']);
    }

    return Response::json(['token' => Csrf::token($session)], 200)
        ->withHeaders(['Cache-Control' => 'no-store']);
});
$router->add('api', 'ALL', '/api/v1', $apiPage);
$router->add('api', 'ALL', '/api/v1/{path:.+}', $apiPage);

$plugins = $container->get(Loader::class);
$plugins->boot();

// 插件自定义路由(《12》§7.2 落地):内核前台路由已全部登记在前,插件只能补在后面;
// 撞内核的插件在 boot() 里已被拒绝启用,这里再兜一道(冲突则整体跳过并记日志)。
$plugins->registerRoutes($router);

do_action('system/init');

// 自定义内容类型(CPT)的前台:类型登记表在 system/init 里填好,本节必须排在其后。
$frontRoutes->registerPostTypes($router);

// R2(《12》第 4.2 节):插件实际挂的钩子与 plugin.json 声明不一致,开发模式当场炸
$plugins->checkDeclarations();

$request = Request::fromGlobals();

// 会话挂载要在路由之前:主题要认识"当前是谁",能力判定也依赖它。
// 会话没了但「记住我」令牌还有效 → 自动登录,换回来的新 cookie 由 pendingCookies() 补发。
$auth = $container->get(Auth::class);
$auth->attach($request->cookie(Auth::COOKIE), $request->cookie(RememberTokens::COOKIE));

// i18n(《33》§4):会话与用户就位后定这一趟的语言。
// 检测链在 Translator 里(用户设置 → 会话/请求开关 → 站点默认 → 浏览器);?locale= 落进会话留给后面几趟。
$translator = $container->get(Translator::class);
$session = Auth::session();
$switch = $translator->normalize($request->input('locale'));

if ($switch !== null && $session !== null) {
    $session->data['locale'] = $switch;
    $container->get(SessionStore::class)->save($session);
}

$userMeta = Auth::user() === null ? [] : $container->get(UserRepository::class)->meta(Auth::id());
$sessionLocaleRaw = $session === null ? null : ($session->data['locale'] ?? null);
$sessionLocale = is_string($sessionLocaleRaw) ? $sessionLocaleRaw : null;

$translator->setLocale($translator->resolveLocale(
    $userMeta['locale'] ?? null,
    $sessionLocale,
    $request->header('Accept-Language') ?? '',
    (string) $options->get('locale', ''),
    $options->get('locale_detect_browser', '0') === '1'
));

/*
 * 维护模式(《29》§3 第 4 步):升级替换文件的那几秒,站点是半新半旧的 ——
 * 与其让访客撞上去,不如给他一个明确的 503。后台、登录、登出永远不挡,
 * 能升级的人也不挡(他正盯着这件事)。生产维护窗口通常只有几秒。
 */
$maintenance = new Maintenance($options);

if ($maintenance->blocks($request->path(), current_user_can('update.core'))) {
    $response = new Response($maintenance->page((string) $options->get('site_name', '')), 503, [
        'Content-Type' => 'text/html; charset=utf-8',
        'Retry-After' => (string) Maintenance::RETRY_AFTER,
    ]);

    $response->withHeaders(SecurityHeaders::apply(
        $response->headers(),
        $request->isSecure(),
        (string) $options->get('csp_policy', '')
    ))->send();

    return;
}

/*
 * 页面静态缓存(《24》§7 第 3 层):未登录访客的 GET 命中就直接吐整页 HTML ——
 * 不查内容、不渲染,请求里最贵的那两段全省掉。
 *
 * 落点比《24》§7.1 写的"最前面"稍靠后,是有意的:维护模式就在上面 —— 升级窗口那几秒
 * 必须照常回 503,不能被一份旧整页盖过去;顺带也只有装配之后才拿得到站点配置与真实安全头。
 */
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$pageCache = $container->get(PageCache::class);
$pageCacheOn = CacheManager::instance()->enabled();

/*
 * 判"登录"只认会话里的 user_id。原来判的是"请求带了 molin_session cookie" —— 而访客会话
 * 也是这条 cookie,文章页又给每个访客建会话,于是读过一篇文章的访客 14 天内全站都不命中缓存。
 * 访客会话只是挂防跨站令牌的容器,不构成身份。
 */
$loggedIn = PageCache::isLoggedIn(Auth::id());

/*
 * 还有两种会话状态会让这一页**只对这个人成立**,必须连读带写一起绕开:
 *   ① 待审评论 —— 提交者本人能看到自己那条"等待审核"的评论;
 *   ② 口令解锁态 —— 解锁之后能看到正文,缓存下来等于把私密正文端给所有人。
 * 这两条都在会话里,判起来是常数开销;除它们以外,匿名访客(哪怕带着会话 cookie)照常命中。
 */
$personalised = $session !== null && (
    (array) ($session->data['pending_comments'] ?? []) !== []
    || (array) ($session->data['unlocked_posts'] ?? []) !== []
);
$cacheUsable = !$loggedIn && !$personalised;

if ($pageCacheOn && $cacheUsable) {
    $cachedPage = $pageCache->fastHit($request->method(), $requestUri);

    if ($cachedPage !== null) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
        header('ETag: ' . $cachedPage['etag']);

        // 这里拿得到容器,安全头按本次请求的真实配置算,不必依赖 sidecar
        foreach (SecurityHeaders::apply([], $request->isSecure(), (string) $options->get('csp_policy', '')) as $head => $value) {
            header($head . ': ' . $value, true);
        }

        if (PageCache::matchesEtag($request->header('If-None-Match'), $cachedPage['etag'])) {
            http_response_code(304);

            return;
        }

        echo $cachedPage['html'];

        return;
    }
}

$response = $router->dispatch($request);

foreach ($auth->pendingCookies() as $cookie) {
    $response = $response->withCookie($cookie['name'], $cookie['value'], $cookie['maxAge'], $request->isSecure());
}

// 口令锁定页给访客新建的那条会话:cookie 要送到浏览器,下一趟口令提交才对得上令牌
if ($front->guestSessionId !== null) {
    $response = $response->withCookie(Auth::COOKIE, $front->guestSessionId, SessionStore::IDLE_TTL, $request->isSecure());
}

$csp = (string) $options->get('csp_policy', '');

/*
 * 页面静态缓存落盘(《24》§7.1):未登录 GET + 200 + text/html 才存(store 自己也会再核一遍)。
 *
 * 两条"这一份不能共享"的判断:
 *   ① 这一趟发过 Set-Cookie —— 刚建了会话(口令锁定页那支),页面里的令牌绑着它;
 *   ② 手里本来就有会话 —— 页面里的 csrf_token 是这条会话的,存下来被别人命中,
 *      对方的登录 / 口令提交就会判 stale。只有"干净访客"渲染出的那一份是人人可用的。
 */
if ($pageCacheOn && $cacheUsable && $session === null && !isset($response->headers()['Set-Cookie'])) {
    $pageCache->store(
        $request->method(),
        $requestUri,
        $loggedIn,
        $response->status(),
        (string) ($response->headers()['Content-Type'] ?? ''),
        $response->body(),
        // 快速通道拿不到容器,安全头只能随整页存一份,命中时原样回放
        SecurityHeaders::apply([], $request->isSecure(), $csp)
    );
}

/*
 * 后台与登录相关页面一律不缓存。后台页面的 HTML 里写着 admin.js / admin.css 的 ?v=,
 * 一旦浏览器拿整页 HTML 做启发式缓存,页面里的 ?v= 就还是旧的,新改的后台脚本永远进不来 ——
 * 表现就是「按钮怎么点都没反应」(2026-10-18 作者线上踩到的就是这个)。
 */
$noStorePaths = ['/login', '/register', '/logout', '/lostpassword', '/resetpassword'];
$pathNow = $request->path();

if ($pathNow === '/admin' || $pathNow === '/admin/' || str_starts_with($pathNow, '/admin/') || in_array($pathNow, $noStorePaths, true)) {
    $response = $response->withHeaders([
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
}

$response->withHeaders(SecurityHeaders::apply($response->headers(), $request->isSecure(), $csp))->send();
