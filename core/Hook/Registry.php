<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Hook;

/**
 * 钩子命名契约与 1.0 冻结清单。数据源:既有规范与第 7 节。
 *
 * 每个钩子的参数与返回签名登记在 core/Hook/signatures.php,
 * 那些签名逐条来自既有规范等文档,随对应模块落地时一并写入;
 * 本类承载两层校验:名字与类型(注册时)、参数与返回值(触发时)。
 *
 * @phpstan-type Signature array{type: string, since: string, args: list<array{name: string, type: string}>, returns: string, stability: string, doc: string}
 */
final class Registry
{
    /** 阶段封闭词表,不许自造 */
    public const STAGES = ['before', 'after', 'render', 'validate', 'collect', 'replace'];

    /**
     * 词表之外的合法例外(既有规范已白名单冻结):
     * 结果类阶段 failed 只允许挂在 user/login/failed 上。
     */
    public const STAGE_EXCEPTIONS = ['user/login/failed'];

    /** 段内形态:小写 kebab-case;用 \A..\z 而非 ^..$,免得尾部换行蒙混过关 */
    public const SEGMENT_PATTERN = '#\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z#';

    /** 1.0 冻结钩子:钩子名 => filter|action,只增不改 */
    public const FROZEN = [
        // content 域(10):content 与 post 同属内容业务域
        'content/fetch/before'      => 'filter',
        'content/fetch/after'       => 'filter',
        'content/save/before'       => 'filter',
        'content/save/after'        => 'action',
        'content/render/before'     => 'filter',
        'content/render/after'      => 'filter',
        'content/delete/before'     => 'action',
        'post/publish/before'       => 'action',
        'post/publish/after'        => 'action',
        'post/status/after'         => 'action',

        // user 域(8):`user/lostpassword/after` 随发信插件落地增补(2026-10-04,since 0.49)
        'user/register/before'      => 'filter',
        'user/register/after'       => 'action',
        'user/login/before'         => 'filter',
        'user/login/after'          => 'action',
        'user/login/failed'         => 'action',
        'user/logout/after'         => 'action',
        'user/lostpassword/after'   => 'action',
        'user/capability/validate'  => 'filter',

        // theme 域(8):`theme/setting/after` 原为既有规范候选,#43 起用并登记(since 0.39)
        'theme/activate/before'     => 'action',
        'theme/activate/after'      => 'action',
        'theme/setting/after'       => 'filter',
        'theme/template/replace'    => 'filter',
        'theme/head/render'         => 'action',
        'theme/footer/render'       => 'action',
        'theme/sidebar/collect'     => 'filter',
        // 能力开关(0.97):插件停用后模板据此隐藏社交/通知 UI
        'theme/features/collect'    => 'filter',

        // plugin 域(9)
        'plugin/install/before'     => 'action',
        'plugin/install/after'      => 'action',
        'plugin/activate/before'    => 'action',
        'plugin/activate/after'     => 'action',
        'plugin/deactivate/after'   => 'action',
        'plugin/uninstall/before'   => 'action',
        'plugin/uninstall/after'    => 'action',
        'plugin/upgrade/before'     => 'action',
        'plugin/upgrade/after'      => 'action',

        // media 域(5):`media/upload/replace` 随图片压缩插件落地(0.2.0)
        'media/upload/before'       => 'filter',
        'media/upload/replace'      => 'filter',
        'media/upload/after'        => 'action',
        'media/delete/after'        => 'action',
        'media/render/after'        => 'filter',

        // comment 域(6)
        'comment/submit/before'     => 'filter',
        'comment/submit/after'      => 'action',
        'comment/moderate/before'   => 'filter',
        'comment/moderate/after'    => 'action',
        'comment/render/before'     => 'filter',
        'comment/render/after'      => 'filter',

        // cache 域(1)
        'cache/invalidate/after'    => 'action',

        // api 域(1)
        'api/response/before'       => 'filter',

        // admin 域(2)
        'admin/menu/collect'        => 'filter',
        'admin/dashboard/collect'   => 'filter',
        'admin/editor/collect'      => 'filter',
        'admin/editor/render'       => 'action',

        // system 域(3):两条两段式生命周期钩子 + 插件定时任务收集
        'system/init'               => 'action',
        'system/shutdown'           => 'action',
        'system/cron/collect'       => 'filter',

        // site 域(3):站点输出(sitemap / feed / seo)的收集与替换口
        'site/sitemap/collect'      => 'filter',
        'site/feed/collect'         => 'filter',
        'site/seo/replace'          => 'filter',

        // social 域(1):关注 / 赞踩 / 通知的实现由插件提供,内核只留接口与路由
        'social/services/collect'   => 'filter',
    ];

    /** 既有规范候选钩子:已登记、尚未冻结,启用时补 since 版本号 */
    public const CANDIDATE = [
        'icon/registry/collect'        => 'filter',
        'admin/editor-fields/collect'  => 'filter',
        'content/custom-field/render'  => 'filter',
        'admin/post-bulk/collect'      => 'filter',
        'admin/post-row/collect'       => 'filter',
        'device/detect/replace'        => 'filter',
        'route/not-found/render'       => 'action',
    ];

