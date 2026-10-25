<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

/**
 * 一次上传的输入(《27-媒体库规范》第 1 节第 ① 步)。
 *
 * 内核不读 `$_FILES`,所以由入口/后台屏把 PHP 给的那坨整理成这个对象再传进来。
 * `fromRequest` 标记它是真上传还是别处(测试、CLI 导入)拿来的文件 ——
 * 真的才走 `is_uploaded_file()` 这道 PHP 自己的来源校验,假的不能冒名走这条路。
 */
final class Upload
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        public readonly string $originalName,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly int $phpError = UPLOAD_ERR_OK,
        private readonly bool $fromRequest = true
    ) {
    }

    /**
     * 由 `$_FILES` 里的一项构造(唯一的"真上传"入口)。
     *
     * @param array{name?: string, tmp_name?: string, size?: int, error?: int} $file
     */
    public static function fromRequest(array $file): self
    {
        return new self(
            (string) ($file['name'] ?? ''),
            (string) ($file['tmp_name'] ?? ''),
            (int) ($file['size'] ?? 0),
            (int) ($file['error'] ?? UPLOAD_ERR_OK),
            true
        );
    }

    /**
     * 由一个已经在磁盘上的文件构造(CLI 导入、测试夹具)。
     */
    public static function fromPath(string $path, ?string $name = null): self
    {
        return new self(
            $name ?? (basename($path)),
            $path,
            self::bytesOf($path),
            UPLOAD_ERR_OK,
            false
        );
    }

    public function fromRequestFile(): bool
    {
        return $this->fromRequest;
    }

    /**
     * 磁盘上的真实字节数 —— 前端给的 size 可以随便写,这里只认文件本身。
     */
    private static function bytesOf(string $path): int
    {
        $size = filesize($path);

        return $size === false ? 0 : $size;
    }

    /**
     * PHP 自己会说清为什么没收到(超 limit、只传了一半……)。
     */
    public function transportError(): string
    {
        return match ($this->phpError) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件太大,超了服务器允许的单文件上限',
            UPLOAD_ERR_PARTIAL => '只传了一半,请重传',
            UPLOAD_ERR_NO_FILE => '没有收到文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写盘失败',
            UPLOAD_ERR_EXTENSION => '被某个 PHP 扩展拦下',
            default => '上传失败',
        };
    }

    /**
     * 扩展名(小写,不含点)。原始名可能是 `Photo.JPG` 或带路径,这里只取最后一段的扩展。
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->baseName(), PATHINFO_EXTENSION));
    }

    /**
     * 原始名的主干(不含目录、不含扩展)。只用于生成可读的落盘名,本体不直接落盘。
     */
    public function baseName(): string
    {
        return basename(str_replace('\\', '/', $this->originalName));
    }

    public function isEmpty(): bool
    {
        return $this->originalName === '' || $this->tmpPath === '';
    }
}
