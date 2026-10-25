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
 * 分类法登记表(《34-内容模型与CPT》第 3 节)。
 *
 * term_taxonomy.taxonomy 同样只受列宽约束(《21》第 4 节),哪些分类法算数由这张表定。
 * 与 PostTypeRegistry 分家:字段与保留名各自一套(这里多 hierarchical、少 has_archive),
 * 合在一起只会让校验互相牵制,分不清哪条规则管哪边。
 */
final class TaxonomyRegistry
{
    /** slug 口径与内容类型一致:小写字母开头,只含小写字母、数字、- 与 _ */
    public const SLUG_PATTERN = '#\A[a-z][a-z0-9_-]*\z#';

    /** 与 term_taxonomy.taxonomy 列宽一致(《21》第 4 节) */
    public const MAX_SLUG_LENGTH = 32;

    /** 内核写死的内置分类法(《34》第 3.2 节),插件不得占用 */
    public const RESERVED = ['category', 'post_tag'];

    private static ?self $instance = null;

    /** @var array<string, array{slug: string, label: string, public: bool, hierarchical: bool, object_types: list<string>, owner: string|null}> */
    private array $taxonomies = [];

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
     * 登记一个分类法(《34》第 3.1 节)。第二个参数与 WP 同款:它绑定的内容类型。
     *
     * @param list<string> $objectTypes 绑定的内容类型;两侧都可声明绑定(类型侧 taxonomies / 这里 object_types)
     * @param array<string, mixed> $args 只取本批最小集:label / public / hierarchical
     */
    public function register(string $slug, array $objectTypes = [], array $args = []): void
    {
        self::assertSlug($slug);

        if (isset($this->taxonomies[$slug])) {
            $by = $this->taxonomies[$slug]['owner'] ?? '内核';
            throw new ConfigException("分类法「{$slug}」已被 {$by} 注册,不能重复注册");
        }

        $label = trim((string) ($args['label'] ?? ''));

        if ($label === '') {
            throw new ConfigException("分类法「{$slug}」缺少 label");
        }

        $this->taxonomies[$slug] = [
            'slug'         => $slug,
            'label'        => $label,
            'public'       => (bool) ($args['public'] ?? false),
            'hierarchical' => (bool) ($args['hierarchical'] ?? false),
            'object_types' => self::stringList($objectTypes),
            'owner'        => HookBus::instance()->currentPlugin(),
        ];
    }

    public function has(string $slug): bool
    {
        return isset($this->taxonomies[$slug]);
    }

    /**
     * @return array{slug: string, label: string, public: bool, hierarchical: bool, object_types: list<string>, owner: string|null}|null
     */
    public function get(string $slug): ?array
    {
        return $this->taxonomies[$slug] ?? null;
    }

    /**
     * @return array<string, array{slug: string, label: string, public: bool, hierarchical: bool, object_types: list<string>, owner: string|null}>
     */
    public function all(): array
    {
        return $this->taxonomies;
    }

    /**
     * 登记它的是哪个插件;内核自身登记的为 null。
     */
    public function owner(string $slug): ?string
    {
        return $this->taxonomies[$slug]['owner'] ?? null;
    }

    /**
     * 该分类法绑定的内容类型(《34》第 3.1 节第二参数),即分类法 → 类型的绑定。
     *
     * @return list<string>
     */
    public function objectTypes(string $slug): array
    {
        return $this->taxonomies[$slug]['object_types'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->taxonomies);
    }

    private static function assertSlug(string $slug): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > self::MAX_SLUG_LENGTH) {
            throw new ConfigException(
                "分类法名「{$slug}」不合法:须小写字母开头,只含小写字母、数字、- 或 _,且不超过 "
                . self::MAX_SLUG_LENGTH . ' 个字符'
            );
        }

        if (in_array($slug, self::RESERVED, true)) {
            throw new ConfigException("「{$slug}」是内核内置分类法,插件不能占用");
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }
}
