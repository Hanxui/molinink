<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli\Command;

use Molin\Backup\Backup;
use Molin\Backup\Cipher;
use Molin\Backup\Restore;
use Molin\Cli\BaseCommand;
use Molin\Cli\ExitCode;
use Molin\Kernel;
use Molin\Logger\Audit;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * 恢复备份(《35》§5)。恢复前自动给当前状态留一份(命令行与网页同一条链)。
 * 加密包需要解密密码:交互式提示,脚本走环境变量 MOLIN_BACKUP_PASSWORD。
 */
final class BackupRestore extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('backup:restore')
            ->setDescription('用一份备份覆盖当前站点(自动先备份现状)')
            ->addArgument('name', InputArgument::REQUIRED, '备份文件名')
            ->addOption('yes', null, InputOption::VALUE_NONE, '跳过确认(脚本用)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');

        if (preg_match(Backup::FILENAME, $name) !== 1) {
            $output->writeln('备份文件名不对。');

            return ExitCode::USAGE;
        }

        $confirmed = $input->getOption('yes') === true;

        if (!$confirmed) {
            $output->writeln('这会用备份覆盖当前站点。确认请加 --yes。');

            return ExitCode::USAGE;
        }

        try {
            $backup = $this->service(Backup::class);
            $path = $backup->path($name);
            $password = null;

            // 加密包先要密码(《35》§4 ⑥):交互式提示,脚本走环境变量
            if ($path !== null && Cipher::isEncryptedFile($path)) {
                $password = $this->askSecret($input, $output, '解密密码', 'MOLIN_BACKUP_PASSWORD');

                if ($password === '') {
                    $output->writeln('这是加密备份:交互式会提示输入密码,脚本请用环境变量 MOLIN_BACKUP_PASSWORD。');

                    return ExitCode::USAGE;
                }
            }

            $restore = new Restore($backup, $this->database(), MOLIN_ROOT);

            foreach ($restore->restore($name, Kernel::VERSION, $password) as $step) {
                $output->writeln('· ' . $step);
            }

            Audit::record('backup.restore', $name, true, null, 'cli');
        } catch (Throwable $e) {
            Audit::record('backup.restore', $name, false, null, 'cli');
            $output->writeln('恢复失败:' . $e->getMessage());

            return ExitCode::GENERIC;
        }

        $output->writeln('恢复完成。建议清一遍浏览器缓存再访问后台。');

        return ExitCode::OK;
    }
}
