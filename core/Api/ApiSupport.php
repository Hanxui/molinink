<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api;

use Molin\Content\Post;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;

/**
 * 接口层各控制器共用的响应包裹、地址、分页、会话防跨站与内容序列化支撑。
 *
 * 这些方法的输出形状由《25-接口规范》§4 / §6 统一约束,放在一处才能保证
 * 每个资源控制器的成功/失败包裹一字不差 —— 尤其 respond() 是 `api/response/before` 的唯一挂点。
 */
abstract class ApiSupport
{
    /** 《25》§5 的 orderby 取值 → 查询列。白名单,用户输入不进 ORDER BY */
    protected const ORDERS = [
        'date' => 'published_at',
        'title' => 'title',
        'id' => 'id',
        'views' => 'view_count',
    ];

    protected const PER_PAGE_DEFAULT = 10;

    protected const PER_PAGE_MAX = 100;

    protected function __construct(
        protected readonly Options $options,
        protected readonly Router $router
    ) {
    }

    protected function bearer(Request $request): ?string
    {
        $authorization = $request->header('Authorization') ?? '';

        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token === '' ? null : $token;
    }

    /**
     * @return array<string, mixed>
     */
    protected function toArray(Post $post, bool $withContent = false): array
    {
        $out = [
            'id' => $post->id,
            'type' => $post->type,
            'status' => $post->status,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'published_at' => $post->published_at,
            'url' => $post->url,
        ];

        if ($withContent) {
            $out['content'] = $post->content;
        }

        return $out;
    }

    /**
     * 公开端点的可见性(《34》§5):未删除、已发布、到点。草稿与定时稿同样回 404,不泄露存在性。
     */
    protected function visible(?Post $post): ?Post
    {
        if ($post === null || $post->status !== 'publish') {
            return null;
        }

        if ($post->published_at !== null) {
            $stamp = strtotime($post->published_at . ' UTC');

            if ($stamp !== false && $stamp > time()) {
                return null;
            }
        }

        return $post;
    }

    /**
     * 白名单后的纯文本(与评论提交流同一条口径:过滤标签再判空)。
     */
    protected function plainText(string $content): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($content)));
    }

    /**
     * 把站点相对地址补成绝对地址(App 端 Image 直接用)。已是绝对地址 / data URI 的原样返回。
     */
    protected function absoluteUrl(string $url): string
    {
        if ($url === '' || str_contains($url, '://') || str_starts_with($url, 'data:')) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return rtrim((string) $this->options->get('site_url', ''), '/') . $url;
        }

        return $url;
    }

    /**
     * @param array<string, string> $carry
     * @return array<string, string>
     */
    protected function links(string $resource, array $carry, int $page, int $pages): array
    {
        $base = $this->router->path('api', ['path' => $resource]);

        $links = ['self' => $base . '?' . http_build_query($carry + ['page' => $page])];

        if ($page < $pages) {
            $links['next'] = $base . '?' . http_build_query($carry + ['page' => $page + 1]);
        }

        $links['last'] = $base . '?' . http_build_query($carry + ['page' => $pages]);

        return $links;
    }

    /**
     * 会话路径的写请求要带防跨站令牌(《61》§3):浏览器自动带 cookie,
     * 不设这层,任何站点的一张表单都能替登录者发文。API Key 路径不需要 —— 头不跨站。
     */
    protected function guardSession(Request $request, bool $viaSession): ?Response
    {
        if (!$viaSession) {
            return null;
        }

        $session = Auth::session();

        if ($session !== null && Csrf::check($session, $request->header('X-Csrf-Token') ?? '')) {
            return null;
        }

        return $this->fail('forbidden', '缺少或过期的防跨站令牌', 403);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function ok(array $payload, int $status = 200): Response
    {
        return $this->respond($payload, $status);
    }

    protected function validation(ValidationException $e): Response
    {
        return $this->fail('validation_failed', $e->getMessage(), 422, $e->errors());
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function fail(string $code, string $message, int $status, array $details = []): Response
    {
        return $this->respond([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
                'details' => $details === [] ? (object) [] : $details,
            ],
        ], $status);
    }

    /**
     * 唯一出口 —— `api/response/before` 只挂这一处,成功与失败响应都过它(《25》§8)。
     *
     * @param array<string, mixed> $payload
     */
    protected function respond(array $payload, int $status): Response
    {
        $filtered = apply_filters('api/response/before', $payload);

        if (is_array($filtered)) {
            /** @var array<string, mixed> $filtered */
            $payload = $filtered;
        }

        return Response::json($payload, $status);
    }
}
