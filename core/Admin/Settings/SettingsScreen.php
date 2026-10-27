<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Settings;

use Molin\Admin\PostsController;
use Molin\Admin\View;
use Molin\Admin\WebAuth;
use Molin\Content\TermAssigner;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Language\Translator;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;

/**
 * 设置页:分组、当前值、字段白名单与落库。页面变量在这里凑齐,外壳只负责合并。
 */
final class SettingsScreen
{
    public function __construct(
        private readonly View $view,
        private readonly Options $options,
        private readonly Router $router,
        private readonly PostsController $pageScreen,
        private readonly TermAssigner $terms
    ) {
    }

    public function siteName(): string
    {
        return (string) $this->options->get('site_name', '墨林');
    }

    /**
     * 设置屏的页面变量(不含缓存状态:那一片由外壳补上)。
     *
     * @return array<string, mixed>
     */
    public function viewVars(Request $request): array
    {
        return [
            'error' => '',
            'settings_section' => $this->settingsSection($request),
            'categories' => $this->termChoices(),
            'updated_text' => $this->settingsUpdatedText(),
            // ?reset=1:只把当前分组换回默认再画一遍,不落库;别的分组原样保留
            'values' => $this->settingsValues(
                $request->input('reset') === '1' ? $this->settingsSection($request) : null
            ),
            'languages' => Translator::instance()->available(),
            'pages' => $this->pageScreen->frontPageChoices((string) $this->options->get('page_on_front', '0')),
            // 固定链接组示例地址的前缀:前端换预设/改结构时现算示例要用
            'site_base' => rtrim((string) $this->options->get('site_url', ''), '/'),
        ];
    }

    /**
     * 设置分组:八组页签,整页跳转靠 ?sec=。认不出的一律回落常规组。
     */
    private function settingsSection(Request $request): string
    {
        $sec = $request->input('sec', 'regular');

        return in_array($sec, ['regular', 'writing', 'reading', 'discussion', 'permalink', 'media', 'cache', 'update'], true)
            ? $sec
            : 'regular';
    }

    /**
     * 设置页表单的当前值:一律从 options 取,缺省给下面这份默认。
     *
     * @return array<string, string>
     */
    private function settingsValues(?string $resetSection = null): array
    {
        $defaults = [
            'site_name' => $this->siteName(),
            'site_description' => '',
            'site_url' => '',
            'admin_email' => '',
            'users_can_register' => '0',
            'locale' => Translator::DEFAULT_LOCALE,
            'default_category' => '0',
            'autosave_interval' => '30',
            'show_on_front' => 'posts',
            'page_on_front' => '0',
            'posts_per_page' => '10',
            'list_display' => 'excerpt',
            'comment_open' => '1',
            'comment_require_name_email' => '1',
            'comment_registered_approved' => '1',
            'comment_whitelist' => '1',
            'comment_default_status' => 'pending',
            'comment_nest_depth' => '2',
            'comment_order' => 'asc',
            'comment_auto_close_days' => '14',
            'comment_flood_sec' => '30',
            'comment_honeypot' => '1',
            'comment_moderation_keys' => '',
            'permalink_structure' => '/%postname%/',
            'jpeg_quality' => '82',
            'webp_quality' => '80',
            'max_upload_mb' => '10',
            'exif_policy' => 'strip',
            'upload_types' => 'jpg jpeg png gif webp pdf zip mp4 webm mp3',
            'update_channel' => 'dev',
            'update_auto' => '0',
        ];

        $values = [];

        foreach ($defaults as $name => $fallback) {
            $values[$name] = (string) $this->options->get($name, $fallback);
        }

        // 重置:当前分组字段换回默认值,只在这次画面上生效;等用户点保存才真正落库
        if ($resetSection !== null) {
            foreach ($this->settingsFields($resetSection) as $name => $spec) {
                if (array_key_exists($name, $values)) {
                    $values[$name] = $spec['default'];
                }
            }
        }

        // 示例地址随当前结构现算,只给人核对用,不落库
        $values['permalink_sample'] = $this->permalinkSample($values['permalink_structure']);

        return $values;
    }

