<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Backup;

use Molin\Support\Files;

/**
 * 备份 / 恢复的服务端进度(《35》§5 的过程反馈)。
 *
 * 长任务在一条请求里跑完,浏览器只能另发一条请求问"到哪了":进度落一个小 JSON,
 * 读写都只碰 storage/backups 下的这一个文件(该目录不对外暴露)。
 * 全程只有一个槽位:新任务 begin() 覆盖旧的,前端凭 job / ts 认出是不是自己那份。
 */
final class Progress
{
    /** 未开始时的空状态,read() 与前端都照这个形状取值 */
    private const IDLE = [
        'job' => '',
        'phase' => '',
        'done' => 0,
        'total' => 0,
        'percent' => 0,
        'label' => '',
        'ok' => null,
        'running' => false,
        'message' => '',
        'ts' => 0,
    ];

    public function __construct(
        private readonly string $root,
        /** 进度落在哪个文件(相对站点根)。备份与升级各占一个槽位,互不打扰 */
        private readonly string $file = '/storage/backups/.progress.json'
    ) {
    }

    /**
     * 开一个新任务:清掉上一次的残留。谁发起任务,谁先调这一步。
     */
    public function begin(string $job): void
    {
        $this->write([
            'job' => $job,
            'phase' => '',
            'done' => 0,
            'total' => 0,
            'percent' => 0,
            'label' => '',
            'ok' => null,
            'running' => true,
            'message' => '',
            'ts' => time(),
        ]);
    }

    /**
     * 报一次进度。total 为 0 表示这一步没法估量,只更新阶段文字。
     */
    public function advance(int $done, int $total, string $label, string $phase = ''): void
    {
        $state = $this->read();
        $state['done'] = max(0, $done);
        $state['total'] = max(0, $total);
        $state['percent'] = $total > 0
            ? (int) min(100, (int) floor($done * 100 / $total))
            : ($done > 0 ? 100 : 0);
        $state['label'] = $label;
        $state['ok'] = null;
        $state['running'] = true;
        $state['ts'] = time();

        if ($phase !== '') {
            $state['phase'] = $phase;
        }

        $this->write($state);
    }

    /**
     * 收尾:成功 / 失败都要落一次,前端凭 ok 与 message 决定放行还是报错。
     */
    public function finish(bool $ok, string $message): void
    {
        $state = $this->read();
        $state['ok'] = $ok;
        $state['message'] = $message;
        $state['running'] = false;
        $state['percent'] = $ok ? 100 : $state['percent'];
        $state['ts'] = time();
        $this->write($state);
    }

    /**
     * @return array{job:string, phase:string, done:int, total:int, percent:int, label:string, ok:bool|null, running:bool, message:string, ts:int}
     */
    public function read(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return self::IDLE;
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            return self::IDLE;
        }

        return [
            'job' => (string) ($data['job'] ?? ''),
            'phase' => (string) ($data['phase'] ?? ''),
            'done' => (int) ($data['done'] ?? 0),
            'total' => (int) ($data['total'] ?? 0),
            'percent' => (int) ($data['percent'] ?? 0),
            'label' => (string) ($data['label'] ?? ''),
            'ok' => isset($data['ok']) ? (bool) $data['ok'] : null,
            'running' => (bool) ($data['running'] ?? false),
            'message' => (string) ($data['message'] ?? ''),
            'ts' => (int) ($data['ts'] ?? 0),
        ];
    }

    /**
     * 抹掉进度文件(任务收尾后由调用方择机清理)。
     */
    public function clear(): void
    {
        Files::delete($this->path());
    }

    private function path(): string
    {
        return $this->root . $this->file;
    }

    /**
     * 小文件整份覆写就够:带 LOCK_EX 防读写撞车,失败时静默 —— 进度丢了不该拖垮主流程。
     *
     * @param array<string, mixed> $state
     */
    private function write(array $state): void
    {
        $dir = dirname($this->path());

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        @file_put_contents($this->path(), (string) json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
