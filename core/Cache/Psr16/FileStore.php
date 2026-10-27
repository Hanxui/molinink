<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cache\Psr16;

use DateInterval;
use DateTimeImmutable;
use Molin\Support\Files;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 文件缓存(落盘用 `<?php return ...;`,借 OPcache)。
 *
 * 目录由调用方指定为「本适配器独占的命名空间根」;clear() 只删其中的分片目录,
 * 同一个父目录里别的缓存(storage/cache 下的 twig / pages / hookmap / data 状态文件)不进射程。
 */
final class FileStore implements CacheInterface
{
    /** unserialize(false) 的产物,用来区分「载荷坏了」与「真存了 false」 */
    private const SERIALIZED_FALSE = 'b:0;';

    /** 分片目录名:两位十六进制。clear() 只认这个形状,别的都不碰 */
    private const SHARD_PATTERN = '/^[0-9a-f]{2}$/';

    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        self::assertKey($key);

        return $this->value($key, $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        self::assertKey($key);

        $expire = $this->expiry($ttl);

        // PSR-16:ttl ≤ 0 视作已过期,不留下能命中的条目
        if ($expire !== null && $expire <= time()) {
            return $this->delete($key);
        }

        $path = $this->path($key);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return false;
        }

        $php = "<?php\n\n// 墨林 - Molinink · 缓存数据,勿手改\n\nreturn " . var_export([
            'expire' => $expire,
            'value' => serialize($value),
        ], true) . ";\n";

        // 临时文件同目录 + rename:读者要么看到旧版、要么看到完整新版,读不到半截
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $php) === false) {
            return false;
        }

        @chmod($temp, 0o644);

        if (!@rename($temp, $path)) {
            Files::delete($temp);

            return false;
        }

        // 同一个键覆写是同一条路径;OPcache 若关了时间校验,不失效就会一直端出旧值
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return true;
    }

    public function delete(string $key): bool
    {
        self::assertKey($key);

        return Files::delete($this->path($key));
    }

    public function clear(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $entries = scandir($this->directory);

        if ($entries === false) {
            return false;
        }

        $ok = true;

        foreach ($entries as $name) {
            if (preg_match(self::SHARD_PATTERN, $name) !== 1) {
                continue;
            }

            $path = $this->directory . '/' . $name;

            if (is_dir($path) && !Files::deleteTree($path)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $list = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            self::assertKey($key);
            $list[] = $key;
        }

        $values = [];

        foreach ($list as $key) {
            $values[$key] = $this->value($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $pairs = [];

        // 先把键全验完再落盘,免得写了一半才发现有个非法键
        foreach ($values as $key => $value) {
            $key = (string) $key;
            self::assertKey($key);
            $pairs[$key] = $value;
        }

        $ok = true;

        foreach ($pairs as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($keys as $key) {
            $key = (string) $key;
            self::assertKey($key);

            if (!$this->delete($key)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        self::assertKey($key);

        // 不能拿 get() !== null 判断:存了 null 也是"存在"
        return $this->load($key) !== null;
    }

    /**
     * 命中返回记录,未命中 / 过期 / 损坏一律 null。
     *
     * @return array{expire:int|null, value:string}|null
     */
    private function load(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        try {
            $record = @include $path;
        } catch (\Throwable) {
            // 半截 / 语法已坏的文件:当未命中,不往业务层抛
            return null;
        }

        if (!is_array($record) || !array_key_exists('expire', $record)
            || !array_key_exists('value', $record) || !is_string($record['value'])) {
            return null;
        }

        $expire = $record['expire'];

        if ($expire !== null && (!is_int($expire) || $expire <= time())) {
            Files::delete($path);   // 顺手清掉,免得过期文件一直堆着

            return null;
        }

        return ['expire' => $expire, 'value' => $record['value']];
    }

    private function value(string $key, mixed $default): mixed
    {
        $record = $this->load($key);

        if ($record === null) {
            return $default;
        }

        $decoded = @unserialize($record['value']);

        // 载荷被截断时 unserialize 回 false;真存了 false 的用序列化原串排除
        if ($decoded === false && $record['value'] !== self::SERIALIZED_FALSE) {
            return $default;
        }

        return $decoded;
    }

    private function path(string $key): string
    {
        // 按哈希前两位分片,单目录不会堆到几万个文件
        $hash = hash('sha256', $key);

        return $this->directory . '/' . substr($hash, 0, 2) . '/' . $hash . '.php';
    }

    private function expiry(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;   // 永不过期
        }

        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable())->add($ttl)->getTimestamp();
        }

        return time() + $ttl;
    }

    private static function assertKey(string $key): void
    {
        // PSR-16 §2:空串,以及保留字符 {}()/\@: 都是非法的
        if ($key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new InvalidCacheKeyException('非法缓存键(PSR-16 保留字符或空串)');
        }
    }
}
