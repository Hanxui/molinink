<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cache;

use Molin\Cache\Psr16\FileStore;
use Molin\Cache\Psr16\NullStore;
use Molin\Support\Files;
use Psr\SimpleCache\CacheInterface;

/**
 * 缓存统一入口(《24-缓存抽象层规范》第 3、4、5、9 节)。
 *
 * 业务代码只说"要缓存这个",换后端只改 config.php 的 `cache.backend` 一行;
 * `backend = null` 时走 NullStore,调用方不用改(§3.4)。
 *
 * 后端的诚实口径:配置里写了 yac / redis,但扩展未装或适配器尚未落地时**如实降级为文件后端**,
 * 并把"配置值 / 实际生效值"分别吐出来(§3.2 / §3.3、健康屏与设置页据此显示真状态),
 * 绝不假装连上了。降级只影响快慢,不影响正确性。
 *
 * 键命名走《24》§5 的 `{prefix}:{域}:{对象}:{标识}` 习惯,但**落到 PSR-16 存储前会把冒号换成点** ——
 * PSR-16 §2 把 `{}()/\@:` 列为保留字符,FileStore 也照此校验,直传冒号键会当场抛。
 * 这是文档习惯与 PSR-16 约束的取舍,不改存储适配器的合规性。
 */
final class CacheManager
{
    /** 允许写在配置里的后端名 */
    public const BACKENDS = ['file', 'yac', 'redis', 'null'];

    /** 键前缀缺省值;配置 `cache.prefix` 可覆盖 */
    public const DEFAULT_PREFIX = 'molin';

    /** 默认存活秒数(《24》§4 的 `cache.ttl`) */
    public const DEFAULT_TTL = 3600;

    /** 标签索引目录名(落在数据目录下,与 FileStore 的两位十六进制分片目录互不干扰) */
    private const TAG_DIR = 'tags';

    /** 标签索引文件后缀;不用 .php,免得被 OPcache / 后台"缓存文件数"统计当成数据分片 */
    private const TAG_SUFFIX = '.idx';

    /** 单个标签索引的体积上限(约几千条键);超了直接丢索引文件,让下一次失效退回全清(安全优先) */
    private const TAG_MAX_BYTES = 524288;

    private static ?self $instance = null;

    private function __construct(
        private readonly CacheInterface $store,
        /** 是否经装配线显式接上(未接通时按"仅编译缓存"如实呈现) */
        private readonly bool $managed,
        /** 配置里写的后端 */
        private readonly string $configured,
        /** 实际生效的后端 */
        private readonly string $effective,
        private readonly string $prefix,
        private readonly int $ttl,
        /** 文件后端的数据目录;非文件后端为空串 */
        private readonly string $directory
    ) {
    }

    /**
     * 当前装配好的实例。没人 boot 过(测试手工装配、孤立调用)时给一份 no-op,
     * 不缓存也就不会把某个进程的状态偷偷带进别处。装配线在 Bootstrap 里。
     */
    public static function instance(): self
    {
        return self::$instance ??= self::none();
    }

    public static function boot(self $manager): void
    {
        self::$instance = $manager;
    }

    /** 装配线换根 / 测试收尾用:丢掉当前实例,回到 no-op */
    public static function forget(): void
    {
        self::$instance = null;
    }

    /** 未接装配线时的 no-op 实例(backend=null) */
    public static function none(): self
    {
        return new self(new NullStore(), false, 'null', 'null', self::DEFAULT_PREFIX, self::DEFAULT_TTL, '');
    }

    /**
     * 按 config.php 的 `cache` 段造一份实例(§4)。
     *
     * @param array<string, mixed> $cache config.php 里的 cache 段;缺配置时全部走默认
     * @param string               $root  站点根;测试经 MOLIN_STATE_ROOT 换根,写盘不碰仓库
     */
    public static function fromConfig(array $cache, string $root): self
    {
        $configured = strtolower(trim((string) ($cache['backend'] ?? 'file')));

        if ($configured === '') {
            $configured = 'file';
        }

        $prefix = trim((string) ($cache['prefix'] ?? self::DEFAULT_PREFIX));
        $ttl = (int) ($cache['ttl'] ?? self::DEFAULT_TTL);

        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TTL;
        }

        $state = getenv('MOLIN_STATE_ROOT');
        $base = is_string($state) && $state !== '' ? $state : $root;
        $directory = rtrim($base, '/\\') . '/storage/cache/data';

