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
use Molin\Kernel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 产品版本与内核契约版本(《56》第 1 节:两者各自演进,不能混报)。
 */
final class CoreVersion extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('core:version')
            ->setDescription('显示墨林产品版本与内核契约版本');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        if ($this->isJson($input)) {
            $this->emitJson($output, ['product' => Kernel::VERSION, 'contract' => MOLIN_CONTRACT]);

            return ExitCode::OK;
        }

        $output->writeln('墨林 ' . Kernel::VERSION . '(契约 ' . MOLIN_CONTRACT . ')');

        return ExitCode::OK;
    }
}
