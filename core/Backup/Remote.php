<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Backup;

use Molin\Plugin\CapabilityGate;
use Molin\Support\Files;
use RuntimeException;

/**
 * 备份推送远端(既有规范⑦:S3 / WebDAV / FTP 三选一)。
 *
 * 凭证放 `storage/backups/remote.json`(服务器文件系统,与 config.php 同一信任级别;
 * 不进备份包、不落日志)。格式:
 *
 *   {"s3":{"endpoint":"…","bucket":"…","region":"…","key":"…","secret":"…","prefix":"…"},
 *    "webdav":{"url":"…","user":"…","password":"…","verify_tls":true},
 *    "ftp":{"host":"…","port":21,"user":"…","password":"…","dir":"…","passive":true,"ssl":false}}
 *
 * 后台「推送到远端」字段与管理对话框走本类的 save / clear;CLI 走 push。
 * 密钥类字段(secret / password)留空提交 = 保持原值——表单不回显密钥。
 *
 * push / probe 是 `http.outbound` 能力的拦截面:三条驱动的直连出网
 * (S3 的签名请求、WebDAV 与 FTP)都从这两个入口出去,门卫落在发出去之前 ——
 * 内核自身(无插件上下文)照常推,插件未声明即被拦下。
 */
final class Remote
{
    public const DRIVERS = ['s3', 'webdav', 'ftp'];

    /** 「测试连接」的探针对象名:写进远端只为证明写得进去,随即删掉 */
    private const PROBE = 'molin-connect-test.txt';

    /** 每驱动的字段表:键 => [必填, 类型];string / secret / int / bool 四种 */
    private const FIELDS = [
        's3' => [
            'endpoint' => [true, 'string'],
            'bucket' => [true, 'string'],
            'region' => [false, 'string'],
            'key' => [true, 'string'],
            'secret' => [true, 'secret'],
            'prefix' => [false, 'string'],
        ],
        'webdav' => [
            'url' => [true, 'string'],
            'user' => [true, 'string'],
            'password' => [true, 'secret'],
            'verify_tls' => [false, 'bool'],
        ],
        'ftp' => [
            'host' => [true, 'string'],
            'port' => [false, 'int'],
            'user' => [true, 'string'],
            'password' => [true, 'secret'],
            'dir' => [false, 'string'],
            'passive' => [false, 'bool'],
            'ssl' => [false, 'bool'],
        ],
    ];

    /** 布尔字段没提交时的默认值 */
    private const BOOL_DEFAULTS = ['verify_tls' => true, 'passive' => true, 'ssl' => false];

    private readonly string $file;

    public function __construct(string $root)
    {
        // 与 DownloadTokens / LoginThrottle 同款:测试经 MOLIN_STATE_ROOT 换根,写盘不碰仓库
        $state = getenv('MOLIN_STATE_ROOT');
        $root = is_string($state) && $state !== '' ? $state : $root;

        $this->file = rtrim($root, '/\\') . '/storage/backups/remote.json';
    }

    /**
     * 已保存的全部凭据;文件不存在或坏档一律当空。
     *
     * @return array<string, array<string, mixed>>
     */
    public function readAll(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach (self::DRIVERS as $driver) {
            if (isset($decoded[$driver]) && is_array($decoded[$driver]) && $decoded[$driver] !== []) {
                $out[$driver] = $decoded[$driver];
            }
        }

        return $out;
    }

    /** 某个驱动是否已配置 */
    public function configured(string $driver): bool
    {
        return ($this->readAll()[$driver] ?? []) !== [];
    }

    /**
     * 表单预填用的值:secret 类字段不回显(留空 = 不修改)。
     *
     * @return array<string, mixed>
     */
    public function prefill(string $driver): array
    {
        $values = $this->readAll()[$driver] ?? [];

        foreach (['secret', 'password'] as $key) {
            unset($values[$key]);
        }

        return $values;
    }

