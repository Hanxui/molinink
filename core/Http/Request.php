<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Http;

/**
 * 请求对象。《23-路由规范》第 9 节:参数一律经统一对象读取,禁止裸用超全局变量拼 SQL。
 * 测试可直接构造(《54-测试策略》第 5 节"构造 Request,断言 Response")。
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server 取自 $_SERVER,只读;审计与限速要 IP/UA,但内核不暴露裸超全局
     * @param array<string, mixed> $post 取自 $_POST;表单提交的唯一入口
     * @param array<string, mixed> $files 取自 $_FILES;只有上传这一处需要,内核仍不读裸超全局
     * @param string $rawBody 原始请求体;接口的 JSON 写端点从这里解析(《25》§4)
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $server = [],
        private readonly array $post = [],
        private readonly array $files = [],
        private readonly string $rawBody = ''
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        // 只有可能带体的方法才读 php://input,GET 走这条就是白花开销
        $raw = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? (string) file_get_contents('php://input')
            : '';

        return new self(
            $method,
            is_string($path) && $path !== '' ? rawurldecode($path) : '/',
            $_GET,
            $_SERVER,
            $_POST,
            $_FILES,
            $raw
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * 换一份带指定 cookie 的请求副本(测试与内部转发用,不改原对象)。
     * cookie 名由调用方给:Http 层不认识会话,更不认识用户层。
     */
    public function withCookie(string $name, string $value): self
    {
        return new self($this->method, $this->path, $this->query, [...$this->server, 'HTTP_COOKIE' => $name . '=' . $value], $this->post, $this->files, $this->rawBody);
    }

    /**
     * 换一份带合并表单字段的请求副本(接口把路径段并进字段时用,如 post_id)。
     * 传入字段覆盖同名旧值;查询串、原始体、服务器变量原样保留。
     *
     * @param array<string, mixed> $fields
     */
    public function withInput(array $fields): self
    {
        return new self($this->method, $this->path, $this->query, $this->server, $fields + $this->post, $this->files, $this->rawBody);
    }

    /**
     * 取请求头。Authorization / X-Api-Key 这类标准头在 $_SERVER 里是 HTTP_ 前缀;
     * Content-Type / Content-Length 两个不在 HTTP_ 前缀下,PHP 单列(PHP 的既定行为)。
     */
    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        $value = $this->server['HTTP_' . $key] ?? null;

        if ($value === null && in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $value = $this->server[$key] ?? null;
        }

        return $value === null ? null : trim((string) $value);
    }

    /**
     * 会话里的一条 SERVER_* 变量(健康屏要看 SERVER_SOFTWARE 判伪静态)。
     * 与 header() 分开:那条只认 HTTP_ 前缀,这条读原始键。
     */
    public function server(string $key, string $default = ''): string
    {
        $value = $this->server[$key] ?? null;

        return $value === null ? $default : trim((string) $value);
    }

    /**
     * 接口写端点的 JSON 请求体(《25》§4)。不是 JSON 体时退回表单字段 ——
     * 契约是 JSON,但兼容表单不添乱。
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode(trim($this->rawBody), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return $this->post;
    }

    /**
     * 某个表单字段收到的文件列表。
     *
     * PHP 把 `$_FILES` 按"列"存:`name`/`tmp_name`/`size`/`error` 各是一个数组,
     * 单文件时又是标量。这里统一拆成"一个文件一条",调用方才不用关心是单选还是多选。
     *
     * @return list<array{name:string, tmp_name:string, size:int, error:int, type:string}>
     */
    public function uploads(string $key): array
    {
        $entry = $this->files[$key] ?? null;

        if (!is_array($entry) || !isset($entry['name'])) {
            return [];
        }

        $names = (array) $entry['name'];
        $tmpNames = (array) ($entry['tmp_name'] ?? []);
        $sizes = (array) ($entry['size'] ?? []);
        $errors = (array) ($entry['error'] ?? []);
        $types = (array) ($entry['type'] ?? []);
        $out = [];

        foreach (array_keys($names) as $index) {
            $name = trim((string) $names[$index]);

            if ($name === '') {
                continue;
            }

            $out[] = [
                'name' => $name,
                'tmp_name' => (string) ($tmpNames[$index] ?? ''),
                'size' => (int) ($sizes[$index] ?? 0),
                'error' => (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE),
                'type' => (string) ($types[$index] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * 表单取值:POST 优先,回落到查询串。禁止任何地方再读裸 $_POST。
     */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? null;

        return $value === null ? $default : trim((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function inputs(): array
    {
        return $this->post;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * 正整数查询参数,非数字或越界一律回默认值(分页用,不接受用户传来的垃圾)。
     */
    public function queryInt(string $key, int $default, int $max = 100000): int
    {
        $raw = $this->query[$key] ?? null;

        if (!is_numeric($raw)) {
            return $default;
        }

        $value = (int) $raw;

        return $value < 1 ? $default : min($value, $max);
    }

    /**
     * 只认 REMOTE_ADDR。X-Forwarded-For 由客户端自填,可伪造 —— 审计与限速用它会失真。
     * 反向代理场景由部署侧改写 REMOTE_ADDR,不由内核猜。
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        $raw = (string) ($this->server['HTTP_COOKIE'] ?? '');

        if ($raw === '') {
            return [];
        }

        $cookies = [];

        foreach (explode(';', $raw) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($name !== '') {
                $cookies[$name] = rawurldecode($value);
            }
        }

        return $cookies;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies()[$name] ?? null;

        return $value === null ? $default : $value;
    }

    /**
     * 是不是走 HTTPS。反向代理场景由部署侧改写,内核不猜(与 ip() 同一口径)。
     */
    public function isSecure(): bool
    {
        return (string) ($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off';
    }
}
