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
 * 内容类型登记表(《34-内容模型与CPT》第 2 节)。
 *
 * posts.type 是自由字符串、库层没有约束(《21》第 2 节),所以"哪些 type 算数"由这张表说了算:
 * 插件在 system/init 里登记,路由、后台列表、模板回退以后都从登记表取。前台路由与模板回退
 * 已接这条表(单篇 / 归档 / `single-{type}` / `archive-{type}`);后台列表与编辑屏仍是后续批次。
 */
final class PostTypeRegistry
{
    /** slug 取 WP 的 post_type key 口径:小写字母开头,只含小写字母、数字、- 与 _ */
    public const SLUG_PATTERN = '#\A[a-z][a-z0-9_-]*\z#';

    /** 与 posts.type 列宽一致(《21》第 2 节) */
    public const MAX_SLUG_LENGTH = 32;

    /** 内核写死的内置类型(《34》第 1 节),插件不得占用 */
    public const RESERVED = ['post', 'page', 'attachment'];

    private static ?self $instance = null;

    /** @var array<string, array{slug: string, label: string, public: bool, has_archive: bool, taxonomies: list<string>, owner: string|null}> */
    private array $types = [];

    /**
     * slug => 前台 URL 段(string)或 false(不做前台重写)。与 $types 分家:前者是《34》§2.2 的
     * 最小集契约(只增不改,已有测试按原样盘它),rewrite 是后补的前台口径,不塞进同一份记录。
     *
     * @var array<string, string|false>
     */
    private array $rewrites = [];

    /**
     * slug => 后台/编辑器要用的扩展声明(《34》§2.2 的 supports / capability_type / menu_icon /
     * hierarchical / singular)。与 $types 分家同样是为了不动 get()/all() 那份已冻结的最小集形状。
     *
     * @var array<string, array{supports: list<string>, capability_type: string, menu_icon: string, hierarchical: bool, singular: string}>
     */
    private array $extras = [];

    /**
     * slug => 是否进首页主列表(《34》§2.2 in_main_list)。仍与 $types 分家,同因。
     *
     * @var array<string, bool>
     */
    private array $mainList = [];

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
     * 登记一个内容类型(《34》第 2.1 节)。插件在 system/init 里调用。
     *
     * @param array<string, mixed> $args 本批取 label / public / has_archive / rewrite / taxonomies;
     *                                   其余参数(《34》第 2.2 节的 supports 等)留给后续批次
     */
    public function register(string $slug, array $args = []): void
    {
        self::assertSlug($slug);

        if (isset($this->types[$slug])) {
            $by = $this->types[$slug]['owner'] ?? '内核';
            throw new ConfigException("内容类型「{$slug}」已被 {$by} 注册,不能重复注册");
        }

        $label = trim((string) ($args['label'] ?? ''));

        if ($label === '') {
            throw new ConfigException("内容类型「{$slug}」缺少 label(复数名)");
        }

        $this->types[$slug] = [
            'slug'        => $slug,
            'label'       => $label,
            'public'      => (bool) ($args['public'] ?? false),
            'has_archive' => (bool) ($args['has_archive'] ?? false),
            'taxonomies'  => self::stringList($args['taxonomies'] ?? []),
            'owner'       => HookBus::instance()->currentPlugin(),
        ];
        $this->rewrites[$slug] = self::normalizeRewrite($slug, $args['rewrite'] ?? null);
        $this->extras[$slug] = [
            // 《34》§2.2 默认 ['title'];给了非法形状(非数组)就当没声明,别让一个坏参数把类型整个弄没
            'supports'        => self::stringList($args['supports'] ?? ['title']),
            'capability_type' => self::normalizeCapabilityType($args['capability_type'] ?? 'post'),
            'menu_icon'       => trim((string) ($args['menu_icon'] ?? '')),
            'hierarchical'    => (bool) ($args['hierarchical'] ?? false),
            'singular'        => trim((string) ($args['singular'] ?? '')) === '' ? $label : trim((string) $args['singular']),
        ];
        // 默认不进主列表:首页那一条流仍以文章为主,短动态要不要掺进来由类型自己声明(《34》§2.2)
        $this->mainList[$slug] = (bool) ($args['in_main_list'] ?? false);
    }

    public function has(string $slug): bool
    {
        return isset($this->types[$slug]);
    }

    /**
     * @return array{slug: string, label: string, public: bool, has_archive: bool, taxonomies: list<string>, owner: string|null}|null
     */
    public function get(string $slug): ?array
    {
        return $this->types[$slug] ?? null;
    }

    /**
     * @return array<string, array{slug: string, label: string, public: bool, has_archive: bool, taxonomies: list<string>, owner: string|null}>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * 前台 URL 段(rewrite.slug,见《34》第 2.2 节):未登记、非 public 或 rewrite=false 时给 null。
     *
     * 路由注册与地址生成(PostRepository 经 Router 算 Post::$url)都从这里取段 ——
     * 段只来自登记表,请求输入永远进不了 URL 拼接。
     */
    public function rewriteSlug(string $slug): ?string
    {
        $type = $this->types[$slug] ?? null;
        $segment = $this->rewrites[$slug] ?? null;

        if ($type === null || !$type['public'] || !is_string($segment) || $segment === '') {
            return null;
        }

        return $segment;
    }

