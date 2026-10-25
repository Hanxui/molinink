<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli\Command;

use Molin\Cli\BaseCommand;
use Molin\Cli\ExitCode;
use Molin\Cron\Cron;
use Molin\Plugin\Loader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 列出内建与插件定时任务、来源、上次运行时间与下次到期时间(《58-CLI工具》第 5 节)。只读,不动任何数据。
 */
final class CronList extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('cron:list')
            ->setDescription('列出内建与插件定时任务及运行状态');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        // 插件加载是增强不是前提:最小装配(测试)里没有装载器,内建任务照跑
        if ($this->hasService(Loader::class)) {
            $this->service(Loader::class)->boot();
        }

        $cron = $this->service(Cron::class);
        $now = time();

        /** @var list<array{job:string, note:string, every:int, last:int, due:bool, next:int, source:string}> $rows */
        $rows = [];

        foreach ($cron->lastRuns() as $job => $last) {
            $every = $cron->seconds($job);
            $next = $last === 0 ? $now : $last + $every;

            $rows[] = [
                'job' => $job,
                'note' => $cron->note($job),
                'every' => $every,
                'last' => $last,
                'due' => $next <= $now,
                'next' => $next,
                'source' => $cron->source($job),
            ];
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, ['data' => $rows]);

            return ExitCode::OK;
        }

        if ($rows === []) {
            $output->writeln('还没有任何定时任务。');

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $when = $row['last'] === 0 ? '从没跑过' : gmdate('Y-m-d H:i', $row['last']) . ' UTC';
            $next = $row['due'] ? '已到期' : gmdate('Y-m-d H:i', $row['next']) . ' UTC';

            $output->writeln(sprintf(
                '%-22s %-4s %-18s %-10s 上次:%-18s 下次:%s',
                $row['job'],
                $row['source'] === 'plugin' ? '插件' : '内建',
                $row['note'],
                self::every($row['every']),
                $when,
                $next
            ));
        }

        return ExitCode::OK;
    }

    private static function every(int $seconds): string
    {
        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            return '每 ' . intdiv($seconds, 3600) . ' 小时';
        }

        if ($seconds >= 60 && $seconds % 60 === 0) {
            return '每 ' . intdiv($seconds, 60) . ' 分钟';
        }

        return '每 ' . $seconds . ' 秒';
    }
}
