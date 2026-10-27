<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Tools;

use Molin\Admin\WebAuth;
use Molin\Backup\Backup;
use Molin\Backup\Cipher;
use Molin\Backup\DownloadTokens;
use Molin\Backup\Progress;
use Molin\Backup\Remote;
use Molin\Backup\Restore;
use Molin\Cache\CacheManager;
use Molin\Cache\PageCache;
use Molin\Cache\Purger;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Kernel;
use Molin\Logger\Audit;
use Molin\Logger\Log;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\User\Auth;
use RuntimeException;
use Throwable;

/**
 * 工具页与缓存:备份/恢复/远端凭据的写动作、下载令牌,以及缓存状态与清理。
 */
final class CacheTools
{
    /** 工具页动作回来的反馈 */
    private const TOOL_NOTICES = [
        'created' => '备份已创建。',
        'create_failed' => '备份没有建起来:写盘或数据库导出出了岔子,详情见运行日志。',
        'created_remote' => '备份已创建，并已推送到远端。',
        'created_remote_failed' => '备份已创建，但推送远端没有成功；本地备份不受影响，查过凭据与网络后再试一次即可。',
        'deleted' => '备份已删除。',
        'restored' => '已按所选备份恢复。',
        'restore_failed' => '恢复没有完成:备份的校验和或结构与当前环境不匹配。恢复前系统已自动留了一份现状。',
        'restore_need_password' => '这份备份是加密的：请填上解密密码，再点「确认恢复」。',
        'restore_bad_password' => '恢复没有完成：解密密码不对，或备份包已损坏。当前数据没有被改动。',
        'download_expired' => '下载链接已过期或用过了；回列表重新点「下载」生成新链接。',
        'remote_saved' => '远端凭据已保存。',
        'remote_cleared' => '远端凭据已清除。',
        'remote_incomplete' => '凭据没有保存：必填项还缺着，按每个字段下面的说明补全即可。',
        'remote_failed' => '凭据没有保存：写盘失败，检查 storage/backups/ 的目录权限。',
    ];

    public function __construct(
        private readonly Router $router,
        private readonly Backup $backup,
        private readonly Restore $restore,
        private readonly DownloadTokens $tokens,
        private readonly Remote $remote,
        private readonly Purger $purger,
        private readonly Progress $progress
    ) {
    }

    public function toolNotice(Request $request): string
    {
        return __(self::TOOL_NOTICES[$request->input('done')] ?? '');
    }

