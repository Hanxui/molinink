<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

use GdImage;
use Molin\Exception\ConfigException;

/**
 * GD 驱动。
 *
 * 两条与隐私/质量有关的取舍照文档办:
 * ① 先按 EXIF Orientation 把图转正,再输出 —— 输出副本天生不带 EXIF(GD 只重画像素),
 *    手机照片里的 GPS、设备信息因此不会跟着公开出去;
 * ② 等比缩放不裁剪;质量 JPEG 82 / WebP 80。
 *
 * Imagick 驱动本轮不做:这台机器与测试环境都没有它,写了就是一条没人跑过的分支
 * (核验数遍的规矩不允许)。等能实测时再补,接口已留好。
 */
final class GdDriver implements Driver
{
    private ?GdImage $image = null;

    private string $extension = 'png';

    private int $width = 0;

    private int $height = 0;

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    public function load(string $path): static
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw new ConfigException('读不到图片:' . $path);
        }

        $image = @imagecreatefromstring($bytes);

        if (!$image instanceof GdImage) {
            throw new ConfigException('这张图解不开,可能已损坏或不是真图片');
        }

        $this->image = $image;
        $this->width = imagesx($image);
        $this->height = imagesy($image);
        $this->extension = $this->extensionOf($path);
        $this->applyOrientation($path);

        return $this;
    }

    /**
     * 等比缩放到不超过 w×h(不裁剪,既有规范)。传 0 表示这条边不约束。
     */
    public function resize(int $w, int $h): static
    {
        $target = Sizes::fit($this->width, $this->height, max($w, $h));
        $toWidth = $target[0];
        $toHeight = $target[1];

        if (!$this->image instanceof GdImage || ($toWidth === $this->width && $toHeight === $this->height)) {
            return $this;
        }

        $canvas = imagecreatetruecolor(max(1, $toWidth), max(1, $toHeight));

        if (!$canvas instanceof GdImage) {
            throw new ConfigException('创建画布失败');
        }

        // PNG/GIF 的透明底要保住,否则透明区变成黑块
        if ($this->extension === 'png' || $this->extension === 'gif' || $this->extension === 'webp') {
            imagealphablending($canvas, false);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagesavealpha($canvas, true);
        }

        imagecopyresampled($canvas, $this->image, 0, 0, 0, 0, $toWidth, $toHeight, $this->width, $this->height);

        if ($this->extension === 'gif') {
            // GD 缩放会把 GIF 动画压成单帧,这里只留当前帧(动图归档是媒体库后面的事)
            imageinterlace($canvas, false);
        }

        imagedestroy($this->image);

        $this->image = $canvas;
        $this->width = $toWidth;
        $this->height = $toHeight;

        return $this;
    }

    /**
     * GD 重画像素后输出的文件里没有 EXIF,这一步只是把意图写明。
     * 「完全保留 EXIF」的 keep 挡位已从设置页拿掉 —— 输出副本经 GD 重编码必丢元数据,
     * 原始文件不受影响(见设置页 EXIF 策略说明)。
     */
    public function stripExif(): static
    {
        return $this;
    }

    public function save(string $path, int $quality = 85): bool
    {
        if (!$this->image instanceof GdImage) {
            return false;
        }

        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return false;
        }

        return match ($this->extension) {
            'jpg', 'jpeg' => imagejpeg($this->image, $path, $quality),
            'webp' => imagewebp($this->image, $path, $quality),
            'gif' => imagegif($this->image, $path),
            // PNG 是无损的,没有"质量"可降:能调的只有压缩档。
            // 曾经按 quality 反推压缩级,结果 quality 82 反推出 0 = 完全不压缩,
            // 一张 4KB 的原图被写成 6MB 的衍生档(真跑抓到的)。无损格式一律用最高压缩。
            default => imagepng($this->image, $path, 9),
        };
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * JPEG 82、WebP 80。别的格式退回传进来的值。
     */
    public static function qualityFor(string $extension, int $fallback = 85): int
    {
        return match ($extension) {
            'jpg', 'jpeg' => 82,
            'webp' => 80,
            default => $fallback,
        };
    }

    /**
     * 按 EXIF Orientation 先转正,免得公开出去的图"躺着"。
     *
     * 对应关系是 EXIF 规范里的 2–8:先旋转,再镜像。
     * `imageflip()` 是原地翻转、返回 bool,所以不能当 `imagerotate()` 那样串在表达式里 ——
     * 这是这一版踩到过的坑(第一次写成了 `imageflip(imagerotate(...))`,翻转的结果被丢掉)。
     */
    private function applyOrientation(string $path): void
    {
        if (!$this->image instanceof GdImage || !in_array($this->extension, ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) {
            return;
        }

        $exif = @exif_read_data($path, 'IFD0');
        $orientation = $exif === false ? 1 : (int) ($exif['Orientation'] ?? 1);

        if ($orientation < 2 || $orientation > 8) {
            return;
        }

        // EXIF Orientation 的 1–8 转成 GD 的操作。两条约定要记住:
        // imagerotate 的**正角是逆时针**;5/7 是沿对角线翻转,只能"先转 90° 再左右镜像"拼出来。
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };

        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };

        $image = $this->image;

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);

            if ($rotated instanceof GdImage) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        if ($flip !== null) {
            imageflip($image, $flip);
        }

        $this->image = $image;
        $this->width = imagesx($image);
        $this->height = imagesy($image);
    }

    private function extensionOf(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $ext : 'png';
    }
}
