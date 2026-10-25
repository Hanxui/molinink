<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Http\Response;
use Molin\Kernel;
use Molin\Language\SourceScanner;
use Molin\Language\Translator;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * 后台视图渲染(模板在 `admin/templates/`)。
 *
 * 后台不走主题:换一套前台皮肤不该把管理界面弄坏,而且主题模板里本来就不许写业务(约定)。
 * 但受限模板引擎的规矩与《14》一致 —— 自动转义常开、禁 sandbox 之外的逃逸、开发期 strict_variables 全开。
 * 样式在 `admin/assets/admin.css`,数值逐字取自 `assets/ui/` 的定稿图与《70-界面》各份规格。
 */
final class View
{
    private Environment $twig;

    public function __construct(
        private readonly Router $router,
        private readonly Options $options
    ) {
        $this->twig = new Environment(new FilesystemLoader(MOLIN_ROOT . '/admin/templates'), [
            'cache' => MOLIN_ROOT . '/storage/cache/twig',
            'autoescape' => 'html',
            'strict_variables' => APP_DEBUG,
            'debug' => APP_DEBUG,
            'auto_reload' => APP_DEBUG,
        ]);

        // 后台的 path() 给站内相对路径(与 Router::path 同口径);前台主题的 path() 才是绝对地址
        $this->twig->addFunction(new TwigFunction('path', static fn (string $name, array $params = []): string => $router->path($name, $params)));

        // i18n(《33》§3、《14》§5.4):后台模板与前台同一套函数,签名同 PHP 侧
        $this->twig->addFunction(new TwigFunction('__', static fn (string $text, string $domain = 'molin', mixed ...$args): string => __($text, $domain, ...$args)));
        $this->twig->addFunction(new TwigFunction('_e', static fn (string $text, string $domain = 'molin', mixed ...$args): Markup => new Markup(Translator::instance()->escape($text, $domain, ...$args), 'UTF-8')));
        $this->twig->addFunction(new TwigFunction('format_date', static fn (mixed $value, string $style = 'long'): string => format_date($value, $style)));
        $this->twig->addFunction(new TwigFunction('format_number', static fn (int|float $value, int $decimals = 0): string => format_number($value, $decimals)));
        $this->twig->addFunction(new TwigFunction('avatar', static fn (string $email, int $size = 48): string => avatar($email, $size)));

        // 覆盖内置 |date:库里时间存的是 UTC,模板直接 date() 会整体偏几小时(《21》)
        $this->twig->addFilter(new TwigFilter('date', static fn (mixed $value, string $format = 'F j, Y H:i', ?string $timezone = null): string => site_datetime($value, $timezone)->format($format)));

        // 提示与报错里的十六进制值等代码片段裹 <code>(《91》§3 字体表)。先转义再包裹,主题写不了注入
        $this->twig->addFilter(new TwigFilter('codes', static function (mixed $text): Markup {
            $out = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $out = (string) preg_replace(
                ['/(#[0-9a-fA-F]{6})(?![0-9a-fA-F])/', '/#(?![0-9a-fA-F])(?=[\s，。]|$)/'],
                ['<code>$1</code>', '<code>#</code>'],
                $out
            );

            return new Markup($out, 'UTF-8');
        }));

        if (APP_DEBUG) {
            $this->twig->addExtension(new DebugExtension());
        }
    }

    /**
     * 插件视图(《12》§3 views/):注册成 @plugin-{name} 命名空间,模板里写 @plugin-shop/orders.twig。
     * 只按目录注册 —— 命名空间之外的文件名取不到,插件读不到 admin/templates 里的东西。
     */
    public function addPluginViews(string $name, string $dir): void
    {
        $loader = $this->twig->getLoader();

        if ($loader instanceof FilesystemLoader && is_dir($dir)) {
            $loader->addPath($dir, 'plugin-' . $name);
        }
    }

    /**
     * 后台内部一律用站内相对路径(《23》第 1 节第 4 条:URL 由路由名生成,不许拼字符串)。
     *
     * 不用 generate() 的绝对地址:后台的链接与跳转跟着当前站点跑就够了,
     * site_url 写错时也不会把人指到别处去。
     *
     * @param array<string, string|int> $params
     */
    public function path(string $name, array $params = []): string
    {
        return $this->router->path($name, $params);
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function render(string $template, array $vars = [], array $menu = [], int $status = 200): Response
    {
        $payload = [
            'site_name' => (string) $this->options->get('site_name', '墨林'),
            'site_tagline' => (string) $this->options->get('site_description', '书写成林，墨笔成荫'),
            'site_url' => (string) $this->options->get('site_url', '/'),
            // 外观组二级导航要指向"当前启用主题"的设置页(partials/appearance-nav.twig):
            // 菜单屏自己不认主题,由这里统一给一个源
            'active_theme_slug' => (string) $this->options->get('active_theme', 'molin-default'),
            'kernel_version' => Kernel::VERSION,
            // 后台静态资源按文件修改时间换 URL：内核版本号不变时，改过 admin.js/admin.css 也能让浏览器拿到新文件
            'admin_asset_version' => (string) max(
                (int) @filemtime(MOLIN_ROOT . '/admin/assets/admin.js'),
                (int) @filemtime(MOLIN_ROOT . '/admin/assets/admin.css')
            ),
            'current_user' => Auth::user(),
            // 「install/ 还没删」的常驻提醒(《42》§7、界面缺口 C11):此前文档写着会有、代码里一直没有
            'install_dir_present' => is_dir(MOLIN_ROOT . '/install'),
            'menu' => $menu,
            'csrf_token' => Auth::csrfToken(),
            'csrf_field' => Csrf::FIELD,
            // <html lang> 跟着这一趟的语言跑(《33》§8)
            'html_lang' => Translator::instance()->htmlLang(),
            'js_strings' => $this->jsStrings(),
        ] + $vars;

        return Response::html($this->twig->render($template, $payload), $status);
    }

    /**
     * 只渲染一小块(上传队列的一行)。XHR 上传要的就是这种片段:文案仍由服务端按《80》
     * 第 10.2 节那张表出,前端不自己拼句子。
     *
     * @param array<string, mixed> $vars
     */
    public function fragment(string $template, array $vars = []): string
    {
        return $this->twig->render($template, $vars);
    }

    /**
     * 脚本里用得到的界面词。模板注成 window.MOLIN_I18N,admin.js 按源文案查表。
     *
     * @return array<string, string>
     */
    private function jsStrings(): array
    {
        $out = [];

        // admin.js 的界面词自动扫进来,加词只改 JS 一处,免得两边不同步
        foreach (SourceScanner::scriptSources(MOLIN_ROOT . '/admin/assets/admin.js') as $source) {
            $out[$source] = __($source);
        }

        // 被变量传进 molinT() 的,正则扫不到,只能点名补
        foreach (['键', '值', '弱', '中', '强'] as $source) {
            $out[$source] = __($source);
        }

        return $out;
    }
}
