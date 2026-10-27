<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Update;

use Closure;
use Molin\Support\Files;
use ZipArchive;

/**
 * 升级包:抓下来、验清楚、解到暂存(既有规范的"包结构校验"与前述 sha256)。
 *
 * **这一层一个字节都不碰现有文件** —— 走到这儿为止,站点还是原来那个站点。
 * 校验不过就整份丢掉并如实说原因,后面的事(替换、迁移、回滚)一律不启动。
 *
 * 白名单而不是黑名单:顶层名只认安装包里那几个,多出一个不认识的顶层项就当包有问题。
 * 黑名单永远漏,白名单漏不了。
 */
final class Package
{
    /** 包体积上限:再大就不是安装包了 */
    public const MAX_BYTES = 209715200;

    /** 解压后总体积上限(防 zip 炸弹) */
    public const MAX_UNPACKED = 524288000;

    /** 条目数上限 */
    public const MAX_ENTRIES = 20000;

    /**
     * 允许出现的顶层项 —— 与「正式版安装包」同构。
     * vendor 是随包的(2026-10-10 作者定"带"),目标机不必跑 composer。
     * storage 不在这张表里:它另开一条更窄的口子,只收 .gitkeep 骨架,见 acceptable()。
     */
    public const TOP_LEVEL = [
        'admin', 'assets', 'core', 'install', 'plugins', 'themes', 'vendor',
        'index.php', 'molin', 'favicon.ico', 'composer.json', 'composer.lock', 'config.example.php',
    ];

    /** 解压后必须存在的几样(少一样这个包就是个残包) */
    public const REQUIRED = ['index.php', 'core/Kernel.php', 'config.example.php', 'vendor/autoload.php'];

    /** @var Closure(string): array{status:int, body:string, etag:string, error:string} */
    private readonly Closure $http;

    /**
     * @param string $root 站点根(MOLIN_ROOT);暂存落在它的 storage/trash/update 下
     * @param (callable(string): array{status:int,body:string,etag:string,error:string})|null $http 测试用替身
     */
    public function __construct(
        private readonly string $root,
        ?callable $http = null
    ) {
        $this->http = Closure::fromCallable(
            $http ?? static fn (string $url): array => Http::get($url, null, 60)
        );
    }

    /**
     * 抓包 → 验 sha256 → 解压到暂存 → 校验结构与版本号。
     *
     * @param array<string, mixed> $manifest 已验过签名的清单
     * @return array{ok:bool, reason:string, detail:string, dir:string, bytes:int, files:int}
     */
    public function stage(array $manifest, string $manifestUrl): array
    {
        $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '';
        $download = is_string($manifest['download'] ?? null) ? $manifest['download'] : '';
        $sha = is_string($manifest['sha256'] ?? null) ? $manifest['sha256'] : '';

        // 落盘之前再卡一道:包的地址只能落在清单自己的主机或官方主机上(同 Source::allows)
        if ($download === '' || !Source::allows($download, $manifestUrl)) {
            return $this->fail('bad_url', '包的下载地址不合规:' . $download);
        }

        if ($version === '' || preg_match('#\A\d+\.\d+\.\d+\z#', $version) !== 1) {
            return $this->fail('bad_version', '清单里的版本号不像话:' . $version);
        }

        if (!class_exists(ZipArchive::class)) {
            return $this->fail('no_zip', '本机 PHP 没装 zip 扩展,解不开安装包');
        }

        $response = ($this->http)($download);

        if ($response['error'] !== '') {
            return $this->fail($response['error'], '抓包失败:' . $response['error']);
        }

        if ($response['status'] !== 200) {
            return $this->fail('http_' . $response['status'], '源站回了 HTTP ' . $response['status']);
        }

        $body = $response['body'];
        $bytes = strlen($body);

        if ($bytes === 0) {
            return $this->fail('empty', '抓回来的包是空的');
        }

        if ($bytes > self::MAX_BYTES) {
            return $this->fail('too_big', '包太大:' . $this->mb($bytes));
        }

        // 清单没有 sha256 = 没得比对,整条校验链就只剩"地址能下"。空的放过去等于谁都能换包,拒收。
        if ($sha === '') {
            return $this->fail('sha256_missing', '清单缺 sha256,拒收');
        }

        if (!hash_equals($sha, hash('sha256', $body))) {
            return $this->fail('sha256', '包的 sha256 与清单不符 —— 传输坏人或被人换过包,拒收');
        }

        $dir = $this->workDir($version);

        // 重来一遍先清干净:上一趟的残渣(旧包、半个树)不许混进来
        if (!Files::deleteTree($dir) || (!is_dir($dir) && !mkdir($dir, 0o755, true))) {
            return $this->fail('no_dir', '建不出暂存目录:' . $dir);
        }

        $zipPath = $dir . '/pkg.zip';

        if (file_put_contents($zipPath, $body) === false) {
            return $this->fail('no_write', '包写不进暂存目录:' . $zipPath);
        }

        $unpacked = $this->unpack($zipPath, $dir . '/tree');

        if ($unpacked['ok'] === false) {
            return $this->fail($unpacked['reason'], $unpacked['detail']);
        }

        $structure = $this->check($dir . '/tree', $version);

        if ($structure['ok'] === false) {
            return $this->fail($structure['reason'], $structure['detail']);
        }

        return [
            'ok' => true,
            'reason' => '',
            'detail' => '',
            'dir' => $dir . '/tree',
            'bytes' => $bytes,
            'files' => $unpacked['files'],
        ];
    }

