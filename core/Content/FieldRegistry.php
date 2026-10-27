<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

use Molin\Exception\ConfigException;
use Molin\Hook\HookBus;

/**
 * 自定义字段登记表(既有规范数据契约 +既有规范界面契约,两段式)。
 *
 * 插件在 system/init 里先 register_post_meta() 声明数据契约、再 register_post_field() 声明界面契约;
 * 后台编辑屏由收集点(PostsController::collectFields)从本表取出字段定义(§3、§5 落位)。
 * 值仍只有 postmeta 一个权威来源(MetaStore):本表只回答"有哪些字段、长什么控件、摆在哪、没有值时用什么默认"。
 *
 * 与 PostTypeRegistry / TaxonomyRegistry 同一副骨架:单例、reset() 给测试用、归属走
 * HookBus::currentPlugin()、坏声明一律 ConfigException。
 *
 * @phpstan-type FieldMeta array<string, mixed>
 * @phpstan-type FieldUi array<string, mixed>
 * @phpstan-type FieldRecord array{meta: FieldMeta, ui: FieldUi|null, owner: string|null}
 */
final class FieldRegistry
{
    /** 控件词表,取自既有规范(14 种);词表外 → 拒绝装载 */
    public const CONTROLS = [
        'text', 'textarea', 'bool', 'select', 'radio', 'color', 'range',
        'number', 'image', 'file', 'url', 'code', 'group', 'repeater',
    ];

    /** 落位:side=右栏 / main=正文下 / panel=独立面板 */
    public const POSITIONS = ['side', 'main', 'panel'];

    /** 数据类型 → 默认控件(既有规范默认推导表) */
    private const CONTROL_BY_TYPE = [
        'string'  => 'text',
        'integer' => 'number',
        'number'  => 'number',
        'boolean' => 'bool',
        'array'   => 'repeater',
        'object'  => 'group',
    ];

    /** 数据类型词表 */
    private const TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object'];

    /** 键名 {插件名}_{字段}:插件名取既有规范的 name 口径,字段名宽松到字母数字下划线 */
    private const KEY_PATTERN = '#\A[a-z][a-z0-9-]*_[A-Za-z0-9_]+\z#';

    private static ?self $instance = null;

    /**
     * 类型 => 键 => 记录
     *
     * @var array<string, array<string, FieldRecord>>
     */
    private array $fields = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * 清空登记。测试用,不是运行时接口。
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 数据契约登记(register_post_meta,既有规范/既有规范)。
     *
     * 同键 + 同声明 + 同归属的重复登记视为幂等(插件入口可能被重复拉起);同键不同声明则报错。
     *
     * @param FieldMeta $meta type / single / default / show_in_rest
     */
    public function registerMeta(string $type, string $key, array $meta): void
    {
        $owner = HookBus::instance()->currentPlugin();
        self::assertKey($key, $owner);

        $normalized = self::normalizeMeta($type, $key, $meta);
        $existing = $this->fields[$type][$key] ?? null;

        if ($existing !== null) {
            if ($existing['meta'] === $normalized && $existing['owner'] === $owner) {
                return;
            }

            $by = $existing['owner'] ?? '内核';

            throw new ConfigException("字段「{$key}」(类型「{$type}」)已被 {$by} 登记,不能重复注册");
        }

        $this->fields[$type][$key] = ['meta' => $normalized, 'ui' => null, 'owner' => $owner];
    }