    /**
     * 远端凭据「测试连接」:拿对话框里的当前值探一次真写入,结果回 JSON 给按钮。
     *
     * 门与写动作同一套:会话 + CSRF(能力门在进页那一步已按 tools 页登记校验过)。
     * 探到"写入被拒"不是 HTTP 错误 —— 请求本身跑通了,结论在 ok 里,所以一律 200。
     */
    public function remoteTest(Request $request): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::json(['ok' => false, 'message' => __(WebAuth::STALE)], 403);
        }

        $driver = $request->input('driver');

        try {
            $where = $this->remote->probe($driver, $request->inputs());
            Audit::record('backup.remote.test', $driver, true, $request, 'user:' . (string) Auth::id());

            return Response::json(['ok' => true, 'message' => __('连接可用：%s', 'molin', $where)]);
        } catch (Throwable $e) {
            Audit::record('backup.remote.test', $driver, false, $request, 'user:' . (string) Auth::id());
            Log::channel('app')->warning('backup.remote.test_failed', ['driver' => $driver, 'error' => $e->getMessage()]);

            return Response::json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 工具页的写动作(创建备份)。名字过 Backup::delete 的正则,穿越进不来。
     */
    public function toolsAction(Request $request, string $path): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect($this->router->path('admin', ['path' => 'tools']));
        }

        $done = '';

        if ($path === 'tools/backups/create') {
            $password = $request->input('password');
            // 备份是长任务:进度落一份 JSON,前端另开一条请求轮 tools/progress
            $progress = $this->progress;
            $progress->begin('backup');

            try {
                $created = $this->backup->create(Kernel::VERSION, 'full', $password === '' ? null : $password, $progress);
            } catch (Throwable $e) {
                $progress->finish(false, $e->getMessage());
                Log::channel('app')->warning('backup.create_failed', ['error' => $e->getMessage()]);
                Audit::record('backup.create', 'failed', false, $request, 'user:' . (string) Auth::id());

                return Response::redirect($this->router->path('admin', ['path' => 'tools']) . '?done=create_failed');
            }

            Audit::record('backup.create', $created['name'], true, $request, 'user:' . (string) Auth::id());
            $progress->finish(true, __('备份已生成'));
            $done = 'created';

            // 「推送到远端」开着才推;驱动名按白名单收,推送失败不回滚本地备份
            $driver = $request->input('remote_on') === '1' ? $request->input('remote') : '';

            if (in_array($driver, Remote::DRIVERS, true)) {
                $path = $this->backup->path($created['name']);

                try {
                    if ($path === null) {
                        throw new RuntimeException('备份文件不见了:' . $created['name']);
                    }

                    $this->remote->push($driver, $path, $created['name']);
                    Audit::record('backup.remote.push', $driver . ' / ' . $created['name'], true, $request, 'user:' . (string) Auth::id());
                    $done = 'created_remote';
                } catch (Throwable $e) {
                    Audit::record('backup.remote.push', $driver . ' / ' . $created['name'], false, $request, 'user:' . (string) Auth::id());
                    Log::channel('app')->warning('backup.remote.push_failed', ['driver' => $driver, 'name' => $created['name'], 'error' => $e->getMessage()]);
                    $done = 'created_remote_failed';
                }
            }
        } elseif ($path === 'tools/remote/save') {
            $driver = $request->input('driver');

            try {
                $this->remote->save($driver, $request->inputs());
                Audit::record('backup.remote.save', $driver, true, $request, 'user:' . (string) Auth::id());
                $done = 'remote_saved';
            } catch (RuntimeException $e) {
                Log::channel('app')->warning('backup.remote.save_failed', ['driver' => $driver, 'error' => $e->getMessage()]);
                Audit::record('backup.remote.save', $driver, false, $request, 'user:' . (string) Auth::id());
                $done = str_contains($e->getMessage(), '写盘失败') ? 'remote_failed' : 'remote_incomplete';

                // 失败时把对话框留在原标签页,让人接着改
                return Response::redirect($this->router->path('admin', ['path' => 'tools'])
                    . '?remote=' . rawurlencode(in_array($driver, Remote::DRIVERS, true) ? $driver : 's3') . '&done=' . $done);
            }
        } elseif ($path === 'tools/remote/clear' && $request->input('confirm') === '1') {
            $driver = $request->input('driver');

            try {
                $this->remote->clear($driver);
                Audit::record('backup.remote.clear', $driver, true, $request, 'user:' . (string) Auth::id());
                $done = 'remote_cleared';
            } catch (RuntimeException $e) {
                Log::channel('app')->warning('backup.remote.clear_failed', ['driver' => $driver, 'error' => $e->getMessage()]);
            }
        } elseif ($path === 'tools/backups/restore' && $request->input('confirm') === '1') {
            $name = $request->input('name');
            $password = $request->input('password');
            $password = $password === '' ? null : $password;
            $file = $this->backup->path($name);

            // 加密包没带密码:把确认卡留在原地,提示补密码,别让一次点击白费
            if ($file !== null && Cipher::isEncryptedFile($file) && $password === null) {
                return Response::redirect($this->router->path('admin', ['path' => 'tools'])
                    . '?restore=' . rawurlencode($name) . '&done=restore_need_password');
            }

            $progress = $this->progress;
            $progress->begin('restore');

            try {
                $this->restore->restore($name, Kernel::VERSION, $password, $progress);
                Audit::record('backup.restore', $name, true, $request, 'user:' . (string) Auth::id());
                $progress->finish(true, __('恢复完成'));
                $done = 'restored';
            } catch (Throwable $e) {
                // 恢复失败时既有规范的"恢复前自动备份"已在 —— 回工具页,不至于更糟
                Audit::record('backup.restore', $name, false, $request, 'user:' . (string) Auth::id());
                $progress->finish(false, $e->getMessage());
                // Cipher 的"解密失败"前缀在这处分流:密码错与包损坏对操作者是同一句提醒
                $done = str_starts_with($e->getMessage(), '解密失败') ? 'restore_bad_password' : 'restore_failed';
            }
        } elseif ($path === 'tools/backups/delete') {
            $name = $request->input('name');

            if ($this->backup->delete($name)) {
                Audit::record('backup.delete', $name, true, $request, 'user:' . (string) Auth::id());
                $done = 'deleted';
            }
        }

        return Response::redirect($this->router->path('admin', ['path' => 'tools'])
            . ($done === '' ? '' : '?done=' . $done));
    }

    /**
     * 下载两步走(一次性、限时、不暴露真实路径):
     * 工具页 `?download=名字` 签发令牌 → `tools/backups/fetch/{token}` 取件,用过即焚。
     */
    public function toolsDownload(Request $request, string $path): ?Response
    {
        if ($path === 'tools' && $request->input('download') !== '') {
            $name = $request->input('download');
            $file = $this->backup->path($name);

            if ($file === null) {
                return Response::redirect($this->router->path('admin', ['path' => 'tools']) . '?done=download_expired');
            }

            return Response::redirect($this->router->path('admin', ['path' => 'tools/backups/fetch/' . $this->tokens->issue($name)]));
        }

        $prefix = 'tools/backups/fetch/';

        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $name = $this->tokens->consume(substr($path, strlen($prefix)));
        $file = $name === null ? null : $this->backup->path($name);

        if ($file === null) {
            return Response::redirect($this->router->path('admin', ['path' => 'tools']) . '?done=download_expired');
        }

        Audit::record('backup.download', (string) $name, true, $request, 'user:' . (string) Auth::id());

        return Response::download($file, (string) $name);
    }

    public function clearCache(Request $request, string $path): Response
    {
        if (!$request->isPost() || $path !== 'cache/clear') {
            throw new NotFoundException('后台没有这个页面:' . $path);
        }

        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return Response::redirect('/admin');
        }

        $deleted = $this->purger->purge();
        // 数据缓存与页面缓存一并清(既有规范;既有规范):「清理缓存」是同一件事,不再各清各的
        CacheManager::instance()->flushAll();
        $pages = $this->pageCache()->purge();
        Audit::record('cache.clear', 'files:' . $deleted . ',pages:' . $pages);

        return Response::redirect('/admin?done=cache&n=' . $deleted);
    }

    /**
     * 「缓存」分组要的真状态(既有规范;数据源既有规范/§9)。
     *
     * 生效后端取 CacheManager 的**实际**后端(配置写 yac/redis 而扩展缺失或适配器未落地时,
     * fromConfig 已如实降级为 file,这里显示的就是 file);未接装配线时 managed=false,
     * 模板照旧走「只读说明」那一支(仅编译缓存)。
     *
     * @return array<string, mixed>
     */
    public function cacheStatus(): array
    {
        $stats = CacheManager::instance()->stats();
        $pages = $this->pageCache()->stats();

        $labels = [
            'file' => __('文件'),
            'yac' => __('YAC'),
            'redis' => __('Redis'),
            'null' => __('已关闭'),
        ];

        return $stats + [
            'backend_label' => $labels[$stats['backend']] ?? $stats['backend'],
            'configured_label' => $labels[$stats['configured']] ?? $stats['configured'],
            'usage_text' => self::bytesText($stats['bytes']),
            'pages_files' => $pages['files'],
            'pages_bytes' => $pages['bytes'],
            'pages_usage_text' => self::bytesText($pages['bytes']),
        ];
    }

    /** 页面静态缓存;目录与 Bootstrap 同一套换根口径(MOLIN_STATE_ROOT 优先) */
    private function pageCache(): PageCache
    {
        return new PageCache($this->cacheRoot() . '/storage/cache/pages');
    }

    private function cacheRoot(): string
    {
        $state = getenv('MOLIN_STATE_ROOT');

        return is_string($state) && $state !== '' ? $state : MOLIN_ROOT;
    }

    /** 字节数报成人读的一档(KB 取整、MB 一位小数;与媒体库同一把尺) */
    private static function bytesText(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return (int) round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }
}
