<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Update;

use Closure;
use Molin\Option\Options;

/**
 * 升级清单的收取、验签与判定(既有规范清单、既有规范安全校验)。
 *
 * **防把源站打挂**这件事在这里做三道:
 *   ① 限频:自动检查 6 小时一次、手动 60 秒一次 —— 站点再多也不许把源站当心跳打;
 *   ② 缓存:清单落在 options 里,看页面走缓存,不重复联网;
 *   ③ 304:带着上次的 ETag 去问,源站回 304 时响应只有几百字节。
 *
 * 拿不到就**如实说拿不到**(超时/连不上/证书不过/没验签过/版本不合法),绝不编一个"已是最新"。
 */
final class Feed
{
    /** 自动检查最小间隔(秒) */
    public const AUTO_INTERVAL = 21600;

    /** 手动「检查更新」最小间隔(秒) */
    public const MANUAL_INTERVAL = 60;

    public const TIMEOUT = 8;

    /**
     * 参与签名的字段与顺序 —— **发布侧与验签侧共用这一份**。
     * 改这里等于改协议:老的清单会立刻验不过,所以只增,且新增必须放在末尾。
     */
    public const SIGNED_FIELDS = [
        'version', 'channel', 'contract', 'min_php', 'release_date', 'requires_backup',
        'published_at', 'download', 'sha256', 'migrations', 'changelog', 'notes', 'previous',
    ];

    private const OPT_MANIFEST = 'update_manifest';
    private const OPT_CHECKED = 'update_manifest_checked_at';
    private const OPT_ETAG = 'update_manifest_etag';

    /** @var Closure(string, string): array{status:int, body:string, etag:string, error:string} */
    private readonly Closure $http;

    /**
     * @param (callable(string, string): array{status:int,body:string,etag:string,error:string})|null $http 测试用替身
     * @param string|null $publicKey 测试用公钥;不传就用内置那份
     */
    public function __construct(
        private readonly Options $options,
        ?callable $http = null,
        private readonly ?string $publicKey = null
    ) {
        $this->http = Closure::fromCallable($http ?? static fn (string $url, string $etag): array => Http::get($url, $etag, self::TIMEOUT));
    }

    /**
     * 收一次清单。
     *
     * @return array{ok:bool, reason:string, from_cache:bool, attempted:bool, source:string, url:string, checked_at:int, manifest:array<string,mixed>}
     */
    public function check(string $channel, string $customSource = '', bool $force = false): array
    {
        $base = Source::base($channel, $customSource);
        $url = Source::manifestUrl($base);
        $cached = $this->cached($channel);
        $checkedAt = $this->cachedAt();
        $interval = $force ? self::MANUAL_INTERVAL : self::AUTO_INTERVAL;

        // 还没到下一次该问的点:直接用缓存,一个包都不发
        if ($cached !== [] && $checkedAt > 0 && time() - $checkedAt < $interval) {
            return $this->result(true, '', true, $base, $url, $checkedAt, $cached);
        }

        if (!Source::isHttps($url)) {
            return $this->fail('bad_source', $base, $url, $checkedAt, $cached);
        }

        $response = ($this->http)($url, (string) $this->options->get(self::OPT_ETAG, ''));

        if ($response['error'] !== '') {
            return $this->fail($response['error'], $base, $url, $checkedAt, $cached);
        }

        // 304:东西没变,只把"问过了"这一刻记下来
        if ($response['status'] === 304 && $cached !== []) {
            $this->stamp($checkedAt);

            return $this->result(true, '', true, $base, $url, $checkedAt, $cached);
        }

        if ($response['status'] !== 200) {
            return $this->fail('http_' . $response['status'], $base, $url, $checkedAt, $cached);
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return $this->fail('bad_json', $base, $url, $checkedAt, $cached);
        }

        /** @var array<string, mixed> $decoded */
        $signature = $decoded['signature'] ?? '';

        if (!is_string($signature) || $signature === '') {
            return $this->fail('unsigned', $base, $url, $checkedAt, $cached);
        }

        if (!Signature::verify(self::canonical($decoded), $signature, $this->publicKey)) {
            return $this->fail('bad_signature', $base, $url, $checkedAt, $cached);
        }

        if (!self::usable($decoded, $channel, $url)) {
            return $this->fail('unusable', $base, $url, $checkedAt, $cached);
        }

        $this->store($decoded, $response['etag']);
        $stamp = time();

        return $this->result(true, '', false, $base, $url, $stamp, $decoded, true);
    }

