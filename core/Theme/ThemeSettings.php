<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Theme;

use Molin\Database\Database;
use Molin\Exception\MolinException;
use Molin\Option\Options;

/**
 * 主题设置器:声明式定义 → 后台自动渲染 → 逐类型校验 →
 * 存 `themes.settings` → `theme_setting()` 取值(用户值 → 主题 default → 兜底)。
 *
 * 父主题的设置由子主题同名键覆盖;字段声明 `store: option` 时值写站点选项、
 * 不落 themes.settings。
 */
final class ThemeSettings
{
    private static ?self $instance = null;

    /** @var array<string, array<string, array<string, mixed>>> slug => 解析后的值(nested) */
    private array $memo = [];

    private ?Theme $active = null;

    public function __construct(
        private readonly Database $db,
        private readonly Options $options,
        private readonly string $themesRoot
    ) {
    }

    public static function boot(self $settings): void
    {
        self::$instance = $settings;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new MolinException('主题设置器还没启动');
        }

        return self::$instance;
    }

    /** 测试用:清掉全局实例,免得两条用例互相串味。 */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function activeTheme(): Theme
    {
        if ($this->active === null) {
            $slug = trim((string) $this->options->get('active_theme', 'molin-default'));

            $this->active = Theme::load($this->themesRoot, $slug !== '' ? $slug : 'molin-default');
        }

        return $this->active;
    }

    /**
     * 合并后的设置声明:根主题在前、子主题同名覆盖并将新键追加。
     *
     * @return array<string, array{label:string, desc:string, fields:array<string, array<string, mixed>>}>
     */
    public function definitions(Theme $theme): array
    {
        $chain = [];

        for ($walk = $theme; $walk !== null; $walk = $walk->parent()) {
            $chain[] = $walk;
        }

        $out = [];

        foreach (array_reverse($chain) as $level) {
            foreach ($level->settingsDefinition() as $groupKey => $group) {
                if (!is_array($group)) {
                    continue;
                }

                $groupKey = (string) $groupKey;

                if (!isset($out[$groupKey])) {
                    $out[$groupKey] = ['label' => $groupKey, 'desc' => '', 'fields' => []];
                }

                if (isset($group['label']) && is_string($group['label'])) {
                    $out[$groupKey]['label'] = $group['label'];
                }

                if (isset($group['desc']) && is_string($group['desc'])) {
                    $out[$groupKey]['desc'] = $group['desc'];
                }

                $fields = $group['fields'] ?? [];

                if (!is_array($fields)) {
                    continue;
                }

                foreach ($fields as $fieldKey => $field) {
                    if (is_array($field)) {
                        /** @var array<string, mixed> $field */
                        $out[$groupKey]['fields'][(string) $fieldKey] = $field;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * 当前取值(用户值 → default 回退后的全量),nested: [组 => [键 => 值]]。
     *
     * @return array<string, array<string, mixed>>
     */
    public function values(Theme $theme): array
    {
        if (isset($this->memo[$theme->slug()])) {
            return $this->memo[$theme->slug()];
        }

        $stored = $this->stored($theme);
        $values = [];

        foreach ($this->definitions($theme) as $groupKey => $group) {
            foreach ($group['fields'] as $fieldKey => $field) {
                $values[$groupKey][$fieldKey] = $this->resolveValue($fieldKey, $field, $stored[$groupKey][$fieldKey] ?? null);
            }
        }

        return $this->memo[$theme->slug()] = $values;
    }

    /**
     * 取一个设置(`colors.ink` 点路径),未设时回退主题 default,再回退调用方兜底。
     */
    public function get(string $path, mixed $fallback = null, ?Theme $theme = null): mixed
    {
        $parts = explode('.', $path);

        if (count($parts) !== 2) {
            return $fallback;
        }

        $values = $this->values($theme ?? $this->activeTheme());

        return $values[$parts[0]][$parts[1]] ?? $fallback;
    }

    /**
     * 上一次保存时间(savebar 那一行用)。
     */
    public function updatedAt(Theme $theme): ?string
    {
        $row = $this->db->selectOne(
            'SELECT updated_at FROM ' . $this->db->table('themes') . ' WHERE slug = :slug',
            ['slug' => $theme->slug()]
        );

        return $row === null ? null : ($row['updated_at'] === null ? null : (string) $row['updated_at']);
    }

    /**
     * 启用某主题:表为权威、options 为派生缓存(2026-09-30 拍板)。
     * 激活不写 updated_at —— 那个字段是"上次保存设置"的口径,不能让它被切换动作搅浑。
     */
    public function activate(string $slug): void
    {
        $table = $this->db->table('themes');

        $this->db->execute('UPDATE ' . $table . ' SET active = 0');
        $this->db->execute(
            'INSERT INTO ' . $table . ' (slug, version, active, settings, updated_at) VALUES (:slug, :version, 1, NULL, NULL)'
            . ' ON DUPLICATE KEY UPDATE active = 1, version = VALUES(version)',
            ['slug' => $slug, 'version' => Theme::load($this->themesRoot, $slug)->version()]
        );

        $this->options->set('active_theme', $slug);
    }

    /**
     * 附件是不是媒体库里还在的一张图(coerce 与选图回跳共用同一把尺)。
     */
    public function imageExists(int $id): bool
    {
        return $this->attachmentInfo($id) !== null;
    }

    /**
     * 附件展示名与体量;不在库里返回 null。
     *
     * @return array{name:string, size_text:string}|null
     */
    public function imageMeta(int $id): ?array
    {
        return $this->attachmentInfo($id);
    }

    /**
     * 校验并保存任一字段不过就整体不落库,回字段级错误。
     *
     * @param array<string, mixed> $input 表单原样(nested: settings[组][键]);缺失的字段保持原值
     * @return array{ok:bool, errors:array<string, string>}
     */
    public function save(Theme $theme, array $input): array
    {
        $definitions = $this->definitions($theme);
        $errors = [];
        $accepted = [];

        foreach ($definitions as $groupKey => $group) {
            foreach ($group['fields'] as $fieldKey => $field) {
                if (!array_key_exists($fieldKey, (array) ($input[$groupKey] ?? []))) {
                    continue;
                }

                $raw = $input[$groupKey][$fieldKey];
                $value = $this->coerce($field, $raw, $error);

                if ($error !== null) {
                    $errors[$groupKey . '.' . $fieldKey] = $error;

                    continue;
                }

                $accepted[$groupKey][$fieldKey] = $value;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $stored = $this->stored($theme);

        foreach ($accepted as $groupKey => $fields) {
            foreach ($fields as $fieldKey => $value) {
                if (($definitions[$groupKey]['fields'][$fieldKey]['store'] ?? null) === 'option') {
                    $this->options->set($fieldKey, is_bool($value) ? ($value ? '1' : '0') : (string) $value);

                    continue;
                }

                $stored[$groupKey][$fieldKey] = $value;
            }
        }

        $this->persist($theme, $stored);
        $this->afterChange($theme);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * 选图即写库(2026-10-16 修):只写命中的那一个字段,其余字段一字不动。
     *
     * 原先是"选完先盖在模型上、点「保存」才写",但那个流程有个暗坑:
     * 连着选两张图时,第二次回跳只带最后一张的 picked,前一张的选择当场丢掉 ——
     * 作者 2026-10-16 报的"选了横幅还是不显示"就是这么来的(横幅先选、头像后选)。
     * 这里借用 save() 的同一套校验与 afterChange(缓存失效),不另开一条写库的口子。
     *
     * @return array{ok:bool, errors:array<string, string>}
     */
    public function applyImage(Theme $theme, string $path, int $attachmentId): array
    {
        $parts = explode('.', $path, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return ['ok' => false, 'errors' => ['_field' => __('要设置的字段不存在。')]];
        }

        return $this->save($theme, [$parts[0] => [$parts[1] => $attachmentId]]);
    }

    /**
     * 清空用户值:取值回落到主题 default。
     * 给了 $group(且该组在声明里)时只清这一组,其余组的用户值一字不动。
     */
    public function reset(Theme $theme, ?string $group = null): void
    {
        $definitions = $this->definitions($theme);
        $group = $group !== null && isset($definitions[$group]) ? $group : null;
        $scope = $group === null ? $definitions : [$group => $definitions[$group]];

        foreach ($scope as $definition) {
            foreach ($definition['fields'] as $fieldKey => $field) {
                if (($field['store'] ?? null) === 'option') {
                    $this->options->set($fieldKey, ($field['default'] ?? null) === true ? '1' : '0');
                }
            }
        }

        if ($group === null) {
            $this->persist($theme, null);
        } else {
            $stored = $this->stored($theme);
            unset($stored[$group]);

            $this->persist($theme, $stored);
        }

        $this->afterChange($theme);
    }

    /**
     * 导出为 JSON(全量当前值,结构同 settings 的值部分;既有规范)。程序版权位是站点选项,不随主题走。
     */
    public function export(Theme $theme): string
    {
        $values = $this->values($theme);
        $out = [];

        foreach ($this->definitions($theme) as $groupKey => $group) {
            foreach ($group['fields'] as $fieldKey => $field) {
                if (($field['store'] ?? null) === 'option') {
                    continue;
                }

                $out[$groupKey][$fieldKey] = $values[$groupKey][$fieldKey];
            }
        }

        return (string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 导入 JSON:先按声明校验,通过才落库。
     *
     * @return array{ok:bool, errors:array<string, string>}
     */
    public function import(Theme $theme, string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'errors' => ['_file' => __('不是合法的 JSON 文件。')]];
        }

        return $this->save($theme, $decoded);
    }

    /**
     * 渲染模型:分组 + 字段 + 当前值 + 错误,后台模板只负责画。
     *
     * @param array<string, string> $errors 字段级错误('组.键' => 文案)
     * @param array<string, mixed>|null $overlay 表单回填(校验失败时用;null 取库值)
     * @return list<array<string, mixed>>
     */
    public function present(Theme $theme, array $errors = [], ?array $overlay = null): array
    {
        $values = $this->values($theme);
        $groups = [];

        foreach ($this->definitions($theme) as $groupKey => $group) {
            $fields = [];

            foreach ($group['fields'] as $fieldKey => $field) {
                $path = $groupKey . '.' . $fieldKey;
                $value = $overlay === null
                    ? $values[$groupKey][$fieldKey]
                    : ($overlay[$groupKey][$fieldKey] ?? $values[$groupKey][$fieldKey]);

                $fields[] = $this->fieldModel($path, $field, $value, $errors[$path] ?? null);
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $group['label'],
                'desc' => $group['desc'],
                'count' => count($fields),
                'fields' => $fields,
            ];
        }

        return $groups;
    }

    // ---- 内部 ----

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stored(Theme $theme): array
    {
        $row = $this->db->selectOne(
            'SELECT settings FROM ' . $this->db->table('themes') . ' WHERE slug = :slug',
            ['slug' => $theme->slug()]
        );

        $settings = $row['settings'] ?? null;

        if (!is_string($settings) || $settings === '') {
            return [];
        }

        $decoded = json_decode($settings, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $field
     */
    private function resolveValue(string $fieldKey, array $field, mixed $stored): mixed
    {
        if (($field['store'] ?? null) === 'option') {
            $default = $field['default'] ?? null;
            $option = $this->options->get($fieldKey, null);

            if ($option === null) {
                return $default;
            }

            return ($field['type'] ?? '') === 'bool' ? $option === '1' : $option;
        }

        return $stored ?? ($field['default'] ?? null);
    }

    /**
     * 逐类型校验与归一(既有规范;错误文案字段级)。
     *
     * @param array<string, mixed> $field
     */
    private function coerce(array $field, mixed $raw, ?string &$error): mixed
    {
        $error = null;
        $type = (string) ($field['type'] ?? 'text');

        switch ($type) {
            case 'bool':
                return $raw === true || $raw === '1' || $raw === 1;

            case 'color':
                $value = trim((string) (is_scalar($raw) ? $raw : ''));

                if (preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) !== 1) {
                    $error = __('颜色格式不对：需 # 加六位十六进制，例如 %s。', 'molin', (string) ($field['default'] ?? '#000000'));

                    return null;
                }

                return $value;

            case 'select':
            case 'radio':
                $value = is_scalar($raw) ? (string) $raw : '';
                $options = is_array($field['options'] ?? null) ? $field['options'] : [];

                if (!array_key_exists($value, $options)) {
                    $error = __('不在主题声明的可选项内。');

                    return null;
                }

                return $value;

            case 'range':
            case 'number':
                $value = is_scalar($raw) ? trim((string) $raw) : '';

                if (!is_numeric($value)) {
                    $error = __('要填一个数字。');

                    return null;
                }

                $min = $field['min'] ?? null;
                $max = $field['max'] ?? null;
                $number = (float) $value;
                $isInt = $type === 'range' || floor($number) === $number;

                if (($min !== null && $number < (float) $min) || ($max !== null && $number > (float) $max)) {
                    $error = __('数值超出范围：需在 %s–%s 之间。', 'molin', (string) $min, (string) $max);

                    return null;
                }

                return $isInt ? (int) $number : $number;

            case 'image':
                $value = is_scalar($raw) ? trim((string) $raw) : '';

                if ($value === '' || $value === '0') {
                    return 0;
                }

                if (!ctype_digit($value) || $this->attachmentInfo((int) $value) === null) {
                    $error = __('选择的附件不存在，请重新从媒体库选一张图。');

                    return null;
                }

                return (int) $value;

            case 'repeater':
                $rows = is_array($raw) ? $raw : [];
                $subFields = is_array($field['fields'] ?? null) ? $field['fields'] : [];
                $out = [];

                foreach ($rows as $row) {
                    $row = is_array($row) ? $row : [];
                    $clean = [];
                    $empty = true;

                    foreach ($subFields as $subKey => $subField) {
                        $subField = is_array($subField) ? $subField : [];
                        $cell = trim((string) ($row[$subKey] ?? ''));

                        if ($cell !== '' && ($subField['maxlength'] ?? null) !== null && mb_strlen($cell) > (int) $subField['maxlength']) {
                            $error = __('最多 %d 个字符。', 'molin', (int) $subField['maxlength']);

                            return null;
                        }

                        $clean[(string) $subKey] = $cell;

                        if ($cell !== '') {
                            $empty = false;
                        }
                    }

                    if (!$empty) {
                        $out[] = $clean;
                    }
                }

                return $out;

            case 'url':
                $value = trim((string) (is_scalar($raw) ? $raw : ''));

                // 空串合法:链接类字段大多"不填就不出这一块"
                if ($value === '') {
                    return '';
                }

                $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

                // 只认 http/https:javascript: 之类塞进来的不会变成可点的坑
                if (filter_var($value, FILTER_VALIDATE_URL) === false || ($scheme !== 'http' && $scheme !== 'https')) {
                    $error = __('请填完整的网址，例如 https://example.com', 'molin');

                    return null;
                }

                if (($field['maxlength'] ?? null) !== null && mb_strlen($value) > (int) $field['maxlength']) {
                    $error = __('最多 %d 个字符。', 'molin', (int) $field['maxlength']);

                    return null;
                }

                return $value;

            case 'code':
            case 'textarea':
            case 'text':
            default:
                $value = (string) (is_scalar($raw) ? $raw : '');

                if (($field['maxlength'] ?? null) !== null && mb_strlen($value) > (int) $field['maxlength']) {
                    $error = __('最多 %d 个字符。', 'molin', (int) $field['maxlength']);

                    return null;
                }

                return $value;
        }
    }

    /**
     * @return array{name:string, size_text:string}|null
     */
    private function attachmentInfo(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT m.file_name, m.file_size FROM ' . $this->db->table('media') . ' m'
            . ' JOIN ' . $this->db->table('posts') . ' p ON p.id = m.post_id'
            . " WHERE m.post_id = :id AND p.deleted_at IS NULL AND m.mime_type LIKE 'image/%'",
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        // 体量口径与媒体库同一把尺(既有规范/4.5 节):KB 取整、MB 一位小数
        $bytes = (int) ($row['file_size'] ?? 0);

        return [
            'name' => (string) $row['file_name'],
            'size_text' => $bytes >= 1048576
                ? number_format($bytes / 1048576, 1) . ' MB'
                : (int) round($bytes / 1024) . ' KB',
        ];
    }

    /**
     * @param array<string, array<string, mixed>>|null $stored
     */
    private function persist(Theme $theme, ?array $stored): void
    {
        $p = $this->db->prefix();
        $table = $this->db->table('themes');
        $now = gmdate('Y-m-d H:i:s');
        $json = $stored === null || $stored === [] ? null : (string) json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->execute(
            "INSERT INTO `{$p}themes` (slug, version, active, settings, updated_at) VALUES (:slug, :version, 0, :settings, :updated_at)"
            . ' ON DUPLICATE KEY UPDATE version = VALUES(version), settings = VALUES(settings), updated_at = VALUES(updated_at)',
            ['slug' => $theme->slug(), 'version' => $theme->version(), 'settings' => $json, 'updated_at' => $now]
        );
    }

    /**
     * 落库之后:记忆失效 + 广播(页面缓存将来接在 cache/invalidate/after 上)。
     */
    private function afterChange(Theme $theme): void
    {
        unset($this->memo[$theme->slug()]);

        do_action('cache/invalidate/after', 'theme/settings');

        /** @var array<string, array<string, mixed>> $values */
        $values = $this->values($theme);
        apply_filters('theme/setting/after', $values, $theme->slug());
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function fieldModel(string $path, array $field, mixed $value, ?string $error): array
    {
        $model = [
            'path' => $path,
            'name' => 'settings[' . str_replace('.', '][', $path) . ']',
            'label' => (string) ($field['label'] ?? $path),
            'type' => (string) ($field['type'] ?? 'text'),
            // 字段级小标题:声明了就在这项前面插一行组内子标题,拿来给首页那三十项分段
            'section' => $field['section'] ?? null,
            'hint' => (string) ($field['hint'] ?? ''),
            'error' => $error ?? '',
            'value' => $value,
        ];

        switch ($model['type']) {
            case 'color':
                $raw = (string) $value;
                // 输入不合法时色块退回默认色:错误态下色块不该变空白(设计稿错误态色块仍有色)
                $model['swatch'] = preg_match('/\A#[0-9a-fA-F]{6}\z/', $raw) === 1
                    ? $raw
                    : (string) ($field['default'] ?? '');

                break;

            case 'select':
            case 'radio':
                $options = [];

                foreach ((array) ($field['options'] ?? []) as $optionValue => $optionLabel) {
                    $options[] = [
                        'value' => (string) $optionValue,
                        'label' => (string) $optionLabel,
                        'selected' => (string) $optionValue === (string) $value,
                    ];
                }

                $model['options'] = $options;

                break;

            case 'range':
            case 'number':
                $model['min'] = $field['min'] ?? '';
                $model['max'] = $field['max'] ?? '';
                $model['step'] = $field['step'] ?? ($model['type'] === 'range' ? 1 : 'any');
                $model['unit'] = (string) ($field['unit'] ?? '');

                break;

            case 'image':
                $model['attachment'] = null;
                $model['value'] = 0;

                if ((int) $value > 0) {
                    $meta = $this->attachmentInfo((int) $value);

                    if ($meta !== null) {
                        $model['attachment'] = ['id' => (int) $value, 'name' => $meta['name'], 'size_text' => $meta['size_text']];
                        $model['value'] = (int) $value;
                    }
                    // 附件被删掉的陈旧值这里显成空,表单里带 0,一存即自愈
                }

                break;

            case 'repeater':
                $subFields = [];
                $rows = [];

                foreach ((array) ($field['fields'] ?? []) as $subKey => $subField) {
                    $subFields[] = ['key' => (string) $subKey, 'type' => (string) (((array) $subField)['type'] ?? 'text')];
                }

                foreach (is_array($value) ? $value : [] as $index => $row) {
                    $cells = [];

                    foreach ($subFields as $sub) {
                        $cells[] = [
                            'key' => $sub['key'],
                            'name' => 'settings[' . str_replace('.', '][', $path) . '][' . $index . '][' . $sub['key'] . ']',
                            'value' => (string) (((array) $row)[$sub['key']] ?? ''),
                        ];
                    }

                    $rows[] = ['index' => $index + 1, 'cells' => $cells];
                }

                $model['rows'] = $rows;
                $model['sub_fields'] = $subFields;

                break;

            case 'text':
            case 'url':
            case 'textarea':
            case 'code':
            default:
                $model['maxlength'] = $field['maxlength'] ?? null;
                $model['rows'] = $field['rows'] ?? null;
                $model['placeholder'] = (string) ($field['placeholder'] ?? '');
                $model['lang'] = (string) ($field['lang'] ?? '');

                break;
        }

        return $model;
    }
}
