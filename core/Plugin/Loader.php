<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Closure;
use Molin\Database\Database;
use Molin\Exception\PluginLoadException;
use Molin\Exception\PluginValidationException;
use Molin\Exception\RouteException;
use Molin\Hook\HookBus;
use Molin\Hook\HookMap;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Logger\Log;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Support\Files;
use Molin\User\Auth;
use ReflectionClass;
use Throwable;

/**
 * 插件装载器:读启用列表 → 校验清单 → 依赖/环境 → 拓扑排序 → 注册命名空间 → 建懒加载索引。
 *
 * 加载顺序由插件自己声明(order.before / order.after),内核拓扑排序;
 * 出现循环依赖直接报错,不给"随机顺序"留活路(《12》第 5 节、《22》第 6 节)。
 * 启用状态以 {p}plugins.active 为权威、options.active_plugins 为派生启动缓存(《21》第 9 节,2026-09-30 定);
 * 本类目前只读缓存,表侧写入与"先表后缓存"的写序随安装向导那轮接线。
 */
final class Loader
{
    /** @var array<string, Plugin> 校验通过的插件(含未启用的,供后台列"可启用") */
    private array $available = [];

    /** @var array<string, string> 插件目录 => 校验失败原因 */
    private array $broken = [];

    /** @var array<string, string> 启用名单里但本趟无法加载的插件(名称 => 原因) */
    private array $skipped = [];

    /** @var list<string> 启用插件的加载顺序 */
    private array $order = [];

    private bool $scanned = false;

    /** @var Closure(string, string): void */
    private Closure $registerPsr4;

    /**
     * @param callable(string, string): void $registerPsr4 把 命名空间 => 目录 交给自动加载器
     */
    public function __construct(
        private readonly Options $options,
        private readonly Database $db,
        private readonly string $pluginsRoot,
        callable $registerPsr4
    ) {
        $this->registerPsr4 = Closure::fromCallable($registerPsr4);
    }

    public function boot(): void
    {
        $this->scan();

        $active = $this->activeNames();
        $chosen = [];
        $this->skipped = [];

        foreach ($active as $name) {
            if (!isset($this->available[$name])) {
                // 启用名单点名的插件不在了(目录被删、清单坏了):跳过这一支,不让整站跟着倒。
                // 该停下的只有"关系错"(循环依赖 / 缺依赖 / 环境不符),那几条在下面照旧抛 ——
                // 《22》§6.3 要的是"配错了就说清",不是"一个目录没了全站白屏"。
                $reason = $this->broken[$name] ?? '插件目录不存在或清单校验未通过';
                $this->skipped[$name] = $reason;
                Log::channel('plugin')->warning('plugin.unavailable_skipped', [
                    'plugin' => $name,
                    'reason' => $reason,
                ]);

                continue;
            }

            $chosen[$name] = $this->available[$name];
        }

        $installed = [];

        foreach ($chosen as $plugin) {
            $installed[$plugin->name()] = $plugin->version();
        }

        $mysql = $this->mysqlVersion();

        foreach ($chosen as $plugin) {
            $plugin->manifest->checkDependencies($installed);
            $plugin->manifest->checkEnvironment($mysql);

            foreach ($plugin->manifest->warnings() as $warning) {
                Log::channel('plugin')->warning($warning);
            }
        }

        $this->reorder($active);

        foreach ($this->order as $name) {
            $manifest = $this->available[$name]->manifest;
            ($this->registerPsr4)($manifest->namespacePrefix(), $manifest->sourceDirectory());
        }

        HookMap::instance()->rebuild($this->ordered());
    }

    /**
     * @return list<Plugin> 按加载顺序
     */
    public function ordered(): array
    {
        $plugins = [];

        foreach ($this->order as $name) {
            $plugins[] = $this->available[$name];
        }

        return $plugins;
    }

    /**
     * @return array<string, Plugin> 校验通过的插件(含未启用)
     */
    public function available(): array
    {
        $this->scan();

        return $this->available;
    }

    /**
     * @return array<string, string> 插件目录 => 不可启用的原因
     */
    public function broken(): array
    {
        $this->scan();

        return $this->broken;
    }