    /**
     * 缓存里的清单(没有、或**验不过**就是空数组)。
     *
     * 每次读都重新验一遍:options 是能被写的地方,存进去的东西不等于可信 ——
     * 缓存不是"免检通道"。
     *
     * @return array<string, mixed>
     */
    public function cached(string $channel = ''): array
    {
        $channel = $channel === '' ? $this->currentChannel() : $channel;
        $url = Source::manifestUrl(Source::base($channel, $this->customSource()));

        return $this->trusted($this->raw(), $channel, $url);
    }

    public function cachedAt(): int
    {
        return (int) $this->options->get(self::OPT_CHECKED, 0);
    }

    /**
     * 缓存里相对当前版本的更新状态(只读:**不联网、不写任何东西**)。
     *
     * 后台的只读屏(仪表盘既有规范/ 站点健康既有规范)要显示"有没有新版",但"去检查"这件事
     * 只该发生在「更新」页(UpdateController)—— 那两屏是取数模型,不经手网络、更不该改缓存。
     * 所以这里只认「更新」页检查后落下的那份**验过签**的缓存,当场比对版本,给出三态:
     * available(有新版)/ latest(清单在手、没有更新)/ unchecked(还没检查过)。
     *
     * @return array{state:string, version:string}
     */
    public function cachedStatus(string $current): array
    {
        $channel = $this->currentChannel();
        $url = Source::manifestUrl(Source::base($channel, $this->customSource()));
        $manifest = $this->trusted($this->raw(), $channel, $url);

        if ($manifest === []) {
            return ['state' => 'unchecked', 'version' => ''];
        }

        $newer = self::newer($manifest, $current, $channel, $url);

        return $newer === null
            ? ['state' => 'latest', 'version' => '']
            : ['state' => 'available', 'version' => $newer['version']];
    }

    /** 换通道时把缓存作废 —— 否则切过去还拿着上一家的清单。 */
    public function forget(): void
    {
        $this->options->set(self::OPT_MANIFEST, '', false);
        $this->options->set(self::OPT_CHECKED, '0', false);
        $this->options->set(self::OPT_ETAG, '', false);
    }

    /**
     * 未经验证的原始缓存(只给 trusted 用)
     *
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        $raw = $this->options->get(self::OPT_MANIFEST, '');

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 验过才认:签名对得上、通道对得上、包地址合规。任何一条不过就当没有这份清单。
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function trusted(array $manifest, string $channel, string $url): array
    {
        if ($manifest === []) {
            return [];
        }

        $signature = $manifest['signature'] ?? '';

        if (!is_string($signature) || $signature === '') {
            return [];
        }

        if (!Signature::verify(self::canonical($manifest), $signature, $this->publicKey)) {
            return [];
        }

        return self::usable($manifest, $channel, $url) ? $manifest : [];
    }

    private function currentChannel(): string
    {
        $channel = $this->options->get('update_channel', 'dev');

        return is_string($channel) && $channel !== '' ? $channel : 'dev';
    }

    /** 自定义源地址(空串 = 用内置的官方源) */
    private function customSource(): string
    {
        return trim((string) $this->options->get('update_source', ''));
    }

