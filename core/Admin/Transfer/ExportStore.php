<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Transfer;

use Molin\Router\Router;

/**
 * 导出件的落盘目录、最近列表、自动清理与名字解析。
 * 单独成类是为了让「渲染本屏」与「导出动作」都不必互相持有 —— 两边都只认这里。
 */
final class ExportStore
{
    /** 最近导出最多留几份(2026-10-17:导出件是临时的,自动收) */
    private const KEEP_EXPORTS = 10;

    /** 导出件保留小时数,过期的直接删 */
    private const EXPORT_TTL = 24;

    public function __construct(
        private readonly Router $router,
        private readonly DownloadTokens $tokens
    ) {
    }

    /**
     * 导出目录。测试或隔离运行用 MOLIN_STATE_ROOT 指到仓库外(与 DownloadTokens 同口径)。
     */
    public function exportDir(): string
    {
        return Toolkit::stateRoot() . '/storage/exports';
    }

    /**
     * 最近导出的文件(2026-10-17 加):此前只能看"刚生成那一份",页面一刷就没了 ——
     * 下载链接十分钟过期,过期后既下不了也删不掉,只能上服务器手清。
     * 现在列最近 KEEP_EXPORTS 份,每份都能重下(现签一次令牌)或删掉。
     *
     * @return list<array{name:string, size_text:string, time_text:string, download_url:string, delete_url:string}>
     */
    public function exportList(): array
    {
        $dir = $this->exportDir();

        if (!is_dir($dir)) {
            return [];
        }

        $found = glob($dir . '/export-molin-*');
        $files = is_array($found) ? $found : [];
        $rows = [];

        foreach ($files as $path) {
            $name = basename($path);

            if (preg_match(Toolkit::EXPORT_FILENAME, $name) !== 1 || !is_file($path)) {
                continue;
            }

            $size = @filesize($path);
            $time = @filemtime($path);

            if ($size === false || $time === false) {
                continue;
            }

            $rows[] = ['name' => $name, 'size' => $size, 'time' => $time];
        }

        usort($rows, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
        $rows = array_slice($rows, 0, self::KEEP_EXPORTS);
        $deleteUrl = $this->router->path('admin', ['path' => 'import-export/export/delete']);
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'name' => $row['name'],
                'size_text' => Toolkit::sizeText($row['size']),
                'time_text' => date('Y-m-d H:i', $row['time']),
                'download_url' => $this->router->path('admin', [
                    'path' => 'import-export/download/' . $this->tokens->issueToken($row['name']),
                ]),
                'delete_url' => $deleteUrl,
            ];
        }

        return $out;
    }

    /**
     * 自动收:只留最近 KEEP_EXPORTS 份,并且超过 EXPORT_TTL 小时的一律删掉 —— 导出件是临时东西,
     * 不该在磁盘上越堆越多。每趟导出、每次删除都会跑一遍。
     */
    public function pruneExports(): void
    {
        $dir = $this->exportDir();

        if (!is_dir($dir)) {
            return;
        }

        $found = glob($dir . '/export-molin-*');
        $files = is_array($found) ? $found : [];
        $rows = [];
        $deadline = time() - self::EXPORT_TTL * 3600;

        foreach ($files as $path) {
            $name = basename($path);

            if (preg_match(Toolkit::EXPORT_FILENAME, $name) !== 1 || !is_file($path)) {
                continue;
            }

            $time = @filemtime($path);

            if ($time === false) {
                continue;
            }

            if ($time < $deadline) {
                @unlink($path);

                continue;
            }

            $rows[] = ['path' => $path, 'time' => $time];
        }

        usort($rows, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        foreach (array_slice($rows, self::KEEP_EXPORTS) as $row) {
            @unlink($row['path']);
        }
    }

    /** 校验并解析导出文件的真实路径;不合规/不在目录里/不存在一律 null */
    public function exportPath(string $name): ?string
    {
        if (preg_match(Toolkit::EXPORT_FILENAME, $name) !== 1) {
            return null;
        }

        $dir = realpath($this->exportDir());

        if ($dir === false) {
            return null;
        }

        $file = realpath($dir . '/' . $name);

        if ($file === false || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }

        return $file;
    }
}
