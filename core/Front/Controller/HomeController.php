<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Content\TermAssigner;
use Molin\Front\FrontContext;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Theme\Renderer;

/**
 * 首页与全站文章页(《71/72》《88》):hero + 编号列表。
 *
 * 条目与分页由 ListSource 装配(与归档族、片段端点同源),这里只决定页型与变量。
 * 首页还要处理两条分派:查询式固定链接(?p= / ?page_id=)与「首页显示 = 静态页面」。
 */
final class HomeController
{
    public function __construct(
        private readonly FrontContext $context,
        private readonly PostController $posts,
        private readonly TermAssigner $terms
    ) {
    }

    /** @param array<string, string> $vars 路由变量 */
    public function home(Request $request, array $vars = []): Response
    {
        // 查询式固定链接的单篇落在首页(?p= / ?page_id=):没有路径段可分,交给文章处理器
        if ($request->input('p') !== '' || $request->input('page_id') !== '') {
            return $this->posts->handle($request, []);
        }

        $options = $this->context->options();

        // 阅读组「首页显示 = 静态页面」(《76》§12.2):挑中的那篇页面顶到首页;
        // 页面没挑、已删或不可见时回落博客流,不留 404 首页
        if ((string) $options->get('show_on_front', 'posts') === 'page') {
            $frontId = (int) $options->get('page_on_front', 0);
            $front = $frontId > 0 ? $this->context->posts()->find($frontId) : null;

            if ($front !== null && $front->type === 'page' && $this->context->isVisible($front)) {
                return $this->posts->handle($request, ['post_id' => $frontId], 'front-page');
            }
        }

        return $this->listPage(1, $this->context->view($request));
    }

    /** @param array<string, string> $vars 路由变量 */
    public function homePage(Request $request, array $vars = []): Response
    {
        return $this->listPage(max(1, (int) ($vars['page'] ?? 1)), $this->context->view($request));
    }

    /*
     * 全站文章页(`/blog`,路由名 home.archive):给"全部文章"一个真正的前台落点(主题的「查看全部」指这里)。
     *
     * 取数与首页同源(ListSource::home),所以不吃「首页显示=静态页面」那条读设置,也不渲染 Hero;
     * 顶部筛选条要的分类计数在这里一并给齐,模板只管画。
     */
    /** @param array<string, string> $vars 路由变量 */
    public function archive(Request $request, array $vars = []): Response
    {
        return $this->blogPage(1, $this->context->view($request));
    }

    /** @param array<string, string> $vars 路由变量 */
    public function archivePage(Request $request, array $vars = []): Response
    {
        return $this->blogPage(max(1, (int) ($vars['page'] ?? 1)), $this->context->view($request));
    }

    private function listPage(int $page, ?Renderer $override = null): Response
    {
        $view = $override ?? $this->context->renderer();
        $result = $this->context->listSource()->build('home', [], $page);

        // 页码越界不渲染空壳,走 404(与 ListSource 同口径)
        if ((int) $result['status'] !== 200) {
            return $this->context->notFound($view);
        }

        return $view->render('home', [
            'items' => $result['items'],
            // 首页分页与归档同一套版式(单页时模板不渲染,基准图上也没有这一块)
            'pager' => $result['pager'],
            // 旧变量名保留给第三方主题,内核自带的 home.twig 用 items/pager
            'posts' => $result['posts'],
            'pagination' => $result['pagination'],
        ]);
    }

    private function blogPage(int $page, ?Renderer $override = null): Response
    {
        $view = $override ?? $this->context->renderer();
        $result = $this->context->listSource()->build('home', ['route' => 'home.archive'], $page);

        if ((int) $result['status'] !== 200) {
            return $this->context->notFound($view);
        }

        return $view->render('home', [
            'items' => $result['items'],
            'pager' => $result['pager'],
            // 旧变量名保留给第三方主题,内核自带的模板用 items/pager
            'posts' => $result['posts'],
            'pagination' => $result['pagination'],
            // 筛选条的分类与计数:与后台分类盒同一份口径(《79》§4.7),count 只数已发布
            'categories' => $this->terms->choices(TermAssigner::CATEGORY),
            'archive_total' => (int) $result['pagination']['total'],
        ]);
    }
}
