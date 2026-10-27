<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Post;

use Molin\Admin\WebAuth;
use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Media\Upload;
use Molin\Media\Uploader;
use Molin\Option\Options;
use Molin\User\User;
use Throwable;

/**
 * 媒体动作:设 / 撤特色图片,编辑器内的本地上传。
 */
final class MediaAction extends EditorScreen
{
    /** 编辑器存图只收图片(既有规范白名单里的图片那一列;pdf/zip/音视频归媒体屏) */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * 设 / 撤特色图片(既有规范盒 + 媒体选择器)。
     *
     * 只动 thumbnail_id 一格 —— 人在选择器里点图,不该顺手把正文也存一遍。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function setThumbnail(Request $request, User $user, array $menu, int $id): Response
    {
        $post = $this->mustFind($id);

        if (!$this->canEdit($user, $post)) {
            return $this->forbidden($menu, [$this->cap('edit.own'), $this->cap('edit.others')], __('改这篇的特色图片'));
        }

        if (!$this->tokenOk($request)) {
            return $this->stale($request, $user, $menu, $post);
        }

        $wanted = max(0, (int) $request->input('thumbnail_id'));

        if ($wanted > 0 && $this->media->byPostId($wanted) === null) {
            return $this->editor($request, $user, $menu, $post, __('这条媒体不存在或已在回收站里,换一张。'));
        }

        $saved = $this->writer->save(['thumbnail_id' => $wanted], $user->id, $id);
        Audit::record('post.thumbnail', 'post:' . $saved->id . ' media:' . $wanted, true, $request, 'user:' . $user->id);

        return Response::redirect($this->url('posts/' . $saved->id . '/edit') . '?done=saved');
    }

    /**
     * 编辑器里的本地上传(#13)。一次一个文件,`target` 说这张图要派什么用:
     * `feat` 存下来当特色图片,然后跳回编辑器看结果;`body` 回一份 JSON,由编辑器插进正文。
     *
     * 只认 XHR 那条路(屏幕上的 file 输入走 `#img-local`),所以失败也回 JSON —— 半页 HTML
     * 塞进 fetch 的 then 里,前端只能干瞪眼。
     */
    public function imageUpload(Request $request, User $user, int $id): Response
    {
        if (!$request->isPost()) {
            throw new NotFoundException('图片上传只认 POST:' . $request->method());
        }

        $post = $this->mustFind($id);

        if (!$this->canEdit($user, $post)) {
            return $this->uploadFail(__('这篇文章你改不了,图也就存不上。'), 403);
        }

        // 存的是媒体库的件,门槛与媒体屏同一条:没 media.upload 的人只能从库里挑
        if (!$this->can->can($user, 'media.upload')) {
            return $this->uploadFail(__('你的账号没有上传图片的能力,先从媒体库里挑一张。'), 403);
        }

        if (!$this->tokenOk($request)) {
            return $this->uploadFail(__(WebAuth::STALE), 403);
        }

        $files = $request->uploads('file');
        $upload = $files === [] ? null : Upload::fromRequest($files[0]);

        if ($upload === null) {
            return $this->uploadFail(__('没有收到文件,挑一张再试。'), 422);
        }

        if (!in_array($upload->extension(), self::IMAGE_EXTENSIONS, true)) {
            return $this->uploadFail(__('这里只收图片:jpg、png、gif、webp。'), 422);
        }

        try {
            $record = $this->uploader()->upload($upload, $user->id, $post->id);
        } catch (ValidationException $e) {
            return $this->uploadFail($this->why($e), 422);
        } catch (Throwable $e) {
            // 过了校验却没能落盘(目录不可写、盘满……):把原因如实带回去,不甩半张错误页
            return $this->uploadFail($e->getMessage(), 500);
        }

        $url = (string) ($record['url'] ?? '');
        $mediaId = (int) ($record['post_id'] ?? 0);

        if ($url === '' || $mediaId === 0) {
            return $this->uploadFail(__('图存下了但回执不完整,到媒体库里看看这张在不在。'), 500);
        }

        if ($request->input('target') === 'feat') {
            $this->writer->save(['thumbnail_id' => $mediaId], $user->id, $id);
            Audit::record('post.thumbnail', 'post:' . $id . ' media:' . $mediaId, true, $request, 'user:' . $user->id);

            return Response::redirect($this->url('posts/' . $id . '/edit') . '?done=saved');
        }

        return Response::json(['ok' => true, 'url' => $url]);
    }

    /**
     * 编辑器存图走媒体库同一条九步,不另造一套取名 / 落盘 / 入库。
     *
     * Uploader 由手上这几个零件现拼:它要的 Store 与 MediaRepository 本屏都已注入,
     * Options 从同一个 Database 取 —— 这样不必动构造签名(后台各屏与测试都按现签名装配)。
     */
    private function uploader(): Uploader
    {
        return new Uploader($this->store, $this->media, new Options($this->db));
    }

    private function uploadFail(string $why, int $status): Response
    {
        return Response::json(['ok' => false, 'error' => $why], $status);
    }
}
