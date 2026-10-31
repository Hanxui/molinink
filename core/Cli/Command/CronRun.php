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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 跑到期的定时任务。
 *
 * 系统计划任务每隔几分钟调一次即可 —— 谁到点由 Cron 按 cron.json 自己判,
 * 不必给每个任务单独挂一条 crontab。
 *
 * CLI 不会自动加载插件,先 boot 一遍装载器再收集任务;否则插件的 `system/cron/collect`
 * 回调根本不会注册,列表里永远只有内核自带的那几条。
 */
final class CronRun extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('cron:run')
            ->setDescription('跑到期的定时任务(内建 + 插件)')
            ->addOption('job', null, InputOption::VALUE_REQUIRED, '只跑这一个任务(无视到期)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        // 插件加载是增强不是前提:最小装配(测试)里没有装载器,内建任务照跑
        if ($this->hasService(Loader::class)) {
            $this->service(Loader::class)->boot();
        }

        $cron = $this->service(Cron::class);
        $now = time();
        $dryRun = $this->isDryRun($input);
        $chosen = (string) $input->getOption('job');

        if ($chosen !== '') {
            if (!$cron->has($chosen)) {
                $output->writeln('没有这个任务:' . $chosen . '(可用:' . implode(' / ', $cron->names()) . ')');

                return ExitCode::USAGE;
            }

            $jobs = [$chosen];
        } else {
            $jobs = $cron->due($now);
        }

        /** @var array<string, int> $done */
        $done = [];
        /** @var array<string, string> $sources */
        $sources = [];

        foreach ($jobs as $job) {
            $done[$job] = $cron->run($job, $now, $dryRun);
            $sources[$job] = $cron->source($job);
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, ['dry_run' => $dryRun, 'jobs' => $done, 'sources' => $sources]);

            return ExitCode::OK;
        }

        if ($done === []) {
            $output->writeln('没有到期的任务。');

            return ExitCode::OK;
        }

        foreach ($done as $job => $count) {
            $output->writeln(sprintf(
                '%s [%s]:%s%d 条',
                $job,
                $sources[$job] === 'plugin' ? '插件' : '内建',
                $dryRun ? '(演练) ' : ' ',
                $count
            ));
        }

        return ExitCode::OK;
    }
}