    /**
     * 启用名单里但本趟没能加载的插件(名称 => 原因)。boot() 之后才有值。
     *
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function get(string $name): ?Plugin
    {
        return $this->available[$name] ?? null;
    }

    /**
     * 启用状态以 {p}plugins.active 为权威(《21》第 9 节,2026-09-30 定),
     * options.active_plugins 只是派生的启动缓存:不一致时按表重建,
     * 表里没有的名字一律丢弃 —— 手工改 option 冒充"已启用"在这一步就断掉。
     *
     * @return list<string>
     */
    public function activeNames(): array
    {
        $fromTable = $this->activeFromTable();

        if ($fromTable !== $this->cachedActiveNames()) {
            if (APP_DEBUG) {
                Log::channel('plugin')->warning('启用状态与表不一致,已按表重建缓存');
            }

            $this->writeCache($fromTable);
        }

        return $fromTable;
    }

    /**
     * @return list<string>
     */
    private function activeFromTable(): array
    {
        $rows = $this->db->select(
            'SELECT slug FROM ' . $this->db->table('plugins') . ' WHERE active = 1 ORDER BY slug'
        );

        return array_values(array_map(static fn (array $row): string => (string) $row['slug'], $rows));
    }

    /**
     * @return list<string>
     */
    private function cachedActiveNames(): array
    {
        $raw = (string) $this->options->get('active_plugins', '[]');
        $names = json_decode($raw, true);
        $list = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if (is_string($name) && $name !== '') {
                $list[] = $name;
            }
        }

        sort($list);

