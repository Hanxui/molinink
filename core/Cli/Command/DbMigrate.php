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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 建表与升级都走这一条路(既有规范;约定:禁 schema.sql,首次安装与升级同一条路)。
 */
final class DbMigrate extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('db:migrate')
            ->setDescription('执行 core/Migrations 里待跑的迁移');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $migrator = $this->migrator();
        $pending = $migrator->pending();
        $dryRun = $this->isDryRun($input);
        $names = $dryRun ? $pending : ($pending === [] ? [] : $migrator->migrate());

        if (!$dryRun && $names !== []) {
            Audit::record('db.migrate', 'migrations:' . implode(',', $names));
        }

        $version = $migrator->version();

        if ($this->isJson($input)) {
            $this->emitJson($output, [
                'dry_run'    => $dryRun,
                'migrations' => $names,
                'db_version' => $version,
            ]);

            return ExitCode::OK;
        }

        $output->writeln('结构版本:' . ($version === '' ? '(空库)' : $version));

        if ($names === []) {
            $output->writeln('没有待执行的迁移,结构已是最新。');

            return ExitCode::OK;
        }

        foreach ($names as $name) {
            $output->writeln(($dryRun ? '将执行: ' : '已执行: ') . $name);
        }

        return ExitCode::OK;
    }
}
