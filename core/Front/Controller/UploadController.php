<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Front\Controller;

use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\UploadPolicy;

/**
 * 直接发上传目录里的文件。
 *
 * 服务器侧配了 `/uploads/` 别名时轮不到这条(nginx 先接走,更快);
 * 没配也不至于全站破图 —— 图片经 PHP 发出去,代价是多一次进程开销。
 * 只发白名单里的扩展名;路径必须落在上传目录内(`..`、符号链接一律挡掉)。
 */
final class UploadController
{
    public function __construct(private readonly string $uploadsDir)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function serve(Request $request, array $vars = []): Response
    {
        $rel = ltrim(str_replace('\\', '/', (string) ($vars['path'] ?? '')), '/');

        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            throw new NotFoundException('文件不存在');
        }

        $base = realpath($this->uploadsDir);
        $path = $base === false ? false : realpath($base . '/' . $rel);

        if ($path === false || !is_file($path) || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            throw new NotFoundException('文件不存在');
        }

        $mime = UploadPolicy::mimeFor(strtolower(pathinfo($path, PATHINFO_EXTENSION)));

        if ($mime === null) {
            throw new NotFoundException('文件不存在');
        }

        return new Response('', 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            // 与服务器侧 `expires 30d; Cache-Control: public, immutable` 同一口径:
            // 文件名带时间戳与随机串,内容不会原地变
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ], $path);
    }
}