    /**
     * 已冻结、但**内核里还没有触发点**的钩子(2026-10-17 扫源码点出 15 条;2026-10-22 第三批接线后剩 2 条)。
     *
     * 这些名字注册不会报错(冻结契约的一部分),但内核此刻不会触发它们 —— 插件照着文档挂上去,
     * 表现就是"注册成功、永远不执行",这种坑最劝退开发者。这张表的作用就是把这件事说破:
     * 接线时从表里删掉一个,`HookWiringContractTest` 会盯着"表"与"源码里到底有没有**真实调用点**"两边一致。
     *
     * 每条都写清为什么没接 —— "接不了"和"忘了接"必须一眼分得开。
     *
     * @var list<string>
     */
    public const NOT_YET_WIRED = [
        // 侧栏挂件收集:挂件子系统 2026-10-27 已整块删除(默认主题只画了一个页脚位,
        // 界面上另一半永远不渲染),这条 filter 名暂时留档、不接线;将来若重做主题侧栏再议。
        'theme/sidebar/collect',
        // 媒体渲染:`image()` 的 <img> 拼装只在 Theme\Renderer 里(第三批),本批禁改;
        // MediaRepository::imageData() 产出的是数据数组而不是 HTML,挂在那里名不副实。
        'media/render/after',
    ];

    /** @var array<string, Signature>|null */
    private static ?array $signatures = null;

    /**
     * 阶段是否被允许:词表内,或命中已冻结的 failed 例外。
     */
    public static function stageAllowed(string $hook, string $stage): bool
    {
        if (in_array($stage, self::STAGES, true)) {
            return true;
        }

        return $stage === 'failed' && in_array($hook, self::STAGE_EXCEPTIONS, true);
    }

    public static function isFrozen(string $hook): bool
    {
        return isset(self::FROZEN[$hook]);
    }

    public static function isCandidate(string $hook): bool
    {
        return isset(self::CANDIDATE[$hook]);
    }

    /**
     * 已登记钩子的声明类型;未登记的自定义钩子返回 null(插件领域事件,既有规范D6)。
     */
    public static function declaredType(string $hook): ?string
    {
        return self::FROZEN[$hook] ?? self::CANDIDATE[$hook] ?? null;
    }

    /**
     * 签名表。只读一次,运行期不再碰磁盘。
     *
     * @return array<string, Signature>
     */
    public static function signatures(): array
    {
        if (self::$signatures === null) {
            /** @var array<string, Signature> $table */
            $table = require __DIR__ . '/signatures.php';
            self::$signatures = $table;
        }

        return self::$signatures;
    }

    /**
     * @return Signature|null
     */
    public static function signature(string $hook): ?array
    {
        return self::signatures()[$hook] ?? null;
    }

    /**
     * 参数类型校验。只查实参,少传由回调自己的 acceptedArgs 决定。
     *
     * @param array<array-key, mixed> $passed
     */
    public static function validateArgs(string $hook, array $passed): void
    {
        $signature = self::signature($hook);

        if ($signature === null) {
            return;
        }

        $values = array_values($passed);

        foreach ($signature['args'] as $index => $arg) {
            if (!array_key_exists($index, $values)) {
                continue;
            }

            if (!self::matches($values[$index], $arg['type'])) {
                throw new HookTypeException(sprintf(
                    "钩子 '%s' 的参数 %s 类型不符:期望 %s,实得 %s",
                    $hook,
                    $arg['name'],
                    $arg['type'],
                    get_debug_type($values[$index])
                ));
            }
        }
    }

    /**
     * 返回值类型校验。
     */
    public static function validateReturn(string $hook, mixed $value): void
    {
        $signature = self::signature($hook);

        if ($signature === null || $signature['type'] !== 'filter') {
            return;
        }

        if (!self::matches($value, $signature['returns'])) {
            throw new HookTypeException(sprintf(
                "filter '%s' 的返回值类型不符:期望 %s,实得 %s",
                $hook,
                $signature['returns'],
                get_debug_type($value)
            ));
        }
    }

    /**
     * 类型写法判定:标量、类名、`Foo[]`、`string|int`、`?string`。
     * 公开是为了让 CLI 与契约测试能复核登记表里的类型写法。
     */
    public static function matches(mixed $value, string $type): bool
    {
        if ($type === 'mixed') {
            return true;
        }

        foreach (explode('|', $type) as $part) {
            if (str_starts_with($part, '?')) {
                $part = substr($part, 1);

                if ($value === null) {
                    return true;
                }
            }

            $isList = str_ends_with($part, '[]');

            if (self::matchesOne($value, $isList ? substr($part, 0, -2) : $part, $isList)) {
                return true;
            }
        }

        return false;
    }

    private static function matchesOne(mixed $value, string $type, bool $asList): bool
    {
        if ($asList) {
            if (!is_array($value)) {
                return false;
            }

            foreach ($value as $item) {
                if (!self::matches($item, $type)) {
                    return false;
                }
            }

            return true;
        }

        return match ($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'bool' => is_bool($value),
            'float' => is_float($value) || is_int($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'null' => $value === null,
            'callable' => is_callable($value),
            default => is_a($value, $type),
        };
    }
}
