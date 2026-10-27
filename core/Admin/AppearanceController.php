<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Media\Upload;
use Molin\Media\Uploader as MediaUploader;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Support\Files;
use Molin\Theme\Theme;
use Molin\Theme\ThemeSettings;
use Molin\Theme\Uploader;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\Roles;
use Molin\User\User;
use Throwable;

/**
 * 外观族(版式照设计稿):
 * 主题列表 / 启用切换 / 上传 / 删除,以及主题配置器(渲染、校验、保存、重置、导入导出、选图)。
 *
 * 主题只管长相:切主题 = 改启用状态,内容一字不动。预览用 ?molin_theme_preview={slug}
 * 让管理员在前台先看,设置写进 themes.settings,取值走 theme_setting。
 */
final class AppearanceController
{
    use AdminGuards;

    private const TABS = ['all', 'on', 'available'];

    /** 选图层一页 6 张,与编辑页那个选择器同一口径 */
    private const PICKER_PER_PAGE = 6;

    public function __construct(
        private readonly View $view,
        private readonly Options $options,
        private readonly Router $router,
        private readonly ThemeSettings $settings,
        private readonly Uploader $uploader,
        private readonly MediaRepository $media,
        private readonly Store $store,
        private readonly Authorizer $can,
        private readonly string $themesRoot,
        // 删除已改物理删,本类不再碰 trash;参数留着不动接线与调用方,收口时一并摘
        /** @phpstan-ignore property.onlyWritten */
        private readonly string $trashRoot
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        $parts = explode('/', trim($path, '/'));
        array_shift($parts);
        $action = $parts[0] ?? '';
        $sub = $parts[1] ?? '';

        if ($action === 'switch' && $request->isPost()) {
            return $this->switch($request, $user);
        }

        if ($action === 'upload' && $request->isPost()) {
            return $this->upload($request, $user, $menu);
        }

        if ($action === 'delete' && $request->isPost()) {
            return $this->delete($request, $user, $menu);
        }

        if ($action === 'settings') {
            if ($sub === 'save' && $request->isPost()) {
                return $this->settingsSave($request, $user, $menu);
            }

            if ($sub === 'reset' && $request->isPost()) {
                return $this->settingsReset($request, $user, $menu);
            }

            if ($sub === 'import' && $request->isPost()) {
                return $this->settingsImport($request, $user, $menu);
            }

            if ($sub === 'image' && $request->isPost()) {
                return $this->settingsImage($request, $user, $menu);
            }

            if ($sub === 'upload' && $request->isPost()) {
                return $this->settingsUpload($request, $user, $menu);
            }

            if ($sub === 'export' && !$request->isPost()) {
                return $this->settingsExport($request, $user, $menu);
            }

            if ($sub === '') {
                return $this->settingsScreen($request, $user, $menu);
            }
        }

        return $this->index($request, $menu);
    }

    // ---- 主题列表 ----

    /**
     * 扫 themes/ 目录读各自的 theme.json, active 的排最前。
     *
     * @return list<array<string, mixed>>
     */
    private function themes(): array
    {
        $active = (string) $this->options->get('active_theme', 'molin-default');
        $found = [];
        $titles = [];

        foreach ($this->manifests() as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);

            if (!is_array($manifest) || !isset($manifest['name'], $manifest['title'])) {
                continue;
            }

            $slug = (string) $manifest['name'];
            $parent = $manifest['parent'] ?? null;
            $titles[$slug] = (string) $manifest['title'];
            $found[] = [
                'slug' => $slug,
                'title' => (string) $manifest['title'],
                'version' => (string) ($manifest['version'] ?? ''),
                'author' => (string) ($manifest['author'] ?? ''),
                'description' => (string) ($manifest['description'] ?? ''),
                'parent' => is_string($parent) ? $parent : '',
                'active' => $slug === $active,
                'has_settings' => is_array($manifest['settings'] ?? null) && $manifest['settings'] !== [],
                'screenshot' => $this->screenshotUrl($file, $manifest),
            ];
        }