    /**
     * 签名负载:按固定字段顺序取一份规整 JSON。两侧同一份代码,才不会各签各的。
     *
     * @param array<string, mixed> $manifest
     */
    public static function canonical(array $manifest): string
    {
        $picked = [];

        foreach (self::SIGNED_FIELDS as $field) {
            $picked[] = [$field, $manifest[$field] ?? null];
        }

        return (string) json_encode($picked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 清单本身立不立得住:通道要对得上、版本号要合法、包的下载地址要落在容许的主机上。
     *
     * `$manifestUrl` 必须传:包的地址只能落在**清单自己的主机**或官方主机上,
     * 否则一个被动的清单就能把包指到别人的域名去(这一步是防掉包的关键)。
     *
     * @param array<string, mixed> $manifest
     */
    public static function usable(array $manifest, string $channel, string $manifestUrl): bool
    {
        $version = $manifest['version'] ?? '';
        $manifestChannel = $manifest['channel'] ?? '';
        $download = $manifest['download'] ?? '';
        $sha = $manifest['sha256'] ?? '';

        if (!is_string($version) || preg_match('#\A\d+\.\d+\.\d+\z#', $version) !== 1) {
            return false;
        }

        if (!is_string($manifestChannel) || $manifestChannel !== $channel) {
            return false;
        }

        if (!is_string($download) || !Source::allows($download, $manifestUrl)) {
            return false;
        }

        return is_string($sha) && preg_match('#\A[0-9a-f]{64}\z#', $sha) === 1;
    }

    /**
     * 清单里的版本比当前新才叫"可更新"。
     *
     * @param array<string, mixed> $manifest
     * @return array{version:string, channel:string, label:string, date:string, notes:string}|null
     */
    public static function newer(array $manifest, string $current, string $channel, string $manifestUrl): ?array
    {
        $version = $manifest['version'] ?? '';

        if (!is_string($version) || $version === '' || version_compare($version, $current, '<=')) {
            return null;
        }

        if (!self::usable($manifest, $channel, $manifestUrl)) {
            return null;
        }

        $date = $manifest['release_date'] ?? '';
        $notes = $manifest['notes'] ?? '';

        return [
            'version' => $version,
            'channel' => $channel,
            'label' => $version,
            'date' => is_string($date) ? $date : '',
            'notes' => is_string($notes) ? $notes : '',
        ];
    }

    /**
     * 清单里的"上一个版本"—— 源站按保留策略还留着的那一份。
     *
     * 这条信息只用来**如实告诉站长上一版是哪一版**。本内核不据此安装旧版:既有规范说明
     * 降级会让旧代码跑在新库结构上,不在升级链的能力范围内。所以这里只做**形状校验**,
     * 校验不过就当没有 —— 不让一份被改过的清单把"上一版"指到别处去。
     *
     * `previous` 与其它字段一样参与签名(既有规范末尾),因此能到这一步的
     * 只可能来自官方发布侧;但发布侧也可能把字段写错,所以不信任、只认完整的形状。
     * 校验不过**不牵连整份清单** —— 上一个版本是可有可无的附加信息,坏掉一个字段不该拦下一次正常升级。
     *
     * @param array<string, mixed> $manifest
     * @return array{version:string, release_date:string, file:string, sha256:string, download:string}|null
     */
    public static function previous(array $manifest, string $manifestUrl): ?array
    {
        $previous = $manifest['previous'] ?? null;

        if (!is_array($previous)) {
            return null;
        }

        $version = $previous['version'] ?? '';
        $download = $previous['download'] ?? '';
        $sha = $previous['sha256'] ?? '';
        $file = $previous['file'] ?? '';
        $date = $previous['release_date'] ?? '';

        if (!is_string($version) || preg_match('#\A\d+\.\d+\.\d+\z#', $version) !== 1) {
            return null;
        }

        if (!is_string($sha) || preg_match('#\A[0-9a-f]{64}\z#', $sha) !== 1) {
            return null;
        }

        // 与主包同一条规矩:上一版的包地址也只许落在清单自己的主机或官方主机上
        if (!is_string($download) || !Source::allows($download, $manifestUrl)) {
            return null;
        }

        // 文件名是给人看的构建产物名;不许带路径分隔符,免得被当成路径用
        if (!is_string($file) || $file === '' || str_contains($file, '/') || str_contains($file, '\\')) {
            return null;
        }

        return [
            'version' => $version,
            'release_date' => is_string($date) ? $date : '',
            'file' => $file,
            'sha256' => $sha,
            'download' => $download,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{ok:bool, reason:string, from_cache:bool, attempted:bool, source:string, url:string, checked_at:int, manifest:array<string,mixed>}
     */
    private function result(bool $ok, string $reason, bool $fromCache, string $base, string $url, int $checkedAt, array $manifest, bool $attempted = false): array
    {
        return [
            'ok' => $ok,
            'reason' => $reason,
            'from_cache' => $fromCache,
            // 这一趟到底联没联网 —— 界面据此决定"要不要再提醒一次",免得同一件事每次开页都弹
            'attempted' => $attempted,
            'source' => $base,
            'url' => $url,
            'checked_at' => $checkedAt,
            'manifest' => $manifest,
        ];
    }

    /**
     * 失败时**不覆盖缓存** —— 上一次拿到的好清单还留着可用;顺便把"问过了"记上,免得失败也狂重试。
     *
     * @param array<string, mixed> $cached
     * @return array{ok:bool, reason:string, from_cache:bool, attempted:bool, source:string, url:string, checked_at:int, manifest:array<string,mixed>}
     */
    private function fail(string $reason, string $base, string $url, int $checkedAt, array $cached): array
    {
        $this->stamp($checkedAt);

        return $this->result(false, $reason, $cached !== [], $base, $url, $checkedAt, $cached, true);
    }

    private function stamp(int $checkedAt): void
    {
        $this->options->set(self::OPT_CHECKED, (string) ($checkedAt > 0 ? $checkedAt : time()), false);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function store(array $manifest, string $etag): void
    {
        $this->options->set(
            self::OPT_MANIFEST,
            (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            false
        );
        $this->options->set(self::OPT_CHECKED, (string) time(), false);
        $this->options->set(self::OPT_ETAG, $etag, false);
    }
}