    /**
     * 保存一个驱动的凭据(其余两段原样保留)。
     *
     * @param array<string, mixed> $fields 通常直接给 Request::inputs()
     * @throws RuntimeException 未知驱动或必填缺失
     */
    public function save(string $driver, array $fields): void
    {
        $all = $this->readAll();
        $all[$driver] = $this->normalize($driver, $fields);
        $this->write($all);
    }

    /**
     * 把表单字段归一成一份完整凭据:白名单外的键丢弃;布尔/整数归一;secret 留空 = 保持原值。
     * 保存与「测试连接」共用这一处口径 —— 测的就是即将存下去的那份。
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws RuntimeException 未知驱动或必填缺失
     */
    private function normalize(string $driver, array $fields): array
    {
        $spec = self::FIELDS[$driver] ?? throw new RuntimeException('未知的远端类型:' . $driver . '(支持 s3 / webdav / ftp)');

        $existing = $this->readAll()[$driver] ?? [];
        $clean = [];

        foreach ($spec as $key => [$required, $type]) {
            $raw = $fields[$key] ?? null;

            if ($type === 'bool') {
                $clean[$key] = $raw === null || $raw === ''
                    ? (bool) ($existing[$key] ?? self::BOOL_DEFAULTS[$key])
                    : filter_var($raw, FILTER_VALIDATE_BOOL);

                continue;
            }

            if ($type === 'int') {
                $value = $raw === null || $raw === '' ? (int) ($existing[$key] ?? 21) : (int) $raw;
                $clean[$key] = max(1, min(65535, $value));

                continue;
            }

            $value = is_string($raw) ? trim($raw) : '';

            // 密钥不回显:表单交白卷 = 保留原值,而不是清空
            if ($value === '' && $type === 'secret' && is_string($existing[$key] ?? null) && $existing[$key] !== '') {
                $clean[$key] = $existing[$key];

                continue;
            }

            $clean[$key] = $value;
        }

        $missing = [];

        foreach ($spec as $key => [$required, $type]) {
            if ($required && ($clean[$key] ?? '') === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('远端凭据缺必填项:' . implode(' / ', $missing));
        }

        return $clean;
    }

    /** 清除一个驱动的凭据(其余两段保留) */
    public function clear(string $driver): void
    {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException('未知的远端类型:' . $driver . '(支持 s3 / webdav / ftp)');
        }

        $all = $this->readAll();
        unset($all[$driver]);
        $this->write($all);
    }