        $themes = array_map(
            static fn (array $t): array => $t + ['parent_title' => $titles[$t['parent']] ?? $t['parent']],
            $found
        );

        usort($themes, static fn (array $a, array $b): int => ($b['active'] ? 1 : 0) <=> ($a['active'] ? 1 : 0));

        return $themes;
    }

    /**
     * @return list<string> theme.json 文件路径清单
     */
    private function manifests(): array
    {
        $files = glob($this->trimRoot($this->themesRoot) . '/*/theme.json');

        return is_array($files) ? $files : [];
    }

    /**
     * 主题卡预览图:清单声明 screenshot 且文件真在主题目录里才给地址,
     * 否则空串 —— 模板落回 #F1EFEA 占位块,不留裂图。
     *
     * @param array<string, mixed> $manifest
     */
    private function screenshotUrl(string $manifestFile, array $manifest): string
    {
        $shot = (string) ($manifest['screenshot'] ?? '');

        // 只认保守的相对路径(字母数字 . _ - /):拼不出取不到的地址,也给越界(.. / 盘符 / 反斜杠)不留缝
        if (preg_match('#\A[A-Za-z0-9][A-Za-z0-9._/-]*\z#', $shot) !== 1 || str_contains($shot, '..')) {
            return '';
        }

        $dir = dirname($manifestFile);

        if (!is_file($dir . '/' . $shot)) {
            return '';
        }

        // 地址根相对,与主题里的 /themes/… 静态资源同一套写法(vhost 走 try_files 直出)
        return '/themes/' . basename($dir) . '/' . $shot;
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function index(Request $request, array $menu): Response
    {
        $themes = $this->themes();
        $tab = $request->input('tab');

        if (!in_array($tab, self::TABS, true)) {
            $tab = 'all';
        }

        $onCount = 0;

        foreach ($themes as $theme) {
            if ((bool) $theme['active']) {
                ++$onCount;
            }
        }

        foreach ($themes as &$theme) {
            $theme['switch_url'] = $this->url('appearance/switch');
            $theme['delete_url'] = $this->url('appearance/delete');
            $theme['settings_url'] = $this->url('appearance/settings') . '?theme=' . rawurlencode((string) $theme['slug']);
            $theme['preview_url'] = $this->previewUrl((string) $theme['slug']);
        }

        unset($theme);

        $visible = array_values(array_filter(
            $themes,
            static fn (array $t): bool => $tab === 'all' || ($tab === 'on' ? (bool) $t['active'] : !(bool) $t['active'])
        ));

        return $this->view->render('appearance.twig', [
            'page_title' => __('主题'),
            'page_kind' => 'appearance',
            'tab' => $tab,
            'tabs' => [
                ['label' => __('全部'), 'url' => $this->url('appearance'), 'count' => count($themes), 'active' => $tab === 'all'],
                ['label' => __('已启用'), 'url' => $this->url('appearance') . '?tab=on', 'count' => $onCount, 'active' => $tab === 'on'],
                ['label' => __('可用'), 'url' => $this->url('appearance') . '?tab=available', 'count' => count($themes) - $onCount, 'active' => $tab === 'available'],
            ],
            'themes' => $visible,
            'switch_url' => $this->url('appearance/switch'),
            'upload_url' => $this->url('appearance/upload'),
            'notice' => $this->flash($request),
        ], $menu);
    }

    /**
     * 上传结果与删除结果的回执,文案由服务端出(前端不拼句子)。
     */
    private function flash(Request $request): string
    {
        $done = $request->input('done');
        $msg = mb_substr($request->input('msg'), 0, 300);

        return match ($done) {
            'switched' => __('主题已切换。'),
            'uploaded' => $msg === '' ? __('主题已装入，出现在「可用」里。') : __('主题「%s」已装入，出现在「可用」里，不会自动启用。', 'molin', $msg),
            'upload-failed' => __('上传没成功：%s', 'molin', $msg),
            'unknown' => __('没有这只主题，或它的 theme.json 有问题。'),
            'deleted' => __('主题已删除，不可恢复。'),
            'delete-failed' => __('删除没成功：%s', 'molin', $msg),
            default => '',
        };
    }

    private function switch(Request $request, User $user): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($this->url('appearance'));
        }

        $slug = trim($request->input('theme'));

        try {
            // load 兼做校验:非法名、缺 theme.json、父主题成环都会抛
            $theme = Theme::load($this->trimRoot($this->themesRoot), $slug);
        } catch (Throwable) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        // 生命周期钩子与整站一致:先 before、再落状态、后 after、最后广播缓存失效
        $previous = (string) $this->options->get('active_theme', 'molin-default');
        do_action('theme/activate/before', $theme->slug(), $previous);
        $this->settings->activate($theme->slug());
        do_action('theme/activate/after', $theme->slug(), $previous);
        do_action('cache/invalidate/after', 'theme/activate');
        Audit::record('theme.switch', $slug, true, $request, 'user:' . (string) Auth::id());

        return Response::redirect($this->url('appearance') . '?done=switched');
    }

    /**
     * 上传主题包:解包 → 七项校验 → 移入 themes/{slug}/,不自动启用。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function upload(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'theme.switch')) {
            return $this->forbidden($menu, ['theme.switch'], __('上传主题'));
        }

        if (!$this->tokenOk($request)) {
            return Response::redirect($this->url('appearance'));
        }

        $files = $request->uploads('file');
        $result = $this->uploader->install($files[0] ?? ['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]);
        Audit::record('theme.upload', $result['slug'] === '' ? 'failed' : $result['slug'], $result['ok'], $request, 'user:' . (string) Auth::id());

        if ($result['ok']) {
            return Response::redirect($this->url('appearance') . '?done=uploaded&msg=' . rawurlencode($result['slug']));
        }

        return Response::redirect($this->url('appearance') . '?done=upload-failed&msg=' . rawurlencode(implode(' ', $result['errors'])));
    }

    /**
     * 删除主题:主题目录真删、不可恢复,内容数据一律不动。
     * 启用中的主题不可自删;有子主题依赖时先提示数目(安全 > 便捷)。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function delete(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'theme.switch')) {
            return $this->forbidden($menu, ['theme.switch'], __('删除主题'));
        }

        if (!$this->tokenOk($request)) {
            return Response::redirect($this->url('appearance'));
        }

        $slug = trim($request->input('theme'));
        $dir = $this->trimRoot($this->themesRoot) . '/' . $slug;
        $active = (string) $this->options->get('active_theme', 'molin-default');

        if ($slug === '' || $slug !== basename($slug) || !is_file($dir . '/theme.json')) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        if ($slug === $active) {
            return Response::redirect($this->url('appearance') . '?done=delete-failed&msg=' . rawurlencode(__('启用中的主题不能删除，先换一只。')));
        }

        $children = $this->childrenOf($slug);

        if ($children !== []) {
            return Response::redirect($this->url('appearance') . '?done=delete-failed&msg=' . rawurlencode(__('有 %s 个子主题依赖它，先把它们换掉或删掉。', 'molin', count($children))));
        }

        $deleted = $this->removeTree($dir);
        Audit::record('theme.delete', $slug, $deleted, $request, 'user:' . (string) Auth::id());

        return $deleted
            ? Response::redirect($this->url('appearance') . '?done=deleted')
            : Response::redirect($this->url('appearance') . '?done=delete-failed&msg=' . rawurlencode(__('目录没删干净，检查文件权限后再试。')));
    }

    /**
     * @return list<string>
     */
    private function childrenOf(string $slug): array
    {
        $children = [];

        foreach ($this->manifests() as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);

            if (is_array($manifest) && ($manifest['parent'] ?? null) === $slug) {
                $children[] = (string) ($manifest['name'] ?? '');
            }
        }

        return $children;
    }

    /**
     * 物理删除目录树:文件一律走 Files::delete(Windows 上新建文件会被杀软短锁,退避重试收在 Files 里);
     * 删不干净就如实回 false,让调用方报失败。链接只删链接本身,不跟进去删目标。
     */
    private function removeTree(string $dir): bool
    {
        if (is_link($dir)) {
            return Files::delete($dir);
        }

        if (!is_dir($dir)) {
            return true;
        }

        $entries = scandir($dir);

        foreach (is_array($entries) ? $entries : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;

            if (is_dir($path) && !is_link($path)) {
                if (!$this->removeTree($path)) {
                    return false;
                }

                continue;
            }

            if (!Files::delete($path)) {
                return false;
            }
        }

        return @rmdir($dir);
    }

    // ---- 配置器 ----

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $overlay
     */
    private function settingsScreen(Request $request, User $user, array $menu, array $errors = [], ?array $overlay = null, string $noticeError = ''): Response
    {
        $theme = $this->target($request);

        if ($theme === null) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        $groups = $this->settings->present($theme, $errors, $overlay);

        // 代码类字段仅管理员可见;本屏本来只有 administrator 进得来,这条是双保险
        if ($user->role !== Roles::ADMINISTRATOR) {
            $groups = $this->withoutCode($groups);
        }

        // 图片字段的预览图:值已经在库里(既有规范修订:选图即写),这里把附件地址补进模型供模板出图
        $groups = $this->withAttachmentPreviews($groups);

        $done = $request->input('done');
        $fieldPath = $request->input('field');
        $picker = $this->settingsPicker($request, $theme, $this->selectedAttachment($groups, $fieldPath), $fieldPath);

        return $this->view->render('theme-settings.twig', [
            'page_title' => __('主题设置'),
            'page_kind' => 'appearance theme-settings',
            'theme_slug' => $theme->slug(),
            'theme_title' => $theme->title(),
            'theme_version' => $theme->version(),
            'updated_text' => $this->updatedText($theme),
            'groups' => $groups,
            'settings_action' => $this->url('appearance/settings/save'),
            'settings_url' => $this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug()),
            'reset_action' => $this->url('appearance/settings/reset'),
            'import_action' => $this->url('appearance/settings/import'),
            'export_url' => $this->url('appearance/settings/export') . '?theme=' . rawurlencode($theme->slug()),
            'notice' => $noticeError === '' ? match ($done) {
                'saved' => __('设置已保存。'),
                'reset' => __('已重置为默认值。'),
                'reset-group' => __('这一组的设置已恢复默认。'),
                'imported' => __('设置已导入。'),
                'picked' => __('图片已选好，这里就是它在页面上的样子。'),
                'uploaded' => __('图片已上传，这里就是它在页面上的样子。'),
                'upload-failed' => __('没传上，重新选一个文件再试。'),
                'pick-failed' => __('那张图片不在媒体库里，重新选一张。'),
                'stale' => __(WebAuth::STALE),
                default => '',
            } : '',
            'notice_error' => $noticeError,
            'picker' => $picker,
            'dialog_open' => $picker !== null,
        ], $menu);
    }

    /**
     * 选图对话框里高亮哪一张:该字段当前存的值(没设就是 0)。
     *
     * @param list<array<string, mixed>> $groups
     */
    private function selectedAttachment(array $groups, string $path): int
    {
        if ($path === '') {
            return 0;
        }

        foreach ($groups as $group) {
            foreach ($group['fields'] as $field) {
                if (($field['path'] ?? '') === $path) {
                    return (int) ($field['value'] ?? 0);
                }
            }
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    private function withoutCode(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['fields'] = array_values(array_filter(
                (array) $group['fields'],
                static fn (array $field): bool => ($field['type'] ?? '') !== 'code'
            ));
            $group['count'] = count($group['fields']);
        }

        unset($group);

        return $groups;
    }

    /**
     * 给每个 image 字段补上预览图地址:模板据此在那个 96×60 的盒子里出图(2026-10-16 修)。
     *
     * 地址走与前台同一条取图通道(附件本体 id → media.byPostId → imageData),
     * 所以"设置页看到的"就是"页面上会出的";盘上没文件时留空,模板落回占位框。
     *
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    private function withAttachmentPreviews(array $groups): array
    {
        foreach ($groups as &$group) {
            foreach ($group['fields'] as &$field) {
                if (($field['type'] ?? '') !== 'image') {
                    continue;
                }

                $id = (int) ($field['value'] ?? 0);
                $record = $id > 0 ? $this->media->byPostId($id) : null;

                if (is_array($record)) {
                    $field['attachment'] = ($field['attachment'] ?? []) + ['id' => $id];
                    $field['attachment']['preview'] = $this->previewOf($record);
                }
            }

            unset($field);
        }

        unset($group);

        return $groups;
    }

    /**
     * 设置页里的选图对话框(既有规范盒同一件,只是回跳目标换成设置页)。
     *
     * @return array<string, mixed>|null
     */
    private function settingsPicker(Request $request, Theme $theme, int $selected, string $fieldPath): ?array
    {
        if ($request->input('pick') !== '1' || $fieldPath === '') {
            return null;
        }

        $keyword = $request->input('q');
        $page = max(1, (int) $request->input('page', '1'));
        $list = $this->media->browse(['q' => $keyword, 'tab' => 'image'], $page, self::PICKER_PER_PAGE);
        $total = (int) $list['total'];
        $pages = max(1, (int) ceil($total / self::PICKER_PER_PAGE));
        $base = $this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug()) . '&pick=1&field=' . rawurlencode($fieldPath);
        $links = [];

        for ($number = 1; $number <= min($pages, 5); $number++) {
            $links[] = [
                'page' => $number,
                'url' => $base . '&page=' . $number . ($keyword === '' ? '' : '&q=' . rawurlencode($keyword)),
                'active' => $number === $page,
            ];
        }

        return [
            'action' => $this->url('appearance/settings'),
            'q' => $keyword,
            'items' => array_map(fn (array $item): array => [
                'id' => (int) $item['post_id'],
                'kind' => strtoupper((string) pathinfo((string) $item['file_name'], PATHINFO_EXTENSION)),
                'name' => (string) $item['file_name'],
                'thumb' => $this->previewOf($item),
                'dims' => $item['width'] === null ? '' : $item['width'] . '×' . $item['height'],
                'size_text' => round((int) $item['file_size'] / 1024) . ' KB',
            ], $list['items']),
            'info' => __('第 %s–%s 项，共 %s 项', 'molin', ($page - 1) * self::PICKER_PER_PAGE + 1, min($total, $page * self::PICKER_PER_PAGE), $total),
            'pages' => $links,
            'set_url' => $this->url('appearance/settings/image'),
            'upload_url' => $this->url('appearance/settings/upload'),
            'cancel_url' => $this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug()),
            'theme' => $theme->slug(),
            'field' => $fieldPath,
            'selected' => $selected,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function previewOf(array $record): string
    {
        foreach (['thumb', 'medium', 'large'] as $name) {
            $file = (string) ($record['sizes'][$name]['file'] ?? '');

            if ($file !== '' && $this->store->exists($file)) {
                return $this->store->url($file);
            }
        }

        $path = (string) ($record['file_path'] ?? '');

        return $path === '' || !$this->store->exists($path) ? '' : $this->store->url($path);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function settingsSave(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'setting.manage')) {
            return $this->forbidden($menu, ['setting.manage'], __('改主题设置'));
        }

        if (!$this->tokenOk($request)) {
            return $this->settingsScreen($request, $user, $menu, [], null, __(WebAuth::STALE));
        }

        $theme = $this->target($request);

        if ($theme === null) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        $raw = $request->inputs()['settings'] ?? [];
        $input = is_array($raw) ? $raw : [];
        $result = $this->settings->save($theme, $input);

        if (!$result['ok']) {
            Audit::record('theme.setting.save', $theme->slug(), false, $request, 'user:' . (string) Auth::id());

            // 校验失败:整体不落库,把原样表单回填,错在哪一行由模板画暖赭
            return $this->settingsScreen($request, $user, $menu, $result['errors'], $input);
        }

        Audit::record('theme.setting.save', $theme->slug(), true, $request, 'user:' . (string) Auth::id());

        return Response::redirect($this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug()) . '&done=saved');
    }

    /**
     * 重置:带 group 只回滚那一组,不带就是整只主题。
     * group 只认主题里真声明过的组键 —— 白名单在 definitions 里,通不过就按整体重置走,不报错。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function settingsReset(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'setting.manage')) {
            return $this->forbidden($menu, ['setting.manage'], __('重置主题设置'));
        }

        if (!$this->tokenOk($request)) {
            return Response::redirect($this->url('appearance/settings') . '?done=stale');
        }

        $theme = $this->target($request);

        if ($theme === null) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        $group = trim($request->input('group'));

        if (preg_match('/\A[a-z0-9_]+\z/', $group) !== 1 || !isset($this->settings->definitions($theme)[$group])) {
            $group = '';
        }

        $this->settings->reset($theme, $group === '' ? null : $group);
        Audit::record('theme.setting.reset', $theme->slug() . ($group === '' ? '' : '#' . $group), true, $request, 'user:' . (string) Auth::id());

        return Response::redirect(
            $this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug())
            . ($group === '' ? '&done=reset' : '&done=reset-group')
        );
    }

    /**
     * 导出为 settings.json 下载;程序版权位是站点选项,不随主题走。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function settingsExport(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'setting.manage')) {
            return $this->forbidden($menu, ['setting.manage'], __('导出主题设置'));
        }

        $theme = $this->target($request);

        if ($theme === null) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        return Response::text($this->settings->export($theme))->withHeaders([
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $theme->slug() . '-settings.json"',
        ]);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function settingsImport(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'setting.manage')) {
            return $this->forbidden($menu, ['setting.manage'], __('导入主题设置'));
        }

        if (!$this->tokenOk($request)) {
            return $this->settingsScreen($request, $user, $menu, [], null, __(WebAuth::STALE));
        }

        $theme = $this->target($request);

        if ($theme === null) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        $files = $request->uploads('file');
        $file = $files[0] ?? null;

        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || $file['tmp_name'] === '') {
            return $this->settingsScreen($request, $user, $menu, [], null, __('没有收到 JSON 文件，再试一次。'));
        }

        if ($file['size'] > 256 * 1024) {
            return $this->settingsScreen($request, $user, $menu, [], null, __('设置文件不该这么大，检查一下是不是选错了文件。'));
        }

        $json = (string) file_get_contents($file['tmp_name']);
        $result = $this->settings->import($theme, $json);

        if (!$result['ok']) {
            $errors = $result['errors'];
            $pageError = $errors['_file'] ?? '';
            unset($errors['_file']);

            return $this->settingsScreen($request, $user, $menu, $errors, null, $pageError);
        }

        Audit::record('theme.setting.import', $theme->slug(), true, $request, 'user:' . (string) Auth::id());

        return Response::redirect($this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug()) . '&done=imported');
    }

    /**
     * 选图回跳:只把附件 id 卡在地址上,落库等「保存」(既有规范"点保存才落库")。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function settingsImage(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'setting.manage')) {
            return $this->forbidden($menu, ['setting.manage'], __('选择图片'));
        }

        if (!$this->tokenOk($request)) {
            return Response::redirect($this->url('appearance'));
        }

        $theme = $this->target($request);

        if ($theme === null) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        $back = $this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug());
        $fieldPath = trim($request->input('field'));
        $media = max(0, (int) $request->input('media'));

        if ($fieldPath === '' || !$this->isImageField($theme, $fieldPath) || $media === 0 || !$this->settings->imageExists($media)) {
            return Response::redirect($back . '&done=pick-failed');
        }

        // 选完立刻写库:只写这一个字段,其余字段一字不动(2026-10-16 修,见 ThemeSettings::applyImage)
        $result = $this->settings->applyImage($theme, $fieldPath, $media);

        if (!$result['ok']) {
            Audit::record('theme.setting.save', $theme->slug(), false, $request, 'user:' . (string) Auth::id());

            return Response::redirect($back . '&done=pick-failed');
        }

        Audit::record('theme.setting.save', $theme->slug(), true, $request, 'user:' . (string) Auth::id());

        return Response::redirect($back . '&done=picked&field=' . rawurlencode($fieldPath));
    }

    /**
     * 选图对话框里的「上传」:传一张新图 → 收进媒体库 → 直接选给这个字段(2026-10-16 加)。
     *
     * 表单带 data-progress,前端走 XHR、进度归 .upbar(与媒体库上传同一套);
     * 成功即 303 回设置页 —— 前端追 responseURL,于是落在设置页,那里就能看到预览图。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    private function settingsUpload(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'setting.manage')) {
            return $this->forbidden($menu, ['setting.manage'], __('上传图片'));
        }

        if (!$this->tokenOk($request)) {
            return Response::redirect($this->url('appearance'));
        }

        $theme = $this->target($request);

        if ($theme === null) {
            return Response::redirect($this->url('appearance') . '?done=unknown');
        }

        $back = $this->url('appearance/settings') . '?theme=' . rawurlencode($theme->slug());
        $fieldPath = trim($request->input('field'));
        $files = $request->uploads('image');

        if ($fieldPath === '' || !$this->isImageField($theme, $fieldPath) || $files === []) {
            return Response::redirect($back . '&done=upload-failed');
        }

        try {
            // 媒体上传器只要 Store / MediaRepository / Options —— 这三样本类都在手上,
            // 就地拼一个,不给构造器再加一根只在"选图上传"用得上的线(与 UpdateController 同例)
            $uploader = new MediaUploader($this->store, $this->media, $this->options);
            $attachment = $uploader->upload(Upload::fromRequest($files[0]), $user->id);
        } catch (Throwable) {
            // 校验不过 / 没落盘:一律回同一句人话(媒体库里那套细分文案是给批量上传看的,这里只有一张)
            return Response::redirect($back . '&done=upload-failed');
        }

        $result = $this->settings->applyImage($theme, $fieldPath, (int) ($attachment['post_id'] ?? 0));

        Audit::record('theme.setting.save', $theme->slug(), $result['ok'], $request, 'user:' . (string) Auth::id());

        return Response::redirect($back . ($result['ok'] ? '&done=uploaded&field=' . rawurlencode($fieldPath) : '&done=upload-failed'));
    }

    private function isImageField(Theme $theme, string $path): bool
    {
        $parts = explode('.', $path);

        if (count($parts) !== 2) {
            return false;
        }

        $groups = $this->settings->definitions($theme);

        return ($groups[$parts[0]]['fields'][$parts[1]]['type'] ?? '') === 'image';
    }

    /**
     * 「上次保存：今天 09:40」——今天只说时刻,隔天补上日期(时间戳全站按 UTC 生成)。
     */
    private function updatedText(Theme $theme): string
    {
        $updated = $this->settings->updatedAt($theme);

        if ($updated === null) {
            return __('还没保存过');
        }

        if (substr($updated, 0, 10) !== gmdate('Y-m-d')) {
            return substr($updated, 0, 16);
        }

        $stamp = strtotime($updated . ' UTC');

        return __('今天 %s', 'molin', date('H:i', $stamp === false ? time() : $stamp));
    }

    /**
     * 目标主题:?theme= 指定,没给就是当前启用那只(既有规范「正在配置」)。
     */
    private function target(Request $request): ?Theme
    {
        $slug = trim($request->input('theme'));

        if ($slug === '') {
            $slug = (string) $this->options->get('active_theme', 'molin-default');
        }

        try {
            return Theme::load($this->trimRoot($this->themesRoot), $slug);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 预览入口:前台首页带预览参数;只有 theme.switch 的人进得去,
     * 换渲染器在 index.php 里做,这里只负责给出地址。
     */
    private function previewUrl(string $slug): string
    {
        $home = rtrim((string) $this->options->get('site_url', ''), '/');

        return $home . '/?molin_theme_preview=' . rawurlencode($slug);
    }

    private function trimRoot(string $dir): string
    {
        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    private function url(string $path): string
    {
        return $this->router->path('admin', ['path' => $path]);
    }
}