    /**
     * 界面契约登记(register_post_field,既有规范)。数据契约必须先于界面契约,否则拒绝。
     *
     * 同声明 + 同归属的重复登记幂等;同键不同声明则报错。
     *
     * @param FieldUi $ui label / description / control / control_args / position / priority / readonly / capability / condition
     */
    public function registerField(string $type, string $key, array $ui): void
    {
        $owner = HookBus::instance()->currentPlugin();
        self::assertKey($key, $owner);

        $existing = $this->fields[$type][$key] ?? null;

        if ($existing === null) {
            throw new ConfigException(
                "字段「{$key}」(类型「{$type}」)未先登记 register_post_meta,数据契约必须先于界面契约"
            );
        }

        $normalized = self::normalizeUi($type, $key, $ui, $existing['meta']);

        if ($existing['ui'] !== null) {
            if ($existing['ui'] === $normalized) {
                return;
            }

            $by = $existing['owner'] ?? '内核';

            throw new ConfigException("字段「{$key}」(类型「{$type}」)的界面契约已被 {$by} 登记,不能重复注册");
        }

        $this->fields[$type][$key]['ui'] = $normalized;
    }

    /**
     * 某类型的全部字段(键 => 记录),按 priority 升序(同值按键名兜底,保证确定性)。
     *
     * @return array<string, FieldRecord>
     */
    public function fieldsFor(string $type): array
    {
        $fields = $this->fields[$type] ?? [];
        $keys = array_keys($fields);

        usort($keys, static function (string $a, string $b) use ($fields): int {
            $cmp = self::priorityOf($fields[$a]) <=> self::priorityOf($fields[$b]);

            return $cmp !== 0 ? $cmp : strcmp($a, $b);
        });

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $fields[$key];
        }

