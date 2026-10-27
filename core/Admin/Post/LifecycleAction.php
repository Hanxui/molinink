<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Post;

use Molin\Admin\View;
use Molin\Content\PostRepository;
use Molin\Content\PostWriter;
use Molin\Content\StatusMachine;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\User\Authorizer;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 生命周期动作:回收站、还原、批量、快速编辑、彻底删除。
 */
final class LifecycleAction extends ListScreen
{
    public function __construct(
        View $view,
        PostRepository $posts,
        UserRepository $users,
        TermAssigner $terms,
        Authorizer $can,
        Database $db,
        private readonly PostWriter $writer,
        string $managedType
    ) {
        parent::__construct($view, $posts, $users, $terms, $can, $db, $managedType);
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function trash(Request $request, User $user, array $menu, int $id): Response
    {
        $post = $this->mustFind($id);

        if (!$this->canDelete($user, $post)) {
            return $this->forbidden($menu, [$this->cap('delete.own'), $this->cap('delete.others')], __('移入回收站'));
        }

        if (!$this->tokenOk($request)) {
            return $this->refused($menu);
        }

        try {
            $this->writer->trash($id, 'user:' . $user->id);
        } catch (ValidationException $e) {
            return $this->listWithError($menu, $this->why($e));
        }

        Audit::record('post.trash', 'post:' . $id, true, $request, 'user:' . $user->id);

        return Response::redirect($this->url('posts') . '?done=trashed');
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function restore(Request $request, User $user, array $menu, int $id): Response
    {
        $post = $this->mustFind($id);

        if (!$this->canDelete($user, $post)) {
            return $this->forbidden($menu, [$this->cap('delete.own'), $this->cap('delete.others')], __('还原这篇文章'));
        }

        if (!$this->tokenOk($request)) {
            return $this->refused($menu);
        }

        $this->writer->restore($id, StatusMachine::DRAFT, 'user:' . $user->id);
        Audit::record('post.restore', 'post:' . $id, true, $request, 'user:' . $user->id);

        return Response::redirect($this->url('posts') . '?done=restored');
    }

    /**
     * 批量操作(既有规范):移入回收站 / 移至草稿。
     *
     * 逐条按各自的能力判(own 与 others 是两套),不合规的那条跳过而不是整批回滚 ——
     * 批量最忌讳"做了一半当全成";一条都没做成才说"没权限"。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function bulk(Request $request, User $user, array $menu): Response
    {
        if (!$request->isPost()) {
            throw new NotFoundException('批量操作只认 POST:' . $request->method());
        }

        if (!$this->tokenOk($request)) {
            return $this->refused($menu);
        }

        $ids = $this->ints($request, 'ids');

        if ($ids === []) {
            // 一行没勾(既有规范条:零行/空提交的行为,2026-10-03 定)
            return Response::redirect($this->url('posts') . '?done=nopick' . $this->tail($this->filters($request)));
        }

        $toDraft = $request->input('bulk') === 'draft';
        $done = 0;

        foreach ($ids as $id) {
            $post = $this->posts->find($id, true);

            if ($post === null || $post->type !== $this->managedType) {
                continue;
            }

            if ($toDraft) {
                if (!$this->canEdit($user, $post) || !StatusMachine::canGo($post->status, StatusMachine::DRAFT)) {
                    continue;
                }

                $this->writer->save(['status' => StatusMachine::DRAFT], $user->id, $id);
            } else {
                if (!$this->canDelete($user, $post) || !StatusMachine::canGo($post->status, StatusMachine::TRASH)) {
                    continue;
                }

                $this->writer->trash($id, 'user:' . $user->id);
            }

            $done++;
        }

        if ($done === 0) {
            return Response::redirect($this->url('posts') . '?done=denied');
        }

        Audit::record(
            $toDraft ? 'post.bulk.draft' : 'post.bulk.trash',
            'posts:' . $done,
            true,
            $request,
            'user:' . $user->id
        );

        // 两句反馈都是列表页原有的:移至草稿 = "已还原为草稿",移入回收站 = "30 天后才真删"
        return Response::redirect(
            $this->url('posts') . '?done=' . ($toDraft ? 'restored' : 'trashed') . $this->tail($this->filters($request))
        );
    }

    /**
     * 快速编辑:标题 / 别名 / 状态 / 分类四格,其余一律不动。
     * 落库仍走 PostWriter 这一条写入口(修订、钩子、白名单都在那条路上)。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function quick(Request $request, User $user, array $menu, int $id): Response
    {
        if (!$request->isPost()) {
            throw new NotFoundException('快速编辑只认 POST:' . $request->method());
        }

        $post = $this->mustFind($id);

        if (!$this->canEdit($user, $post)) {
            return $this->forbidden($menu, [$this->cap('edit.own'), $this->cap('edit.others')], __('改这篇文章'));
        }

        if (!$this->tokenOk($request)) {
            return $this->refused($menu);
        }

        $wanted = $request->input('status', $post->status);

        if (!StatusMachine::isKnown($wanted)) {
            return $this->listWithError($menu, __('没有这个状态:') . $wanted);
        }

        if ($wanted !== $post->status && !StatusMachine::canGo($post->status, $wanted)) {
            return $this->listWithError(
                $menu,
                __('状态不能从「%s」直接改成「%s」', 'molin', self::LABELS[$post->status] ?? $post->status, self::LABELS[$wanted] ?? $wanted)
            );
        }

        // 与整页编辑同一条口径:没有 post.publish 的人勾"已发布"只会落到待审
        if (($wanted === StatusMachine::PUBLISH || $wanted === StatusMachine::PRIVATE) && !$this->can->can($user, $this->cap('publish'))) {
            $wanted = StatusMachine::PENDING;
        }

        try {
            $this->writer->save([
                'title' => $request->input('title'),
                'slug' => $request->input('slug'),
                'status' => $wanted,
                // 设计稿那格是单选下拉,而一篇文章可以挂多个分类:
                // 下拉给的是"主分类",其余的由隐藏域原样带回,免得改个标题就掉分类
                'categories' => array_values(array_unique(array_merge(
                    $this->ints($request, 'categories'),
                    $this->ints($request, 'keep')
                ))),
            ], $user->id, $id);
        } catch (ValidationException $e) {
            return $this->listWithError($menu, $this->why($e));
        }

        Audit::record('post.quick', 'post:' . $id, true, $request, 'user:' . $user->id);

        return Response::redirect($this->url('posts') . '?done=saved' . $this->tail($this->filters($request)));
    }

    /**
     * 彻底删除:既有规范要求敏感操作二次确认,表单里那个勾就是确认;没勾什么都不做。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function destroy(Request $request, User $user, array $menu, int $id): Response
    {
        $post = $this->mustFind($id);

        if (!$this->canDelete($user, $post)) {
            return $this->forbidden($menu, [$this->cap('delete.others')], __('彻底删除'));
        }

        if (!$this->tokenOk($request)) {
            return $this->refused($menu);
        }

        if ($request->input('confirm') !== 'destroy') {
            return $this->listWithError($menu, __('要彻底删除请先把那一格勾上。'));
        }

        $this->writer->forceDelete($id, 'user:' . $user->id);
        Audit::record('post.destroy', 'post:' . $id, true, $request, 'user:' . $user->id);

        return Response::redirect($this->url('posts?status=trash') . '?done=destroyed');
    }
}
