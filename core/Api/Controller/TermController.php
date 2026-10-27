<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api\Controller;

use Molin\Api\ApiSupport;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\TermAssigner;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\User;

/**
 * 词条端点:分类树 / 标签云 / 某分类下的文章。
 */
final class TermController extends ApiSupport
{
    public function __construct(
        Options $options,
        Router $router,
        private readonly TermAssigner $terms,
        private readonly PostRepository $posts
    ) {
        parent::__construct($options, $router);
    }

    /**
     * `GET /api/v1/categories` 与 `/tags` —— 分类树 / 标签云(App 发文时选词)。
     * 公开内容工厂,登录即可读。
     *
     * @param 'category'|'post_tag' $taxonomy
     */
    public function termList(string $taxonomy, ?User $user): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        $rows = $this->terms->listAll($taxonomy);

        return $this->ok([
            'data' => array_map(static fn (array $term): array => [
                'id' => $term['term_taxonomy_id'],
                'name' => $term['name'],
                'slug' => $term['slug'],
                'count' => $term['count'],
            ], $rows),
            'meta' => ['total' => count($rows)],
        ]);
    }

    /**
     * `GET /api/v1/categories` 与 `/api/v1/categories/{id}/posts`。
     * 两条端点首段相同,只按首段分派会把 `/categories/7/posts` 当成分类列表静默吐回 —— 必须看尾段。
     *
     * @param list<string> $segments
     */
    public function categoryRoute(Request $request, array $segments, ?User $user): Response
    {
        if (!isset($segments[1])) {
            return $this->termList('category', $user);
        }

        if (preg_match('/\A\d+\z/', $segments[1]) !== 1 || ($segments[2] ?? '') !== 'posts' || isset($segments[3])) {
            return $this->fail('not_found', '没有这个端点', 404);
        }

        return $this->categoryPosts($request, (int) $segments[1]);
    }

    /**
     * `GET /api/v1/categories/{id}/posts` —— 某分类下的文章。
     * `{id}` 取 `GET /categories` 对外给的 id(= term_taxonomy_id),与分类列表自洽;
     * 取文章走前台分类页同一条 `PostQuery::category(slug)`。公开内容,可见性与 `/posts` 一致。
     */
    private function categoryPosts(Request $request, int $termTaxonomyId): Response
    {
        $term = $this->terms->findByTTid($termTaxonomyId);

        if ($term === null || $term['taxonomy'] !== 'category') {
            return $this->fail('category_not_found', '分类不存在', 404);
        }

        $orderby = (string) $request->query('orderby', 'date');

        if (!isset(self::ORDERS[$orderby])) {
            return $this->fail('bad_request', 'orderby 只认 date / title / id / views', 400);
        }

        $order = strtolower((string) $request->query('order', 'desc'));

        if ($order !== 'asc' && $order !== 'desc') {
            return $this->fail('bad_request', 'order 只认 asc / desc', 400);
        }

        $perPage = $request->queryInt('per_page', self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $page = $request->queryInt('page', 1);

        $query = $this->posts->query()->type('post')->status('publish')->category($term['slug']);
        $total = $query->count();
        $items = $query->orderBy(self::ORDERS[$orderby], $order)->limit($perPage)->page($page)->get();
        $pages = max(1, (int) ceil($total / $perPage));

        // 链接只带生效过的筛选,与 /posts 列表同一条口径
        $carry = [];

        if ($orderby !== 'date') {
            $carry['orderby'] = $orderby;
        }

        if ($order !== 'desc') {
            $carry['order'] = $order;
        }

        if ($perPage !== self::PER_PAGE_DEFAULT) {
            $carry['per_page'] = (string) $perPage;
        }

        return $this->ok([
            'data' => array_map(fn (Post $post): array => $this->toArray($post), $items),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
            'links' => $this->links('categories/' . $termTaxonomyId . '/posts', $carry, $page, $pages),
        ]);
    }
}
