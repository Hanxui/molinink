<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api;

use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Theme\ListSource;
use Molin\Theme\Renderer;

/**
 * 列表片段端点(《23-路由规范》第 4 节 `list.fragment`):只吐列表项 + 分页,不含整页骨架。
 *
 * 取数与组项全走 ListSource —— 与整页渲染同一套 PostQuery / Searcher 与同一张卡片 partial,
 * 不复制查询,也不手拼 HTML。公开只读(GET、免会话、免 CSRF),与内核其余前台路由同一条口径。
 *
 * 入参(查询串):
 * - context 必填:home / category / tag / author / date / archive / search
 * - page 可选:正整数,缺省 1;非正整数 → 400,越界 → 404(与整页同口径)
 * - slug:分类 / 标签 / 作者的别名;type:自定义内容类型;year + month:日期归档;q:搜索词
 * - route:`home` 的翻页走哪组路由(缺省 `home`;全站文章页给 `home.archive`),只认这两条
 *
 * 响应:200 = HTML 片段(text/html);缺参 / 非法页 → 400,资源不存在(词条 / 类型 / 作者 / 越界)→ 404,
 * 均为 text/plain 的明确中文原因(与《25》错误形状同一段位:参数类 4xx、资源类 4xx)。
 */
final class ListFragmentController
{
    public function __construct(
        private readonly ListSource $source,
        private readonly Renderer $renderer
    ) {
    }

    public function handle(Request $request): Response
    {
        $context = $request->input('context');

        if ($context === '') {
            return Response::text('缺少列表类型:context', 400);
        }

        $pageRaw = $request->input('page');
        $page = 1;

        if ($pageRaw !== '') {
            if (preg_match('/\A\d+\z/', $pageRaw) !== 1 || (int) $pageRaw < 1) {
                return Response::text('页码不合法:page 必须是正整数', 400);
            }

            $page = (int) $pageRaw;
        }

        // 全站文章页翻页地址与首页不同(`/blog/page/N`),分页链接跟着 route 走;只认表里这两条。
        // 缺省必须是 `home` —— ListSource 对 'home' 取的是 `$params['route'] ?? 'home'`,
        // 传空串进去会让 pager() 拿空路由名去 path(),直接 RouteException(2026-10-22 修)。
        $route = $request->input('route');

        $result = $this->source->build($context, [
            'slug' => $request->input('slug'),
            'type' => $request->input('type'),
            'year' => $request->input('year'),
            'month' => $request->input('month'),
            'q' => $request->input('q'),
            'route' => in_array($route, ['home', 'home.archive'], true) ? $route : 'home',
        ], $page);

        $status = (int) $result['status'];

        if ($status !== 200) {
            return Response::text((string) $result['error'], $status);
        }

        // 主题没给片段模板(如官方默认主题)时明确 404,不回落整页、也不吐空壳
        if (!$this->renderer->theme()->hasTemplate('list-fragment.twig')) {
            return Response::text('当前主题未提供列表片段模板:list-fragment.twig', 404);
        }

        return $this->renderer->renderFragment('list-fragment', [
            'items' => $result['items'],
            'pager' => $result['pager'],
            'context' => (string) $result['context'],
        ], ['context' => (string) $result['context']]);
    }
}
