<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Logger;

/**
 * 日志读取(《112-后台日志查看器实现规格》):把 storage/logs/ 的文件读成页面行。
 *
 * 《21》没有 logs 表,读取来源就是内核正在写的文件(《26》§5 的通道与轮转):
 * 常规通道 `{channel}-YYYY-MM-DD.log` 按天轮转,审计 `audit/audit-YYYY-MM.log` 单独存放;
 * 行格式同《26》§5.4:`[2026-09-28 16:33:12] app.INFO: 正文 {"k":"v"} []`。
 * 只读、永不删改 —— 审计"不可清空、不可编辑"在这一层就是不提供任何写方法。
 *
 * 两段式:先轻扫(时间/级别/原行,一次遍历),窗口内的 20 行才做完整解析(正文分段 / 上下文)。
 * 高密度的日志文件不该为一次翻页付出全量 json 解析。
 */
final class LogReader
{
    public const PER_PAGE = 20;

    /** 级别序(《26》§5.2) */
    private const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** 级别→暖赭的分档(《112》§7 张表反推;《112》§11-1 映射未定,先只分"要留意"一档) */
    private const HOT = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** 摘要里的文件名(《112》§5.6 文件名走 code):认扩展名的常见档 */
    private const FILE = '#^[A-Za-z0-9][A-Za-z0-9_.-]*\.(?:tar\.gz|tgz|zip|gz|sql|json|log|txt|csv|xml|html|md|png|jpe?g|gif|webp|svg|pdf)$#i';

    private readonly string $root;

    public function __construct(?string $root = null)
    {
        // 与 Log 同一口径:测试进程可把日志根挪出仓库;缺省读当前站的 storage/logs
        $env = getenv('MOLIN_LOG_ROOT');
        $this->root = $root ?? (is_string($env) && $env !== '' ? $env : MOLIN_ROOT . '/storage/logs');
    }

    /**
     * @return list<string>
     */
    public static function levels(): array
    {
        return self::LEVELS;
    }

