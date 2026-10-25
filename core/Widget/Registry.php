<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Widget;

/**
 * 挂件注册表(《108》§11-5:挂件注册 API 此前未定,本类即那个口)。
 *
 * 主题与插件经 register_widget() 往里登记;登记了什么就有什么 ——
 * 后台左栏「可用挂件」与前台 `widget_area()` 助手读的是同一份,不各存一套清单。
 * 「哪个区放哪些、什么顺序」不在这里,那是 option `theme_widgets` 的事。
 *
 * 同名第一次赢:重复登记多半是主题与插件撞了名,先到者认领、后到者不许悄悄覆盖 ——
 * 否则前台渲染结果会随加载顺序漂移(同《11》钩子总线的"只加不改"口径)。
 */
final class Registry
{
    /** key 形态:小写字母/数字/下划线/短横 */
    private const KEY_PATTERN = '#\A[a-z0-9_-]+\z#';

    private static ?self $instance = null;

    /** @var array<string, array{name: string, desc: string, body: callable|null}> */
    private array $widgets = [];

    private function __construct()
    {
    }

    /** 进程内单例:注册发生在启动期,渲染期只读 */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * 登记一个挂件。key 不合法直接忽略;同名重复第一次赢。
     *
     * @param callable|null $body 取数口:吃上下文、吐一小段 HTML(见《108》§11-5)
     */
    public function register(string $key, string $name, string $desc = '', ?callable $body = null): void
    {
        $key = trim($key);

        if (preg_match(self::KEY_PATTERN, $key) !== 1 || isset($this->widgets[$key])) {
            return;
        }

        $this->widgets[$key] = ['name' => trim($name), 'desc' => trim($desc), 'body' => $body];
    }

    public function has(string $key): bool
    {
        return isset($this->widgets[$key]);
    }

    /**
     * 全部登记项,保持登记顺序。
     *
     * @return array<string, array{name: string, desc: string, body: callable|null}>
     */
    public function all(): array
    {
        return $this->widgets;
    }

    /** 清空登记表 —— 给测试用,线上不调 */
    public function reset(): void
    {
        $this->widgets = [];
    }
}
