<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

/**
 * 尺寸档。这张表是尺寸档的唯一权威定义,
 * 其他文档提到档位一律引用这里;站点可用 option `media_sizes`(JSON)增删档位。
 *
 * `0` 表示不缩放(原图)。改档位只影响**新上传**,已上传的要重新生成
 * (CLI `media:regenerate` 归后面那轮)。
 */
final class Sizes
{
    /** 既有规范的默认档 */
    private const DEFAULTS = [
        'thumb' => 300,
        'medium' => 768,
        'large' => 1200,
        'xlarge' => 1600,
        'full' => 0,
    ];

    /**
     * @return array<string, int>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * @param array<string, mixed>|null $override options.media_sizes 解出来的键值
     * @return array<string, int>
     */
    public static function resolve(?array $override): array
    {
        if ($override === null || $override === []) {
            return self::DEFAULTS;
        }

        $sizes = [];

        foreach ($override as $name => $edge) {
            $name = trim((string) $name);

            if ($name === '' || !is_numeric($edge) || (int) $edge < 0) {
                continue;
            }

            $sizes[$name] = (int) $edge;
        }

        return $sizes === [] ? self::DEFAULTS : $sizes;
    }

    /**
     * 需要落盘的档(原图除外 —— 原文件本身就是 master)。
     *
     * @param array<string, int> $sizes
     * @return array<string, int>
     */
    public static function scalable(array $sizes): array
    {
        return array_filter($sizes, static fn (int $edge): bool => $edge > 0);
    }

    /**
     * 等比缩放后的目标边长:原图比档还小就不放大(放大只会糊)。
     *
     * @return array{0:int, 1:int}
     */
    public static function fit(int $width, int $height, int $edge): array
    {
        if ($edge <= 0 || $width <= 0 || $height <= 0) {
            return [$width, $height];
        }

        $longer = max($width, $height);

        if ($longer <= $edge) {
            return [$width, $height];
        }

        $ratio = $edge / $longer;

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    /**
     * 站点配置的 JSON(option `media_sizes`)解成数组;坏数据当没配。
     *
     * @return array<string, mixed>|null
     */
    public static function fromJson(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