        return $list;
    }

    /**
     * 先写表,再派生缓存(写序不可反,《21》第 9 节)。后台插件页与测试都走这条路。
     */
    public function markActive(string $name, bool $active): void
    {
        // 表里要记版本,所以得先扫一遍目录
        $this->scan();
        $version = isset($this->available[$name]) ? $this->available[$name]->version() : '';
        $now = date('Y-m-d H:i:s');
        $existing = $this->db->selectOne(
            'SELECT slug FROM ' . $this->db->table('plugins') . ' WHERE slug = :slug',
            ['slug' => $name]
        );

        if ($existing === null) {
            $this->db->insert('plugins', [
                'slug'         => $name,
                'version'      => $version,
                'active'       => $active ? 1 : 0,
                'activated_at' => $active ? $now : null,
            ]);
        } else {
            $this->db->update(
                'plugins',
                [
                    'version'      => $version,
                    'active'       => $active ? 1 : 0,
                    'activated_at' => $active ? $now : null,
                ],
                'slug = :slug',
                ['slug' => $name]
            );
        }

        $this->writeCache($this->activeFromTable());
    }

    /**
     * @param list<string> $names
     */
    private function writeCache(array $names): void
    {
        sort($names);
        $this->options->set('active_plugins', json_encode($names, JSON_UNESCAPED_SLASHES));
    }

    /**
     * 启用一个插件:写状态 → 排好加载顺序 → 建索引 → 立刻加载入口(本请求即生效)→ 触发钩子。
     */
    public function activate(string $name): Plugin
    {
        $this->scan();
        $plugin = $this->available[$name] ?? null;

        if ($plugin === null) {
            throw new PluginLoadException("插件 {$name} 不可启用:" . ($this->broken[$name] ?? '目录不存在或 name 与目录不符'));
        }

        // 自定义路由与内核撞车:直接拒绝启用 —— 与清单致命项同一条处理,不写状态、不跑迁移
        $conflicts = $this->routeConflicts($plugin);

        if ($conflicts !== []) {
            throw new PluginLoadException(sprintf(
                '插件 %s 的自定义路由与内核路径冲突,拒绝启用:%s',
                $name,
                implode(';', $conflicts)
            ));
        }

        // 迁移类可能引用插件 src/ 里的类,命名空间先挂;建表先于记状态,失败即拒绝启用,{p}plugins 不留半行
        $this->registerPsr4For($plugin);
        (new PluginMigrator($this->db))->enable($plugin);

        $this->markActive($name, true);
        $active = $this->activeNames();

        $this->reorder($active);
        HookMap::instance()->rebuild($this->ordered());

        do_action('plugin/activate/before', $name);

        $bus = HookBus::instance();
        $bus->beginLoading($name);

        try {
            $plugin->load();
        } finally {
            $bus->endLoading();
        }

        do_action('plugin/activate/after', $name);

        // 插件启停必须审计(《26》第 7 节)
        Audit::record('plugin.activate', $name);

        return $plugin;
    }

    /**
     * 停用:索引与状态立即改;本次请求里已注册的回调收不回(PHP 无法撤销已注册的闭包),
     * 因此"完全不再参与"从下一个请求开始。
     */
    public function deactivate(string $name): void
    {
        $active = $this->activeNames();

        if (!in_array($name, $active, true)) {
            return;
        }

        $this->markActive($name, false);
        $this->reorder($this->activeNames());
        HookMap::instance()->rebuild($this->ordered());

        // 状态与索引都改完才播报 —— after 的监听要能读到"停用后"的真相
        do_action('plugin/deactivate/after', $name);

        Audit::record('plugin.deactivate', $name);
    }

    /**
     * 离线上传包:新装,或已存在且 replace=true 时升级。
     *
     * 文件系统那一段(解包/校验/备份/原子替换/回滚)在 ZipInstaller;这里管停用、迁移、
     * 生命周期钩子与启用状态恢复 —— 它才有 DB。
     *
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     * @param string|null $pluginsRoot 落点:后台固定传站点真实的 plugins/,免得注入的 Loader 指向别处
     * @return array{action: string, slug: string, old_version: string|null, new_version: string, backup: string|null, warning: string}
     */
    public function install(array $upload, bool $replace = false, ?string $pluginsRoot = null): array
    {
        $root = $pluginsRoot ?? $this->pluginsRoot;

        // 根不一样就换一个同依赖的 Loader 来跑,扫描、迁移、启用状态才不会对着两套目录
        if (rtrim(str_replace('\\', '/', $root), '/') !== rtrim(str_replace('\\', '/', $this->pluginsRoot), '/')) {
            return (new self($this->options, $this->db, $root, $this->registerPsr4))->install($upload, $replace);
        }

        $installer = new ZipInstaller($this->pluginsRoot);
        $slug = $installer->inspect($upload);

        if (is_dir($this->pluginsRoot . '/' . $slug)) {
            if (!$replace) {
                throw new PluginValidationException("插件 {$slug} 已存在,不能重复安装。");
            }

            return $this->upgrade($slug, $upload);
        }

        do_action('plugin/install/before', $slug);

        $result = $installer->install($upload);

        // 新目录得进内存索引,同一请求里点「启用」才看得到它
        $this->scan(true);

        do_action('plugin/install/after', $slug, $result['new_version']);

        return $result + ['warning' => ''];
    }

    /**
     * 覆盖升级:自动停用 → 升级 → 按原状态恢复启用。
     *
     * 版本不升反降、同版本都拒绝 —— 升级包不是重装工具。任何一步失败都把目录还原到旧版;
     * DDL 已经跑出去的部分收不回(PluginMigrator 会尽量补偿,失败信息里报跑到哪一条),这是
     * MySQL 的规矩,不是这里偷懒。升级不跑 down(),也不清表:插件数据要穿过版本活下来。
     *
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     * @return array{action: string, slug: string, old_version: string, new_version: string, backup: string, warning: string}
     */
    public function upgrade(string $slug, array $upload): array
    {
        $installer = new ZipInstaller($this->pluginsRoot);

        // 目录、清单、slug、版本四道闸门都在 prepareUpgrade 里,这里只按顺序插停用/备份/钩子
        $prepared = $installer->prepareUpgrade($slug, $upload);
        $oldVersion = $prepared['old_version'];
        $newVersion = $prepared['new_version'];

        $wasActive = in_array($slug, $this->activeNames(), true);
        $deactivated = false;
        $swapped = false;
        $backup = null;

        try {
            if ($wasActive) {
                // 先停用再换代码:换到一半的插件不该继续接线上请求(审计由 deactivate() 记)
                $this->deactivate($slug);
                $deactivated = true;
            }

            $backup = $installer->backup($slug);

            do_action('plugin/upgrade/before', $slug, $oldVersion, $newVersion);

            $installer->replace($slug, $prepared['staging']);
            $swapped = true;

            // 换完代码重新扫,后面的迁移与启用都该看到新清单
            $this->scan(true);
            $plugin = $this->available[$slug] ?? null;

            if ($plugin === null) {
                throw new PluginLoadException('新版清单在落盘后无法加载:' . ($this->broken[$slug] ?? '未知原因'));
            }

            $this->registerPsr4For($plugin);
            (new PluginMigrator($this->db))->upgrade($plugin);

            do_action('plugin/upgrade/after', $slug, $oldVersion, $newVersion);
        } catch (Throwable $e) {
            $installer->discardStaging($prepared['staging']);

            if ($swapped && $backup !== null) {
                try {
                    $installer->restore($slug, $backup);
                } catch (Throwable $restoreError) {
                    Log::channel('plugin')->error('plugin.upgrade.restore_failed', [
                        'plugin' => $slug,
                        'error' => $restoreError->getMessage(),
                    ]);

                    throw new PluginLoadException(
                        "插件 {$slug} 升级失败,且旧目录还原失败:" . $restoreError->getMessage()
                        . ';原始错误:' . $e->getMessage(),
                        0,
                        $e
                    );
                }

                $this->scan(true);
            }

            $warning = $deactivated && $wasActive ? $this->restoreActivation($slug) : '';
            $message = "插件 {$slug} 升级到 {$newVersion} 失败,已回滚到 {$oldVersion}";

            throw new PluginLoadException(
                $message . ($warning === '' ? '' : ';' . $warning) . ':' . $e->getMessage(),
                0,
                $e
            );
        }

        $warning = $wasActive ? $this->restoreActivation($slug) : '';

        Audit::record('plugin.upgrade', $slug);

        return [
            'action' => 'upgraded',
            'slug' => $slug,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'backup' => $backup,
            'warning' => $warning,
        ];
    }

    /**
     * 升级成功后按原状态恢复启用。启不回来时不推翻已经成功的新版 —— 保留新版、保持停用,
     * 把原因带回回执,让人知道下一步该修什么。
     */
    private function restoreActivation(string $slug): string
    {
        try {
            $this->activate($slug);

            return '';
        } catch (Throwable $e) {
            Log::channel('plugin')->warning('plugin.upgrade.reactivate_failed', [
                'plugin' => $slug,
                'error' => $e->getMessage(),
            ]);

            return '恢复启用失败,插件保持停用:' . $e->getMessage();
        }
    }

    /**
     * plugins/ 本身可不可写。装卸插件都要在它下面增删目录,而 PHP 常以 www 跑:
     * 目录属主被部署改成 root 时,里面删得掉、目录本身删不掉(线上真实踩过)。
     */
    public function writable(): bool
    {
        return is_writable($this->pluginsRoot);
    }

    /**
     * 物理删除(《85》本轮口径):目录连文件一起清,磁盘与 {p}plugins 注册行都不留。
     * 名字来自请求,这里只认扫描到的真实插件,目录取清单里的,绝不拿参数拼路径;
     * 启用中的一律拒绝 —— 停在逻辑层的插件不能带着激活态被拔掉。
     */
    public function delete(string $name): void
    {
        $this->scan();
        $plugin = $this->available[$name] ?? null;

        if ($plugin === null) {
            throw new PluginLoadException("插件 {$name} 不可删除:" . ($this->broken[$name] ?? '目录不存在或 name 与目录不符'));
        }

        if (in_array($name, $this->activeNames(), true)) {
            throw new PluginLoadException("插件 {$name} 正在启用中,先停用再删除");
        }

        if (!$this->writable()) {
            throw new PluginLoadException("插件目录不可写: {$this->pluginsRoot}");
        }

        // 校验都过了、确定要删了才播报:before 的监听此刻还能读到目录与清单(《11》§7.4)
        do_action('plugin/uninstall/before', $name);

        // 回滚要读迁移文件,必须跑在删目录之前;失败就整个中断,目录与注册行都留着还好重试
        $this->registerPsr4For($plugin);
        (new PluginMigrator($this->db))->purge($plugin);

        // 整棵清:Files::deleteTree 对 unlink / rmdir 都带退避重试,比这里自己走一遍 scandir 稳
        $dir = $plugin->manifest->dir;
        Files::deleteTree($dir);

        // 留了残骸就是没删干净:先别动表,让人重试
        if (file_exists($dir) || is_link($dir)) {
            throw new PluginLoadException("插件目录没有删干净: {$name}");
        }

        $this->db->delete('plugins', 'slug = :slug', ['slug' => $name]);
        unset($this->available[$name]);
        $this->writeCache($this->activeFromTable());

        // 索引要跟着变:删掉的可能正是缓存里那些残留条目(after 的监听应看到"删干净了"的索引)
        $this->reorder($this->activeNames());
        HookMap::instance()->rebuild($this->ordered());

        do_action('plugin/uninstall/after', $name);
    }

    /**
     * 清理「装不上的」残骸:清单读不出来的目录没有 Plugin 对象可走,只能按目录名整棵删。
     * 连同 {p}plugins 里那行与启用名单里的同名项一起摘掉 —— 它本来就加载不了,留着只会每次都报。
     *
     * 只认 broken()/skipped() 自己报出来的名字:能正常读出来的插件走 delete(),这里不接手;
     * 名字再过一道字符白名单,免得请求参数拼出 plugins/ 之外的路径。
     */
    public function purge(string $dir): void
    {
        $this->scan();
        $slug = trim($dir);
        $reported = isset($this->broken[$slug]) || isset($this->skipped[$slug]);

        if (!$reported || preg_match('#\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z#', $slug) !== 1) {
            throw new PluginLoadException('清理失败:这个目录不在「装不上的」名单里');
        }

        $path = $this->pluginsRoot . '/' . $slug;

        // 符号链接只删链接本身都不做:不跟进去,也不替人猜链接指向哪
        if (is_link($path)) {
            throw new PluginLoadException("清理失败: {$slug} 是符号链接,请到服务器上处理");
        }

        // 残骸清理也是"卸载":目录还在,监听方仍能取证
        do_action('plugin/uninstall/before', $slug);

        if (is_dir($path)) {
            if (!$this->writable()) {
                throw new PluginLoadException("插件目录不可写: {$this->pluginsRoot}");
            }

            Files::deleteTree($path);

            if (file_exists($path)) {
                throw new PluginLoadException("目录没有删干净: {$slug}");
            }
        }

        $this->db->delete('plugins', 'slug = :slug', ['slug' => $slug]);
        unset($this->broken[$slug], $this->skipped[$slug]);
        $this->writeCache($this->activeFromTable());

        $this->reorder($this->activeNames());
        HookMap::instance()->rebuild($this->ordered());

        do_action('plugin/uninstall/after', $slug);
    }

    /**
     * R2 / R4:已加载插件的"实际注册"与"声明"对账。
     */
    public function checkDeclarations(): void
    {
        foreach ($this->ordered() as $plugin) {
            if (!$plugin->isLoaded()) {
                continue;
            }

            $name = $plugin->name();
            $declared = $plugin->manifest->hooks();
            $registered = HookBus::instance()->hooksRegisteredBy($name);
            $undeclared = array_values(array_filter(
                $registered,
                static fn (string $hook): bool => !self::hookMatches($hook, $declared)
            ));

            if ($undeclared !== []) {
                $message = sprintf(
                    'plugin "%s" registered callback on "%s" but did NOT declare it in plugin.json hooks.'
                    . ' 修复:在该插件 plugin.json 的 hooks 数组里补上这些名字。',
                    $name,
                    implode('", "', $undeclared)
                );

                if (APP_DEBUG) {
                    throw new HookDeclarationMismatch($message);
                }

                // R4:生产不报错、不崩站,只留痕
                Log::channel('plugin')->warning($message, ['plugin' => $name, 'undeclared' => $undeclared]);
            }

            $unused = array_values(array_filter(
                $declared,
                static fn (string $pattern): bool => !str_contains($pattern, '*') && !in_array($pattern, $registered, true)
            ));

            if ($unused !== []) {
                Log::channel('plugin')->info(
                    "插件 {$name} 声明了但还没注册的钩子(可能懒加载尚未触发)",
                    ['plugin' => $name, 'declared_unused' => $unused]
                );
            }
        }
    }

    /**
     * 把已启用插件的自定义路由注册进 Router(《12》§7.2、《23》§5)。
     *
     * 必须在 dispatch 之前调用(boot() 之后、@see index.php 分发请求之前)。只认「已启用」这一份
     * 真相:停用即不注册。与内核路径撞车的插件整体跳过 —— 该在 activate() 就被拒,
     * 这里再兜一道,免得漏网的把站点打成 500。
     */
    public function registerRoutes(Router $router): void
    {
        $this->scan();

        foreach ($this->activeNames() as $name) {
            $plugin = $this->available[$name] ?? null;

            if ($plugin === null) {
                continue;
            }

            if ($this->routeConflicts($plugin) !== []) {
                Log::channel('plugin')->warning('插件自定义路由与内核冲突,跳过注册', ['plugin' => $name]);

                continue;
            }

            // handler 的类要靠插件自己的 PSR-4 解析;这里兜一道,免得调用方漏了 boot()
            $this->registerPsr4For($plugin);

            foreach ($plugin->manifest->routes() as $route) {
                [$class, $method] = explode('@', $route['handler'], 2);

                if (!$this->handlerUsable($class, $method)) {
                    Log::channel('plugin')->warning('plugin.route.handler_unusable', [
                        'plugin' => $name,
                        'handler' => $route['handler'],
                    ]);

                    continue;
                }

                try {
                    $router->add($route['name'], $route['method'], $route['path'], $this->routeHandler($route));
                } catch (RouteException $e) {
                    // 跨插件撞同一条:不把站点打成 500,记下来
                    Log::channel('plugin')->warning('插件路由注册失败:' . $e->getMessage(), ['plugin' => $name]);
                }
            }
        }
    }

    /**
     * 插件自定义路由与内核路径的冲突(《23》§5.1:内核优先)。整条相同,或路径开头与内核保留段重名。
     *
     * 内核只保留自己路径开头的**静态段**:`/admin/*` 保留 `admin`,`/api/v1/*` 保留 `api/v1` ——
     * 故 `/api/` 下的插件 webhook 不与之撞车,而 `/api/v1/...` 仍归内核。
     *
     * @return list<string>
     */
    private function routeConflicts(Plugin $plugin): array
    {
        $reasons = [];

        foreach ($plugin->manifest->routes() as $route) {
            $owner = self::kernelPathOwnedBy($route['path']);

            if ($owner !== null) {
                $reasons[] = sprintf('%s %s 与内核路径「%s」冲突', $route['method'], $route['path'], $owner);
            }
        }

        return $reasons;
    }

    /**
     * 这条路径是否被内核占着:整条相同,或以某个内核保留段开头。
     */
    private static function kernelPathOwnedBy(string $path): ?string
    {
        foreach (Router::kernelNames() as $route) {
            if (in_array($path, $route['paths'], true)) {
                return $path;
            }
        }

        $trimmed = trim($path, '/');

        foreach (self::kernelReservedPrefixes() as $prefix) {
            if ($trimmed === $prefix || str_starts_with($trimmed, $prefix . '/')) {
                return '/' . $prefix;
            }
        }

        return null;
    }

    /**
     * 内核各路径开头的**最长静态段**。取最长而非逐段累计:逐段会把 `/api` 也圈进来,
     * `/api/` 下的插件 webhook 就没活路了。
     *
     * @return list<string>
     */
    private static function kernelReservedPrefixes(): array
    {
        $prefixes = [];

        foreach (Router::kernelNames() as $route) {
            foreach ($route['paths'] as $kernelPath) {
                $run = [];

                foreach (explode('/', trim($kernelPath, '/')) as $segment) {
                    if ($segment === '' || str_contains($segment, '{')) {
                        break;
                    }

                    $run[] = $segment;
                }

                if ($run !== []) {
                    $prefixes[] = implode('/', $run);
                }
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * handler 可用性(《12》§14 的后台页控制器同口径):类在、方法在且公开、能无参构造、
     * 形参不多于 2(内核只传 request 与 vars)。任一不过 → 该路由不生效,按 404 办。
     */
    private function handlerUsable(string $class, string $method): bool
    {
        if ($class === '' || !class_exists($class) || !method_exists($class, $method)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return $reflection->isInstantiable()
            && ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0
            && $reflection->getMethod($method)->isPublic()
            && $reflection->getMethod($method)->getNumberOfRequiredParameters() <= 2;
    }

    /**
     * @param array{name: string, method: string, path: string, handler: string, auth: bool, guard: string} $route
     * @return Closure(Request, array<string, mixed>): mixed
     */
    private function routeHandler(array $route): Closure
    {
        [$class, $method] = explode('@', $route['handler'], 2);
        $needsLogin = $route['auth'];
        $guard = $route['guard'];
        $write = in_array($route['method'], ['POST', 'PUT', 'DELETE'], true);

        return static function (Request $request, array $vars = []) use ($class, $method, $needsLogin, $guard, $write): mixed {
            // guard:none 是有意留的 webhook 口子(免会话、免 CSRF),范围已在清单层钉死在 /api/ 下
            if ($guard === 'session') {
                if ($needsLogin && Auth::user() === null) {
                    // 未登录:当作没有这条路由,不暴露它的存在
                    return Response::text('Not Found', 404);
                }

                if ($write) {
                    $session = Auth::session();

                    if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
                        return Response::text('Forbidden', 403);
                    }
                }
            }

            // 无参构造,与 AdminPageController 同一口径;可用性在注册时已判过
            if (!class_exists($class)) {
                return Response::text('Not Found', 404);
            }

            /** @var callable(Request, array<string, mixed>): mixed $call */
            $call = [new $class(), $method];

            return $call($request, $vars);
        };
    }

    /**
     * 扫 plugins/ 目录。结果走 PluginIndex 缓存;后台插件页进入时传 force 刷一次。
     */
    public function scan(bool $force = false): void
    {
        if ($this->scanned && !$force) {
            return;
        }

        $wasScanned = $this->scanned;
        $result = (new PluginIndex($this->pluginsRoot, $this->indexCacheFile()))->all($force);

        $this->available = $result['available'];
        $this->broken = $result['broken'];
        $this->scanned = true;

        // 目录换过一轮,旧的加载顺序里可能有已经不存在的插件;按当前启用名单重排
        if ($force && $wasScanned) {
            $this->reorder($this->activeNames());
        }
    }

    /**
     * 测试与多站点用自己的 plugins 根时,缓存放根下;只有正式站点的 plugins/ 才写 storage/cache,
     * 免得同一台机器上两套目录互相顶掉指纹。
     */
    private function indexCacheFile(): string
    {
        $root = rtrim(str_replace('\\', '/', $this->pluginsRoot), '/');
        $canonical = rtrim(str_replace('\\', '/', MOLIN_ROOT), '/') . '/plugins';

        return $root === $canonical
            ? MOLIN_ROOT . '/storage/cache/plugins.index.php'
            : $root . '/.plugins.index.php';
    }

    private function registerPsr4For(Plugin $plugin): void
    {
        ($this->registerPsr4)(
            $plugin->manifest->namespacePrefix(),
            $plugin->manifest->sourceDirectory()
        );
    }

    /**
     * 按启用名单排出加载顺序(拓扑排序,循环依赖即报错)。
     *
     * @param list<string> $names
     */
    private function reorder(array $names): void
    {
        $plugins = [];

        foreach ($names as $name) {
            if (isset($this->available[$name])) {
                $plugins[] = $this->available[$name];
            }
        }

        $this->order = $this->topoSort($plugins);
    }

    /**
     * @param list<Plugin> $plugins
     * @return list<string>
     */
    private function topoSort(array $plugins): array
    {
        $names = [];
        $edges = [];
        $inDegree = [];

        foreach ($plugins as $plugin) {
            $name = $plugin->name();
            $names[] = $name;
            $inDegree[$name] = 0;
            $edges[$name] = [];
        }

        $known = array_flip($names);

        foreach ($plugins as $plugin) {
            $name = $plugin->name();

            // after: 这些插件先加载 → 它们指向 $name
            foreach ($plugin->manifest->orderAfter() as $other) {
                if (isset($known[$other])) {
                    $edges[$other][] = $name;
                    $inDegree[$name]++;
                }
            }

            // before: $name 先加载 → $name 指向它们
            foreach ($plugin->manifest->orderBefore() as $other) {
                if (isset($known[$other])) {
                    $edges[$name][] = $other;
                    $inDegree[$other]++;
                }
            }
        }

        $ready = [];

        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $ready[] = $name;
            }
        }

        sort($ready);

        $sorted = [];

        while ($ready !== []) {
            $name = array_shift($ready);
            $sorted[] = $name;
            $newlyReady = [];

            foreach ($edges[$name] as $next) {
                $inDegree[$next]--;

                if ($inDegree[$next] === 0) {
                    $newlyReady[] = $next;
                }
            }

            foreach ($newlyReady as $candidate) {
                $ready[] = $candidate;
            }

            sort($ready);
        }

        if (count($sorted) !== count($names)) {
            $stuck = array_values(array_diff($names, $sorted));
            sort($stuck);

            throw new PluginLoadException('插件加载顺序存在循环依赖:' . implode(' ↔ ', $stuck));
        }

        return $sorted;
    }

    /**
     * @param list<string> $patterns
     */
    private static function hookMatches(string $hook, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*') {
                return true;
            }

            if (str_ends_with($pattern, '/*') && explode('/', $hook)[0] === substr($pattern, 0, -2)) {
                return true;
            }

            if ($pattern === $hook) {
                return true;
            }
        }

        return false;
    }

    private function mysqlVersion(): ?string
    {
        try {
            $row = $this->db->selectOne('SELECT VERSION() AS v');
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : (string) $row['v'];
    }
}