    /**
     * 分类下拉的候选:writing 组「默认分类」用。
     *
     * @return list<array{id:int, name:string, selected:bool}>
     */
    private function termChoices(): array
    {
        $current = (string) $this->options->get('default_category', '0');

        return array_map(function (array $term) use ($current): array {
            return [
                'id' => $term['term_taxonomy_id'],
                'name' => $term['name'],
                'selected' => (string) $term['term_taxonomy_id'] === $current,
            ];
        }, $this->terms->choices('category'));
    }

    /**
     * 当前固定链接结构下的一条示例地址:把占位符换成示例值,前面接站点地址。
     */
    private function permalinkSample(string $structure): string
    {
        if ($structure === '') {
            return '';
        }

        $path = strtr($structure, [
            '%postname%' => 'hello-world',
            '%post_id%' => '123',
            '%category%' => 'uncategorized',
            '%year%' => '2026',
            '%month%' => '10',
            '%day%' => '06',
            '%type%' => 'post',
        ]);

        $base = rtrim((string) $this->options->get('site_url', ''), '/');

        if ($path === '') {
            return $base;
        }

        if ($path[0] === '/' || $path[0] === '?') {
            return $base . $path;
        }

        return $base . '/' . $path;
    }

    /**
     * 保存栏「上次保存」那一行:存的是时间戳,摆设而已,不参与逻辑。
     */
    private function settingsUpdatedText(): string
    {
        $stamp = (int) $this->options->get('settings_updated_at', '0');

        return $stamp > 0 ? date('Y-m-d H:i', $stamp) : '';
    }

    /**
     * 各分组可写的字段白名单:值类型 + 取值范围,超界的直接夹回默认。
     *
     * @return array<string, array{type:string, default:string, min?:int, max?:int, options?:list<string>}>
     */
    private function settingsFields(string $sec): array
    {
        return match ($sec) {
            'writing' => [
                'default_category' => ['type' => 'text', 'default' => '0'],
                'autosave_interval' => ['type' => 'int', 'default' => '30', 'min' => 5, 'max' => 600],
            ],
            'reading' => [
                'show_on_front' => ['type' => 'select', 'default' => 'posts', 'options' => ['posts', 'page']],
                'page_on_front' => ['type' => 'int', 'default' => '0', 'min' => 0, 'max' => PHP_INT_MAX],
                'posts_per_page' => ['type' => 'int', 'default' => '10', 'min' => 1, 'max' => 100],
                'list_display' => ['type' => 'select', 'default' => 'excerpt', 'options' => ['excerpt', 'full']],
            ],
            'discussion' => [
                'comment_open' => ['type' => 'bool', 'default' => '1'],
                'comment_require_name_email' => ['type' => 'bool', 'default' => '1'],
                'comment_registered_approved' => ['type' => 'bool', 'default' => '1'],
                'comment_whitelist' => ['type' => 'bool', 'default' => '1'],
                'comment_default_status' => ['type' => 'select', 'default' => 'pending', 'options' => ['pending', 'approved']],
                'comment_nest_depth' => ['type' => 'select', 'default' => '2', 'options' => ['1', '2', '3']],
                'comment_order' => ['type' => 'select', 'default' => 'asc', 'options' => ['asc', 'desc']],
                'comment_auto_close_days' => ['type' => 'int', 'default' => '14', 'min' => 0, 'max' => 3650],
                'comment_flood_sec' => ['type' => 'int', 'default' => '30', 'min' => 0, 'max' => 3600],
                'comment_honeypot' => ['type' => 'bool', 'default' => '1'],
                'comment_moderation_keys' => ['type' => 'text', 'default' => ''],
            ],
            'permalink' => [
                'permalink_structure' => ['type' => 'permalink', 'default' => '/%postname%/'],
            ],
            'media' => [
                'jpeg_quality' => ['type' => 'int', 'default' => '82', 'min' => 40, 'max' => 100],
                'webp_quality' => ['type' => 'int', 'default' => '80', 'min' => 40, 'max' => 100],
                'max_upload_mb' => ['type' => 'int', 'default' => '10', 'min' => 1, 'max' => 512],
                'exif_policy' => ['type' => 'select', 'default' => 'strip', 'options' => ['strip']],
                'upload_types' => ['type' => 'text', 'default' => 'jpg jpeg png gif webp pdf zip mp4 webm mp3'],
            ],
            // 缓存组暂无「可配」字段:缓存开关在 config.php 的 cache 段,这一组本来就不写库。
            // (2026-10-12 更正旧注释"既有规范的数据缓存适配器与页面缓存都还没落地"—— 已过时:
            //  0.74.0 起 CacheManager / PageCache 已接线,设置页与仪表盘显示的是实际生效的后端。)
            // 页面上只放只读说明,不留会写库的输入 —— 有输入却没人读,就是会说谎的开关。
            'cache' => [],
            'update' => [
                // 2026-10-23 起只留开发版一条官方通道(stable 源已下线),正式版源恢复时再把 stable 加回来
                'update_channel' => ['type' => 'select', 'default' => 'dev', 'options' => ['dev']],
                'update_auto' => ['type' => 'bool', 'default' => '0'],
            ],
            default => [
                'site_name' => ['type' => 'title', 'default' => '墨林'],
                'site_description' => ['type' => 'text', 'default' => ''],
                'site_url' => ['type' => 'url', 'default' => ''],
                'admin_email' => ['type' => 'email', 'default' => ''],
                'users_can_register' => ['type' => 'bool', 'default' => '0'],
                'locale' => ['type' => 'locale', 'default' => Translator::DEFAULT_LOCALE],
            ],
        };
    }

