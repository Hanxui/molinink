<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api\Controller;

use Molin\Api\ApiSupport;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Remover;
use Molin\Media\Store;
use Molin\Media\Upload;
use Molin\Media\Uploader;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\Authorizer;
use Molin\User\User;

/**
 * 媒体端点:媒体库网格、上传、入待清理。
 */
final class MediaController extends ApiSupport
{
    /** 媒体库一页多少(App 走图片网格,与后台同量级) */
    private const MEDIA_PER_PAGE = 20;

    public function __construct(
        Options $options,
        Router $router,
        private readonly MediaRepository $media,
        private readonly Uploader $uploader,
        private readonly Remover $remover,
        private readonly Store $store,
        private readonly Authorizer $can
    ) {
        parent::__construct($options, $router);
    }

    /**
     * `GET /api/v1/media` —— 媒体库(App 上传后的网格)。门控与后台媒体屏同一条(media.upload)。
     */
    public function mediaList(Request $request, ?User $user): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (!$this->can->can($user, 'media.upload')) {
            return $this->fail('forbidden', '没有查看媒体的权限', 403);
        }

        $perPage = $request->queryInt('per_page', self::MEDIA_PER_PAGE, self::PER_PAGE_MAX);
        $page = $request->queryInt('page', 1);
        $tab = trim((string) $request->query('type', ''));

        $result = $this->media->browse([
            'tab' => in_array($tab, ['image', 'doc', 'av'], true) ? $tab : 'all',
            'q' => trim((string) $request->query('search', '')),
        ], $page, $perPage);

        $total = $result['total'];
        $pages = max(1, (int) ceil($total / $perPage));

        return $this->ok([
            'data' => array_map(fn (array $item): array => $this->mediaRow($item), $result['items']),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $pages,
            ],
            'links' => $this->links('media', [], $page, $pages),
        ]);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function mediaRow(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'file_name' => (string) $item['file_name'],
            'mime_type' => (string) $item['mime_type'],
            'size' => (int) $item['file_size'],
            'width' => $item['width'] === null ? null : (int) $item['width'],
            'height' => $item['height'] === null ? null : (int) $item['height'],
            'url' => $this->absoluteUrl($this->store->url((string) $item['file_path'])),
            'thumbnail_url' => $this->mediaThumb($item),
            'uploaded_at' => (string) $item['created_at'],
        ];
    }

    /**
     * 各档 sizes 里挑一张当缩略图(优先 medium/thumbnail),没有再退回原图。
     *
     * @param array<string, mixed> $item
     */
    private function mediaThumb(array $item): string
    {
        $sizes = $item['sizes'] ?? [];

        if (is_array($sizes)) {
            foreach (['thumbnail', 'medium', 'large'] as $key) {
                $sized = $sizes[$key] ?? null;

                if (is_array($sized) && isset($sized['file'])) {
                    return $this->absoluteUrl($this->store->url((string) $sized['file']));
                }
            }
        }

        return $this->absoluteUrl($this->store->url((string) $item['file_path']));
    }

    /**
     * `POST /api/v1/media` —— 上传一张图/一段音视频(App 编辑器与头像)。multipart 字段名 `file`。
     * 门控 media.upload,与后台媒体屏同一条。
     */
    public function mediaUpload(Request $request, ?User $user, bool $viaSession): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        if (!$this->can->can($user, 'media.upload')) {
            return $this->fail('forbidden', '没有上传媒体的权限', 403);
        }

        $file = $request->uploads('file')[0] ?? null;

        if ($file === null) {
            return $this->fail('validation_failed', '请选择要上传的文件', 422, ['file' => ['不能为空']]);
        }

        $upload = Upload::fromRequest($file);

        if ($upload->transportError() !== '') {
            return $this->fail('upload_failed', $upload->transportError(), 422);
        }

        try {
            $record = $this->uploader->upload($upload, $user->id);
        } catch (ValidationException $e) {
            return $this->validation($e);
        }

        if ($record === []) {
            return $this->fail('upload_failed', '上传未能完成,请重试', 500);
        }

        return $this->ok(['data' => $this->mediaRow($record)], 201);
    }

    /**
     * `DELETE /api/v1/media/{id}` —— 入待清理(软删,文件先不动)。归自己的或 deleted.others。
     */
    public function mediaDelete(Request $request, ?User $user, bool $viaSession, int $id): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        $record = $this->media->find($id);

        if ($record === null) {
            return $this->fail('media_not_found', '附件不存在', 404);
        }

        if (!$this->canTrash($user, (int) $record['uploaded_by'])) {
            return $this->fail('forbidden', '没有删除这条媒体的权限', 403);
        }

        $this->remover->trash($id, $user->id);

        return $this->ok(['data' => ['id' => $id, 'removed' => true]]);
    }

    /**
     * 归自己的或 deleted.others —— 与后台媒体屏 canTrash 同一条。
     */
    private function canTrash(User $user, int $uploadedBy): bool
    {
        return $this->can->can($user, 'media.delete.others')
            || $this->can->can($user, 'media.delete.own', (object) ['author_id' => $uploadedBy]);
    }
}
