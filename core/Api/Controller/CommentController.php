<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api\Controller;

use Molin\Api\ApiSupport;
use Molin\Comment\CommentRepository;
use Molin\Comment\Moderator;
use Molin\Comment\Submitter;
use Molin\Content\HtmlSanitizer;
use Molin\Content\PostRepository;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\Authorizer;
use Molin\User\Avatar;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 评论端点(《25》§3.4):公开读、App 审核屏、作者回复、游客提交、实删。
 */
final class CommentController extends ApiSupport
{
    public function __construct(
        Options $options,
        Router $router,
        private readonly CommentRepository $comments,
        private readonly Moderator $moderator,
        private readonly UserRepository $users,
        private readonly MediaRepository $media,
        private readonly Store $store,
        private readonly PostRepository $posts,
        private readonly Authorizer $can,
        private readonly Submitter $submitter
    ) {
        parent::__construct($options, $router);
    }

    public function commentsFor(string $second): Response
    {
        if (preg_match('/\A\d+\z/', $second) !== 1) {
            return $this->fail('not_found', '没有这个端点', 404);
        }

        $post = $this->visible($this->posts->find((int) $second));

        if ($post === null || $post->type !== 'post') {
            return $this->fail('post_not_found', '文章不存在', 404);
        }

        $rows = $this->comments->approvedForPost($post->id);

        return $this->ok([
            'data' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'parent_id' => (int) $row['parent_id'],
                'author_name' => (string) $row['author_name'],
                'content' => (string) $row['content'],
                'created_at' => (string) $row['created_at'],
            ], $rows),
            'meta' => ['total' => count($rows)],
        ]);
    }

    /**
     * `GET /api/v1/comments?context=edit` —— 全站评论(App 审核屏)。
     * 门控与后台评论屏同一条(comment.moderate):能审才能看全。
     */
    public function commentList(Request $request, ?User $user): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (!$this->can->can($user, 'comment.moderate')) {
            return $this->fail('forbidden', '没有查看评论的权限', 403);
        }

        $perPage = $request->queryInt('per_page', self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $page = $request->queryInt('page', 1);
        $result = $this->comments->browse([
            'status' => trim((string) $request->query('status', '')),
            'post' => $request->queryInt('post', 0),
        ], $page, $perPage);

        $total = $result['total'];
        $pages = max(1, (int) ceil($total / $perPage));
        $carry = ['context' => 'edit'];

        $rows = $result['items'];
        $repliedIds = $this->comments->repliedBy(
            $user->id,
            array_map(static fn (array $r): int => (int) $r['id'], $rows)
        );

        return $this->ok([
            'data' => array_map(fn (array $row): array => $this->commentRow($row, $repliedIds, $user), $rows),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
            'links' => $this->links('comments', $carry, $page, $pages),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $repliedIds 已被当前作者回复过的评论 id
     * @param ?User $user 当前登录用户;null(公开/匿名)时 is_mine 恒为 false
     * @return array<string, mixed>
     */
    private function commentRow(array $row, array $repliedIds = [], ?User $user = null): array
    {
        // App 读 `body`,后台口径是 `content` —— 两个键都给,任一端都不用改
        $content = (string) ($row['content'] ?? '');
        // DB 存 UTC 无时区标记;App 端 DateTime.parse(...).toLocal() 显示本地时间
        $createdAt = (string) ($row['created_at'] ?? '');
        $stamp = $createdAt === '' ? false : strtotime($createdAt . ' UTC');
        $iso = $stamp === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $stamp);

        return [
            'id' => (int) $row['id'],
            'post_id' => (int) $row['post_id'],
            'post_title' => (string) ($row['post_title'] ?? ''),
            'parent_id' => (int) ($row['parent_id'] ?? 0),
            'author_name' => (string) ($row['author_name'] ?? ''),
            'avatar' => $this->commentAvatar($row),
            'content' => $content,
            'body' => $content,
            'created_at' => $iso,
            'status' => (string) ($row['status'] ?? ''),
            'replied' => in_array((int) $row['id'], $repliedIds, true),
            // 作者端过滤自己的评论:只认 user_id(游客恒 0),昵称/邮箱一样也不算
            'is_mine' => $user !== null && (int) ($row['user_id'] ?? 0) === $user->id,
        ];
    }

    /**
     * 评论作者头像:登录用户读 usermeta.avatar(media.id) 派生;游客回退 email Gravatar;
     * 都没有则 null(App 端回退昵称首字符)。
     *
     * @param array<string, mixed> $row
     */
    private function commentAvatar(array $row): ?string
    {
        $uid = (int) ($row['user_id'] ?? 0);

        if ($uid > 0) {
            $meta = (string) ($this->users->meta($uid)['avatar'] ?? '');
            if ($meta !== '') {
                $media = $this->media->find((int) $meta);
                if ($media !== null && str_starts_with((string) $media['mime_type'], 'image/')) {
                    return $this->absoluteUrl($this->store->url((string) $media['file_path']));
                }
            }
        }

        $email = (string) ($row['author_email'] ?? '');
        if ($email !== '') {
            return $this->absoluteUrl(Avatar::instance()->url($email, 96));
        }

        return null;
    }

    /**
     * `POST /api/v1/comments/{id}/replies` —— 作者行内回复(《82》§6 口径:以当前用户身份直发已通过)。
     * 两级嵌套:回"回复"时挂回它所在的顶层楼层。
     */
    public function commentReply(Request $request, ?User $user, bool $viaSession, int $id): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        if (!$this->can->can($user, 'comment.moderate')) {
            return $this->fail('forbidden', '没有回复评论的权限', 403);
        }

        $row = $this->comments->find($id);

        if ($row === null || !in_array((string) $row['status'], ['pending', 'approved'], true)) {
            return $this->fail('comment_not_found', '评论不存在或不可回复', 404);
        }

        $body = $request->json();
        // App 发 `body`,后台口径是 `content` —— 两个键都收
        $content = HtmlSanitizer::clean((string) ($body['body'] ?? $body['content'] ?? ''), HtmlSanitizer::COMMENT_TAGS);

        if ($this->plainText($content) === '') {
            return $this->fail('validation_failed', '回复内容不能为空', 422, ['body' => ['不能为空']]);
        }

        $parentId = (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : $id;

        $commentId = $this->comments->insert([
            'post_id' => (int) $row['post_id'],
            'parent_id' => $parentId,
            'user_id' => $user->id,
            'author_name' => $user->display_name !== '' ? $user->display_name : $user->username,
            'author_email' => $user->email,
            'author_ip' => $request->ip(),
            'author_ua' => mb_substr($request->userAgent(), 0, 255),
            'content' => $content,
            'status' => 'approved',
            'type' => 'comment',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->comments->recomputeCount((int) $row['post_id']);
        Audit::record('comment.reply', 'comment:' . $commentId . ' parent:' . $parentId, true, $request, 'user:' . $user->id);
        do_action('comment/submit/after', $commentId, (int) $row['post_id']);

        return $this->ok(['data' => [
            'id' => $commentId,
            'parent_id' => $parentId,
            'post_id' => (int) $row['post_id'],
            'status' => 'approved',
        ]], 201);
    }

    /**
     * `POST /api/v1/posts/{id}/comments` —— 发评论(《25》§3.4,认证:—,游客可发)。
     * 路径段 post_id 并进字段后交给提交流,与网页表单同源(《31》§4):频率、蜜罐、首评必审、
     * comment/submit/* 钩子一条不落;带 Bearer / 会话身份时由提交流绑成昵称与邮箱。
     */
    public function commentSubmit(Request $request, ?User $user, bool $viaSession, int $postId): Response
    {
        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        $input = $request->json();
        $input['post_id'] = $postId;

        $result = $this->submitter->submit($request->withInput($input), $user);

        return $this->ok(['data' => [
            'id' => $result['id'],
            'status' => $result['status'],
            'message' => $result['status'] === 'approved' ? '评论已发表。' : '评论已提交，等待审核后展示。',
        ]], 201);
    }

    /**
     * `DELETE /api/v1/comments/{id}` —— 实删:直接从库里移除(含直接子回复),不进回收站。
     * 后台审核屏走 `status → trash` 那条路仍保留回收站能力,两条路互不影响。
     */
    public function commentDelete(Request $request, ?User $user, bool $viaSession, int $id): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        if (!$this->can->can($user, 'comment.moderate')) {
            return $this->fail('forbidden', '没有删除评论的权限', 403);
        }

        $row = $this->comments->find($id);

        if ($row === null) {
            return $this->fail('comment_not_found', '评论不存在', 404);
        }

        $removed = $this->comments->deleteTree($id);
        $this->comments->recomputeCount((int) $row['post_id']);
        Audit::record('comment.destroy', 'comment:' . $id, true, $request, 'user:' . $user->id);

        return $this->ok(['data' => ['id' => $id, 'deleted' => true, 'removed' => $removed]]);
    }

    public function moderate(Request $request, ?User $user, bool $viaSession, int $id): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        if (!$this->can->can($user, 'comment.moderate')) {
            return $this->fail('forbidden', '没有审核评论的权限', 403);
        }

        $status = trim((string) ($request->json()['status'] ?? ''));

        if (!in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            return $this->fail('validation_failed', 'status 只认 pending / approved / spam / trash', 422, [
                'status' => ['取值无效:' . $status],
            ]);
        }

        if ($this->comments->find($id) === null) {
            return $this->fail('comment_not_found', '评论不存在', 404);
        }

        if (!$this->moderator->apply($id, $status, $user)) {
            return $this->fail('conflict', '状态没有变化,或转移被插件拦下', 409);
        }

        return $this->ok(['data' => ['id' => $id, 'status' => $status]]);
    }
}
