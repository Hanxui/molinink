<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Update;

use Molin\Cache\PageCache;
use Molin\Cache\Purger;
use Molin\Option\Options;

/**
 * 维护模式(既有规范步、既有规范与第 9 步)。
 *
 * 替换文件的那几秒,站点是"半新半旧"的:与其让访客撞上去,不如给他一个明确的 503。
 * **后台与登录页永远不挡** —— 正在升级的那个人得进得来,出事了也得进得来收拾。
 * 能升级的人(holds update.core)一律放行:他正在盯着这件事。
 *
 * 开关状态在 Options(数据库),但 index.php 的整页快速通道跑在装配之前、查不了库,
 * 所以 on()/off() 另落一个 `storage/cache/maintenance.on` 标志文件给它看。两边都写:
 * 常规路径以数据库为准,快速通道以文件为准,任何一边先读到都只会更保守。
 */
final class Maintenance
{
    public const OPTION = 'maintenance';

    /** 维护期间照常开放的路口:后台、登录、登出 */
    public const OPEN = ['/admin', '/login', '/logout'];

    /** 访客要等多久再来(秒),写进 Retry-After */
    public const RETRY_AFTER = 120;

    /** 快速通道读的标志文件;它不是真相来源,只是数据库开关的一份文件镜像 */
    private const FLAG = 'maintenance.on';

    public function __construct(
        private readonly Options $options,
        private readonly string $root = ''
    ) {
    }

    public function on(): void
    {
        // 先落文件再写库:反过来的话,升级窗口开始的头几毫秒快速通道还会吐旧整页
        $this->mark();
        $this->options->set(self::OPTION, '1');
    }

    public function off(): void
    {
        $this->options->set(self::OPTION, '0');
        $this->clearMark();
    }

    /**
     * 快速通道用的只读判定:装配之前没有容器,只能问文件。
     * 路径与 Bootstrap / PageCache 同一套换根口径,MOLIN_STATE_ROOT 优先。
     */
    public static function isOn(string $root): bool
    {
        return is_file(self::flagPath($root));
    }

    public static function flagPath(string $root): string
    {
        $state = getenv('MOLIN_STATE_ROOT');
        $base = is_string($state) && $state !== '' ? $state : $root;

        return rtrim($base, '/\\') . '/storage/cache/' . self::FLAG;
    }

    public function active(): bool
    {
        return (string) $this->options->get(self::OPTION, '0') === '1';
    }

    /**
     * 这一趟要不要挡。
     *
     * @param string $path 请求路径(不带查询串)
     * @param bool $privileged 来的人能不能升级(能就别挡他)
     */
    public function blocks(string $path, bool $privileged): bool
    {
        if (!$this->active() || $privileged) {
            return false;
        }

        foreach (self::OPEN as $open) {
            if ($path === $open || str_starts_with($path, $open . '/')) {
                return false;
            }
        }

        return true;
    }

    private function mark(): void
    {
        $root = $this->siteRoot();

        if ($root === '') {
            return;
        }

        $path = self::flagPath($root);
        $dir = dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->purgePages($root);

            return;
        }

        if (@file_put_contents($path, (string) time(), LOCK_EX) === false) {
            // 标志写不下去,快速通道就看不见维护态;这种情况下只能清掉整页缓存兜底
            $this->purgePages($root);
        }
    }

    private function clearMark(): void
    {
        $root = $this->siteRoot();

        if ($root !== '') {
            @unlink(self::flagPath($root));
        }
    }

    /** CLI / 升级流程里没有 MOLIN_ROOT 常量时给空串,只走选项、不碰文件 */
    private function siteRoot(): string
    {
        if ($this->root !== '') {
            return rtrim($this->root, '/\\');
        }

        return defined('MOLIN_ROOT') ? rtrim((string) constant('MOLIN_ROOT'), '/\\') : '';
    }

    private function purgePages(string $root): void
    {
        $state = getenv('MOLIN_STATE_ROOT');
        $base = is_string($state) && $state !== '' ? $state : $root;
        $pages = $base . '/storage/cache/pages';

        (new Purger($pages, PageCache::SUFFIX))->purge();
        (new Purger($pages, PageCache::HEADERS_SUFFIX))->purge();
    }

    /**
     * 给访客看的那一页。刻意保持极简:这不是设计稿,是故障期间的兜底。
     * 站点名走配置,别让访客猜这是谁的站。
     */
    public function page(string $siteName): string
    {
        $name = htmlspecialchars($siteName !== '' ? $siteName : '本站', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html>'
            . '<html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>正在维护 · ' . $name . '</title></head>'
            . '<body style="margin:0;padding:12vh 8vw;font:16px/1.7 system-ui,-apple-system,\'Segoe UI\',sans-serif;color:#1f2328">'
            . '<h1 style="font-size:22px;margin:0 0 12px">' . $name . ' 正在维护</h1>'
            . '<p style="margin:0 0 8px;color:#57606a">升级正在进行，几分钟后再来。</p>'
            . '<p style="margin:0;color:#8c959f;font-size:14px">墨林 - Molinink · https://www.molinink.com</p>'
            . '</body></html>';
    }
}