    /**
     * 各通道条目数(页签计数,全时段)。
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $out = [];

        foreach (Log::CHANNELS as $channel) {
            $out[$channel] = count($this->scan($channel));
        }

        return $out;
    }

    /**
     * 窗口内某动作出现几次(《111》慢查询那格要计数,不要全量读)。
     * 只认正文首词:行如 `[ts] db.WARNING: slow_query {"ms":...} []`,
     * 词界收口,正文里提一嘴 slow_query 的句子不算。
     */
    public function countSince(string $channel, string $action, int $since): int
    {
        $pattern = '/: ' . preg_quote($action, '/') . '(?:[\s{]|$)/';
        $count = 0;

        foreach ($this->scan($channel) as $row) {
            if ((int) ($row['ts'] ?? 0) >= $since && preg_match($pattern, $row['line']) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 当前通道按筛选取一页。缺省按时间倒序(新的在前)。
     *
     * @param array{q?: string, level?: string, range?: string, sort?: string, page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(string $channel, array $filters = []): array
    {
        $rows = $this->scan($channel);

        $q = trim((string) ($filters['q'] ?? ''));
        $level = strtoupper(trim((string) ($filters['level'] ?? '')));
        $range = (string) ($filters['range'] ?? '');
        $sort = ($filters['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($filters['page'] ?? 1));

        $from = match ($range) {
            'today' => strtotime('today'),
            '7d' => strtotime('today') - 6 * 86400,
            '30d' => strtotime('today') - 29 * 86400,
            'month' => strtotime(date('Y-m-01 00:00:00')),
            default => 0,
        };

        $kept = [];

        foreach ($rows as $row) {
            if ($level !== '' && in_array($level, self::LEVELS, true) && $row['lv'] !== $level) {
                continue;
            }

            if ($from > 0 && ($row['ts'] === null || (int) $row['ts'] < $from)) {
                continue;
            }

            if ($q !== '' && stripos($row['line'], $q) === false) {
                continue;
            }

            $kept[] = $row;
        }

        if ($sort === 'desc') {
            $kept = array_reverse($kept);
        }

        $total = count($kept);
        $window = array_slice($kept, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $parsed = [];

        foreach ($window as $row) {
            $full = $this->parse($row['line'], $channel);

            if ($full !== null) {
                $parsed[] = $full;
            }
        }

        return ['rows' => $parsed, 'total' => $total];
    }

    /**
     * 轻扫:只取时间/级别/原行,按时间稳定排序(PHP 8 的 usort 稳定;null 时间排最前)。
     * 只认行首是 `[` 的行 —— Monolog 的续行(异常堆栈)不进列表。
     *
     * @return list<array{ts: ?int, lv: string, line: string}>
     */
    private function scan(string $channel): array
    {
        $rows = [];

        foreach ($this->files($channel) as $file) {
            $body = @file_get_contents($file);

            if ($body === false || $body === '') {
                continue;
            }

            $lines = preg_split('/\r?\n/', $body);

            foreach ($lines === false ? [] : $lines as $line) {
                if ($line === '' || $line[0] !== '[') {
                    continue;
                }

                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [a-z]+\.([A-Z]+): /', $line, $m) !== 1) {
                    continue;
                }

                $ts = strtotime($m[1]);
                $rows[] = ['ts' => $ts === false ? null : $ts, 'lv' => $m[2], 'line' => $line];
            }
        }

        usort($rows, static fn (array $a, array $b): int => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function files(string $channel): array
    {
        $files = glob($channel === 'audit'
            ? $this->root . '/audit/audit-*.log'
            : $this->root . '/' . $channel . '-*.log');

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $line, string $channel): ?array
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] ([a-z]+)\.([A-Z]+): (.*)$/', $line, $m) !== 1) {
            return null;
        }

        $body = $m[4];

        // Monolog 的 %context% %extra% 都是 json_encode 出来的:先剥最多两个尾部空数组
        for ($i = 0; $i < 2; $i++) {
            if (str_ends_with($body, ' []')) {
                $body = substr($body, 0, -3);
            }
        }

        $context = [];
        $brace = strrpos($body, ' {');

        if ($brace !== false && str_ends_with($body, '}')) {
            $decoded = json_decode(substr($body, $brace + 1), true);

            if (is_array($decoded)) {
                $context = $decoded;
                $body = substr($body, 0, $brace);
            }
        }

        $ip = is_string($context['ip'] ?? null) ? $context['ip'] : '';
        $ua = is_string($context['ua'] ?? null) ? $context['ua'] : '';
        $level = $m[3];

        $parsed = strtotime($m[1]);

        return [
            'ts' => $parsed === false ? null : $parsed,
            'tm' => substr($m[1], 5, 14),
            'lv' => $level,
            'hi' => in_array($level, self::HOT, true),
            'ch' => $channel,
            'parts' => $this->compose($channel, $body, $context),
            'ip' => $ip,
            'ua' => $ua,
        ];
    }

    /**
     * 摘要列的分段(《112》§5.6):正文按 ` · ` 拆段、分段符渲染成 dim 中点;
     * 段内动作名与文件名走等宽 code,`•••` / `***@` 脱敏段走 mask。
     *
     * 审计行若是"纯动作名"正文(《26》§7 的机器写法,《26》行如 `audit.INFO: user.login`),
     * 再把对象 / 结果 / 操作者摆出来;散文正文自带事实,不再补以免重复。
     *
     * @param array<string, mixed> $context
     * @return list<array{t: string, x: string}>
     */
    private function compose(string $channel, string $message, array $context): array
    {
        $parts = [];

        $segments = preg_split('/\s+·\s+/u', $message);

        foreach ($segments === false ? [$message] : $segments as $i => $segment) {
            if ($i > 0) {
                foreach ($this->separator() as $part) {
                    $parts[] = $part;
                }
            }

            foreach ($this->segment($segment) as $part) {
                $parts[] = $part;
            }
        }

        if ($channel !== 'audit' || !$this->isAction($message)) {
            return $parts;
        }

        $facts = [];
        $target = $context['target'] ?? null;

        if (is_string($target) && $target !== '') {
            $facts[] = '对象 ' . $target;
        }

        if (($context['result'] ?? null) === 'ok') {
            $facts[] = '结果 成功';
        } elseif (($context['result'] ?? null) === 'denied') {
            $facts[] = '结果 拒绝';
        }

        $actor = $context['actor'] ?? null;

        if (is_string($actor) && $actor !== '' && $actor !== 'system') {
            $facts[] = '操作者 ' . $actor;
        }

        foreach ($facts as $fact) {
            foreach ($this->separator() as $part) {
                $parts[] = $part;
            }

            $parts[] = ['t' => 'x', 'x' => $fact];
        }

        return $parts;
    }

    /**
     * 分段符:两个空格留在 dim span 外面,与基准源件的包裹方式逐字对齐。
     * 空格在 span 内还是外,会影响后续字形的亚像素相位 —— 拉开就会整段文字相移一格,
     * 截图比对里表现为 1px 的笔画差异(《112》基准重渲 0 差异,比拼的正是这层)。
     *
     * @return list<array{t: string, x: string}>
     */
    private function separator(): array
    {
        return [
            ['t' => 'x', 'x' => ' '],
            ['t' => 'd', 'x' => '·'],
            ['t' => 'x', 'x' => ' '],
        ];
    }

    /**
     * 单个分段里的词级上色:首个动作名词、文件名走 code,脱敏段走 mask,其余原样。
     *
     * @return list<array{t: string, x: string}>
     */
    private function segment(string $text): array
    {
        /** @var list<array{t: string, x: string}> $parts */
        $parts = [];
        $lead = true;
        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($tokens === false ? [$text] : $tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (trim($token) === '') {
                $type = 'x';
            } elseif (($lead && $this->isAction($token)) || preg_match(self::FILE, $token) === 1) {
                $type = 'c';
            } elseif (preg_match('/^•+$/u', $token) === 1 || str_contains($token, '***@')) {
                $type = 'm';
            } else {
                $type = 'x';
            }

            $at = count($parts) - 1;

            if ($type === 'x' && $at >= 0 && $parts[$at]['t'] === 'x') {
                $parts[$at]['x'] .= $token;
            } else {
                $parts[] = ['t' => $type, 'x' => $token];
            }

            if (trim($token) !== '') {
                $lead = false;
            }
        }

        return array_values($parts);
    }

    private function isAction(string $text): bool
    {
        return preg_match('/^[a-z][a-z0-9_.]*\.[a-z0-9_.]+$/', $text) === 1;
    }
}
