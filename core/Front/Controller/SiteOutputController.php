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
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Site\Feed;
use Molin\Site\Robots;
use Molin\Site\Sitemap;
use Molin\User\UserRepository;

/**
 * 站点输出三件(《23》§4 表内最后三格):订阅两式、站点地图、爬虫规则。
 *
 * 口径(2026-10-05 更新,记录在案):订阅条数走选项 posts_per_rss、摘要/全文走 rss_use_excerpt;
 * sitemap = 首页 + 文章 + 页面 + 归档页(分类/标签/作者,只收有已发布内容的);blog_public=0 时
 * sitemap 谢客、robots 整站禁爬,feed 照常 —— 订阅是给人看的,不是给爬虫的。
 */
final class SiteOutputController
{
    public function __construct(
        private readonly FrontContext $context,
        private readonly Router $router,
        private readonly Options $options,
        private readonly TermAssigner $terms,
        private readonly UserRepository $users
    ) {
    }

    /** @param array<string, string> $vars 路由变量 */
    public function rss(Request $request, array $vars = []): Response
    {
        return $this->feed('rss');
    }

    /** @param array<string, string> $vars 路由变量 */
    public function atom(Request $request, array $vars = []): Response
    {
        return $this->feed('atom');
    }

    /** @param array<string, string> $vars 路由变量 */
    public function sitemap(Request $request, array $vars = []): Response
    {
        // 不欢迎搜索引擎时不给地图(与模板的 noindex 同一个开关,《125》§6.3)
        if ((string) $this->options->get('blog_public', '1') !== '1') {
            return $this->context->notFound();
        }

        $posts = $this->context->posts();
        $articles = $posts->query()->type('post')->status('publish')->orderBy('updated_at', 'desc')->limit(Sitemap::LIMIT)->get();
        $pages = $posts->query()->type('page')->status('publish')->orderBy('updated_at', 'desc')->limit(Sitemap::LIMIT)->get();

        // 归档页:分类 / 标签 / 作者,只收真有已发布内容的(空归档不收)。URL 一律经路由名生成。
        $archives = [];

        foreach ([TermAssigner::CATEGORY => 'category', TermAssigner::TAG => 'tag'] as $taxonomy => $route) {
            foreach ($this->terms->listAll($taxonomy) as $term) {
                if ($term['count'] > 0) {
                    $archives[] = $this->router->generate($route, ['slug' => $term['slug']]);
                }
            }
        }

        foreach ($this->users->authorsWithPublishedPosts(Sitemap::LIMIT) as $author) {
            $archives[] = $this->router->generate('author', ['slug' => $author->username]);
        }

        return new Response((new Sitemap($this->options))->xml($articles, $pages, $archives), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** @param array<string, string> $vars 路由变量 */
    public function robots(Request $request, array $vars = []): Response
    {
        $public = (string) $this->options->get('blog_public', '1') === '1';

        return Response::text((new Robots($this->options))->text($public));
    }

    private function feed(string $format): Response
    {
        $items = $this->context->posts()->query()->type('post')->status('publish')->orderBy('published_at', 'desc')
            ->limit(Feed::limit($this->options))->content()->get();
        $feed = new Feed($this->options);

        return $format === 'atom'
            ? new Response($feed->atom($items), 200, ['Content-Type' => 'application/atom+xml; charset=utf-8'])
            : new Response($feed->rss($items), 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }
}
