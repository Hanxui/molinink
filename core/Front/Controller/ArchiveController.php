<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Front\FrontContext;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Social\SocialRepository;
use Molin\User\Auth;

/**
 * 归档族:分类 / 标签 / 作者 / 日期 / 搜索 / 自定义内容类型归档。
 *
 * 同一套编号列表壳,差异只在头部文案与条目元信息;取数与分页全走 ListSource,不复制查询。
 */
final class ArchiveController
{
    public function __construct(
        private readonly FrontContext $context,
        private readonly Router $router,
        private readonly ?SocialRepository $social
    ) {
    }

    /** @param array<string, string> $vars 路由变量 */
    public function category(Request $request, array $vars = []): Response
    {
        return $this->termArchive($request, $vars, 'category');
    }

    /** @param array<string, string> $vars 路由变量 */
    public function categoryPage(Request $request, array $vars = []): Response
    {
        return $this->termArchive($request, $vars, 'category');
    }

    /** @param array<string, string> $vars 路由变量 */
    public function tag(Request $request, array $vars = []): Response
    {
        return $this->termArchive($request, $vars, 'tag');
    }

    /** @param array<string, string> $vars 路由变量 */
    public function tagPage(Request $request, array $vars = []): Response
    {
        return $this->termArchive($request, $vars, 'tag');
    }

    /** @param array<string, string> $vars 路由变量 */
    public function author(Request $request, array $vars = []): Response
    {
        return $this->authorArchive($request, $vars, 1);
    }

    /** @param array<string, string> $vars 路由变量 */
    public function authorPage(Request $request, array $vars = []): Response
    {
        return $this->authorArchive($request, $vars, max(1, (int) ($vars['page'] ?? 1)));
    }

    /** @param array<string, string> $vars 路由变量 */
    public function date(Request $request, array $vars = []): Response
    {
        return $this->dateArchive($request, $vars, 1);
    }

    /** @param array<string, string> $vars 路由变量 */
    public function datePage(Request $request, array $vars = []): Response
    {
        return $this->dateArchive($request, $vars, max(1, (int) ($vars['page'] ?? 1)));
    }

    // 搜索(LIKE 起步、标题命中优先;结果页按搜索结果-设计稿渲染)
    // 取数与组项(含无结果态两条建议)收进 ListSource,与搜索片段端点同源。
    /** @param array<string, string> $vars 路由变量 */
    public function search(Request $request, array $vars = []): Response
    {
        $result = $this->context->listSource()->build('search', ['q' => $request->input('q')], max(1, (int) $request->input('page', '1')));
        $view = $this->context->view($request);
        $search = is_array($result['search']) ? $result['search'] : [];

        return $view->render('search', $search + [
            'items' => $result['items'],
            'pager' => $result['pager'],
            'posts' => [],
        ], ['q' => (string) ($search['keyword'] ?? '')]);
    }

    /**
     * 自定义内容类型的前台归档(CPT):与归档族同一套取数与分页链,页型走 archive。
     *
     * @param array<string, string> $vars
     */
    public function cptArchive(string $type, Request $request, array $vars): Response
    {
        $view = $this->context->view($request);
        $page = max(1, (int) ($vars['page'] ?? 1));

        $result = $this->context->listSource()->build('archive', ['type' => $type], $page);

        // 类型不可达 / 页码越界 → 404(与归档族同口径)
        if ((int) $result['status'] !== 200) {
            return $this->context->notFound($view);
        }

        return $view->render('archive', [
            'term' => $result['term'] ?? [],
            'items' => $result['items'],
            'pager' => $result['pager'],
        ], ['type' => $type]);
    }

    /**
     * @param array<string, string> $vars
     */
    private function termArchive(Request $request, array $vars, string $kind): Response
    {
        $view = $this->context->view($request);
        $slug = (string) ($vars['slug'] ?? '');
        $page = max(1, (int) ($vars['page'] ?? 1));

        // 词条不存在或已删 / 页码越界 → 404;空词条 → 头部照常,列表区一句中灰提示
        $result = $this->context->listSource()->build($kind, ['slug' => $slug], $page);

        if ((int) $result['status'] !== 200) {
            return $this->context->notFound($view);
        }

        $term = is_array($result['term']) ? $result['term'] : [];

        return $view->render($kind, [
            'term' => $term,
            'items' => $result['items'],
            // 分类 / 标签单页时计数不带「每页 N 篇」;满页与既有规范一致
            'pager' => $result['pager'],
        ], ['slug' => $slug, 'id' => (int) ($term['term_id'] ?? 0)]);
    }

    /**
     * 作者页:头部是信息卡,列表与分类页同壳。
     * 作者查无此人 / 被禁用 → 404。
     *
     * @param array<string, string> $vars 路由变量
     */
    private function authorArchive(Request $request, array $vars, int $page): Response
    {
        $view = $this->context->view($request);
        $slug = (string) ($vars['slug'] ?? '');

        $result = $this->context->listSource()->build('author', ['slug' => $slug], $page);

        // 查无此人 / 被禁用 / 页码越界 → 404
        if ((int) $result['status'] !== 200) {
            return $this->context->notFound($view);
        }

        $authorId = (int) ($result['author']['id'] ?? 0);
        $viewer = Auth::user();
        $session = Auth::session();

        // 社交插件未启用:统计归零、关注态为否、按钮动作留空,模板靠 features.social 隐藏这一块
        $authorStats = $this->social === null
            ? ['followers' => 0, 'following' => 0, 'likes' => 0, 'dislikes' => 0]
            : $this->social->statsForAuthor($authorId);
        $isFollowing = $this->social !== null
            && $viewer !== null
            && $viewer->id !== $authorId
            && $this->social->isFollowing($viewer->id, $authorId);

        return $view->render('author', [
            // 作者不是词条;term 形状照既有规范的"列表页变量"给(既有规范条的变量缺口待补文档)
            'term' => $result['term'] ?? [],
            'author' => $result['author'] ?? [],
            'categories' => $result['categories'] ?? [],
            'items' => $result['items'],
            // 作者页只有页码、没有上/下页(既有规范设计稿);计数两个断点都带「每页 N 篇」
            'pager' => $result['pager'],
            'author_stats' => $authorStats,
            'is_following' => $isFollowing,
            'follow_action' => $this->social === null ? '' : $this->router->path('social.follow'),
            'csrf_token' => $session === null ? '' : Csrf::token($session),
        ], ['slug' => $slug]);
    }

    /*
     * 日期归档:按月过滤。
     * 日期超出 1–12 月 → 404。
     *
     * @param array<string, string> $vars
     */
    /** @param array<string, string> $vars 路由变量 */
    private function dateArchive(Request $request, array $vars, int $page): Response
    {
        $view = $this->context->view($request);
        $year = (int) ($vars['year'] ?? 0);
        $month = (int) ($vars['month'] ?? 0);

        $result = $this->context->listSource()->build('date', ['year' => $year, 'month' => $month], $page);

        // 日期超出 1–12 月 / 页码越界 → 404
        if ((int) $result['status'] !== 200) {
            return $this->context->notFound($view);
        }

        return $view->render('date', [
            // lede 取既有规范条里可自动生成的前半句;内容概括那半句内核生成不了(该条待拍板)
            'term' => $result['term'] ?? [],
            'items' => $result['items'],
            // 日期页单页只留计数;计数两个断点都带「每页 N 篇」
            'pager' => $result['pager'],
        ], ['year' => $year, 'month' => $month]);
    }
}
