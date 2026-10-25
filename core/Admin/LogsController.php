<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Log;
use Molin\Logger\LogReader;
use Molin\Router\Router;
use Molin\User\User;

/**
 * 日志查看器(《112-后台日志查看器实现规格》)。只读屏:六个通道页签、四件筛选、分页。
 *
 * 数据源是内核正在写的日志文件(见 LogReader)——《21》没有 logs 表。
 * 筛选与分页全部服务端做(URL 带参,页签/分页/翻页链接都由这里算好,模板不拼 URL);
 * 本页永不出现"清空 / 删除日志"控件(《112》§5.4)。
 */
final class LogsController
{
    /** 每页条数(《41》§6.1:20) */
    private const PER_PAGE = LogReader::PER_PAGE;

    public function __construct(
        private readonly View $view,
        private readonly LogReader $reader,
        private readonly Router $router
    ) {
    }

    /**
     * @param list<array{title: string, path: string, active: bool, badge?: string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if ($path !== 'logs') {
            throw new NotFoundException('后台没有这个页面:' . $path);
        }

        $channel = $request->input('ch', 'audit');

        if (!in_array($channel, Log::CHANNELS, true)) {
            $channel = 'audit';
        }

        $level = strtoupper($request->input('level'));

        if (!in_array($level, LogReader::levels(), true)) {
            $level = '';
        }

        $range = $request->input('range');

        if (!in_array($range, ['today', '7d', '30d', 'month'], true)) {
            $range = '';
        }

        $state = [
            'ch' => $channel,
            'q' => $request->input('q'),
            'level' => $level,
            'range' => $range,
            'sort' => $request->input('sort') === 'asc' ? 'asc' : 'desc',
        ];

        $page = max(1, (int) $request->input('page', '1'));
        $result = $this->reader->page($channel, $state + ['page' => $page]);
        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $start = $total === 0 ? 0 : ($page - 1) * self::PER_PAGE + 1;
        $end = min($total, $page * self::PER_PAGE);

        return $this->view->render('logs.twig', [
            'page_kind' => 'logs',
            'page_title' => __('日志'),
            'tabs' => $this->tabs($channel, $state),
            'channel' => $channel,
            'q' => $state['q'],
            'level' => $level,
            'range' => $range,
            'sort' => $state['sort'],
            'levels' => $this->levels(),
            'ranges' => $this->ranges(),
            'logs_url' => $this->url([]),
            'rows' => $result['rows'],
            'pager' => [
                'info' => __('第 %s–%s 条，共 %s 条', 'molin', (string) $start, (string) $end, (string) $total),
                'pages' => $this->pages($state, $page, $totalPages),
                'prev' => $this->url($state + ['page' => max(1, $page - 1)]),
                'next' => $this->url($state + ['page' => min($totalPages, $page + 1)]),
            ],
        ], $menu);
    }

    /**
     * 页签:六个通道 + 各自计数;当前项带 active。切页签保留筛选,回到第 1 页。
     *
     * @param array<string, string> $state
     * @return list<array{ch: string, n: int, url: string, active: bool}>
     */
    private function tabs(string $channel, array $state): array
    {
        $counts = $this->reader->counts();
        $tabs = [];

        foreach (Log::CHANNELS as $name) {
            $tabs[] = [
                'ch' => $name,
                'n' => $counts[$name] ?? 0,
                'url' => $this->url($state + ['ch' => $name]),
                'active' => $name === $channel,
            ];
        }

        return $tabs;
    }

    /**
     * 分页数字:前 3 页 ∪ 当前页 ±1,空档给省略号(《112》§11-7 折叠规则未定,
     * 先照基准页 1 的形状「1 2 3 …」;首尾两端不硬塞尾页)。
     * 条目统一形状(n/url/current/gap 四键齐全:《14》strict_variables 缺键会报错)。
     *
     * @param array<string, string> $state
     * @return list<array{n: int, url: string, current: bool, gap: bool}>
     */
    private function pages(array $state, int $page, int $totalPages): array
    {
        $visible = [];

        for ($n = 1; $n <= min(3, $totalPages); $n++) {
            $visible[$n] = true;
        }

        for ($n = max(1, $page - 1); $n <= min($totalPages, $page + 1); $n++) {
            $visible[$n] = true;
        }

        ksort($visible);

        $out = [];
        $last = 0;

        foreach (array_keys($visible) as $n) {
            if ($last !== 0 && $n > $last + 1) {
                $out[] = ['n' => 0, 'url' => '', 'current' => false, 'gap' => true];
            }

            $out[] = ['n' => $n, 'url' => $this->url($state + ['page' => $n]), 'current' => $n === $page, 'gap' => false];
            $last = $n;
        }

        if ($totalPages > $last) {
            $out[] = ['n' => 0, 'url' => '', 'current' => false, 'gap' => true];
        }

        return $out;
    }

    /**
     * @return array<string, string> 值 => 文案
     */
    private function levels(): array
    {
        $out = ['' => __('全部级别')];

        foreach (LogReader::levels() as $level) {
            $out[$level] = $level; // 级别名原样不翻译(《112》§5.3)
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function ranges(): array
    {
        return [
            '' => __('全部时间'),
            'today' => __('今天'),
            '7d' => __('近 7 天'),
            '30d' => __('近 30 天'),
            'month' => date('Y 年 n 月'),
        ];
    }

    /**
     * 页内地址:基准 + 非缺省参数(缺省项不进 URL,翻页/切签复制同一份状态)。
     *
     * @param array<string, string|int> $params
     */
    private function url(array $params): string
    {
        $clean = [];

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if ($key === 'sort' && $value === 'desc') {
                continue;
            }

            // 缺省项不进 URL(与 sort 同一口径):缺省通道 audit、第 1 页
            if ($key === 'ch' && $value === 'audit') {
                continue;
            }

            if ($key === 'page' && (int) $value === 1) {
                continue;
            }

            $clean[$key] = (string) $value;
        }

        $base = $this->router->path('admin', ['path' => 'logs']);

        return $clean === [] ? $base : $base . '?' . http_build_query($clean);
    }
}