        // yac / redis 与未知值一律降级为文件后端:扩展未装或适配器未落地,如实说、不假装
        if ($configured === 'null') {
            return new self(new NullStore(), true, $configured, 'null', $prefix, $ttl, '');
        }

        if ($configured !== 'file') {
            return new self(new FileStore($directory), true, $configured, 'file', $prefix, $ttl, $directory);
        }

        return new self(new FileStore($directory), true, 'file', 'file', $prefix, $ttl, $directory);
    }

    /**
     * 最常用入口(§9):命中直接回值,未命中才跑回调并落盘。
     *
     * `$tags` 是标签失效的登记口:落盘成功后把这条键写进对应标签的索引,
     * 之后 invalidateTags() 才能按标签精确删。不传也能用 —— 键的命名空间(`post:list:…`)会自动
     * 当作标签登记,业务侧不改写法就已覆盖到;要更细的粒度再显式传。
     *
     * @param int|null     $ttl  null = 用配置的默认 TTL
     * @param list<string> $tags
     */
    public function remember(string $key, ?int $ttl, callable $callback, array $tags = []): mixed
    {
        if (!$this->enabled()) {
            return $callback();
        }

        $logical = $key;
        $key = $this->qualify($key);
        $miss = new \stdClass();
        $value = $this->store->get($key, $miss);

        if ($value !== $miss) {
            return $value;
        }

        $value = $callback();

        // 落盘失败就没有可失效的条目,不必登记(否则索引里会留下指向不存在的键)
        if ($this->store->set($key, $value, $ttl ?? $this->ttl)) {
            $this->index($logical, $key, $tags);
        }

        return $value;
    }

    /**
     * 标签失效(§6.1/§6.2):按标签→键索引精确删。
     *
     * 只要有一个标签的索引文件在,就只删清单里的键 —— 其它域(插件、主题、别的模块)的键原样留着。
     * 一个索引文件都没有,说明这批标签从没被登记过(旧版本写的键、或拼错的标签),影响范围不可知,
     * 这时才退回 flushAll()。宁可多清,不能留下脏值。
     *
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): void
    {
        if (!$this->enabled()) {
            return;
        }

        $keys = [];
        $indexed = 0;

        foreach ($tags as $tag) {
            $path = $this->tagPath((string) $tag);

            if (!is_file($path)) {
                continue;
            }

            $indexed++;

            foreach ($this->readIndex($path) as $key) {
                $keys[$key] = true;
            }

            Files::delete($path);
        }

        if ($indexed === 0) {
            $this->flushAll();

            return;
        }

        foreach (array_keys($keys) as $key) {
            $this->store->delete((string) $key);
        }
    }

    /**
     * 清空数据缓存(文件后端只删自己的分片目录,旁边 cron.json 等不碰)。
     * 标签索引一并清掉:键都没了,留着清单只会让下一次失效走"精确删"而漏掉之后新写的键。
     */
    public function flushAll(): bool
    {
        $ok = $this->store->clear();
        $this->clearIndex();

        return $ok;
    }

    public function enabled(): bool
    {
        return $this->effective !== 'null';
    }

    public function managed(): bool
    {
        return $this->managed;
    }

    /** 配置里写的后端 */
    public function configured(): string
    {
        return $this->configured;
    }

    /** 实际生效的后端:file | yac | redis | null */
    public function backend(): string
    {
        return $this->effective;
    }

    /** 配置值与实际生效值不一致 = 发生降级 */
    public function degraded(): bool
    {
        return $this->configured !== $this->effective;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * 后台显示用的读数(设置页 / 健康屏 / 仪表盘)。
     *
     * @return array{managed:bool, enabled:bool, configured:string, backend:string, degraded:bool,
     *               prefix:string, ttl:int, directory:string, files:int, bytes:int}
     */
    public function stats(): array
    {
        $files = 0;
        $bytes = 0;

        if ($this->effective === 'file' && $this->directory !== '') {
            [$files, $bytes] = self::dirStats($this->directory);
        }

        return [
            'managed' => $this->managed,
            'enabled' => $this->enabled(),
            'configured' => $this->configured,
            'backend' => $this->effective,
            'degraded' => $this->degraded(),
            'prefix' => $this->prefix,
            'ttl' => $this->ttl,
            'directory' => $this->directory,
            'files' => $files,
            'bytes' => $bytes,
        ];
    }

    /**
     * 把物理键登记进标签索引。
     *
     * 只记"这个标签下面有哪些键",不记值 —— 值仍在 PSR-16 存储里,失效时按清单逐键删。
     *
     * 用追加写而不是读-改-写:多个请求同时渲染、各自登记同一个标签时,读改写会互相覆盖,
     * 丢掉的键在失效时清不到 —— 那就成脏值了。追加 + LOCK_EX 保证整行原子落盘,重复行无害。
     *
     * @param list<string> $tags
     */
    private function index(string $logicalKey, string $physicalKey, array $tags): void
    {
        if ($this->directory === '') {
            return;   // null 后端不落盘,没有索引可言
        }

        $labels = [];

        foreach ($tags as $tag) {
            if (is_scalar($tag) && trim((string) $tag) !== '') {
                $labels[] = trim((string) $tag);
            }
        }

        foreach (self::namespaceTags($logicalKey) as $tag) {
            $labels[] = $tag;
        }

        if ($labels === []) {
            return;
        }

        $dir = $this->directory . '/' . self::TAG_DIR;

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return;
        }

        foreach (array_unique($labels) as $tag) {
            $path = $this->tagPath($tag);
            $size = is_file($path) ? (int) @filesize($path) : 0;

            if ($size > self::TAG_MAX_BYTES) {
                // 索引胀得太大(大多是长期没被失效过):丢掉清单,下一次失效没有依据,退回全清
                Files::delete($path);

                continue;
            }

            @file_put_contents($path, $physicalKey . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * 键的命名空间当标签:标准键形如 `post:list:{hash}`(《24》§5),
     * 于是它同时属于 `post` 与 `post:list` —— 内容一变,失效方传 `post` 就能一并清掉,
     * 不用让每个业务调用点各自学会打标签。
     *
     * @return list<string>
     */
    private static function namespaceTags(string $key): array
    {
        $parts = array_values(array_filter(
            explode(':', strtolower(trim($key))),
            static fn (string $part): bool => $part !== ''
        ));

        if ($parts === []) {
            return [];
        }

        if (count($parts) === 1) {
            return [$parts[0]];
        }

        return [$parts[0], $parts[0] . ':' . $parts[1]];
    }

    /**
     * 标签 → 索引文件路径。
     *
     * 放在数据目录的 tags/ 子目录:FileStore::clear() 只认两位十六进制的分片目录名,
     * 不会误删它;标签里的冒号在 Windows 文件名里非法,换成横线再挂 8 位哈希,看得懂又不会撞名。
     */
    private function tagPath(string $tag): string
    {
        $name = preg_replace('/[^a-z0-9_.-]+/i', '-', strtolower(trim($tag))) ?? '';
        $name = trim($name, '-.');

        if ($name === '') {
            $name = 'tag';
        }

        return $this->directory . '/' . self::TAG_DIR . '/'
            . $name . '-' . substr(hash('sha256', $tag), 0, 8) . self::TAG_SUFFIX;
    }

    /**
     * @return list<string>
     */
    private function readIndex(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), explode("\n", $raw)),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /** 整片清掉标签索引(flushAll 之后索引里的键都成了"已不存在的键",留着只会误导下一次失效) */
    private function clearIndex(): void
    {
        if ($this->directory === '') {
            return;
        }

        $dir = $this->directory . '/' . self::TAG_DIR;

        if (is_dir($dir)) {
            Files::deleteTree($dir);
        }
    }

    /**
     * 逻辑键 → PSR-16 合法物理键:小写、冒号等保留字符换点、接上前缀。
     * 用户输入绝不直拼进键 —— 调用方先把输入哈希成定长串(见 PostQuery)。
     */
    private function qualify(string $key): string
    {
        $full = ($this->prefix === '' ? '' : $this->prefix . '.') . ltrim(strtolower(trim($key)), '.');
        // PSR-16 §2 保留字符 { } ( ) / \ @ : 一律换点;前缀里万一混进保留字符也一并收干净
        $full = preg_replace('#[{}\(\)/\\\\@:]+#', '.', $full) ?? $full;

        return trim($full, '.') === '' ? 'entry' : $full;
    }

    /**
     * 只数文件后端自己写下的 `.php` 分片(不含 cron.json、api-rate.json 等兄弟文件)。
     *
     * @return array{0:int, 1:int} 文件数、字节数
     */
    private static function dirStats(string $directory): array
    {
        if (!is_dir($directory)) {
            return [0, 0];
        }

        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files++;
            $bytes += (int) $file->getSize();
        }

        return [$files, $bytes];
    }
}