    /**
     * 原子落盘:临时文件 → 0600 → rename(与 DownloadTokens 同一做法)。
     *
     * @param array<string, array<string, mixed>> $all
     */
    private function write(array $all): void
    {
        $dir = dirname($this->file);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $temp = $this->file . '.' . bin2hex(random_bytes(4));
        $json = (string) json_encode($all, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (file_put_contents($temp, $json) === false) {
            throw new RuntimeException('凭据写盘失败:' . $this->file);
        }

        @chmod($temp, 0o600);
        rename($temp, $this->file);
    }

    /**
     * 推送一份备份,返回一句给人看的去处描述。
     *
     * @param string $driver s3 / webdav / ftp
     */
    public function push(string $driver, string $path, string $name): string
    {
        // 出网门卫落在发出去之前:三条驱动的推送都是外部 HTTP/FTP 请求,一处圈住。
        CapabilityGate::assert('http.outbound');

        if (!in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException('未知的远端类型:' . $driver . '(支持 s3 / webdav / ftp)');
        }

        $config = $this->driverConfig($driver);

        return match ($driver) {
            's3' => $this->pushS3($config, $path, $name),
            'webdav' => $this->pushWebdav($config, $path, $name),
            'ftp' => $this->pushFtp($config, $path, $name),
        };
    }

    /**
     * 就地探一次"真写得进去吗"(既有规范「测试连接」):PUT 一个几十字节的探针对象,随即删掉。
     *
     * 拿**表单里当前的值**探,不只认已保存的凭据 —— 填完先测、再保存才是顺手的顺序;
     * 只读握手不解决问题:读得到不等于写得进,而备份推送的本质是写。
     * 删不掉不算失败(推送备份本来只写不删),但要在回执里如实说清探针没清掉。
     *
     * @param array<string, mixed> $fields 通常直接给 Request::inputs()
     * @return string 一句给人看的去处描述
     * @throws RuntimeException 配不全 / 连不上 / 被拒
     */
    public function probe(string $driver, array $fields): string
    {
        // 「测试连接」也是一次直连出网,与 push() 同一道门卫。
        CapabilityGate::assert('http.outbound');

        if (!in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException('未知的远端类型:' . $driver . '(支持 s3 / webdav / ftp)');
        }

        $config = $this->normalize($driver, $fields);
        $local = $this->probeFile();

        try {
            return match ($driver) {
                's3' => $this->probeS3($config, $local),
                'webdav' => $this->probeWebdav($config, $local),
                'ftp' => $this->probeFtp($config, $local),
            };
        } finally {
            Files::delete($local);
        }
    }

    /** 探针的本地内容:几十字节,够证明一次写入往返 */
    private function probeFile(): string
    {
        $path = rtrim(sys_get_temp_dir(), '/\\') . '/' . self::PROBE;
        file_put_contents($path, "molin connect test\n");

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function driverConfig(string $driver): array
    {
        if (!is_file($this->file)) {
            throw new RuntimeException('远端未配置:请先在服务器上准备 ' . $this->file);
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);
        $config = is_array($decoded) ? ($decoded[$driver] ?? null) : null;

        if (!is_array($config) || $config === []) {
            throw new RuntimeException('远端配置里没有「' . $driver . '」这一段:' . $this->file);
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pushWebdav(array $config, string $path, string $name): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('这台 PHP 没开 curl 扩展,WebDAV 推送用不了。');
        }

        $url = rtrim((string) ($config['url'] ?? ''), '/') . '/' . rawurlencode($name);
        $user = (string) ($config['user'] ?? '');

        if ($url === '/' || $user === '') {
            throw new RuntimeException('WebDAV 配置缺 url 或 user。');
        }

        $this->webdavSend($url, $user, (string) ($config['password'] ?? ''), ($config['verify_tls'] ?? true) !== false, 'PUT', $path, 1800);

        return 'WebDAV ' . $url;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function probeWebdav(array $config, string $local): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('这台 PHP 没开 curl 扩展,WebDAV 推送用不了。');
        }

        $url = rtrim((string) ($config['url'] ?? ''), '/') . '/' . rawurlencode(self::PROBE);
        $user = (string) ($config['user'] ?? '');

        if ($url === '/' || $user === '') {
            throw new RuntimeException('WebDAV 配置缺 url 或 user。');
        }

        $password = (string) ($config['password'] ?? '');
        $verify = ($config['verify_tls'] ?? true) !== false;
        $this->webdavSend($url, $user, $password, $verify, 'PUT', $local, 60);

        try {
            $this->webdavSend($url, $user, $password, $verify, 'DELETE', null, 60);

            return 'WebDAV ' . $url . '(写入通过,探针已删除)';
        } catch (RuntimeException) {
            return 'WebDAV ' . $url . '(写入通过,探针未能自动删除)';
        }
    }

    /**
     * 发一条 WebDAV 请求。$path 给 null = 无 body(DELETE)。
     */
    private function webdavSend(string $url, string $user, string $password, bool $verify, string $method, ?string $path, int $timeout): void
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('curl 初始化失败。');
        }

        $options = [
            CURLOPT_USERPWD => $user . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_TIMEOUT => $timeout,
        ];

        $handle = null;

