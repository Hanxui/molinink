<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli\Command;

use Molin\Backup\Backup;
use Molin\Cli\BaseCommand;
use Molin\Cli\ExitCode;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 列出备份(《35》§10、《58》§1 的 backup:list)。
 */
final class BackupList extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('backup:list')
            ->setDescription('列出备份(含大小、时间、来源与是否加密)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $backups = $this->service(Backup::class)->list();

        if ($this->isJson($input)) {
            $this->emitJson($output, ['data' => $backups]);

            return ExitCode::OK;
        }

        if ($backups === []) {
            $output->writeln('还没有备份,用 molin backup:create 留第一份。');

            return ExitCode::OK;
        }

        foreach ($backups as $backup) {
            $output->writeln(sprintf(
                '%s  %-9s %s  %s%s',
                (string) $backup['name'],
                $this->sizeText((int) $backup['size']),
                date('Y-m-d H:i', (int) $backup['created_at']),
                (string) $backup['source'],
                $backup['encrypted'] ? '  [已加密]' : ''
            ));
        }

        $output->writeln('共 ' . count($backups) . ' 份。');

        return ExitCode::OK;
    }

    private function sizeText(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024, 1) . ' KB';
    }
}
