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
use Molin\Logger\Audit;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 回滚迁移(每个迁移必须带 down)。
 *
 * 破坏性动作一律显式 --force;先 --dry-run 看清范围再执行。
 */
final class DbRollback extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('db:rollback')
            ->setDescription('倒序回滚已执行的迁移(需 --force)')
            ->addOption('steps', 's', InputOption::VALUE_REQUIRED, '回滚几步', '1');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $migrator = $this->migrator();
        $steps = max(1, (int) $input->getOption('steps'));
        $applied = array_values(array_diff($migrator->all(), $migrator->pending()));
        $targets = array_slice(array_reverse($applied), 0, $steps);

        if ($targets === []) {
            if ($this->isJson($input)) {
                $this->emitJson($output, ['rolled_back' => [], 'db_version' => $migrator->version()]);

                return ExitCode::OK;
            }

            $output->writeln('没有可回滚的迁移。');

            return ExitCode::OK;
        }

        // 演练与 JSON 都只报范围,不动库
        if ($this->isDryRun($input) || $this->isJson($input)) {
            if ($this->isJson($input)) {
                $this->emitJson($output, ['will_rollback' => $targets, 'db_version' => $migrator->version()]);

                return ExitCode::OK;
            }

            foreach ($targets as $name) {
                $output->writeln('将回滚: ' . $name);
            }

            return ExitCode::OK;
        }

        if (!$this->isForce($input)) {
            $output->writeln('回滚会真删表结构。先用 --dry-run 看清范围,再带 --force 执行。');

            return ExitCode::USAGE;
        }

        $undone = $migrator->rollback($steps);
        Audit::record('db.rollback', 'migrations:' . implode(',', $undone));

        foreach ($undone as $name) {
            $output->writeln('已回滚: ' . $name);
        }

        $output->writeln('结构版本:' . ($migrator->version() === '' ? '(空库)' : $migrator->version()));

        return ExitCode::OK;
    }
}
