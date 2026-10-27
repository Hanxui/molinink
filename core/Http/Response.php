<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Http;

/**
 * 响应对象。前台页面与接口共用一个形状,便于既有规范"输出前过钩子"。
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param string|null $file 非 null 时不走内存 body,直接流式发这个文件(备份下载既有规范)
     */
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly ?string $file = null
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * 附件下载:只给文件名,不给真实路径;几百 MB 的备份包也不进内存。
     */
    public static function download(string $file, string $filename): self
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'download';

        return new self('', 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) filesize($file),
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'X-Content-Type-Options' => 'nosniff',
        ], $file);
    }

    /**
     * 接口层的统一出口:内容类型固定,斜杠不转义,中文原样。
     *
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withStatus(int $status): self
    {
        return new self($this->body, $status, $this->headers, $this->file);
    }

    /**
     * 挂一个 Set-Cookie(可多次调用,浏览器会各自记一条)。
     */
    public function withCookie(string $name, string $value, int $maxAge = 0, bool $https = false): self
    {
        $headers = $this->headers;
        $headers['Set-Cookie'] = ($headers['Set-Cookie'] ?? '') !== ''
            ? $headers['Set-Cookie'] . "\n" . SecurityHeaders::cookie($name, $value, $maxAge, $https)
            : SecurityHeaders::cookie($name, $value, $maxAge, $https);

        return new self($this->body, $this->status, $headers, $this->file);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, [...$this->headers, ...$headers], $this->file);
    }

    /**
     * 只许站内跳转:绝对地址会沦为开放重定向。
     */
    public static function redirect(string $path, int $status = 302): self
    {
        $target = str_starts_with($path, '/') ? $path : '/';

        return new self('', $status, ['Location' => $target]);
    }

    public function without(string $name): self
    {
        $headers = $this->headers;
        unset($headers[$name]);

        return new self($this->body, $this->status, $headers, $this->file);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            if ($name === 'Set-Cookie') {
                // 存的是 cookie 本体(不含头名),发的时候补上;多条必须逐条发(replace=false),
                // 否则后者顶掉前者。
                foreach (explode("\n", $value) as $line) {
                    header('Set-Cookie: ' . $line, false);
                }

                continue;
            }

            header($name . ': ' . $value, true);
        }

        if ($this->file !== null) {
            $this->stream();

            return;
        }

        echo $this->body;
    }

    /**
     * 8 KiB 一段往外送:大备份不进内存,也绕开 output buffering 的整块缓存。
     */
    private function stream(): void
    {
        $handle = fopen((string) $this->file, 'rb');

        if ($handle === false) {
            return;
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 8192);

            if ($chunk === false) {
                break;
            }

            echo $chunk;
        }

        fclose($handle);
    }
}
