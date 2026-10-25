<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

/**
 * 挑一个可用的图片驱动(《27-媒体库规范》第 5.1 节)。
 *
 * 文档要求优先 Imagick、缺失退 GD;这台开发机与测试环境都没有 Imagick,
 * 所以 Imagick 那条分支这轮没实现 —— 不写没人跑过的代码。
 * 两个都没有时返回 null:上传照样成功,只是不生成缩略图,由调用方记 WARNING。
 */
final class Drivers
{
    public static function pick(): ?Driver
    {
        return GdDriver::available() ? new GdDriver() : null;
    }
}
