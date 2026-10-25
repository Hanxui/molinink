<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Comment\Submitter;
use Molin\Exception\ValidationException;
use Molin\Front\FrontContext;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;
use Molin\User\SessionStore;

/**
 * 评论提交(《31》§4)。成功/失败都回文章页:成功按状态给反馈,失败把原因带回表单。
 */
final class CommentController
{
    public function __construct(
        private readonly FrontContext $context,
        private readonly Router $router,
        private readonly Submitter $submitter,
        private readonly SessionStore $sessions
    ) {
    }

    public function submit(Request $request): Response
    {
        $posts = $this->context->posts();
        $back = '/';
        $target = $posts->find(max(0, (int) $request->input('post_id')));

        if ($target !== null) {
            // 回跳必须站内相对(同后台一条纪律):Response::redirect() 只认 '/' 开头,
            // $post->url 是随 site_url 的绝对地址 —— 配了站点地址就会被压成 '/',回跳直接坏
            $back = $this->router->relative($target->url);
        }

        $session = Auth::session();

        // 查询式固定链接回跳地址自带 '?p=1',再拼 '?' 会产成 '?p=1?comment=…' 而丢文章
        $sep = str_contains($back, '?') ? '&' : '?';

        // 令牌对不上多半是页面放旧了,提示去而复返即可
        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($back . $sep . 'comment_error=' . rawurlencode('页面放得太久了,请重新提交。') . '#comments');
        }

        try {
            $result = $this->submitter->submit($request, Auth::user());
        } catch (ValidationException $e) {
            return Response::redirect($back . $sep . 'comment_error=' . rawurlencode($e->getMessage()) . '#comments');
        }

        // 待审的记进会话(《31》§3:作者可见"待审")—— 刷新文章页时它还在,且只对这位访客可见
        if ($result['status'] === 'pending') {
            $mine = array_map('intval', (array) ($session->data['pending_comments'] ?? []));
            $mine[] = (int) $result['id'];
            $session->data['pending_comments'] = array_slice(array_values(array_unique($mine)), -10);
            $this->sessions->save($session);
        }

        // approved = 直接见读者;pending = "等待审核"要如实说
        return Response::redirect($back . $sep . 'comment=' . $result['status'] . '#comments');
    }
}