        if ($path === null) {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_POSTFIELDS] = '';
        } else {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('读不了本地备份文件:' . $path);
            }

            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $handle;
            $options[CURLOPT_INFILESIZE] = (int) filesize($path);
        }

        curl_setopt_array($curl, $options);

        try {
            $this->curlSend($curl, 'WebDAV');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pushFtp(array $config, string $path, string $name): string
    {
        if (!function_exists('ftp_connect')) {
            throw new RuntimeException('这台 PHP 没开 ftp 扩展,FTP 推送用不了。');
        }

        $host = (string) ($config['host'] ?? '');
        $port = max(1, min(65535, (int) ($config['port'] ?? 21)));
        $user = (string) ($config['user'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $dir = rtrim((string) ($config['dir'] ?? ''), '/');
        $passive = ($config['passive'] ?? true) !== false;
        $ssl = ($config['ssl'] ?? false) === true;

        if ($host === '' || $user === '') {
            throw new RuntimeException('FTP 配置缺 host 或 user。');
        }

        $conn = $ssl ? ftp_ssl_connect($host, $port, 15) : ftp_connect($host, $port, 15);

        if ($conn === false) {
            throw new RuntimeException('连不上 FTP:' . $host . ':' . $port);
        }

        try {
            if (!ftp_login($conn, $user, $password)) {
                throw new RuntimeException('FTP 登录被拒(看下 user / password)。');
            }

            ftp_pasv($conn, $passive);

            if ($dir !== '' && !@ftp_chdir($conn, $dir)) {
                throw new RuntimeException('FTP 目录进不去:' . $dir);
            }

            $remote = $dir === '' ? $name : $dir . '/' . $name;

            if (!ftp_put($conn, $remote, $path, FTP_BINARY)) {
                throw new RuntimeException('FTP 上传失败:' . $remote);
            }
        } finally {
            ftp_close($conn);
        }

        return 'FTP ' . $host . ':' . $port . '/' . ($dir === '' ? '' : $dir . '/') . $name;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function probeFtp(array $config, string $local): string
    {
        if (!function_exists('ftp_connect')) {
            throw new RuntimeException('这台 PHP 没开 ftp 扩展,FTP 推送用不了。');
        }

        $host = (string) ($config['host'] ?? '');
        $port = max(1, min(65535, (int) ($config['port'] ?? 21)));
        $user = (string) ($config['user'] ?? '');
        $dir = rtrim((string) ($config['dir'] ?? ''), '/');
        $ssl = ($config['ssl'] ?? false) === true;

        if ($host === '' || $user === '') {
            throw new RuntimeException('FTP 配置缺 host 或 user。');
        }

        $conn = $ssl ? ftp_ssl_connect($host, $port, 15) : ftp_connect($host, $port, 15);

        if ($conn === false) {
            throw new RuntimeException('连不上 FTP:' . $host . ':' . $port);
        }

        try {
            if (!ftp_login($conn, $user, (string) ($config['password'] ?? ''))) {
                throw new RuntimeException('FTP 登录被拒(看下 user / password)。');
            }

            ftp_pasv($conn, ($config['passive'] ?? true) !== false);

            if ($dir !== '' && !@ftp_chdir($conn, $dir)) {
                throw new RuntimeException('FTP 目录进不去:' . $dir);
            }

            $remote = $dir === '' ? self::PROBE : $dir . '/' . self::PROBE;

            if (!ftp_put($conn, $remote, $local, FTP_BINARY)) {
                throw new RuntimeException('FTP 上传失败:' . $remote);
            }

            $where = 'FTP ' . $host . ':' . $port . '/' . ($dir === '' ? '' : $dir . '/') . self::PROBE;

            return @ftp_delete($conn, $remote)
                ? $where . '(写入通过,探针已删除)'
                : $where . '(写入通过,探针未能自动删除)';
        } finally {
            ftp_close($conn);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pushS3(array $config, string $path, string $name): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('这台 PHP 没开 curl 扩展,S3 推送用不了。');
        }

        $parts = $this->s3Parts($config);
        $this->s3Send($parts, 'PUT', $name, $path, 1800);

        return 'S3 ' . $parts['host'] . $parts['keyPath'] . $name;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function probeS3(array $config, string $local): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('这台 PHP 没开 curl 扩展,S3 推送用不了。');
        }

        $parts = $this->s3Parts($config);
        $where = 'S3 ' . $parts['host'] . $parts['keyPath'] . self::PROBE;
        $this->s3Send($parts, 'PUT', self::PROBE, $local, 60);

        try {
            $this->s3Send($parts, 'DELETE', self::PROBE, null, 60);

            return $where . '(写入通过,探针已删除)';
        } catch (RuntimeException) {
            return $where . '(写入通过,探针未能自动删除)';
        }
    }

    /**
     * S3 的公共件:校验必填、把 endpoint 拆成签名要用的几样。推送与探针共用一处口径。
     *
     * @param array<string, mixed> $config
     * @return array{endpoint:string, keyPath:string, region:string, accessKey:string, secretKey:string, host:string}
     */
    private function s3Parts(array $config): array
    {
        $endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $bucket = (string) ($config['bucket'] ?? '');
        $accessKey = (string) ($config['key'] ?? '');

        if ($endpoint === '' || $bucket === '' || $accessKey === '') {
            throw new RuntimeException('S3 配置缺 endpoint / bucket / key。');
        }

        $host = parse_url($endpoint, PHP_URL_HOST);
        $host = is_string($host) ? $host : '';

        if ($host === '') {
            throw new RuntimeException('S3 endpoint 不是合法地址:' . $endpoint);
        }

        $port = parse_url($endpoint, PHP_URL_PORT);
        $prefix = trim((string) ($config['prefix'] ?? ''), '/');

        return [
            'endpoint' => $endpoint,
            'keyPath' => '/' . $bucket . '/' . ($prefix === '' ? '' : $prefix . '/'),
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            'accessKey' => $accessKey,
            'secretKey' => (string) ($config['secret'] ?? ''),
            'host' => $host . (is_int($port) ? ':' . $port : ''),
        ];
    }

    /**
     * 发一条签名过的 S3 请求。$path 给 null = 无 body(DELETE)。
     *
     * @param array{endpoint:string, keyPath:string, region:string, accessKey:string, secretKey:string, host:string} $parts
     */
    private function s3Send(array $parts, string $method, string $object, ?string $path, int $timeout): void
    {
        $amzDate = gmdate('Ymd\THis\Z');
        // URL 与规范串都要单次转义、且互不重复转义:URL 在这边编码,规范串由 S3Signer 逐段编码
        $keyPath = $parts['keyPath'] . $object;
        $urlPath = '/' . implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', ltrim($keyPath, '/'))
        ));
        $payloadHash = $path === null ? hash('sha256', '') : (string) hash_file('sha256', $path);

        $headers = S3Signer::sign(
            $method,
            $keyPath,
            [],
            [
                'host' => $parts['host'],
                'x-amz-content-sha256' => $payloadHash,
                'x-amz-date' => $amzDate,
            ],
            $payloadHash,
            's3',
            $parts['region'],
            $parts['accessKey'],
            $parts['secretKey'],
            $amzDate
        );

        $curl = curl_init($parts['endpoint'] . $urlPath);

        if ($curl === false) {
            throw new RuntimeException('curl 初始化失败。');
        }

        $lines = [];

        foreach ($headers as $headerName => $value) {
            $lines[] = $headerName . ': ' . $value;
        }

        $options = [
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ];

        $handle = null;

        if ($path === null) {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_POSTFIELDS] = '';
        } else {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('读不了本地备份文件:' . $path);
            }

            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $handle;
            $options[CURLOPT_INFILESIZE] = (int) filesize($path);
        }

        curl_setopt_array($curl, $options);

        try {
            $this->curlSend($curl, 'S3');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * 发出去、把错误信息翻译成人话;失败抛出。
     */
    private function curlSend(\CurlHandle $curl, string $label): void
    {
        $response = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException($label . ' 推送失败:' . ($error === '' ? '网络错误' : $error));
        }

        if ($code < 200 || $code >= 300) {
            $snippet = trim(substr(is_string($response) ? $response : '', 0, 200));

            throw new RuntimeException($label . ' 推送被拒(HTTP ' . $code . ')' . ($snippet === '' ? '' : ':' . $snippet));
        }
    }
}