    /**
     * @param array<string, mixed> $pageVars
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function saveSettings(Request $request, array $pageVars, array $menu): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return $this->view->render('settings.twig', [
                'page_title' => __('设置'),
                'error' => __(WebAuth::STALE),
            ] + $pageVars, $menu, 403);
        }

        $sec = $this->settingsSection($request);

        foreach ($this->settingsFields($sec) as $name => $spec) {
            // Options::set 自己会记一条 setting.update 审计,这里不再重复记
            $this->options->set($name, $this->castSetting($request->input($name, ''), $spec));
        }

        $this->options->set('settings_updated_at', (string) time());

        $back = $this->router->path('admin', ['path' => 'settings']);

        return Response::redirect($sec === 'regular' ? $back : $back . '?sec=' . $sec);
    }

    /**
     * @param array{type:string, default:string, min?:int, max?:int, options?:list<string>} $spec
     */
    private function castSetting(string $raw, array $spec): string
    {
        $raw = trim($raw);

        return match ($spec['type']) {
            'bool' => $raw === '1' ? '1' : '0',
            'int' => (string) max($spec['min'] ?? 0, min($spec['max'] ?? PHP_INT_MAX, (int) ($raw === '' ? $spec['default'] : $raw))),
            'select' => in_array($raw, $spec['options'] ?? [], true) ? $raw : $spec['default'],
            'title' => $raw === '' ? $spec['default'] : $raw,
            'url' => rtrim($raw, '/'),
            'email' => $raw === '' || filter_var($raw, FILTER_VALIDATE_EMAIL) !== false ? $raw : '',
            'locale' => $this->validLocale($raw) ?? $spec['default'],
            'permalink' => $this->normalizePermalink($raw, $spec['default']),
            default => $raw,
        };
    }

    /**
     * 站点语言只收装得上语言包的。
     */
    private function validLocale(string $raw): ?string
    {
        $locale = Translator::instance()->normalize($raw);

        return $locale !== null && isset(Translator::instance()->available()[$locale]) ? $locale : null;
    }

    /**
     * 固定链接结构:必须留至少一个占位符,且以 / 或 ? 起头,否则回落默认。
     */
    private function normalizePermalink(string $raw, string $default): string
    {
        if ($raw === '' || !str_contains($raw, '%')) {
            return $default;
        }

        if ($raw[0] !== '/' && $raw[0] !== '?') {
            $raw = '/' . $raw;
        }

        return $raw;
    }
}