    /**
     * 登记它的是哪个插件;内核自身登记的为 null(《34》第 2 节的"来源")。
     */
    public function owner(string $slug): ?string
    {
        return $this->types[$slug]['owner'] ?? null;
    }

    /**
     * 该类型声明的分类法(《34》第 2.2 节 taxonomies),即类型 → 分类法的绑定。
     *
     * @return list<string>
     */
    public function taxonomies(string $slug): array
    {
        return $this->types[$slug]['taxonomies'] ?? [];
    }

    /**
     * 编辑器支持的功能(《34》§2.2 supports)。未登记的类型给最小集 ['title']。
     *
     * @return list<string>
     */
    public function supports(string $slug): array
    {
        return $this->extras[$slug]['supports'] ?? ['title'];
    }

    /**
     * 该类型是否声明了某项编辑支持(title / editor / thumbnail / excerpt / comments / custom-fields)。
     * 后台编辑屏据此决定某一盒出不出 —— 不支持就老实不显示,不假装。
     */
    public function hasSupport(string $slug, string $feature): bool
    {
        return in_array($feature, $this->supports($slug), true);
    }

    /**
     * 是否像页面那样有层级(《34》§2.2 hierarchical)。
     */
    public function hierarchical(string $slug): bool
    {
        return $this->extras[$slug]['hierarchical'] ?? false;
    }

    /**
     * 该类型是否进首页主列表(《34》§2.2 in_main_list,默认 false)。
     *
     * 首页取文 = `post` + 所有声明为 true 的类型;分类 / 标签 / 作者 / 日期 / 搜索这些归档仍只取 `post` ——
     * 短动态有自己的归档页,归档族不该被它冲淡。
     */
    public function inMainList(string $slug): bool
    {
        return $this->mainList[$slug] ?? false;
    }

    /**
     * 声明要进首页主列表的类型(登记顺序)。
     *
     * 不含内置 `post` —— 首页恒有它,由调用方拼在最前(见 ListSource::homeTypes)。
     *
     * @return list<string>
     */
    public function mainListTypes(): array
    {
        return array_keys(array_filter($this->mainList));
    }

    /**
     * 权限能力前缀(《34》§2.2 capability_type)。后台按 `{前缀}.create` 之类去找已登记的能力;
     * 找不到就回落内核既有的 `post.*` 一族(不新造能力名)。
     */
    public function capabilityType(string $slug): string
    {
        return $this->extras[$slug]['capability_type'] ?? 'post';
    }

    /**
     * 后台菜单图标名(《34》§2.2 menu_icon)。图标能力尚未落地时它只是个名字,渲染层安全回落。
     */
    public function menuIcon(string $slug): string
    {
        return $this->extras[$slug]['menu_icon'] ?? '';
    }

    /**
     * 单数名(《34》§2.2 singular),缺省同 label。
     */
    public function singular(string $slug): string
    {
        return $this->extras[$slug]['singular'] ?? ($this->types[$slug]['label'] ?? $slug);
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->types);
    }

    /**
     * URL 规则归一化(《34》第 2.2 节):缺省 slug = 类型名;`false` = 不做前台重写。
     * 段本身必须合 slug 口径 —— 前台路由直接拿它拼路径,非法字符要在登记这一关就挡掉。
     *
     * @return string|false 归一后的 URL 段;false 表示前台无重写地址
     */
    private static function normalizeRewrite(string $slug, mixed $rewrite): string|false
    {
        if ($rewrite === false) {
            return false;
        }

        if ($rewrite === null) {
            return $slug;
        }

        if (!is_array($rewrite)) {
            throw new ConfigException("内容类型「{$slug}」的 rewrite 须是数组或 false");
        }

        $segment = trim((string) ($rewrite['slug'] ?? $slug));

        if ($segment === '') {
            $segment = $slug;
        }

        if (preg_match(self::SLUG_PATTERN, $segment) !== 1 || strlen($segment) > self::MAX_SLUG_LENGTH) {
            throw new ConfigException(
                "内容类型「{$slug}」的 rewrite.slug「{$segment}」不合法:须小写字母开头,只含小写字母、数字、- 或 _,且不超过 "
                . self::MAX_SLUG_LENGTH . ' 个字符'
            );
        }

        return $segment;
    }

    /**
     * 能力前缀归一化:与类型名同口径。给不出合法的就当 'post' —— 后台据此回落内核既有能力,
     * 不拿一个拼错的前缀去撞"未知能力一律拒绝"的闸(《32》§4)。
     */
    private static function normalizeCapabilityType(mixed $value): string
    {
        $prefix = trim((string) $value);

        if (preg_match(self::SLUG_PATTERN, $prefix) !== 1 || strlen($prefix) > self::MAX_SLUG_LENGTH) {
            return 'post';
        }

        return $prefix;
    }

    private static function assertSlug(string $slug): void
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1 || strlen($slug) > self::MAX_SLUG_LENGTH) {
            throw new ConfigException(
                "内容类型名「{$slug}」不合法:须小写字母开头,只含小写字母、数字、- 或 _,且不超过 "
                . self::MAX_SLUG_LENGTH . ' 个字符'
            );
        }

        if (in_array($slug, self::RESERVED, true)) {
            throw new ConfigException("「{$slug}」是内核内置内容类型,插件不能占用");
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
