<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Container;

use Closure;
use Psr\Container\ContainerInterface;

/**
 * 极简服务容器(《00-总览》第 5 节:自研极简 + psr/container)。
 * 内核层次浅,不做自动装配、不做注解扫描 —— 需要什么就注册什么。
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /**
     * 注册工厂。$shared 为真时同一个服务只造一次。
     */
    public function set(string $id, Closure $factory, bool $shared = true): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id] = $shared;
        unset($this->resolved[$id]);
    }

    /**
     * 注册已有对象(配置数组、已建连接等)。
     */
    public function instance(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (!isset($this->bindings[$id])) {
            throw new NotFoundException("服务未注册: {$id}");
        }

        $object = ($this->bindings[$id])($this);

        if ($this->shared[$id] ?? false) {
            $this->resolved[$id] = $object;
        }

        return $object;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || array_key_exists($id, $this->resolved);
    }
}
