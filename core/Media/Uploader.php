<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

use Molin\Logger\Log;
use Molin\Option\Options;
use Throwable;

/**
 * 上传九步(《27-媒体库规范》第 1 节):
 * 接收 → 基础校验 → 内容检测 → 重命名 → 隔离存储 → 生成多尺寸 → 写 media + posts → 触发钩子 → 返回。
 *
 * 校验全在 `UploadPolicy`(不信前端),落盘全在 `Store`(名字服务端生成、目录按年/月),
 * 入库全在 `MediaRepository`(一条附件 = posts 本体 + media 扩展)。
 * 这里只负责把它们按顺序串起来,以及在缺图片库时如实记一笔。
 */
final class Uploader
{
    public function __construct(
        private readonly Store $store,
        private readonly MediaRepository $media,
        private readonly Options $options
    ) {
    }

    /**
     * @return array<string, mixed> 新建的 media 记录(带对外 url)
     */
    public function upload(Upload $upload, int $userId, ?int $parentId = null): array
    {
        $meta = $this->policyMeta();

        // ②③ 校验前先给插件改白名单/上限的机会(《27》第 2 节的 media/upload/before)
        $filtered = apply_filters('media/upload/before', $meta);
        $meta = is_array($filtered) ? $filtered : $meta;

        $allowedExt = array_values((array) ($meta['allowed_ext'] ?? []));

        $policy = new UploadPolicy(
            (int) ($meta['max_bytes'] ?? Upload::MAX_BYTES),
            $this->extraExtensions($allowedExt),
            $allowedExt
        );

        $checked = $policy->assert($upload);

        $extension = $upload->extension();
        $stem = preg_replace('/\.[A-Za-z0-9]+$/', '', $upload->baseName()) ?? $upload->baseName();

        // ④⑤ 服务端命名 + 落到 storage/uploads/年/月/(不对外暴露)
        $name = $this->store->nameFor((string) $stem, $extension);
        $relative = $this->store->relativePath($name);
        $this->store->place($upload, $relative);

        $absolute = $this->store->absolutePath($relative);

        // ⑥ 多尺寸:只对图片做;没有图片库就跳过,原图仍可用(《27》第 5.1 节)
        $width = $checked['width'];
        $height = $checked['height'];
        $sizes = [];

        if (str_starts_with((string) $checked['mime'], 'image/')) {
            $generated = $this->makeSizes($absolute, $relative, $extension);
            $width = $generated['width'];
            $height = $generated['height'];
            $sizes = $generated['sizes'];
        }

        // ⑦ 写两张表
        $id = $this->media->create([
            'file_path' => $relative,
            'file_name' => $name,
            'mime_type' => (string) $checked['mime'],
            'file_size' => $this->sizeOnDisk($absolute, $upload->size),
            'width' => $width,
            'height' => $height,
            'alt_text' => '',
            'caption' => null,
            'sizes' => $sizes,
            'hash' => hash_file('sha256', $absolute),
            'uploaded_by' => $userId,
        ], [
            'title' => $this->titleOf($upload),
            'author_id' => $userId,
            'parent_id' => $parentId ?? 0,
        ]);

        $record = $this->media->find($id);

        if ($record === null) {
            return [];
        }

        $record['url'] = $this->store->url($relative);
        $record['sizes_urls'] = $this->urlsOf($record);

        // ⑧⑨ 落盘与入库都完成了才播报:插件拿到的是带 url / sizes_urls 的完整附件数组
        // (《11》§7.5 media/upload/after;参数形状按本条返回的 media 记录,与 media/delete/after 同一口径)
        do_action('media/upload/after', $record);

        Log::channel('app')->info('media.uploaded', [
            'media_id' => $record['id'],
            'post_id' => $record['post_id'],
            'mime' => $record['mime_type'],
            'bytes' => $record['file_size'],
        ]);

        return $record;
    }

