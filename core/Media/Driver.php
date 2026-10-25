<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

/**
 * 图片处理驱动接口(《27-媒体库规范》第 5.1 节)。
 *
 * 优先 Imagick(质量、EXIF、方向更好),缺失退 GD;两者都没有就跳过缩略图,
 * 原图照常可用并记 WARNING —— 上传本身不许因为"没有图片库"而失败。
 *
 * 比文档那段示例多了 width()/height() 两个读取:调用方必须知道"转正之后到底多大",
 * 才能算各档尺寸并写进 media.width/height(文档的接口片段是示意,不是冻结契约;
 * 这是加方法,不是改方法)。
 */
interface Driver
{
    public function load(string $path): static;

    public function resize(int $w, int $h): static;

    public function save(string $path, int $quality = 85): bool;

    public function stripExif(): static;

    /** 当前这张图(已按 EXIF 转正)的宽 */
    public function width(): int;

    /** 当前这张图(已按 EXIF 转正)的高 */
    public function height(): int;
}
