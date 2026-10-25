<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Install;

/**
 * 环境检测(《42-安装向导》第 2 节)。
 *
 * 判据只用标准件(PHP 版本、扩展、目录可写、ini 阈值、伪静态探测),
 * 不认任何面板路径 —— 部署环境不写死宝塔(2026-09-30 作者定)。
 */
final class EnvironmentChecker
{
    private const MIN_PHP = '8.2.0';

    private const REQUIRED_EXT = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo'];

    /** gd 与 imagick 二选一,其余为推荐 */
    private const RECOMMENDED_EXT = ['intl', 'zip', 'curl', 'opcache'];

    /**
     * @param array<string, mixed> $server 入口脚本传进来的 $_SERVER —— 内核不碰裸超全局
     */
    public function __construct(private readonly string $root, private readonly array $server = [])
    {
    }

    /**
     * @return list<Requirement>
     */
    public function check(): array
    {
        return [
            $this->phpVersion(),
            $this->extensions(self::REQUIRED_EXT, true),
            $this->imageExtension(),
            $this->extensions(self::RECOMMENDED_EXT, false),
            $this->writable($this->root, '根目录要能被程序写入'),
            $this->writable($this->root . '/storage', '缓存、日志、上传都要写这里'),
            $this->ini('memory_limit', '128M', '内存上限偏低,大列表页可能被截断'),
            $this->ini('upload_max_filesize', '2M', '上传媒体文件会受这个上限限制'),
            $this->rewrite(),
        ];
    }

    /**
     * @param list<Requirement> $items
     */
    public static function hasBlocker(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->isBlocker()) {
                return true;
            }
        }

        return false;
    }

    private function phpVersion(): Requirement
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            return Requirement::fail(
                'php',
                'PHP 版本',
                '当前 ' . PHP_VERSION . ',需要 ≥ ' . self::MIN_PHP,
                '换到 PHP 8.2 或更高再装'
            );
        }

        return Requirement::ok('php', 'PHP 版本', PHP_VERSION);
    }

    /**
     * @param list<string> $list
     */
    private function extensions(array $list, bool $required): Requirement
    {
        $missing = array_values(array_filter($list, static fn (string $name): bool => !self::loaded($name)));

        if ($missing === []) {
            return Requirement::ok($required ? 'ext_required' : 'ext_recommended', $required ? '必需扩展' : '推荐扩展', implode(', ', $list));
        }

        $detail = '缺少:' . implode(', ', $missing);

        return $required
            ? Requirement::fail('ext_required', '必需扩展', $detail, '在 php.ini 打开这些扩展(或让宿主环境装上)')
            : Requirement::warn('ext_recommended', '推荐扩展', $detail, '不装也能跑,但对应功能会缺');
    }

    /**
     * OPcache 是 Zend 扩展:`extension_loaded('opcache')` 恒为假,要认 'Zend OPcache';
     * 其余扩展名与 extension_loaded() 入参同名(与后台健康页同口径)。
     */
    private static function loaded(string $name): bool
    {
        return $name === 'opcache' ? extension_loaded('Zend OPcache') : extension_loaded($name);
    }

    private function imageExtension(): Requirement
    {
        if (extension_loaded('gd') || extension_loaded('imagick')) {
            return Requirement::ok('ext_image', '图片处理', extension_loaded('gd') ? 'gd' : 'imagick');
        }

        return Requirement::warn('ext_image', '图片处理', 'gd 与 imagick 都没装', '媒体库的多尺寸图片生不出来');
    }

    private function writable(string $path, string $why): Requirement
    {
        if (!is_dir($path)) {
            return Requirement::fail('writable:' . basename($path), '目录存在', $path . ' 不存在', '检查部署是否漏拷该目录');
        }

        if (!is_writable($path)) {
            return Requirement::fail('writable:' . basename($path), '目录可写', $path . ' 不可写 —— ' . $why, '把该目录属主改成站点运行用户,或 chmod 755/775');
        }

        return Requirement::ok('writable:' . basename($path), '目录可写', $path);
    }

    /**
     * shorthand 阈值比较(K/M/G)。-1 表示不限,视为通过。
     */
    private function ini(string $key, string $minimum, string $why): Requirement
    {
        $actual = trim((string) ini_get($key));
        $value = self::toBytes($actual);
        $floor = self::toBytes($minimum);

        if ($actual === '') {
            return Requirement::warn($key, $key, '读不到当前值', '原因可能是该指令被宿主禁用,请人工确认 —— ' . $why);
        }

        if ($value === -1 || $value >= $floor) {
            return Requirement::ok($key, $key, $actual);
        }

        return Requirement::warn($key, $key, '当前 ' . $actual . ',建议 ≥ ' . $minimum, $why);
    }

    /**
     * 伪静态只能"观察",不能断言:PHP 看不见 nginx/apache 的配置。
     * 所以这里给的是判据 + 两种配置示例,而不是"检测通过"的假保证。
     */
    private function rewrite(): Requirement
    {
        $software = strtolower((string) ($this->server['SERVER_SOFTWARE'] ?? ''));

        if ($software === '') {
            return Requirement::warn('rewrite', '伪静态', '当前不是网页请求,读不到服务器信息', '装完请确认固定链接能打开;Nginx 用 try_files $uri /index.php?$args,Apache 用标准 .htaccess');
        }

        if (str_contains($software, 'apache') && function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules(), true)) {
            return Requirement::ok('rewrite', '伪静态', 'mod_rewrite 已加载');
        }

        if (str_contains($software, 'nginx')) {
            return Requirement::ok('rewrite', '伪静态', 'Nginx(需自行确认 try_files 指向 index.php)');
        }

        return Requirement::warn('rewrite', '伪静态', '服务器:' . $software . '(无法确认改写规则)', '装完点开一篇固定链接验证;不通过就在服务器配置里把请求改写指向 /index.php');
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
