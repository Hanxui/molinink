<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Hook;

use Molin\Exception\MolinException;
use Molin\Logger\Log;
use Throwable;

/**
 * 钩子总线:注册与触发。对外契约内部结构
 * (含 `withPlugin` 作用域助手:给"内核直呼插件"的入口划归属,)。
 */
final class HookBus
{
    /** 允许两段式的生命周期钩子,封闭集合 */
    private const LIFECYCLE = ['system/init', 'system/shutdown'];

    private const PRIORITY_MIN = 1;

    /** 100+ 为内核保留段位,插件注册被夹回此处 */
    private const PRIORITY_PLUGIN_MAX = 99;

    private static ?self $instance = null;

    /** @var array<string, array<int, array<int, array{cb: callable, args: int, plugin: string|null, seq: int}>>> */
    private array $callbacks = [];

    /** @var list<string> 插件在拓扑序中的位次,由插件系统在加载时注入 */
    private array $pluginOrder = [];

    /** @var array<string, int> 钩子名 => 触发次数 */
    private array $didAction = [];

    /** @var list<string> 调用栈,支持嵌套 */
    private array $current = [];

    /** @var list<string> 正在加载的插件(懒加载 require 期间归属用) */
    private array $loading = [];

    /** @var list<string|null> 正在执行的回调归属(注册类 API 记来源用) */
    private array $executing = [];

    private int $seq = 0;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function add(
        string $hook,
        callable $cb,
        int $priority = 10,
        int $args = 1,
        ?string $plugin = null
    ): void {
        $hook = $this->normalize($hook);
        $prio = $this->clampPriority($priority, $plugin !== null);

        $this->callbacks[$hook][$prio][] = [
            'cb'     => $cb,
            'args'   => max(1, $args),
            'plugin' => $plugin ?? $this->loadingPlugin(),
            'seq'    => $this->seq++,
        ];
    }

    /**
     * 注销一个回调。闭包按同一实例匹配,字符串与 [类, 方法] 数组按值匹配。
     */
    public function remove(string $hook, callable $cb, int $priority = 10): bool
    {
        $hook = strtolower($hook);
        $prio = $this->clampPriority($priority, false);

        foreach ($this->callbacks[$hook][$prio] ?? [] as $index => $handler) {
            if ($handler['cb'] !== $cb) {
                continue;
            }

            unset($this->callbacks[$hook][$prio][$index]);

            if ($this->callbacks[$hook][$prio] === []) {
                unset($this->callbacks[$hook][$prio]);
            }

            if ($this->callbacks[$hook] === []) {
                unset($this->callbacks[$hook]);
            }

            return true;
        }

        return false;
    }

    /**
     * filter:逐个执行,上一个的产出喂给下一个。
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $hook = $this->begin($hook);

        try {
            if (APP_DEBUG) {
                Registry::validateArgs($hook, [$value, ...$args]);
            }

            foreach ($this->sortHandlers($hook) as $handler) {
                $slice = array_slice([$value, ...$args], 0, $handler['args']);
                $plugin = $handler['plugin'];
                $this->executing[] = $plugin;

                try {
                    $result = ($handler['cb'])(...$slice);
                } catch (Throwable $e) {
                    $this->handleCallbackFailure($hook, $plugin, $e);
                    // 插件坏了不等于链断了:filter 以入参原值继续,后面的回调照常跑
                    $result = $value;
                } finally {
                    array_pop($this->executing);
                }

                // 既有规范条 + 漏返回值在开发模式当场炸,
                // 生产模式按"不改"容错,绝不让一个插件的 bug 白屏整站。
                if (APP_DEBUG && $result === null && $value !== null) {
                    throw new HookTypeException("filter '{$hook}' 的回调未返回值");
                }

                if ($result !== null) {
                    $value = $result;
                }

                if (APP_DEBUG) {
                    Registry::validateReturn($hook, $value);
                }
            }

            return $value;
        } finally {
            $this->end();
        }
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $hook = $this->begin($hook);

        try {
            if (APP_DEBUG) {
                Registry::validateArgs($hook, $args);
            }

            foreach ($this->sortHandlers($hook) as $handler) {
                $slice = array_slice($args, 0, $handler['args']);
                $plugin = $handler['plugin'];
                $this->executing[] = $plugin;

                try {
                    ($handler['cb'])(...$slice);
                } catch (Throwable $e) {
                    // action 没有返回值要保,跳过这一个回调,后面接着播
                    $this->handleCallbackFailure($hook, $plugin, $e);
                } finally {
                    array_pop($this->executing);
                }
            }
        } finally {
            $this->end();
        }
    }

    public function has(string $hook, ?callable $cb = null): bool
    {
        $hook = strtolower($hook);

        foreach ($this->callbacks[$hook] ?? [] as $handlers) {
            if ($cb === null) {
                return true;
            }

            foreach ($handlers as $handler) {
                if ($handler['cb'] === $cb) {
                    return true;
                }
            }
        }

        return false;
    }

    public function did(string $hook): int
    {
        return $this->didAction[strtolower($hook)] ?? 0;
    }

    public function current(): ?string
    {
        return $this->current === [] ? null : $this->current[count($this->current) - 1];
    }

    /**
     * 注入插件拓扑序,让同优先级下的执行顺序可预测。
     *
     * @param list<string> $order
     */
    public function orderPlugins(array $order): void
    {
        $this->pluginOrder = array_values($order);
    }

