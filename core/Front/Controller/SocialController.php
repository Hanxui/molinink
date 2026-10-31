<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Content\PostTypeRegistry;
use Molin\Front\FrontContext;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Log;
use Molin\Notification\Notifier;
use Molin\Security\Csrf;
use Molin\Social\Ratings;
use Molin\Social\SocialRepository;
use Molin\Social\VoterKey;
use Molin\User\Auth;
use Molin\User\UserRepository;

/**
 * 作者页关注 / 文章赞踩(POST + 会话 CSRF,返回 JSON 给主题原地更新)。
 *
 * 赞踩与关注的生命周期不同(2026-10-31):赞由内核的 Ratings 保证可用,
 * 关注仍依赖插件注入的 SocialRepository —— 插件停用只掉关注,不会掉赞。
 */
final class SocialController
{
    public function __construct(
        private readonly FrontContext $context,
        private readonly ?SocialRepository $social,
        private readonly Ratings $ratings,
        private readonly UserRepository $users,
        private readonly ?Notifier $notifier
    ) {
    }

    public function follow(Request $request): Response
    {
        $social = $this->social;

        // 社交实现由插件注入;插件未启用时路由仍在,只回一个明确的 404 让前端按钮静默失败
        if ($social === null || $this->notifier === null) {
            return Response::json(['ok' => false, 'error' => '社交功能未启用'], 404);
        }

        $session = Auth::session();
        $token = $request->header(Csrf::HEADER) ?? '';

        if ($token === '') {
            $token = $request->input(Csrf::FIELD);
        }

        $viewer = Auth::user();

        if ($viewer === null) {
            return Response::json(['ok' => false, 'error' => '请先登录'], 401);
        }

        if ($session === null || !Csrf::check($session, $token)) {
            return Response::json(['ok' => false, 'error' => '页面放得太久了，请刷新后重试'], 403);
        }

        $targetId = max(0, (int) $request->input('user_id'));
        $target = $targetId > 0 ? $this->users->find($targetId) : null;

        if ($target === null || !$target->isUsable()) {
            return Response::json(['ok' => false, 'error' => '用户不存在'], 404);
        }

        if ($target->id === $viewer->id) {
            return Response::json(['ok' => false, 'error' => '不能关注自己'], 422);
        }

        $result = $social->toggleFollow($viewer->id, $target->id);

        return Response::json(['ok' => true] + $result);
    }

    public function rate(Request $request): Response
    {
        $ratings = $this->ratings;
        $notifier = $this->notifier;

        // 赞由内核保证可用;通知是可选扩展,没有通知插件也照常记录投票,只是不发通知
        $session = Auth::session();
        $token = $request->header(Csrf::HEADER) ?? '';

        if ($token === '') {
            $token = $request->input(Csrf::FIELD);
        }

        $viewer = Auth::user();

        // 登录用户仍要防跨站；游客没有会话，用匿名投票标识防重复（与原版一样允许游客参与）
        if ($viewer !== null && ($session === null || !Csrf::check($session, $token))) {
            return Response::json(['ok' => false, 'error' => '页面放得太久了，请刷新后重试'], 403);
        }

        $postId = max(0, (int) $request->input('post_id'));
        $type = $request->input('type');
        $post = $postId > 0 ? $this->context->posts()->find($postId) : null;

        // 文章之外,「说说」这类公开内容类型的赞踩也走同一条接口(旧站说说流带点赞)
        $rateable = $post !== null && $post->status === 'publish'
            && ($post->type === 'post' || (PostTypeRegistry::instance()->get($post->type)['public'] ?? false) === true);

        if (!$rateable) {
            return Response::json(['ok' => false, 'error' => '文章不存在'], 404);
        }

        if (!in_array($type, ['like', 'dislike'], true)) {
            return Response::json(['ok' => false, 'error' => '投票类型无效'], 422);
        }

        $voter = trim($request->input('voter'));
        if ($voter === '') {
            $voter = trim((string) $request->cookie('molin_voter', ''));
        }

        $newVoter = false;

        if ($viewer === null && !VoterKey::valid($voter)) {
            // 旧页面/禁用 cookie 的浏览器：发一个，下一次就能认出来
            $voter = bin2hex(random_bytes(16));
            $newVoter = true;
        }

        // 投票标识的拼法收在 VoterKey 一处:列表页要算「这条我投没投」,两边必须同一个算法
        $voterKey = VoterKey::compose($viewer->id ?? 0, $voter);
        $before = $ratings->ratingFor($post->id, $viewer->id ?? 0, $voterKey);
        $after = $ratings->rate($post->id, $viewer->id ?? 0, $type, $voterKey);

        // 只在意「态度真的变了」：取消投票撤回通知，新投票/换边写一条；通知失败不影响投票结果
        if ($notifier !== null && $before['mine'] !== $after['mine']) {
            try {
                if ($after['mine'] === '') {
                    $notifier->removeRating($post->id, $voterKey);
                } else {
                    $notifier->rating($post->id, $viewer->id ?? 0, $after['mine'], $voterKey);
                }
            } catch (\Throwable $e) {
                Log::channel('app')->error('notification.rating_failed', [
                    'post_id' => $post->id,
                    'type' => $after['mine'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $response = Response::json(['ok' => true] + $after);

        return $newVoter ? $response->withCookie('molin_voter', $voter, 31536000) : $response;
    }
}
