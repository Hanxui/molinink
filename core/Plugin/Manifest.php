<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Exception\PluginLoadException;
use Molin\Exception\PluginValidationException;
use Molin\Hook\Registry;
use Molin\Support\Str;

/**
 * plugin.json 的读取与校验。
 *
 * 致命项一律拒绝启用;只有"未知钩子"第 8 项降级为警告。
 * 字段只增不改,新增字段必须是可选的。
 */
final class Manifest
{
    private const NAME_PATTERN = '#\A[a-z][a-z0-9-]{1,49}\z#';

    private const SEMVER_PATTERN = '#\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\z#';

    private const CONTRACT_PATTERN = '#\A(?:([1-9]\d*)\.([1-9]\d*|x))\z#';

    private const REQUIRED = ['name', 'title', 'version', 'contract', 'author', 'main'];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{name: string, method: string, path: string, handler: string, auth: bool, guard: string}>|null */
    private ?array $parsedRoutes = null;

    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly string $dir,
        private readonly array $data
    ) {
    }

    /**
     * 读清单并跑致命项校验。$dir 是插件目录绝对路径。
     */
    public static function load(string $dir, string $kernelContract = MOLIN_CONTRACT): self
    {
        $file = $dir . '/plugin.json';

        if (!is_file($file)) {
            throw new PluginValidationException("插件缺清单: {$dir}/plugin.json");
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            throw new PluginValidationException("plugin.json 不是合法 JSON: {$file}");
        }

        return self::fromArray($decoded, $dir, $kernelContract);
    }

    /**
     * 用已解码的清单数据建对象 —— 扫描缓存省掉重复读盘与 json_decode,校验口径与 load() 一致。
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $dir, string $kernelContract = MOLIN_CONTRACT): self
    {
        $manifest = new self($dir, $data);
        $manifest->checkRequiredFields();
        $manifest->checkNames($dir);
        $manifest->checkCapabilities();
        $manifest->checkPaths();
        $manifest->checkContract($kernelContract);
        $manifest->collectHookWarnings();

        return $manifest;
    }

    /**
     * 原始清单数据。扫描缓存靠它落盘,再由 fromArray() 原样重建。
     *
     * @return array<string, mixed>
     */
    public function rawData(): array
    {
        return $this->data;
    }

    public function name(): string
    {
        return (string) $this->data['name'];
    }

    public function title(): string
    {
        return (string) $this->data['title'];
    }

    public function version(): string
    {
        return (string) $this->data['version'];
    }

    public function contract(): string
    {
        return (string) $this->data['contract'];
    }

    /**
     * @return string|array<string, mixed>
     */
    public function author(): string|array
    {
        return is_string($this->data['author']) ? $this->data['author'] : (array) $this->data['author'];
    }

    /** 作者显示名:清单允许「字符串」与「{name, url}」两副长相,展示层要的是一行文本。 */
    public function authorName(): string
    {
        $author = $this->author();

        return is_string($author) ? $author : (string) ($author['name'] ?? '');
    }

    public function description(): string
    {
        return (string) ($this->data['description'] ?? '');
    }

    public function license(): string
    {
        return (string) ($this->data['license'] ?? 'proprietary');
    }

    /**
     * 入口文件绝对路径。
     */
    public function main(): string
    {
        return $this->dir . '/' . $this->relative((string) $this->data['main']);
    }

    /**
     * @return list<string>
     */
    public function hooks(): array
    {
        return array_values(array_map('strval', (array) ($this->data['hooks'] ?? [])));
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return array_values(array_map('strval', (array) ($this->data['capabilities'] ?? [])));
    }

    /**
     * @return array<string, mixed>
     */
    public function requires(): array
    {
        return (array) ($this->data['requires'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function depends(): array
    {
        return (array) ($this->data['depends'] ?? []);
    }

    /**
     * @return list<string>
     */
    public function orderBefore(): array
    {
        return array_values(array_map('strval', (array) ($this->depends()['order']['before'] ?? [])));
    }

    /**
     * @return list<string>
     */
    public function orderAfter(): array
    {
        return array_values(array_map('strval', (array) ($this->depends()['order']['after'] ?? [])));
    }

    /**
     * @return list<string>
     */
    public function hardPluginDependencies(): array
    {
        return array_keys((array) ($this->depends()['hard']['plugins'] ?? []));
    }

    /**
     * @return array<string, string> 插件名 => 版本约束
     */
    public function hardPluginConstraints(): array
    {
        $out = [];

        foreach ((array) ($this->depends()['hard']['plugins'] ?? []) as $name => $constraint) {
            $out[(string) $name] = (string) $constraint;
        }

        return $out;
    }

    /**
     * @return list<array{name: string, migration: string}>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ((array) ($this->data['tables'] ?? []) as $table) {
            if (is_array($table) && isset($table['name'], $table['migration'])) {
                $tables[] = ['name' => (string) $table['name'], 'migration' => (string) $table['migration']];
            }
        }

        return $tables;
    }

    public function uninstallScript(): ?string
    {
        $path = $this->data['uninstall'] ?? null;

        return is_string($path) ? $this->dir . '/' . $this->relative($path) : null;
    }

    /**
     * 插件类的 PSR-4 根目录。
     */
    public function sourceDirectory(): string
    {
        return $this->dir . '/src';
    }

    /**
     * 插件根目录。views/ 与 migrations/ 都挂在它下面。
     */
    public function directory(): string
    {
        return $this->dir;
    }

    public function namespacePrefix(): string
    {
        return 'Molin\\Plugins\\' . Str::studly($this->name()) . '\\';
    }

    /**
     * 后台菜单声明(既有规范admin.menu):入口挂在 children 的 route 上,摊平成一层给「管理」键用。
     *
     * @return list<array{title: string, route: string}>
     */
    public function adminMenu(): array
    {
        if (!in_array('admin.menu', $this->capabilities(), true)) {
            return [];
        }

        $out = [];

        foreach ((array) ($this->data['admin']['menu'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ((array) ($entry['children'] ?? []) as $child) {
                if (!is_array($child) || !isset($child['route'])) {
                    continue;
                }

                $out[] = [
                    'title' => (string) ($child['title'] ?? $entry['title'] ?? ''),
                    'route' => (string) $child['route'],
                ];
            }
        }

        return $out;
    }

    /**
     * 后台页面声明(既有规范):每个 children 一项 = 一个插件后台页。
     * 页面归 admin.page 管,没声明就一个页也不认。
     * route 与 template 两个都合规才收 —— 前者由内核解析,后者在插件自己的 views/ 里($12》§3)。
     * `controller` 可选:声明了才有写动作;声明得不对只在警告里点名、按"没有写动作"处理,
     * 不连坐整页 —— 展示页不该因为一个写动作没接好就整个消失。
     *
     * @return list<array{route: string, title: string, capability: string, template: string, controller: string|null}>
     */
    public function adminPages(): array
    {
        if (!in_array('admin.page', $this->capabilities(), true)) {
            return [];
        }

        $out = [];

        foreach ((array) ($this->data['admin']['menu'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ((array) ($entry['children'] ?? []) as $child) {
                if (!is_array($child)) {
                    continue;
                }

                $route = self::adminRoute($child['route'] ?? null);
                $template = self::adminView($child['template'] ?? null);

                if ($route === null || $template === null) {
                    $this->warnings[] = "插件 {$this->name()} 的后台页声明不合规:route 只认小写字母数字与 - ,template 要是 views/ 下的 .twig";

                    continue;
                }

                $controller = null;

                if (array_key_exists('controller', $child)) {
                    $controller = self::adminController($child['controller'], $this->namespacePrefix());

                    if ($controller === null) {
                        $this->warnings[] = "插件 {$this->name()} 的后台页 controller 不合规(要合法类名,且必须落在本插件命名空间 " . $this->namespacePrefix() . ' 下),该页按「无写动作」处理';
                    }
                }

                $out[] = [
                    'route' => $route,
                    'title' => (string) ($child['title'] ?? $entry['title'] ?? ''),
                    'capability' => (string) ($child['capability'] ?? $entry['capability'] ?? ''),
                    'template' => $template,
                    'controller' => $controller,
                ];
            }
        }

        return $out;
    }

    /**
     * 页面路径:只认小写字母数字与 - ,段间用 / 分隔。刻意收窄 —— 这条路径会被当后台 URL 用,
     * 放宽就等于让插件自己决定 URL 长什么样(既有规范条)。
     */
    private static function adminRoute(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '' || str_contains($raw, '..')) {
            return null;
        }

        $route = trim($raw, '/');

        return preg_match('#^[a-z0-9][a-z0-9-]*(/[a-z0-9][a-z0-9-]*)*$#', $route) === 1 ? $route : null;
    }

    /** 视图路径:相对 views/,只认 .twig。拦掉绝对路径与 .. ,免得跳出插件目录去读别的模板。 */
    private static function adminView(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '' || str_starts_with($raw, '/') || str_contains($raw, '..')) {
            return null;
        }

        return preg_match('#^[A-Za-z0-9][A-Za-z0-9_\-/]*\.twig$#', $raw) === 1 ? $raw : null;
    }

    /**
     * 后台页控制器类名。只做**形状**校验:合法类名 + 落在插件自己的命名空间下。
     *
     * 「类存不存在、实没实现接口、能不能无参构造」是运行期的事 —— 走到这里时插件的 PSR-4 还没挂上,
     * `class_exists()` 一定说不存在。那三项留给 AdminController 在真要调它时判。
     */
    private static function adminController(mixed $raw, string $namespacePrefix): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (preg_match('#\A[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z#', $raw) !== 1) {
            return null;
        }

        // 只能指自己的类(对自定义能力的规矩):不许把内核或别的插件的类拿来当控制器
        return str_starts_with($raw, $namespacePrefix) ? $raw : null;
    }

    /**
     * 自定义路由声明。method 白名单、path 规范化、handler 解析到本插件命名空间。
     *
     * 不合规的一条只记 warning 并丢开(按"无此路由"处理),不连坐整个插件 ——
     * 一条路由写错不该让插件整个启不来;真正与内核路径撞车的那类只在这里如实收下,
     * 拒启用整个插件的事交给 Loader 在注册前判(见 Loader::routeConflicts)。
     *
     * guard:none 只对 `/api/` 开头的路径放行:webhook 是第三方服务器打过来的,带不了会话与
     * CSRF —— 这是有意留的口子,不是后门,故把范围钉死在 `/api/`。
     *
     * @return list<array{name: string, method: string, path: string, handler: string, auth: bool, guard: string}>
     */
    public function routes(): array
    {
        if ($this->parsedRoutes !== null) {
            return $this->parsedRoutes;
        }

        $routes = [];
        $seen = [];

        foreach ((array) ($this->data['routes'] ?? []) as $entry) {
            if (!is_array($entry)) {
                $this->warnings[] = "插件 {$this->name()} 的路由声明不合规:每一项要是对象";

                continue;
            }

            $method = self::routeMethod($entry['method'] ?? null);
            $path = self::routePath($entry['path'] ?? null);
            $handler = is_string($entry['handler'] ?? null)
                ? self::routeHandler($entry['handler'], $this->namespacePrefix())
                : null;

            if ($method === null || $path === null || $handler === null) {
                $this->warnings[] = "插件 {$this->name()} 的路由声明不合规:method 只认 GET/POST/PUT/DELETE,path 要以 / 开头且不含 .. 与查询串,handler 要是本插件命名空间下的 Class@method";

                continue;
            }

            $authRaw = $entry['auth'] ?? null;
            $auth = is_bool($authRaw) ? $authRaw : true;
            $guard = self::routeGuard($entry['guard'] ?? null);

            if ($guard === 'none' && !str_starts_with($path, '/api/')) {
                $this->warnings[] = "插件 {$this->name()} 的路由 {$method} {$path} 声明 guard:none,但只有 /api/ 开头的路径才允许免会话免 CSRF —— 该条按无此路由处理";

                continue;
            }

            // 同一条(method + path)重复声明只留第一条:否则注册时 Router 会当场抛冲突
            $key = $method . ' ' . $path;

            if (isset($seen[$key])) {
                $this->warnings[] = "插件 {$this->name()} 重复声明了路由 {$key},只留第一条";

                continue;
            }

            $seen[$key] = true;
            $routes[] = [
                'name' => 'plugin.' . $this->name() . '.' . self::routeNameSuffix($path),
                'method' => $method,
                'path' => $path,
                'handler' => $handler,
                'auth' => $auth,
                'guard' => $guard,
            ];
        }

        return $this->parsedRoutes = $routes;
    }

    /** method 白名单,大小写不敏感统一大写。 */
    private static function routeMethod(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $method = strtoupper(trim($raw));

        return in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true) ? $method : null;
    }

    /** path 规范化:必须以 / 开头、不许 .. 与查询串;尾斜杠去掉(分发前内核也会去,既有规范)。 */
    private static function routePath(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $path = trim($raw);

        if ($path === '' || !str_starts_with($path, '/') || preg_match('#\s#', $path) === 1) {
            return null;
        }

        if (str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#') || str_contains($path, "\0")) {
            return null;
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * handler 解析到本插件命名空间:短名补前缀,全名必须落在自己的命名空间下。
     * 只做**形状**校验 —— 「类在不在、能不能无参构造」是运行期的事,留给 Loader 在注册时判。
     */
    private static function routeHandler(mixed $raw, string $namespacePrefix): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $parts = explode('@', $raw, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$class, $method] = [trim($parts[0]), trim($parts[1])];

        if ($method === '' || preg_match('#\A[A-Za-z_][A-Za-z0-9_]*\z#', $method) !== 1) {
            return null;
        }

        if (preg_match('#\A[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z#', $class) !== 1) {
            return null;
        }

        if (str_contains($class, '\\')) {
            // 全名写法:只认自己命名空间下的类,不许把内核或别的插件的类拿来当处理器
            if (!str_starts_with($class, $namespacePrefix)) {
                return null;
            }
        } else {
            $class = $namespacePrefix . $class;
        }

        return $class . '@' . $method;
    }

    /** guard 只认 session(缺省)与 none;其余写法一律按更严的 session 办。 */
    private static function routeGuard(mixed $raw): string
    {
        return is_string($raw) && strtolower(trim($raw)) === 'none' ? 'none' : 'session';
    }

    /** 路由名后缀:路径段转点分(`/api/pay/{id}` → `api.pay.id`)。URL 仍按路由名生成。 */
    private static function routeNameSuffix(string $path): string
    {
        $trimmed = trim(str_replace(['{', '}'], '', $path), '/');
        $name = preg_replace('#[^A-Za-z0-9]+#', '.', $trimmed);

        return $name === null || $name === '' ? 'root' : $name;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * 环境要求(PHP 版本、必需扩展)。$mysqlVersion 传 null 表示暂不校验 MySQL。
     */
    public function checkEnvironment(?string $mysqlVersion = null): void
    {
        $requires = $this->requires();
        $php = (string) ($requires['php'] ?? '');

        if ($php !== '' && !self::versionSatisfies(PHP_VERSION, $php)) {
            throw new PluginLoadException(sprintf('插件 %s 要求 PHP %s,当前 %s', $this->name(), $php, PHP_VERSION));
        }

        $mysql = (string) ($requires['mysql'] ?? '');

        if ($mysql !== '' && $mysqlVersion !== null && !self::versionSatisfies($mysqlVersion, $mysql)) {
            throw new PluginLoadException(sprintf('插件 %s 要求 MySQL %s,当前 %s', $this->name(), $mysql, $mysqlVersion));
        }

        foreach ((array) ($requires['ext'] ?? []) as $ext) {
            if (!extension_loaded((string) $ext)) {
                throw new PluginLoadException(sprintf('插件 %s 缺 PHP 扩展:%s', $this->name(), $ext));
            }
        }
    }

    /**
     * 硬依赖是否都在且版本可读。$installed 是 插件名 => 版本。
     *
     * @param array<string, string> $installed
     */
    public function checkDependencies(array $installed): void
    {
        $core = (string) ($this->depends()['hard']['core'] ?? '');

        if ($core !== '' && !self::versionSatisfies(MOLIN_CONTRACT, $core)) {
            throw new PluginLoadException(sprintf('插件 %s 要求内核 %s,当前契约 %s', $this->name(), $core, MOLIN_CONTRACT));
        }

        foreach ($this->hardPluginConstraints() as $name => $constraint) {
            if (!isset($installed[$name])) {
                throw new PluginLoadException(sprintf('插件 %s 缺硬依赖:%s', $this->name(), $name));
            }

            if ($constraint !== '*' && !self::versionSatisfies($installed[$name], $constraint)) {
                throw new PluginLoadException(sprintf(
                    '插件 %s 要求 %s %s,已装 %s',
                    $this->name(),
                    $name,
                    $constraint,
                    $installed[$name]
                ));
            }
        }
    }

    private function checkRequiredFields(): void
    {
        foreach (self::REQUIRED as $field) {
            if (!isset($this->data[$field]) || $this->data[$field] === '') {
                throw new PluginValidationException("插件清单缺必填字段: {$field}");
            }
        }
    }

    private function checkNames(string $dir): void
    {
        $name = $this->name();

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new PluginValidationException("name 不合规(^ [a-z][a-z0-9-]{1,49} $): {$name}");
        }

        if (preg_match(self::SEMVER_PATTERN, $this->version()) !== 1) {
            throw new PluginValidationException("version 不是合法 SemVer: {$this->version()}({$name})");
        }

        $titleLen = mb_strlen($this->title());

        if ($titleLen < 1 || $titleLen > 80) {
            throw new PluginValidationException("插件 {$name} 的 title 须 1–80 字,当前 {$titleLen}");
        }

        if (basename(str_replace('\\', '/', $dir)) !== $name) {
            throw new PluginValidationException("目录名({$dir})必须等于 name({$name})");
        }
    }

    private function checkCapabilities(): void
    {
        foreach ($this->capabilities() as $capability) {
            if (Capability::isKnown($capability)) {
                continue;
            }

            $replacement = Capability::DEPRECATED[$capability] ?? null;

            if ($replacement !== null) {
                throw new PluginValidationException(
                    __('插件 %s 使用了已废弃能力 %s，改用 %s', 'molin', $this->name(), $capability, $replacement)
                );
            }

            if (in_array($capability, Capability::REVOKED, true)) {
                throw new PluginValidationException(
                    __('插件 %s 声明了已移除的能力 %s：它拦不住，不提供', 'molin', $this->name(), $capability)
                );
            }

            // 插件自定义能力只允许在自己的命名空间下
            if (str_starts_with($capability, $this->name() . '.') && Capability::isValidName($capability)) {
                continue;
            }

            throw new PluginValidationException("插件 {$this->name()} 声明了词表外的能力: {$capability}");
        }
    }

    private function checkPaths(): void
    {
        $main = $this->dir . '/' . $this->relative((string) $this->data['main']);

        if (!is_file($main)) {
            throw new PluginValidationException("插件 {$this->name()} 的入口文件不存在: {$this->data['main']}");
        }

        foreach ($this->tables() as $table) {
            $path = $this->dir . '/' . $this->relative($table['migration']);

            if (!is_file($path)) {
                throw new PluginValidationException("插件 {$this->name()} 缺迁移文件: {$table['migration']}");
            }

            // 必须提供回滚,否则拒绝启用
            if (preg_match('/function\s+down\s*\(/', (string) file_get_contents($path)) !== 1) {
                throw new PluginValidationException(
                    "插件 {$this->name()} 的迁移 {$table['migration']} 没有 down(),无法回滚"
                );
            }
        }

        $uninstall = $this->data['uninstall'] ?? null;

        if (is_string($uninstall) && !is_file($this->dir . '/' . $this->relative($uninstall))) {
            throw new PluginValidationException("插件 {$this->name()} 声明的卸载脚本不存在: {$uninstall}");
        }
    }

    private function checkContract(string $kernelContract): void
    {
        if (preg_match(self::CONTRACT_PATTERN, $this->contract(), $m) !== 1) {
            throw new PluginValidationException("contract 写法不合法(1.x 或 1.2): {$this->contract()}");
        }

        [$kernelMajor, $kernelMinor] = array_map('intval', explode('.', $kernelContract));
        $major = (int) $m[1];

        if ($major !== $kernelMajor) {
            throw new PluginValidationException(sprintf(
                '插件 %s 要求的兼容版本是 %s，当前程序是 %s —— 拒绝启用。',
                $this->name(),
                $this->contract(),
                $kernelContract
            ));
        }

        if ($m[2] !== 'x' && (int) $m[2] > $kernelMinor) {
            throw new PluginValidationException(sprintf(
                '插件 %s 要求的兼容版本是 %s，当前程序 %s 太旧 —— 拒绝启用',
                $this->name(),
                $this->contract(),
                $kernelContract
            ));
        }
    }

    private function collectHookWarnings(): void
    {
        foreach ($this->hooks() as $hook) {
            if ($hook === '*') {
                $this->warnings[] = "插件 {$this->name()} 用 hooks:[\"*\"] 全量加载,放弃了懒加载收益";

                continue;
            }

            if (str_ends_with($hook, '/*')) {
                continue;
            }

            if (!Registry::isFrozen($hook) && !Registry::isCandidate($hook)) {
                $this->warnings[] = __('插件 %s 声明了未登记的钩子：%s', 'molin', $this->name(), $hook);
            }
        }
    }

    /**
     * 拒绝绝对路径与目录穿越。
     */
    private function relative(string $path): string
    {
        $clean = str_replace('\\', '/', trim($path));

        if ($clean === '' || str_starts_with($clean, '/') || str_contains($clean, '..') || str_contains($clean, "\0")) {
            throw new PluginValidationException("插件 {$this->name()} 的清单路径不合法: {$path}");
        }

        return $clean;
    }

    /**
     * 支持 >=、>、<=、<、== 与 * 的最小版本约束比较。
     */
    private static function versionSatisfies(string $actual, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        $rules = preg_split('#\s*,\s*#', $constraint);

        foreach ($rules === false ? [] : $rules as $rule) {
            if (preg_match('#\A(>=|<=|==|>|<|\^|~)?\s*([0-9][0-9A-Za-z.\-]*)\z#', $rule, $m) !== 1) {
                return false;
            }

            $op = $m[1] === '' || $m[1] === '==' ? '>=' : $m[1];
            $compare = self::compareVersions($actual, $m[2]);
            // ^1.2 与 ~1.2 都按"同主版本且不低于给定值"处理,够清单表达需求
            $sameMajor = (int) explode('.', $actual)[0] === (int) explode('.', $m[2])[0];

            $ok = match (true) {
                $op === '^', $op === '~' => $compare >= 0 && $sameMajor,
                $op === '>' => $compare > 0,
                $op === '<' => $compare < 0,
                $op === '<=' => $compare <= 0,
                // >=、== 与没写操作数都按"不低于"处理
                default => $compare >= 0,
            };

            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * 只比数值段,预发布标记按 SemVer 忽略(内核与插件版本约束都用稳定段)。
     */
    public static function compareVersions(string $left, string $right): int
    {
        $trim = static function (string $v): array {
            $core = preg_split('#[-+]#', $v, 2)[0] ?? $v;

            return array_map('intval', array_pad(explode('.', $core), 3, '0'));
        };

        return $trim($left) <=> $trim($right);
    }
}
