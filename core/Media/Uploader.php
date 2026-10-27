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

        // ②½ 转码口(《27》第 7.1 节):校验过了、还没命名落盘。插件可以换一份字节(转 WebP / 压一压),
        // 内核按同一套尺子复核;任何一步不对就当没换,原图照旧落盘 —— 上传不因插件失败。
        $replaced = $this->replacedImage($upload, $checked, (int) ($meta['max_bytes'] ?? Upload::MAX_BYTES));

        $extension = $replaced['extension'] ?? $upload->extension();
        $mime = $replaced['mime'] ?? (string) $checked['mime'];
        $source = $replaced['upload'] ?? $upload;
        $stem = preg_replace('/\.[A-Za-z0-9]+$/', '', $upload->baseName()) ?? $upload->baseName();

        // ④⑤ 服务端命名 + 落到 storage/uploads/年/月/(不对外暴露)
        $name = $this->store->nameFor((string) $stem, $extension);
        $relative = $this->store->relativePath($name);
        $this->store->place($source, $relative);

        $absolute = $this->store->absolutePath($relative);

        // ⑥ 多尺寸:只对图片做;没有图片库就跳过,原图仍可用(《27》第 5.1 节)
        $width = $replaced['width'] ?? (int) $checked['width'];
        $height = $replaced['height'] ?? (int) $checked['height'];
        $sizes = [];

        if (str_starts_with($mime, 'image/')) {
            $generated = $this->makeSizes($absolute, $relative, $extension, $replaced['quality'] ?? null);
            $width = $generated['width'];
            $height = $generated['height'];
            $sizes = $generated['sizes'];
        }

        // ⑦ 写两张表
        $id = $this->media->create([
            'file_path' => $relative,
            'file_name' => $name,
            'mime_type' => $mime,
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
    private function makeSizes(string $absolute, string $relative, string $extension, ?int $qualityOverride = null): array
    {
        $driver = Drivers::pick();

        if ($driver === null) {
            Log::channel('app')->warning('媒体库缺少图片扩展(gd/imagick 都没有),本次不生成缩略图,原图仍可用');

            return ['width' => null, 'height' => null, 'sizes' => []];
        }

        $wanted = Sizes::resolve(Sizes::fromJson($this->options->get('media_sizes') === null ? null : (string) $this->options->get('media_sizes')));
        $quality = $this->qualityFor($extension, $qualityOverride);
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
     *
     * `$override` 来自转码口:这一张的字节既然是插件给的,衍生档也按插件那档质量出,
     * 否则会出现"设了 75、缩略图还是 80"。越界放宽到 30..100(允许更狠的压缩)。
     */
    private function qualityFor(string $extension, ?int $override = null): int
    {
        if ($override !== null) {
            return max(30, min(100, $override));
        }

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
     * 转码口(《27》第 7.1 节,`media/upload/replace`)。
     *
     * 钩子给回来的东西一律当"不可信输入"复核:扩展名必须是内核认识的**图片**格式、
     * 真 MIME 与扩展名对得上、`getimagesize` 解得开、像素与字节不越界。
     * 任一条不过就返回 null(调用方按原图落盘),并把原因写进日志 —— 插件坏了不该让上传坏掉。
     *
     * @param array<string, mixed> $checked UploadPolicy::assert() 的结果
     * @return array{upload:Upload, extension:string, mime:string, width:int, height:int, quality:?int}|null
     */
    private function replacedImage(Upload $upload, array $checked, int $maxBytes): ?array
    {
        $image = [
            'path' => $upload->tmpPath,
            'extension' => $upload->extension(),
            'mime' => (string) ($checked['mime'] ?? ''),
            'width' => (int) ($checked['width'] ?? 0),
            'height' => (int) ($checked['height'] ?? 0),
        ];

        try {
            $filtered = apply_filters('media/upload/replace', $image);
        } catch (Throwable $e) {
            Log::channel('app')->warning('media.upload.replace_failed:' . $e->getMessage());

            return null;
        }

        if (!is_array($filtered) || $filtered === $image) {
            return null;
        }

        $extension = strtolower(trim((string) ($filtered['extension'] ?? '')));
        $mime = UploadPolicy::mimeFor($extension);

        // 转码口只认图片:换成 pdf/zip 之类不在这一格的职责里,也不给"换掉校验结论"的口子
        if ($mime === null || !str_starts_with($mime, 'image/')) {
            return null;
        }

        $path = $this->materializeReplacement($filtered, $maxBytes);

        if ($path === null) {
            return null;
        }

        $size = @getimagesize($path);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;

        if ($width < 1 || $height < 1 || $width > UploadPolicy::MAX_PIXELS || $height > UploadPolicy::MAX_PIXELS) {
            @unlink($path);
            Log::channel('app')->warning('media.upload.replace_rejected:转码结果不是能解开的图片,或像素越界');

            return null;
        }

        // 与上传校验同一条约定:不信声明,只认 finfo 读出来的真 MIME
        $real = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';

        if ($real !== $mime && !($real === 'image/pjpeg' && $mime === 'image/jpeg')) {
            @unlink($path);
            Log::channel('app')->warning('media.upload.replace_rejected:内容与扩展名对不上(' . $real . ')');

            return null;
        }

        $quality = $filtered['quality'] ?? null;

        return [
            'upload' => Upload::fromPath($path, 'converted.' . $extension),
            'extension' => $extension,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'quality' => is_numeric($quality) ? (int) $quality : null,
        ];
    }

    /**
     * 把钩子给的替换件搬到内核自己的临时文件里(落盘那步只认"我们的临时文件")。
     * 支持两种给法:`bytes`(直接给内容)与 `path`(给已落盘的临时件,复制过来,不动物件本身)。
     */
    private function materializeReplacement(array $filtered, int $maxBytes): ?string
    {
        $bytes = $filtered['bytes'] ?? null;

        if (is_string($bytes) && $bytes !== '') {
            if (strlen($bytes) > $maxBytes) {
                return null;
            }

            $path = tempnam(sys_get_temp_dir(), 'molin-img-');

            if ($path === false) {
                return null;
            }

            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                @unlink($path);

                return null;
            }

            return $path;
        }

        $path = $filtered['path'] ?? null;

        if (!is_string($path) || $path === '' || !is_file($path)) {
            return null;
        }

        $size = (int) (filesize($path) ?: 0);

        if ($size < 1 || $size > $maxBytes) {
            return null;
        }

        $copy = tempnam(sys_get_temp_dir(), 'molin-img-');

        if ($copy === false || !copy($path, $copy)) {
            if ($copy !== false) {
                @unlink($copy);
            }

            return null;
        }

        return $copy;
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