    /**
     * 尺寸档生成(《27》第 5、6、7 节)。
     *
     * 每一档都是重画过的副本:GD 输出天生不含 EXIF,手机照片里的 GPS 与设备信息
     * 因此不会跟着公开文件出去(第 6 节的隐私口径)。原始 master 只留在 storage 里。
     * 宽高取"按 EXIF 转正之后"的那一组,存的和给前台用的才是同一个尺寸。
     *
     * @return array{width:?int, height:?int, sizes:array<string, array{file:string, width:int, height:int}>}
     */
    private function makeSizes(string $absolute, string $relative, string $extension): array
    {
        $driver = Drivers::pick();

        if ($driver === null) {
            Log::channel('app')->warning('媒体库缺少图片扩展(gd/imagick 都没有),本次不生成缩略图,原图仍可用');

            return ['width' => null, 'height' => null, 'sizes' => []];
        }

        $wanted = Sizes::resolve(Sizes::fromJson($this->options->get('media_sizes') === null ? null : (string) $this->options->get('media_sizes')));
        $quality = $this->qualityFor($extension);
        $out = [];

        try {
            $master = $driver->load($absolute)->stripExif();
            $width = $master->width();
            $height = $master->height();

            foreach ($wanted as $name => $edge) {
                $working = $driver->load($absolute)->stripExif()->resize($edge, $edge);
                $toWidth = $working->width();
                $toHeight = $working->height();

                // full 档且尺寸与原图一致:不另写一份,直接指向 master,省一半盘
                $path = $edge === 0 && $toWidth === $width && $toHeight === $height
                    ? $relative
                    : $this->store->sizePath($relative, $extension, $toWidth, $toHeight);

                if ($path === $relative || $working->save($this->store->absolutePath($path), $quality)) {
                    $out[$name] = ['file' => $path, 'width' => $toWidth, 'height' => $toHeight];
                }
            }

            return ['width' => $width, 'height' => $height, 'sizes' => $out];
        } catch (Throwable $e) {
            // 一张图处理失败不能把整次上传判死:原图已安全落盘,也已入库
            Log::channel('app')->warning('生成缩略图失败:' . $e->getMessage());

            return ['width' => null, 'height' => null, 'sizes' => []];
        }
    }

    /**
     * 编码质量:JPEG / WebP 读设置(`jpeg_quality` / `webp_quality`,`《27》第 7 节`),
     * 越界夹回 40..100;设置缺失/非法或其它格式退回 GdDriver 的既定值(png/gif 无损,质量无意义)。
     */
    private function qualityFor(string $extension): int
    {
        $fallback = GdDriver::qualityFor($extension);
        $raw = match ($extension) {
            'jpg', 'jpeg' => $this->options->get('jpeg_quality'),
            'webp' => $this->options->get('webp_quality'),
            default => null,
        };

        if (!is_numeric($raw)) {
            return $fallback;
        }

        return max(40, min(100, (int) $raw));
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function urlsOf(array $record): array
    {
        $urls = [];

        foreach ((array) $record['sizes'] as $name => $size) {
            if (is_array($size) && isset($size['file'])) {
                $urls[(string) $name] = $this->store->url((string) $size['file']);
            }
        }

        return $urls;
    }

    /**
     * @param array<mixed> $allowed 钩子给回来的白名单(形状由插件决定,这里只挑字符串)
     * @return list<string> 插件追加的扩展名(默认白名单之外的)
     */
    private function extraExtensions(array $allowed): array
    {
        $extra = [];

        foreach ($allowed as $ext) {
            $ext = strtolower(trim((string) $ext));

            if ($ext !== '' && !UploadPolicy::allowsExtension($ext)) {
                $extra[] = $ext;
            }
        }

        return $extra;
    }

    /**
     * @return array{allowed_ext: list<string>, max_bytes: int, name: string}
     */
    private function policyMeta(): array
    {
        $mb = (int) $this->options->get('max_upload_mb', (string) (int) round(Upload::MAX_BYTES / 1048576));

        return [
            'allowed_ext' => $this->allowedExtensions(),
            'max_bytes' => $mb > 0 ? $mb * 1048576 : Upload::MAX_BYTES,
            'name' => '',
        ];
    }

    /**
     * 白名单按「系统设置 > 上传类型」走:管理员配一份空格分隔的扩展名。
     * 默认表内项走 UploadPolicy 的严格 MIME;表外项交 extraExtensions 追加 ——
     * 但 DENIED_BY_DEFAULT(svg/html/php…) 仍是硬拒,安全闸不变(《27》第 3 节)。
     *
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        $config = trim((string) $this->options->get('upload_types', ''));

        if ($config === '') {
            return UploadPolicy::allowedExtensions();
        }

        $exts = [];

        foreach (explode(' ', $config) as $ext) {
            $ext = strtolower(trim($ext));

            if ($ext !== '') {
                $exts[] = $ext;
            }
        }

        return array_values(array_unique($exts));
    }

    /**
     * 落盘后的真实字节数(缩放可能让它与上传时不同);读不到就退回上传声称的大小。
     */
    private function sizeOnDisk(string $absolute, int $claimed): int
    {
        $size = filesize($absolute);

        return $size === false ? $claimed : $size;
    }

    private function titleOf(Upload $upload): string
    {
        $stem = (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $upload->baseName());

        return trim($stem) === '' ? '附件' : $stem;
    }
}
