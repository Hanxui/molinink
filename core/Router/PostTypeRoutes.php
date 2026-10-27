<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Router;

use Molin\Content\PostTypeRegistry;
use Molin\Exception\RouteException;
use Molin\Http\Request;
use Molin\Http\Response;

/**
 * 自定义内容类型的前台路由。
 *
 * 单篇 = `/{rewrite.slug}/{slug}`,归档 = `/{rewrite.slug}`(`has_archive=true`,含 `/page/{n}` 分页)。
 * 段只从登记表取(`PostTypeRegistry`),请求输入永不进 URL 拼接 —— 没有可注入的路径口子。
 *
 * 路由名:单篇 `posttype.{type}`、归档 `posttype.{type}.archive`、归档分页 `posttype.{type}.archive.page`。
 * 与其他路由一样走 `path` 生成,模板与插件不拼字符串;这三个名字是**运行时**按登记表
 * 现挂的,不进 `core/Router/names.php` 的冻结表 —— 那张表只列内核固定路由。
 *
 * 与既有路由的冲突处理(明确、可解释):
 * - 非 `public` 或 `rewrite=false` 的类型:不挂任何前台路由,前台不可达;
 * - 前台段与内核静态首段重名(如 `category` / `admin`):整类跳过并给出中文原因,不把站点打成 500;
 * - 两个类型撞同一段:后挂的那条由 Router 抛冲突,这里捕获后同样跳过并记录。
 */
final class PostTypeRoutes
{
    /** 单篇路由名 */
    public static function singleName(string $type): string
    {
        return 'posttype.' . $type;
    }

    /** 归档路由名(无分页) */
    public static function archiveName(string $type): string
    {
        return 'posttype.' . $type . '.archive';
    }

    /** 归档分页路由名 */
    public static function archivePageName(string $type): string
    {
        return self::archiveName($type) . '.page';
    }

    /** 单篇路径模式(内核按同一套占位符现算路由) */
    public static function singlePath(string $segment): string
    {
        return '/' . $segment . '/{postname:[^/]+}';
    }

    /** 归档路径 */
    public static function archivePath(string $segment): string
    {
        return '/' . $segment;
    }

    /** 归档分页路径(与 `home.page` 同款后缀) */
    public static function archivePagePath(string $segment): string
    {
        return '/' . $segment . '/page/{page:\d+}';
    }

    /**
     * 一个已登记类型的前台路由定义,按 `public` / `has_archive` 定取舍。
     *
     * @param string $type 类型名(用于路由名)
     * @param ?string $segment 前台 URL 段(`PostTypeRegistry::rewriteSlug()`;null = 前台不可达)
     * @param bool $hasArchive 是否有归档页
     * @return list<array{name: string, path: string, kind: 'single'|'archive'|'archive.page'}>
     * @throws RouteException 前台段与内核静态首段重名时(整类不挂,交由调用方记录原因)
     */
    public static function definitions(string $type, ?string $segment, bool $hasArchive): array
    {
        if ($segment === null) {
            return [];
        }

        if (in_array($segment, self::reservedSegments(), true)) {
            throw new RouteException(sprintf(
                '内容类型「%s」的前台段「%s」与内核路由保留段重名,前台路由不注册',
                $type,
                $segment
            ));
        }

        $routes = [[
            'name' => self::singleName($type),
            'path' => self::singlePath($segment),
            'kind' => 'single',
        ]];

        if ($hasArchive) {
            $routes[] = ['name' => self::archiveName($type), 'path' => self::archivePath($segment), 'kind' => 'archive'];
            $routes[] = ['name' => self::archivePageName($type), 'path' => self::archivePagePath($segment), 'kind' => 'archive.page'];
        }

        return $routes;
    }

    /**
     * 把登记表里所有类型的前台路由挂上路由器。必须在 `system/init`(插件在此登记类型)之后调用。
     *
     * @param callable(string, Request, array<string, string>): Response $single 单篇处理器(类型, 请求, 路由参数)
     * @param callable(string, Request, array<string, string>): Response $archive 归档处理器(分页走同一支)
     * @return list<string> 未挂上的原因(中文);全部挂上时为空数组 —— 由调用方决定记日志还是静默
     */
    public static function register(Router $router, PostTypeRegistry $types, callable $single, callable $archive): array
    {
        $notes = [];

        foreach ($types->all() as $type) {
            $typeSlug = (string) $type['slug'];

            try {
                $definitions = self::definitions($typeSlug, $types->rewriteSlug($typeSlug), $type['has_archive']);
            } catch (RouteException $e) {
                $notes[] = $e->getMessage();

                continue;
            }

            foreach ($definitions as $definition) {
                $handler = $definition['kind'] === 'single'
                    ? static function (Request $request, array $vars = []) use ($single, $typeSlug): Response {
                        return $single($typeSlug, $request, $vars);
                    }
                : static function (Request $request, array $vars = []) use ($archive, $typeSlug): Response {
                    return $archive($typeSlug, $request, $vars);
                };

                try {
                    $router->add($definition['name'], 'GET', $definition['path'], $handler);
                } catch (RouteException $e) {
                    $notes[] = $e->getMessage();
                }
            }
        }

        return $notes;
    }

    /**
     * 内核各路由的**静态首段**:自定义类型的前台段与它们重名即拒挂,免得盖掉内核地址。
     *
     * 从 `Router::kernelNames()` 现算,不写死清单 —— 内核加路由时这里自动跟上。
     * 只取首段(自定义类型的前台段就是一段),动态首段(`/{slug}` 这类)不算保留。
     *
     * @return list<string>
     */
    public static function reservedSegments(): array
    {
        $segments = [];

        foreach (Router::kernelNames() as $route) {
            foreach ($route['paths'] as $path) {
                $first = explode('/', trim($path, '/'))[0];

                if ($first !== '' && !str_contains($first, '{')) {
                    $segments[] = $first;
                }
            }
        }

        return array_values(array_unique($segments));
    }
}
