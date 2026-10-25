<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Notification\NotificationFeed;
use Molin\Notification\NotificationRepository;
use Molin\Security\Csrf;
use Molin\Theme\Renderer;
use Molin\User\Auth;

/**
 * 站内通知（对齐 ZAXU 原版）：列表片段 / 未读数 / 已读 / 删除。
 * 只给本人看：所有查询都带 author_id = 当前用户。
 */
final class NotificationController
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly ?NotificationFeed $feed,
        private readonly ?NotificationRepository $repository
    ) {
    }

    /** @param array<string, string> $vars 路由变量 */
    public function listItems(Request $request, array $vars = []): Response
    {
        $feed = $this->feed;

        // 通知实现由插件注入;插件未启用时整组端点统一回 404,模板靠 features.notifications 隐藏入口
        if ($feed === null || $this->repository === null) {
            return Response::json(['ok' => false, 'error' => '通知功能未启用'], 404);
        }

        $viewer = Auth::user();

        if ($viewer === null) {
            return Response::json(['ok' => false, 'error' => '请先登录'], 401);
        }

        return $this->renderer->renderFragment('notifications', [
            'items' => $feed->forAuthor($viewer->id),
        ]);
    }

    /** @param array<string, string> $vars 路由变量 */
    public function count(Request $request, array $vars = []): Response
    {
        $repository = $this->repository;

        if ($repository === null || $this->feed === null) {
            return Response::json(['ok' => false, 'error' => '通知功能未启用'], 404);
        }

        $viewer = Auth::user();

        if ($viewer === null) {
            return Response::json(['ok' => false, 'count' => 0], 401);
        }

        return Response::json([
            'ok' => true,
            'count' => $repository->unreadCount($viewer->id),
        ]);
    }

    /** @param array<string, string> $vars 路由变量 */
    public function read(Request $request, array $vars = []): Response
    {
        $repository = $this->repository;

        if ($repository === null || $this->feed === null) {
            return Response::json(['ok' => false, 'error' => '通知功能未启用'], 404);
        }

        $viewer = Auth::user();
        $session = Auth::session();
        $token = $request->header(Csrf::HEADER) ?? '';

        if ($token === '') {
            $token = $request->input(Csrf::FIELD);
        }

        if ($viewer === null) {
            return Response::json(['ok' => false, 'error' => '请先登录'], 401);
        }

        if ($session === null || !Csrf::check($session, $token)) {
            return Response::json(['ok' => false, 'error' => '页面放得太久了，请刷新后重试'], 403);
        }

        if ($request->input('all') === '1') {
            $repository->markAllRead($viewer->id);
        } else {
            $repository->markRead($viewer->id, (int) $request->input('id'));
        }

        return Response::json(['ok' => true, 'count' => $repository->unreadCount($viewer->id)]);
    }

    /** @param array<string, string> $vars 路由变量 */
    public function delete(Request $request, array $vars = []): Response
    {
        $repository = $this->repository;

        if ($repository === null || $this->feed === null) {
            return Response::json(['ok' => false, 'error' => '通知功能未启用'], 404);
        }

        $viewer = Auth::user();
        $session = Auth::session();
        $token = $request->header(Csrf::HEADER) ?? '';

        if ($token === '') {
            $token = $request->input(Csrf::FIELD);
        }

        if ($viewer === null) {
            return Response::json(['ok' => false, 'error' => '请先登录'], 401);
        }

        if ($session === null || !Csrf::check($session, $token)) {
            return Response::json(['ok' => false, 'error' => '页面放得太久了，请刷新后重试'], 403);
        }

        if ($request->input('all') === '1') {
            $repository->deleteAll($viewer->id);
        } else {
            $repository->delete($viewer->id, (int) $request->input('id'));
        }

        return Response::json(['ok' => true, 'count' => $repository->unreadCount($viewer->id)]);
    }
}