    /**
     * 该插件实际注册了哪些钩子(既有规范R2 漏声明检测的依据)。
     *
     * @return list<string>
     */
    public function hooksRegisteredBy(string $plugin): array
    {
        $hooks = [];

        foreach ($this->callbacks as $hook => $byPriority) {
            foreach ($byPriority as $handlers) {
                foreach ($handlers as $handler) {
                    if ($handler['plugin'] === $plugin) {
                        $hooks[$hook] = true;
                        continue 3;
                    }
                }
            }
        }

        $list = array_keys($hooks);
        sort($list);

        return $list;
    }

    /**
     * 懒加载 require 插件入口期间标记归属,让插件里的 add_filter() 自动带上自己的名字。
     */
    public function beginLoading(string $plugin): void
    {
        $this->loading[] = $plugin;
    }

    public function endLoading(): void
    {
        array_pop($this->loading);
    }

    /**
     * 清空全部状态。测试用,不是运行时接口。
     */
    public function flush(): void
    {
        $this->callbacks = [];
        $this->pluginOrder = [];
        $this->didAction = [];
        $this->current = [];
        $this->loading = [];
        $this->executing = [];
        $this->seq = 0;
    }

    /**
     * 一次触发开场:定名、计数、懒加载,最后才压栈。返回归一化后的钩子名。
     *
     * 压栈放在懒加载之后:插件入口抛异常时 begin() 直接向外抛,外层的 finally 收不到,
     * 若此时栈里已留着本次钩子名,后续请求内的 current_hook() 就会看到脏栈。
     * 非法钩子名在 normalize 里当场抛出,同样不碰栈。
     */
    private function begin(string $hook): string
    {
        $hook = $this->normalize($hook);
        $this->didAction[$hook] = ($this->didAction[$hook] ?? 0) + 1;

        $this->loadLazy($hook);
        $this->current[] = $hook;

        return $hook;
    }

    private function end(): void
    {
        array_pop($this->current);
    }

    /**
     * 命中钩子时才拉起相关插件。必须早于读取回调列表,
     * 否则本次触发会漏掉刚注册进来的回调。
     */
    private function loadLazy(string $hook): void
    {
        HookMap::instance()->loadFor($hook);
    }

