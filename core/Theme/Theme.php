<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Theme;

use Molin\Exception\ThemeException;

/**
 * 主题:只管长相。清单、模板目录、父主题链、设置默认值(《13-主题目录约定》)。
 */
final class Theme
{
    /**
     * pageTemplates() 的进程内缓存:同一只主题只扫一次盘。缓存挂在实例上,不同主题实例互不串味。
     *
     * @var list<array{file: string, name: string}>|null
     */
    private ?array $pageTemplatesCache = null;

    /** @param array<string, mixed> $manifest */
    private function __construct(
        private readonly string $slug,
        private readonly string $directory,
        private readonly array $manifest,
        private readonly ?self $parent
    ) {
    }

    /**
     * 读取主题清单并沿 parent 链装载(《13》第 10 节校验 1/2/4)。
     *
     * @param list<string> $visited 环检测用
     */
    public static function load(string $themesRoot, string $slug, array $visited = []): self
    {
        if (preg_match('#\A[a-z][a-z0-9-]*\z#', $slug) !== 1) {
            throw new ThemeException("非法主题名: {$slug}");
        }

        $directory = rtrim($themesRoot, '/') . '/' . $slug;
        $manifest = self::readManifest($directory . '/theme.json');
        $parentSlug = $manifest['parent'] ?? null;
        $parent = null;

        if (is_string($parentSlug) && $parentSlug !== '') {
            if ($parentSlug === $slug || in_array($parentSlug, $visited, true)) {
                throw new ThemeException("父主题成环: {$slug} → {$parentSlug}");
            }

            $parent = self::load($themesRoot, $parentSlug, array_merge($visited, [$slug]));
        }

        $theme = new self($slug, $directory, $manifest, $parent);

        if (!$theme->hasTemplate('index.twig')) {
            throw new ThemeException("主题 {$slug} 及其父链都没有 index.twig");
        }

        return $theme;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return (string) ($this->manifest['title'] ?? $this->slug);
    }

    public function version(): string
    {
        return (string) ($this->manifest['version'] ?? '0.0.0');
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    /**
     * @return array<string, mixed>
     */
    public function supports(): array
    {
        $supports = $this->manifest['supports'] ?? [];

        return is_array($supports) ? $supports : [];
    }

    /**
     * 模板搜索路径:子主题在前,父主题在后(《13》第 4 节解析顺序)。
     *
     * @return list<string>
     */
    public function templateDirs(): array
    {
        $dirs = [$this->directory . '/templates'];

        if ($this->parent !== null) {
            $dirs = array_merge($dirs, $this->parent->templateDirs());
        }

        return $dirs;
    }

    public function hasTemplate(string $name): bool
    {
        foreach ($this->templateDirs() as $dir) {
            if (is_file($dir . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按候选顺序取第一个真实存在的模板。
     *
     * @param list<string> $candidates
     */
    public function resolveFirst(array $candidates): string
    {
        foreach ($candidates as $name) {
            if ($this->hasTemplate($name)) {
                return $name;
            }
        }

        throw new ThemeException('模板找不到: ' . implode(' → ', $candidates));
    }

    /**
     * 主题声明的页面模板清单:扫各模板目录里带 `{# molin:template 显示名 #}` 标记的 *.twig。
     *
     * 目录顺序同 templateDirs()(子主题在前、父主题在后);同名文件子主题已出现,父主题那份不再重复。
     * 结果按文件名升序,保证界面与测试稳定。只扫 templates/ 这一层,不递归。
     *
     * @return list<array{file: string, name: string}>
     */
    public function pageTemplates(): array
    {
        if ($this->pageTemplatesCache !== null) {
            return $this->pageTemplatesCache;
        }

        $seen = [];
        $templates = [];

        foreach ($this->templateDirs() as $dir) {
            $files = glob($dir . '/*.twig');

            if ($files === false) {
                continue;
            }

            foreach ($files as $path) {
                $file = basename($path);

                // 子主题的同名文件在搜索路径里先被看到:它就算没标记,也把父主题那份遮住
                if (isset($seen[$file])) {
                    continue;
                }

                $seen[$file] = true;
                $name = self::readPageTemplateName($path);

                if ($name !== null) {
                    $templates[$file] = $name;
                }
            }
        }

        ksort($templates);

        $out = [];

        foreach ($templates as $file => $name) {
            $out[] = ['file' => $file, 'name' => $name];
        }

        return $this->pageTemplatesCache = $out;
    }

    /**
     * 页面模板白名单闸:只认主题里真实声明过的文件名,命中原样返回,否则 null。
     *
     * 前台的模板名来自数据库 meta(半可信),这里只做"查表命中",绝不拼路径 ——
     * 路径分隔符、`..`、绝对路径都取不到,天然挡住路径穿越。
     *
     * @param ?string $file 待校验的模板文件名(来自 meta)
     * @return ?string 命中时原样返回文件名,未命中/空值返回 null
     */
    public function resolvePageTemplate(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }

        foreach ($this->pageTemplates() as $template) {
            if ($template['file'] === $file) {
                return $template['file'];
            }
        }

        return null;
    }

    /**
     * 读文件头部若干行找 `{# molin:template 名称 #}` 标记,返回 trim 后的显示名;没有或空名给 null。
     */
    private static function readPageTemplateName(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = '';

        for ($i = 0; $i < 10; $i++) {
            $line = fgets($handle);

            if ($line === false) {
                break;
            }

            $head .= $line;
        }

        fclose($handle);

        // 带 BOM 的模板照样要认得出标记
        $head = ltrim($head, "\xEF\xBB\xBF");

        if (preg_match('~\{#\s*molin:template\s+(.+?)\s*#\}~', $head, $m) !== 1) {
            return null;
        }

        $name = trim($m[1]);

        return $name === '' ? null : $name;
    }

    /**
     * 主题声明的设置原样(《36-主题配置器》第 1 节;父链合并与内核兜底在 ThemeSettings)。
     *
     * @return array<string, mixed>
     */
    public function settingsDefinition(): array
    {
        $settings = $this->manifest['settings'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * 主题设置默认值(《13》第 5.2 节:主题必须声明默认值;结构权威定义见《36》)。
     *
     * @return array<string, mixed>
     */
    public function settingsDefaults(): array
    {
        $defaults = [];
        $settings = $this->manifest['settings'] ?? [];

        if (!is_array($settings)) {
            return [];
        }

        foreach ($settings as $group) {
            $fields = is_array($group) ? ($group['fields'] ?? []) : [];

            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $key => $field) {
                if (is_array($field) && array_key_exists('default', $field)) {
                    $defaults[(string) $key] = $field['default'];
                }
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readManifest(string $file): array
    {
        if (!is_file($file)) {
            throw new ThemeException("主题缺少清单: {$file}");
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            throw new ThemeException("主题清单不是合法 JSON: {$file}");
        }

        if (!isset($decoded['name']) || !is_string($decoded['name']) || $decoded['name'] === '') {
            throw new ThemeException("主题清单缺 name: {$file}");
        }

        return $decoded;
    }
}
