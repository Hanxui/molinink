<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Admin\Post\EditorScreen;
use Molin\Admin\Post\LifecycleAction;
use Molin\Admin\Post\ListScreen;
use Molin\Admin\Post\MediaAction;
use Molin\Admin\Post\SaveAction;
use Molin\Content\MetaStore;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\PostWriter;
use Molin\Content\StatusMachine;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Theme\Theme;
use Molin\User\Authorizer;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 内容管理屏(《41-后台信息架构》第 6.1、6.2 节):列表 + 新建/编辑 + 回收站三条路。
 *
 * 写动作一律走 `PostWriter`(第 9 轮的保存链、修订、事务、钩子都在那儿),这里只管四件事:
 * 认路、按能力放行(《32》第 3、4 节:没有 post.publish 的人提交的就是"待审",不是"发布")、
 * 把表单收成写入数据、把结果讲给人听。每页都再校验一次能力,不靠藏按钮(《61》第 3.1 节)。
 *
 * 编辑页的外观照《79-后台编辑页实现规格》:右栏六盒 + 富文本正文区 + 自动保存与离开提醒
 * (《41》§6.2),特色图片从媒体库选(《80》)。没 JS 时正文区退回纯文本域,存法不变。
 *
 * 拆屏后这里只剩门面:按地址选屏并把活交出去,各屏的依赖由下面的工厂现拼。
 */
final class PostsController
{
    /** 进"文章"这一栏的最低门槛:至少要有一项 post.*(《41》第 4 节) */
    private const FLOOR = [
        'post.create',
        'post.edit.own',
        'post.edit.others',
        'post.publish',
        'post.delete.own',
        'post.delete.others',
    ];

    public function __construct(
        private readonly View $view,
        private readonly PostRepository $posts,
        private readonly PostWriter $writer,
        private readonly UserRepository $users,
        private readonly TermAssigner $terms,
        private readonly Authorizer $can,
        private readonly MediaRepository $media,
        private readonly Store $store,
        private readonly MetaStore $meta,
        private readonly Database $db,
        private string $managedType = 'post',
        // 前台当前使用的主题:页面屏要读它的页面模板清单做下拉与白名单校验。追加在末尾且可选,
        // 保持既有构造调用向后兼容(文章屏用不到,可留空)
        private readonly ?Theme $theme = null
    ) {
    }

    /**
     * 同一块屏换一个内容类型用(CPT 后台复用文章屏那套列表 / 编辑 / 回收站链)。
     * 依赖一个不动,只把 managedType 换掉 —— 类型必须是登记表里的,别名 / 能力 / 支持项都从那儿现取。
     */
    public function forType(string $type): self
    {
        $clone = clone $this;
        $clone->managedType = $type;

        return $clone;
    }

    /**
     * 进"文章"这一栏的最低门槛:至少要有一项 post.*(《41》第 4 节)。
     *
     * @return list<string>
     */
    public static function floorCapabilities(): array
    {
        return self::FLOOR;
    }

    /**
     * 阅读组「首页页面」下拉的候选(《76》§12.2):当前类型下已发布的条目,给静态首页挑一篇用。
     *
     * @return list<array{id:int, title:string, selected:bool}>
     */
    public function frontPageChoices(string $current): array
    {
        $items = $this->posts->query()->type($this->managedType)->status(StatusMachine::PUBLISH)
            ->orderBy('title', 'asc')->get();

        return array_map(static fn (Post $post): array => [
            'id' => $post->id,
            'title' => $post->title,
            'selected' => (string) $post->id === $current,
        ], $items);
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        $parts = explode('/', trim($path, '/'));
        array_shift($parts);

        if ($parts === []) {
            return $this->listScreen()->index($request, $user, $menu);
        }

        $head = $parts[0];

        if ($head === 'bulk') {
            return $this->lifecycleAction()->bulk($request, $user, $menu);
        }

        if ($head === 'new') {
            return $request->isPost()
                ? $this->saveAction()->save($request, $user, $menu, null)
                : $this->editorScreen()->show($request, $user, $menu, null);
        }

        if (ctype_digit($head)) {
            $id = (int) $head;
            $verb = $parts[1] ?? 'edit';

            return match ($verb) {
                'edit' => $request->isPost()
                    ? $this->saveAction()->save($request, $user, $menu, $id)
                    : $this->editorScreen()->show($request, $user, $menu, $id),
                'quick' => $this->lifecycleAction()->quick($request, $user, $menu, $id),
                'trash' => $this->lifecycleAction()->trash($request, $user, $menu, $id),
                'thumbnail' => $this->mediaAction()->setThumbnail($request, $user, $menu, $id),
                'image-upload' => $this->mediaAction()->imageUpload($request, $user, $id),
                'restore' => $this->lifecycleAction()->restore($request, $user, $menu, $id),
                'destroy' => $this->lifecycleAction()->destroy($request, $user, $menu, $id),
                default => throw new NotFoundException('文章屏没有这个操作:' . $verb),
            };
        }

        throw new NotFoundException('文章屏没有这个地址:' . $path);
    }

    private function listScreen(): ListScreen
    {
        return new ListScreen(
            $this->view,
            $this->posts,
            $this->users,
            $this->terms,
            $this->can,
            $this->db,
            $this->managedType
        );
    }

    private function editorScreen(): EditorScreen
    {
        return new EditorScreen(
            $this->view,
            $this->posts,
            $this->users,
            $this->terms,
            $this->can,
            $this->db,
            $this->writer,
            $this->meta,
            $this->media,
            $this->store,
            $this->managedType,
            $this->theme
        );
    }

    private function saveAction(): SaveAction
    {
        return new SaveAction(
            $this->view,
            $this->posts,
            $this->users,
            $this->terms,
            $this->can,
            $this->db,
            $this->writer,
            $this->meta,
            $this->media,
            $this->store,
            $this->managedType,
            $this->theme
        );
    }

    private function mediaAction(): MediaAction
    {
        return new MediaAction(
            $this->view,
            $this->posts,
            $this->users,
            $this->terms,
            $this->can,
            $this->db,
            $this->writer,
            $this->meta,
            $this->media,
            $this->store,
            $this->managedType,
            $this->theme
        );
    }

    private function lifecycleAction(): LifecycleAction
    {
        return new LifecycleAction(
            $this->view,
            $this->posts,
            $this->users,
            $this->terms,
            $this->can,
            $this->db,
            $this->writer,
            $this->managedType
        );
    }
}