    /**
     * 回调抛异常时的分流:内核自己的代码与契约层异常照抛,插件抛的普通异常只记不炸。
     *
     * 为什么不一律兜住:`MolinException` 家族是**有意的控制流** —— 能力门卫拒权
     * (`CapabilityDeniedException`)、`user/login/before` 里抛校验异常中止登录、
     * `plugin/activate/before` 里拦下依赖不满足的启用,既有规范都要求它一路抛到调用方。
     * 把它们吞掉等于把安全闸和"可中止"语义一起废掉。
     *
     * 兜底只包插件注册的回调($plugin !== null):内核回调是内核自己的 bug,该炸在开发机与测试里,
     * 被这层吞掉只会变成"线上少了一段 HTML"的哑谜。
     */
    private function handleCallbackFailure(string $hook, ?string $plugin, Throwable $e): void
    {
        if ($plugin === null || $e instanceof MolinException) {
            throw $e;
        }

        try {
            Log::channel('plugin')->error('plugin.callback_failed', [
                'hook' => $hook,
                'plugin' => $plugin,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (Throwable) {
            // 日志自己写不了(目录不可写等)不能把这次请求也带走,与 ErrorHandler::log 同一口径
        }
    }

    private function loadingPlugin(): ?string
    {
        return $this->loading === [] ? null : $this->loading[count($this->loading) - 1];
    }

    /**
     * 当前归属的插件:优先「正在执行的回调」,其次「正在加载的入口」。
     * 注册类 API(register_post_type / register_taxonomy)多在 system/init 的回调里调用,
     * 那时懒加载已经结束,只有 executing 栈说得清是谁注册的。
     */
    public function currentPlugin(): ?string
    {
        return $this->executing === []
            ? $this->loadingPlugin()
            : $this->executing[count($this->executing) - 1];
    }

    /**
     * 在"插件 $plugin 正在执行"的归属下跑 $fn,返回它的产出。
     *
     * 钩子回调与插件入口懒加载之外,内核还会直接调插件的东西 —— 插件的后台页控制器
     * (`AdminPageController`)就是这一类:它不在 executing / loading 两个栈上,于是
     * `currentPlugin()` 为 null、能力门卫放行,插件在那儿等于无归属。
     * 内核在调这类"直呼"入口时用本方法划出归属:压入与钩子回调同一套 executing 栈,
     * `currentPlugin()` 的口径一字不改;`finally` 保证弹出,`$fn` 抛异常也不留脏栈。
     *
     * @param callable(): mixed $fn
     */
    public function withPlugin(string $plugin, callable $fn): mixed
    {
        $this->executing[] = $plugin;

        try {
            return $fn();
        } finally {
            array_pop($this->executing);
        }
    }
    /**
     * 校验并归一化钩子名。
     */
    private function normalize(string $hook): string
    {
        $hook = strtolower($hook);

        if (in_array($hook, self::LIFECYCLE, true)) {
            return $hook;
        }

        if (substr_count($hook, '/') !== 2) {
            throw new HookTypeException("钩子名必须三段式: {$hook}");
        }

        [$domain, $object, $stage] = explode('/', $hook);

        foreach ([$domain, $object, $stage] as $segment) {
            if (preg_match(Registry::SEGMENT_PATTERN, $segment) !== 1) {
                throw new HookTypeException("非法段(须小写 kebab-case): {$segment}");
            }
        }

        if (!Registry::stageAllowed($hook, $stage)) {
            throw new HookTypeException("非法阶段: {$stage}");
        }

        return $hook;
    }

    private function clampPriority(int $priority, bool $fromPlugin): int
    {
        $max = $fromPlugin ? self::PRIORITY_PLUGIN_MAX : PHP_INT_MAX;

        return max(self::PRIORITY_MIN, min($max, $priority));
    }

    /**
     * @return list<array{cb: callable, args: int, plugin: string|null, seq: int, prio: int}>
     */
    private function sortHandlers(string $hook): array
    {
        $flat = [];

        foreach ($this->callbacks[$hook] ?? [] as $prio => $handlers) {
            foreach ($handlers as $handler) {
                $handler['prio'] = $prio;
                $flat[] = $handler;
            }
        }

        usort(
            $flat,
            fn (array $a, array $b): int => [
                $a['prio'],
                $this->pluginRank($a['plugin']),
                $a['plugin'] ?? '',
                $a['seq'],
            ] <=> [
                $b['prio'],
                $this->pluginRank($b['plugin']),
                $b['plugin'] ?? '',
                $b['seq'],
            ]
        );

        return $flat;
    }

    /**
     * 内核回调(无归属插件)最先,插件按拓扑序,未声明序的排在已声明之后。
     */
    private function pluginRank(?string $plugin): int
    {
        if ($plugin === null) {
            return -1;
        }

        $rank = array_search($plugin, $this->pluginOrder, true);

        return $rank === false ? count($this->pluginOrder) : $rank;
    }
}