        return $out;
    }

    /**
     * 某类型里有界面契约的字段(键 => ui),按 priority 升序 —— 收集点(§3、§5)直接吃这份。
     *
     * @return array<string, FieldUi>
     */
    public function uiFor(string $type): array
    {
        $out = [];

        foreach ($this->fieldsFor($type) as $key => $record) {
            if ($record['ui'] !== null) {
                $out[$key] = $record['ui'];
            }
        }

        return $out;
    }

    /**
     * @return FieldRecord|null
     */
    public function get(string $type, string $key): ?array
    {
        return $this->fields[$type][$key] ?? null;
    }

    public function has(string $type, string $key): bool
    {
        return isset($this->fields[$type][$key]);
    }

    /**
     * 摊平全表:类型/键 => 记录(带 type / key)。
     *
     * @return array<string, array{type: string, key: string, meta: FieldMeta, ui: FieldUi|null, owner: string|null}>
     */
    public function all(): array
    {
        $out = [];

        foreach ($this->fields as $type => $fields) {
            foreach ($fields as $key => $record) {
                $out[$type . '/' . $key] = [
                    'type'  => $type,
                    'key'   => $key,
                    'meta'  => $record['meta'],
                    'ui'    => $record['ui'],
                    'owner' => $record['owner'],
                ];
            }
        }

        return $out;
    }

    /**
     * 取某内容的字段值:没写过就用数据契约声明的 default(既有规范)。
     *
     * 只认已登记的键(未登记给 null)—— 与"表单只接受已注册键"同一口径(§8)。
     * 结构值按 MetaStore 的存法(json 字符串)解码回 array;标量按 type 归一。
     */
    public function value(MetaStore $store, int $postId, string $type, string $key): mixed
    {
        $field = $this->get($type, $key);

        if ($field === null) {
            return null;
        }

        $raw = $store->get($postId, $key);

        if ($raw === null) {
            return $field['meta']['default'] ?? null;
        }

        return self::cast($raw, (string) ($field['meta']['type'] ?? 'string'));
    }

    /**
     * 数据契约归一与校验:type 在既有规范词表内、必须带 default。
     *
     * @param FieldMeta $meta
     * @return FieldMeta
     */
    private static function normalizeMeta(string $type, string $key, array $meta): array
    {
        $kind = (string) ($meta['type'] ?? 'string');

        if (!in_array($kind, self::TYPES, true)) {
            throw new ConfigException(
                "字段「{$key}」(类型「{$type}」)的 type「{$kind}」不在既有规范词表内(string|integer|number|boolean|array|object)"
            );
        }

        if (!array_key_exists('default', $meta)) {
            throw new ConfigException(
                "字段「{$key}」(类型「{$type}」)缺少 default ——既有规范要求每个字段都带默认值"
            );
        }

        return $meta + ['type' => $kind, 'single' => true, 'show_in_rest' => false];
    }

    /**
     * 界面契约归一与校验:label 必填、control 在既有规范词表内(缺省按 type 推导)、落位归一。
     *
     * @param FieldUi $ui
     * @param FieldMeta $meta
     * @return FieldUi
     */
    private static function normalizeUi(string $type, string $key, array $ui, array $meta): array
    {
        $label = trim((string) ($ui['label'] ?? ''));

        if ($label === '') {
            throw new ConfigException("字段「{$key}」(类型「{$type}」)的界面契约缺少 label");
        }

        $control = trim((string) ($ui['control'] ?? ''));

        if ($control === '') {
            $control = self::CONTROL_BY_TYPE[(string) ($meta['type'] ?? 'string')] ?? 'text';
        }

        self::assertControl($key, $control);

        $controlArgs = is_array($ui['control_args'] ?? null) ? $ui['control_args'] : [];
        self::assertNestedControls($key, $controlArgs);

        $position = (string) ($ui['position'] ?? 'side');

        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'side';
        }

        return [
            'label'        => $label,
            'description'  => (string) ($ui['description'] ?? ''),
            'control'      => $control,
            'control_args' => $controlArgs,
            'position'     => $position,
            'priority'     => (int) ($ui['priority'] ?? 10),
            'readonly'     => (bool) ($ui['readonly'] ?? false),
            'capability'   => isset($ui['capability']) ? (string) $ui['capability'] : null,
            'condition'    => is_array($ui['condition'] ?? null) ? $ui['condition'] : null,
        ];
    }

    /**
     * group / repeater 的 `control_args.fields` 是嵌套字段定义,控件名同样受词表约束。
     *
     * @param FieldUi $controlArgs
     */
    private static function assertNestedControls(string $key, array $controlArgs): void
    {
        $fields = $controlArgs['fields'] ?? null;

        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as $nested) {
            if (!is_array($nested)) {
                continue;
            }

            $control = $nested['control'] ?? null;

            if (is_string($control) && $control !== '') {
                self::assertControl($key, $control);
            }

            self::assertNestedControls($key, $nested);
        }
    }

    private static function assertControl(string $key, string $control): void
    {
        if (!in_array($control, self::CONTROLS, true)) {
            throw new ConfigException(
                "字段「{$key}」的 control「{$control}」不在既有规范词表内,拒绝装载"
            );
        }
    }

    /**
     * 键名必须匹配 {插件名}_{字段};已知登记方时前缀还须与插件名一致。
     */
    private static function assertKey(string $key, ?string $owner): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ConfigException(
                "字段键名「{$key}」不合 {插件名}_{字段} 规范"
            );
        }

        if ($owner !== null && !str_starts_with($key, $owner . '_')) {
            throw new ConfigException(
                "字段键名「{$key}」必须以登记方插件名「{$owner}_」为前缀"
            );
        }
    }

    /**
     * @param FieldRecord $record
     */
    private static function priorityOf(array $record): int
    {
        $ui = $record['ui'];

        return $ui === null ? 10 : (int) ($ui['priority'] ?? 10);
    }

    /**
     * 存的是字符串:结构值存 JSON,这里按声明类型解回。
     */
    private static function cast(string $raw, string $type): mixed
    {
        return match ($type) {
            'integer'         => (int) $raw,
            'number'          => (float) $raw,
            'boolean'         => $raw !== '' && $raw !== '0',
            'array', 'object' => self::decodeJson($raw, $type),
            default           => $raw,
        };
    }

    private static function decodeJson(string $raw, string $type): mixed
    {
        if ($raw === '') {
            return $type === 'array' ? [] : null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
