<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

use Molin\Content\Slug;
use Molin\Exception\ConfigException;

/**
 * 落盘与命名(《27-媒体库规范》第 4、9 节)。
 *
 * 三条硬规矩:
 * ① **永不使用原始文件名** —— 落盘名 = `主干-时间戳-随机8位.扩展`,人看得懂又不会被覆盖/猜到;
 * ② 按 年/月 分目录,单目录不堆山;
 * ③ 一切都在 `storage/uploads/` 里,这个目录不对外直接暴露(铁律 21),
 *    对外 URL 走 `/uploads/...` 别名(部署侧配置见《27》第 9 节的 Nginx 片段)。
 */
final class Store
{
    private const UPLOADS_DIR = 'storage/uploads';

    /** 对外别名前缀;与《27》第 9 节、部署侧的 `location ^~ /uploads/` 同一口径 */
    private const PUBLIC_PREFIX = '/uploads/';

    public function __construct(private readonly string $root)
    {
    }

    /**
     * 《27》第 4.1 节的落盘名。主干取自原始名(只取可读性,不取真实性)。
     *
     * 两点要紧的:
     * ① 末尾的扩展先剥掉,否则 `Photo.JPG` 会 slug 成 `photo-jpg`;
     * ② **落盘名只留 ASCII**(`[a-z0-9-]`)—— 文件名要过 URL、过 Nginx、过备份与 FTP,
     *    跨环境时非 ASCII 名最容易出乱码;`sanitize()` 那道路径白名单也只认这一套字符。
     *    人看的是附件在 `posts` 里的标题,那里原样保留中文。
     */
    public function nameFor(string $baseName, string $extension, ?int $now = null): string
    {
        $now ??= time();
        $stem = (string) preg_replace('/\.[A-Za-z0-9]+$/', '', $baseName);
        $stem = trim((string) preg_replace('/[^a-z0-9-]/', '', Slug::slugify($stem)), '-');

        if ($stem === '') {
            $stem = 'file';
        }

        return $stem . '-' . $now . '-' . bin2hex(random_bytes(4)) . ($extension === '' ? '' : '.' . $extension);
    }

    /**
     * 相对 `storage/uploads/` 的路径(《21》第 8 节 `media.file_path` 存的就是它)。
     */
    public function relativePath(string $name, ?int $now = null): string
    {
        $now ??= time();

        return date('Y', $now) . '/' . date('m', $now) . '/' . $name;
    }

    /**
     * 生成同一条内容的衍生档路径:`xxx-1759048392-a3f9c2d1.jpg` → `...-300x200.jpg`
     */
    public function sizePath(string $relative, string $extension, int $width, int $height): string
    {
        $dot = strrpos($relative, '.');
        $stem = $dot === false ? $relative : substr($relative, 0, $dot);

        return $stem . '-' . $width . 'x' . $height . ($extension === '' ? '' : '.' . $extension);
    }

    public function absolutePath(string $relative): string
    {
        return $this->root . '/' . self::UPLOADS_DIR . '/' . $this->sanitize($relative);
    }

    /**
     * 对外 URL。内核只拼前缀,权限与禁执行由部署侧的别名规则保证(《27》第 9 节)。
     */
    public function url(string $relative): string
    {
        return self::PUBLIC_PREFIX . $this->sanitize($relative);
    }

    public function exists(string $relative): bool
    {
        return is_file($this->absolutePath($relative));
    }

    /**
     * 把上传来的 master 文件放进目录。真上传必须过 `is_uploaded_file()` ——
     * 这一步防的是"拿服务器上任意文件冒充上传结果"。
     */
    public function place(Upload $upload, string $relative): void
    {
        $target = $this->absolutePath($relative);
        $dir = dirname($target);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new ConfigException('建上传目录失败:' . $dir . '(检查 storage 权限)');
        }

        if ($upload->fromRequestFile()) {
            if (!is_uploaded_file($upload->tmpPath) || !move_uploaded_file($upload->tmpPath, $target)) {
                throw new ConfigException('保存上传文件失败,请重传');
            }

            return;
        }

        // CLI 导入与测试夹具:文件已在本地磁盘,没有"上传来源"这一说
        if (!rename($upload->tmpPath, $target)) {
            // 临时目录与站点不在同一个卷时 rename 会失败(EXDEV / Windows 跨盘):
            // 退回复制再删,语义不变 —— 转码口换回来的那份文件也走这条路
            if (!copy($upload->tmpPath, $target)) {
                throw new ConfigException('移动文件失败:' . $relative);
            }

            @unlink($upload->tmpPath);
        }
    }

    /**
     * 物理删一个文件(含衍生档)。定时任务清理待删内容时用得到(《27》第 8.1 节)。
     */
    public function remove(string $relative): bool
    {
        $path = $this->absolutePath($relative);

        return is_file($path) && @unlink($path);
    }

    /**
     * 路径遍历防线:落盘相对路径只允许"年/月/文件名"这一段安全字符集。
     */
    private function sanitize(string $relative): string
    {
        $clean = str_replace('\\', '/', $relative);

        if (preg_match('#\A[0-9]{2,4}/[0-9]{2}/[A-Za-z0-9._-]+\z#', $clean) !== 1) {
            throw new ConfigException('非法的上传路径:' . $relative);
        }

        return $clean;
    }
}
