<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api;

use Molin\Api\Controller\AuthController;
use Molin\Api\Controller\CommentController;
use Molin\Api\Controller\MediaController;
use Molin\Api\Controller\PostController;
use Molin\Api\Controller\ProfileController;
use Molin\Api\Controller\TermController;
use Molin\Comment\CommentRepository;
use Molin\Comment\Moderator;
use Molin\Comment\Submitter;
use Molin\Content\PostRepository;
use Molin\Content\PostWriter;
use Molin\Content\TermAssigner;
use Molin\Exception\ForbiddenException;
use Molin\Exception\RateLimitException;
use Molin\Exception\UnauthorizedException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Remover;
use Molin\Media\Store;
use Molin\Media\Uploader;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Search\Searcher;
use Molin\Security\RateLimiter;
use Molin\User\ApiKeys;
use Molin\User\AppTokens;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 接口层(《25-接口规范》第 1–9 节):读端点 + 写端点 + 密钥认证 + 限速。
 *
 * 认证与授权分离(§2):先认"是谁"(会话 cookie 或 API Key),再由能力决定"能不能"(§7);
 * 会话路径的写请求要过防跨站令牌 —— 浏览器会自动带 cookie,没这层就是现成的 CSRF 面。
 * 未实现的端点方法是 405(不装样子),包裹与错误形状按 §4、§6。
 */
final class ApiController extends ApiSupport
{
    private readonly ProfileController $profileController;

    private readonly AuthController $authController;

    private readonly CommentController $commentController;

    private readonly MediaController $mediaController;

    private readonly PostController $postController;

    private readonly TermController $termController;

    public function __construct(
        PostRepository $posts,
        private readonly UserRepository $users,
        Options $options,
        Searcher $searcher,
        Router $router,
        PostWriter $writer,
        Authorizer $can,
        Moderator $moderator,
        CommentRepository $comments,
        private readonly ApiKeys $keys,
        private readonly AppTokens $appTokens,
        Auth $auth,
        private readonly RateLimiter $limiter,
        MediaRepository $media,
        Uploader $uploader,
        Remover $remover,
        Store $store,
        TermAssigner $terms,
        Submitter $submitter
    ) {
        parent::__construct($options, $router);

        $this->profileController = new ProfileController($options, $router, $users, $searcher, $media, $store);
        $this->authController = new AuthController($options, $router, $appTokens, $auth, $this->profileController);
        $this->commentController = new CommentController($options, $router, $comments, $moderator, $users, $media, $store, $posts, $can, $submitter);
        $this->mediaController = new MediaController($options, $router, $media, $uploader, $remover, $store, $can);
        $this->postController = new PostController($options, $router, $posts, $writer, $can, $terms, $this->commentController);
        $this->termController = new TermController($options, $router, $terms, $posts);
    }

    public function handle(Request $request, string $path): Response
    {
        try {
            return $this->dispatch($request, $path);
        } catch (RateLimitException $e) {
            return $this->fail('rate_limited', $e->getMessage(), 429)
                ->withHeaders(['Retry-After' => (string) $e->retryAfter()]);
        } catch (ForbiddenException $e) {
            return $this->fail('forbidden', $e->getMessage(), 403);
        } catch (UnauthorizedException $e) {
            return $this->fail('unauthorized', $e->getMessage(), 401);
        } catch (ValidationException $e) {
            return $this->validation($e);
        }
    }

