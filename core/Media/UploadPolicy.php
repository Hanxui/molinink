<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

use Molin\Exception\ValidationException;

/**
 * 上传校验。安全基调下这是最硬的一道闸:
 * **永远不信前端给的文件名与 MIME**。
 *
 * 逐条对应文档的校验清单:扩展名白名单 → 真实 MIME(finfo)→ 图片可解析(getimagesize)
 * → 大小上限 → 像素上限(防解压炸弹)→ 双扩展名 → 空字节。任一条不过就拒。
 */
final class UploadPolicy
{
    /** 既有规范的默认白名单:扩展名 => 允许的 MIME 前缀/值 */
    private const ALLOWED = [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
    ];

    /**
     * svg 默认关(可含脚本,是 XSS 载体)。
     * 真要开必须显式配置,且落盘后按附件下载或先净化 —— 那套归 SVG 增量,这里只拒。
     */
    private const DENIED_BY_DEFAULT = ['svg', 'html', 'htm', 'xhtml', 'php', 'php5', 'phtml', 'phar', 'sh', 'py', 'pl', 'cgi', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd'];

    /** 像素上限,防"解压炸弹"。转码口复核同一把尺子,故对外可见 */
    public const MAX_PIXELS = 8000;

    /**
     * @var list<string> 实际放行的扩展名:默认白名单,或被系统设置「上传类型」覆盖
     */
    private readonly array $allowed;

    /**
     * @param list<string> $extraAllowed 插件通过 `media/upload/before` 追加的扩展名
     * @param list<string> $configured   系统设置「上传类型」给的允许扩展名;空则退回默认白名单
     */
    public function __construct(
        private readonly int $maxBytes = Upload::MAX_BYTES,
        private readonly array $extraAllowed = [],
        array $configured = []
    ) {
        $this->allowed = $configured !== [] ? $configured : array_keys(self::ALLOWED);
    }

    public static function allowsExtension(string $ext): bool
    {
        return isset(self::ALLOWED[$ext]);
    }

    /**
     * 扩展名 → 对外声明的 MIME(取白名单里的第一个)。不在白名单给 null。
     */
    public static function mimeFor(string $ext): ?string
    {
        return self::ALLOWED[strtolower($ext)][0] ?? null;
    }

    /**
     * @return list<string> 默认白名单的扩展名(给提示文案与测试用)
     */
    public static function allowedExtensions(): array
    {
        return array_keys(self::ALLOWED);
    }

    /**
     * 收得下就返回真实 MIME 与尺寸;收不下就抛字段级错误(表单能就地显示)。
     *
     * 具体理由写进**顶层消息**:屏幕往往只打印一行话,得让人看见到底哪条不合格。
     *
     * @return array{mime:string, width:?int, height:?int}
     */
    public function assert(Upload $upload): array
    {
        if ($upload->phpError !== UPLOAD_ERR_OK) {
            $why = $upload->transportError();

            throw new ValidationException('上传没成功:' . $why, ['file' => [$why]]);
        }

        if ($upload->isEmpty()) {
            throw new ValidationException('没有收到文件', ['file' => ['换一个文件再试']]);
        }

        if (!is_readable($upload->tmpPath)) {
            throw new ValidationException('文件读不到', ['file' => ['临时文件不存在或不可读']]);
        }

        // 空字节:老式截断攻击的签名,一律拒
        if (str_contains($upload->originalName, "\0")) {
            throw new ValidationException('文件名不合法:含空字节', ['file' => ['文件名里有非法字符']], 'null_byte');
        }

        $base = $upload->baseName();

        // 双扩展名:`evil.php.jpg` 一律拒 —— 白名单只看最后一截是不够的。
        // 首段是主名、末段是真扩展名(由下面的白名单判),要查的是**中间**那几截。
        $hops = explode('.', $base);
        array_shift($hops);
        array_pop($hops);

        foreach ($hops as $segment) {
            if (in_array(strtolower($segment), self::DENIED_BY_DEFAULT, true)) {
                throw new ValidationException("这种文件名不收:{$base} 中间那段像 .{$segment},是双重扩展名", ['file' => ['双重扩展名']], 'double_extension');
            }
        }

        $ext = $upload->extension();

        if ($ext === '') {
            throw new ValidationException('这种类型不收:文件名没有扩展名', ['file' => ['可收:' . $this->hintList($this->extraAllowed)]], 'ext_not_allowed');
        }

        if (in_array($ext, self::DENIED_BY_DEFAULT, true)) {
            $why = $ext === 'svg'
                ? 'svg 可以内含脚本,是 XSS 载体,默认关闭;要开得显式配置并强制净化'
                : '可执行或可脚本的内容一律不收';

            throw new ValidationException("这种类型不收:.{$ext} —— {$why}", ['file' => ['默认白名单之外']], $ext === 'svg' ? 'svg_default' : 'ext_not_allowed');
        }

        if (!in_array($ext, $this->allowed, true) && !in_array($ext, $this->extraAllowed, true)) {
            throw new ValidationException(
                "这种类型不收:.{$ext} —— 默认拒绝一切不在白名单的文件;要加类型得由插件显式申报",
                ['file' => ['可收:' . $this->hintList($this->extraAllowed)]],
                'ext_not_allowed'
            );
        }

        if ($upload->size <= 0 || $upload->size > $this->maxBytes) {
            $why = sprintf('上限 %dMB,当前 %sMB', (int) round($this->maxBytes / 1048576), number_format($upload->size / 1048576, 2));

            throw new ValidationException('文件太大:' . $why, ['file' => [$why]], 'too_large');
        }

        // 真实 MIME 由 finfo 读文件内容,不信前端给的 type
        $mime = $this->realMime($upload->tmpPath);
        $why = '';
        $rule = '';

        if (self::allowsExtension($ext)) {
            if (!in_array($mime, self::ALLOWED[$ext], true)) {
                $why = "内容与扩展名对不上:实际是 {$mime},却用 .{$ext} 结尾";
                $rule = 'mime_mismatch';
            }
        } elseif (!$this->inDocumentedCategory($mime)) {
            // 插件通过 `media/upload/before` 加的扩展名,内容仍要落在既有规范那四类里 ——
            // 白名单可以谈,可执行/可脚本的内容一律不行
            $why = "类型不在许可的类别里:读到的是 {$mime},墨林只收图片、文档、压缩、音视频四类";
            $rule = 'mime_unlisted';
        }

        if ($why !== '') {
            throw new ValidationException($why, ['file' => [$why]], $rule);
        }

        $width = null;
        $height = null;

        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($upload->tmpPath);

            if ($info === false) {
                throw new ValidationException('图片解析失败:文件可能是伪造的,或已损坏', ['file' => ['不是能解析的图片']], 'image_unreadable');
            }

            $width = $info[0];
            $height = $info[1];

            if ($width > self::MAX_PIXELS || $height > self::MAX_PIXELS) {
                $why = "图片 {$width}×{$height},超过 " . self::MAX_PIXELS . '×' . self::MAX_PIXELS . ' 的上限';

                throw new ValidationException('像素太大:' . $why, ['file' => [$why]], 'pixel_limit');
            }
        }

        return ['mime' => $mime, 'width' => $width, 'height' => $height];
    }

    /**
     * 拒人的时候要说清能收什么,别让人猜。
     *
     * @param list<string> $extra
     */
    private function hintList(array $extra): string
    {
        $list = $this->allowed;

        foreach ($extra as $ext) {
            $list[] = $ext;
        }

        return implode(', ', $list);
    }

    /**
     * 既有规范的四类:图片 / 文档 / 压缩 / 音视频。插件追加扩展名时按类放行。
     */
    private function inDocumentedCategory(string $mime): bool
    {
        foreach (['image/', 'video/', 'audio/'] as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return in_array($mime, ['application/pdf', 'application/zip', 'application/x-zip-compressed'], true);
    }

    /**
     * 只认文件内容,不认前端给的 type(既有规范约定)。
     */
    private function realMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new ValidationException('读不了文件内容', ['file' => ['服务器缺少 fileinfo 扩展']]);
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? '' : $mime;
    }
}
