<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Front\FrontContext;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Sizes;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\UserRepository;

/**
 * 附件页:内容栏 900px,媒体框 / 元数据表 / 引用列表,无评论区也无分页。
 *
 * 附件本体 status=inherit(既有规范软删只打 deleted_at、findBySlug 已排除);
 * 媒体框盘上有真文件才输出 <img>,否则模板走线稿占位(既有规范条);
 * 引用行日期只给文章(页面那行不带,既有规范两种日期格式并存)。
 */
final class AttachmentController
{
    public function __construct(
        private readonly FrontContext $context,
        private readonly MediaRepository $media,
        private readonly UserRepository $users,
        private readonly Options $options,
        private readonly Router $router
    ) {
    }

    /** @param array<string, string> $vars 路由变量 */
    public function show(Request $request, array $vars = []): Response
    {
        $view = $this->context->view($request);
        $slug = (string) ($vars['slug'] ?? '');

        $post = $this->context->posts()->findBySlug($slug, 'attachment');

        if ($post === null || $post->status !== 'inherit') {
            return $this->context->notFound($view);
        }

        $media = $this->media->byPostId($post->id);

        if ($media === null) {
            return $this->context->notFound($view);
        }

        $uploadsDir = MOLIN_ROOT . '/storage/uploads/';
        $sizesRaw = (array) $media['sizes'];

        // 真图分支:master 与各档都验在盘上才给地址;没有就整段落给模板的线稿占位
        $fileUrl = is_file($uploadsDir . $media['file_path']) ? '/uploads/' . $media['file_path'] : '';
        $srcset = [];

        foreach ($sizesRaw as $size) {
            $file = (string) ($size['file'] ?? '');
            $width = (int) ($size['width'] ?? 0);

            // 跳过指向 master 的那份(full 档),它就是 src 本身
            if ($file !== '' && $file !== (string) $media['file_path'] && $width > 0 && is_file($uploadsDir . $file)) {
                $srcset[] = '/uploads/' . $file . ' ' . $width . 'w';
            }
        }

        // 尺寸档 chips:档位与数值取站点配置的解析结果(默认即既有规范权威表),0 = 原图;
        // 档文件不在盘上就不给链接(模板落回 '#')
        $chips = [];

        foreach (Sizes::resolve(Sizes::fromJson((string) ($this->options->get('media_sizes') ?? ''))) as $name => $edge) {
            $file = (string) ($sizesRaw[$name]['file'] ?? '');
            $chips[] = [
                'name' => $name,
                'edge' => $edge === 0 ? '原图' : (string) $edge,
                'url' => $file !== '' && is_file($uploadsDir . $file) ? '/uploads/' . $file : '',
            ];
        }

        $uploader = $this->users->find((int) $media['uploaded_by']);

        // 引用列表:日期只给文章;usage 在仓库层已判好
        $references = [];

        foreach ($this->media->usages((int) $media['id']) as $row) {
            $isPost = $row['type'] === 'post';
            $stamp = strtotime((string) $row['published_at'] . ' UTC');

            $references[] = [
                'title' => $row['title'],
                'url' => $this->router->path('post', [
                    'slug'         => (string) $row['slug'],
                    'post_id'      => (int) $row['id'],
                    'type'         => (string) $row['type'],
                    'published_at' => (string) $row['published_at'],
                ]),
                'type_label' => $isPost ? '文章' : '页面',
                'date_text' => $isPost && $stamp !== false ? date('Y 年 n 月 j 日', $stamp) : '',
                'usage_label' => $row['usage'] === 'featured' ? '特色图片' : '正文内嵌',
            ];
        }

        return $view->render('attachment', [
            // h1 后缀规则(既有规范条待拍板):被当特色图片用时缀「— 特色图片」,先按此实现
            'heading' => $post->title . ($this->media->featuredBy((int) $media['id']) ? ' — 特色图片' : ''),
            'media' => [
                'url' => $fileUrl,
                'srcset' => implode(', ', $srcset),
                'alt' => (string) $media['alt'],
                'caption' => (string) $media['caption'],
                'file_text' => '/uploads/' . $media['file_path'],
                'size_text' => $this->sizeText((int) $media['file_size']),
                'mime' => (string) $media['mime_type'],
                'width' => $media['width'],
                'height' => $media['height'],
                'created_text' => substr((string) $media['created_at'], 0, 16),
                'uploader' => $uploader === null ? '' : $uploader->display_name,
            ],
            'sizes' => $chips,
            'references' => $references,
        ], ['slug' => $post->slug, 'id' => $post->id]);
    }

    /** 口径与后台媒体库同一把尺:KB 取整、MB 一位小数 */
    private function sizeText(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return (int) round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }
}