    private function dispatch(Request $request, string $path): Response
    {
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));

        $isAuthRoute = ($segments[0] ?? '') === 'auth';

        [$user, $viaSession, $badKey] = $this->actor($request);

        // 认证端点要能带着过期/无效凭据进来换新令牌,坏令牌在这里当匿名处理
        if ($badKey && !$isAuthRoute) {
            return $this->fail('unauthorized', '密钥无效或已撤回', 401);
        }

        if ($badKey) {
            $user = null;
        }

        $limited = $this->throttle($request, $user);

        if ($limited !== null) {
            return $limited;
        }

        if ($request->method() === 'GET') {
            // 首段相同的端点必须看尾段:只认首段会把 `/tags/7/posts`、`/comments/9/whatever`、`/media/3`
            // 当成列表静默吐回整张表 —— 悄悄给错数据比 404 更糟。
            return match ($segments[0] ?? '') {
                'health' => $this->profileController->health(),
                'site' => $this->profileController->site(),
                'me' => $this->profileController->me($user),
                'search' => $this->profileController->search($request),
                'posts' => $this->postController->collection($request, $segments, 'post', $user),
                'pages' => $this->postController->collection($request, $segments, 'page', $user),
                'comments' => $this->listOnly($segments, fn (): Response => $this->commentController->commentList($request, $user)),
                'media' => $this->listOnly($segments, fn (): Response => $this->mediaController->mediaList($request, $user)),
                'categories' => $this->termController->categoryRoute($request, $segments, $user),
                'tags' => $this->listOnly($segments, fn (): Response => $this->termController->termList('post_tag', $user)),
                // 《25》§3.6 列了 `GET /menus`,但菜单拼装只活在后台屏与主题渲染器里,
                // 内核没有可复用的读取路径 —— 按未实现端点诚实 404,不吐不相干的东西。
                'menus' => $this->fail('not_found', '这个端点尚未实现', 404),
                default => $this->fail('not_found', '没有这个端点', 404),
            };
        }

        return match ($request->method()) {
            'POST' => $this->post($request, $user, $viaSession, $segments),
            'PUT' => $this->put($request, $user, $viaSession, $segments),
            'DELETE' => $this->delete($request, $user, $viaSession, $segments),
            default => $this->fail('method_not_allowed', '这个端点暂不支持该请求方法', 405),
        };
    }

    /**
     * 当前是谁:先认会话 cookie(后台自带前端),再认 App 令牌,最后认 API Key(《25》§2)。
     * 头里带了凭据却认不出来 → 401,不悄悄降级成匿名(配置错了要当场知道)。
     *
     * @return array{0: ?User, 1: bool, 2: bool} 用户 / 是否会话路径 / 凭据无效
     */
    private function actor(Request $request): array
    {
        $session = Auth::user();

        if ($session !== null) {
            return [$session, true, false];
        }

        $token = (string) $request->header('X-Api-Key');

        if ($token === '') {
            $token = $this->bearer($request) ?? '';
        }

        if ($token === '') {
            return [null, false, false];
        }

        // App 令牌与会话同权,但走头认证、不沾 cookie,所以不算"会话路径"(不校验 CSRF)
        $userId = $this->appTokens->authenticateAccess($token) ?? $this->keys->authenticate($token);

        if ($userId === null) {
            return [null, false, true];
        }

        $user = $this->users->find($userId);

        if ($user === null || !$user->isUsable()) {
            return [null, false, true];
        }

        return [$user, false, false];
    }

    /**
     * 未认证按 IP、认证按用户(《25》§9)。超限 429 + Retry-After。
     */
    private function throttle(Request $request, ?User $user): ?Response
    {
        $retry = $user === null
            ? $this->limiter->hit('ip:' . $request->ip(), $this->limiter->anonymousLimit())
            : $this->limiter->hit('user:' . $user->id, $this->limiter->userLimit());

        if ($retry === null) {
            return null;
        }

        return $this->fail('rate_limited', '请求过于频繁,请稍后再试', 429)
            ->withHeaders(['Retry-After' => (string) $retry]);
    }

    /**
     * 只有"列表"一种形状的端点入口(`GET /tags`、`/comments`、`/media`,《25》§3.2/§3.4/§3.5)。
     * 分派只认首段,`/tags/7/posts` 之类会落回列表静默吐回整张表 —— 多带任何尾段一律 404,不许给错数据。
     *
     * @param list<string> $segments
     * @param callable(): Response $list
     */
    private function listOnly(array $segments, callable $list): Response
    {
        if (isset($segments[1])) {
            return $this->fail('not_found', '没有这个端点', 404);
        }

        return $list();
    }

    /**
     * POST 写端点的分派:认证、媒体上传、评论回复/提交、新建文章各归各的控制器。
     *
     * @param list<string> $segments
     */
    private function post(Request $request, ?User $user, bool $viaSession, array $segments): Response
    {
        if (($segments[0] ?? '') === 'auth') {
            if (isset($segments[2])) {
                return $this->fail('not_found', '没有这个端点', 404);
            }

            return match ($segments[1] ?? '') {
                'login' => $this->authController->authLogin($request),
                'refresh' => $this->authController->authRefresh($request),
                'logout' => $this->authController->authLogout($request, $user, $viaSession),
                default => $this->fail('not_found', '没有这个端点', 404),
            };
        }

        if (($segments[0] ?? '') === 'media' && !isset($segments[1])) {
            return $this->mediaController->mediaUpload($request, $user, $viaSession);
        }

        if (($segments[0] ?? '') === 'comments' && isset($segments[1]) && preg_match('/\A\d+\z/', $segments[1]) === 1 && ($segments[2] ?? '') === 'replies' && !isset($segments[3])) {
            return $this->commentController->commentReply($request, $user, $viaSession, (int) $segments[1]);
        }

        // 发评论(《25》§3.4,认证:—):游客可发,与网页表单同一条提交流
        if (($segments[0] ?? '') === 'posts' && isset($segments[1]) && preg_match('/\A\d+\z/', $segments[1]) === 1 && ($segments[2] ?? '') === 'comments' && !isset($segments[3])) {
            return $this->commentController->commentSubmit($request, $user, $viaSession, (int) $segments[1]);
        }

        if (($segments[0] ?? '') !== 'posts' || isset($segments[1])) {
            return $this->fail('method_not_allowed', '这个端点暂不支持该请求方法', 405);
        }

        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        return $this->postController->create($request, $user, $viaSession);
    }

    /**
     * PUT /api/v1/posts/{id} 与 PUT /api/v1/comments/{id}/status。
     *
     * @param list<string> $segments
     */
    private function put(Request $request, ?User $user, bool $viaSession, array $segments): Response
    {
        if (($segments[0] ?? '') === 'posts' && isset($segments[1]) && preg_match('/\A\d+\z/', $segments[1]) === 1 && !isset($segments[2])) {
            return $this->postController->update($request, $user, $viaSession, (int) $segments[1]);
        }

        if (($segments[0] ?? '') === 'comments' && isset($segments[1]) && preg_match('/\A\d+\z/', $segments[1]) === 1 && ($segments[2] ?? '') === 'status' && !isset($segments[3])) {
            return $this->commentController->moderate($request, $user, $viaSession, (int) $segments[1]);
        }

        // App 走 `PUT /comments/{id}` 的短形,与 `/status` 同一处审核(只认 status 字段)
        if (($segments[0] ?? '') === 'comments' && isset($segments[1]) && preg_match('/\A\d+\z/', $segments[1]) === 1 && !isset($segments[2])) {
            return $this->commentController->moderate($request, $user, $viaSession, (int) $segments[1]);
        }

        // 改自己的资料(当前只开放头像)
        if (($segments[0] ?? '') === 'me' && !isset($segments[1])) {
            return $this->profileController->updateProfile($request, $user, $viaSession);
        }

        return $this->fail('method_not_allowed', '这个端点暂不支持该请求方法', 405);
    }

    /**
     * DELETE 写端点的分派:媒体、评论、文章各归各的控制器。
     *
     * @param list<string> $segments
     */
    private function delete(Request $request, ?User $user, bool $viaSession, array $segments): Response
    {
        if (($segments[0] ?? '') === 'media' && isset($segments[1]) && preg_match('/\A\d+\z/', $segments[1]) === 1 && !isset($segments[2])) {
            return $this->mediaController->mediaDelete($request, $user, $viaSession, (int) $segments[1]);
        }

        if (($segments[0] ?? '') === 'comments' && isset($segments[1]) && preg_match('/\A\d+\z/', $segments[1]) === 1 && !isset($segments[2])) {
            return $this->commentController->commentDelete($request, $user, $viaSession, (int) $segments[1]);
        }

        if (($segments[0] ?? '') !== 'posts' || !isset($segments[1]) || preg_match('/\A\d+\z/', $segments[1]) !== 1 || isset($segments[2])) {
            return $this->fail('method_not_allowed', '这个端点暂不支持该请求方法', 405);
        }

        $id = (int) $segments[1];

        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        return $this->postController->delete($request, $user, $viaSession, $id);
    }
}