    /** 暂存目录(重来一遍先清干净,不留上一趟的残渣) */
    public function workDir(string $version): string
    {
        return $this->root . '/storage/trash/update/' . $version;
    }

    /**
     * 解压。三层防:条目名不许穿越、顶层名必须在白名单里、总量与条目数有上限。
     *
     * @return array{ok:bool, reason:string, detail:string, files:int}
     */
    private function unpack(string $zipPath, string $target): array
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'reason' => 'zip', 'detail' => '不是个能打开的 zip', 'files' => 0];
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            return ['ok' => false, 'reason' => 'entries', 'detail' => '条目太多:' . $zip->numFiles, 'files' => 0];
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];
            $total += (int) $stat['size'];

            if (!$this->acceptable($name)) {
                $zip->close();

                return ['ok' => false, 'reason' => 'entries', 'detail' => '包里有个不该有的条目:' . $name, 'files' => 0];
            }
        }

        if ($total > self::MAX_UNPACKED) {
            $zip->close();

            return ['ok' => false, 'reason' => 'bomb', 'detail' => '解压后太大:' . $this->mb($total), 'files' => 0];
        }

        // close() 会把 numFiles 归零 —— 先记下来再说
        $files = $zip->numFiles;

        if (is_dir($target)) {
            Files::deleteTree($target);
        }

        if (is_dir($target) || !mkdir($target, 0o755, true) || !$zip->extractTo($target)) {
            $zip->close();

            return ['ok' => false, 'reason' => 'zip', 'detail' => '解压写到一半失败', 'files' => 0];
        }

        $zip->close();

        return ['ok' => true, 'reason' => '', 'detail' => '', 'files' => $files];
    }

    /** 条目名合规:不穿越、不以 / 开头、不含反斜杠、顶层名在白名单里 */
    private function acceptable(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')) {
            return false;
        }

        $parts = explode('/', $name);

        foreach ($parts as $part) {
            if ($part === '..') {
                return false;
            }
        }

        // storage 是站点自己的数据,升级器根本不碰它(Installer::UNTOUCHABLE);
        // 放行 .gitkeep 只为让"全新安装"的目录骨架能随包一起到,骨架之外的一律不收。
        // 2026-10-11:此前 storage 整个没进白名单,正式版安装包里的 storage/.gitkeep 被自家验包挡下,
        // 站点一升级就报"包里有个不该有的条目",升级链从此走不通。
        if ($parts[0] === 'storage') {
            return str_ends_with($name, '.gitkeep');
        }

        return in_array($parts[0], self::TOP_LEVEL, true);
    }

    /**
     * 结构校验:该在的都在,而且**包里的版本号必须与清单一致** ——
     * 不然就是"清单说升到 A、包里其实是 B",这种事绝不能放过去。
     *
     * @return array{ok:bool, reason:string, detail:string}
     */
    private function check(string $tree, string $version): array
    {
        foreach (self::REQUIRED as $rel) {
            if (!is_file($tree . '/' . $rel)) {
                return ['ok' => false, 'reason' => 'structure', 'detail' => '包里缺:' . $rel];
            }
        }

        $kernel = (string) file_get_contents($tree . '/core/Kernel.php');

        if (preg_match("#const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)'#", $kernel, $m) !== 1) {
            return ['ok' => false, 'reason' => 'structure', 'detail' => '包里的 core/Kernel.php 读不出版本号'];
        }

        if ($m[1] !== $version) {
            return ['ok' => false, 'reason' => 'version', 'detail' => '清单说 ' . $version . ',包里是 ' . $m[1]];
        }

        return ['ok' => true, 'reason' => '', 'detail' => ''];
    }

    private function mb(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1) . ' MB'
            : (int) round($bytes / 1024) . ' KB';
    }

    /**
     * @return array{ok:bool, reason:string, detail:string, dir:string, bytes:int, files:int}
     */
    private function fail(string $reason, string $detail): array
    {
        return ['ok' => false, 'reason' => $reason, 'detail' => $detail, 'dir' => '', 'bytes' => 0, 'files' => 0];
    }
}
