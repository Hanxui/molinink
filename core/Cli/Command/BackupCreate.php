<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli\Command;

use Molin\Backup\Backup;
use Molin\Backup\Remote;
use Molin\Cli\BaseCommand;
use Molin\Cli\ExitCode;
use Molin\Kernel;
use Molin\Logger\Audit;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * 创建备份(既有规范的 backup:create)。
 *
 * 加密走提示或环境变量(密码不写进 shell 历史);远端推送需要
 * `storage/backups/remote.json` 里的凭证,配置口径见 Remote 类。
 */
final class BackupCreate extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('backup:create')
            ->setDescription('创建一份全量备份(可选加密与推送远端;后台另有 200 MB 上限,大库请走本命令)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, '备份类型(目前仅 full)', 'full')
            ->addOption('encrypt', null, InputOption::VALUE_NONE, '整包加密(AES-256);密码走提示或 MOLIN_BACKUP_PASSWORD')
            ->addOption('remote', null, InputOption::VALUE_REQUIRED, '推送远端:s3 / webdav / ftp(凭证在 storage/backups/remote.json)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getOption('type');

        if ($type !== 'full') {
            // 增量 / 仅DB / 仅文件还没落地 —— 说清楚,别装作支持
            $output->writeln('目前只支持 --type=full;增量 / 仅数据库 / 仅文件还没落地。');

            return ExitCode::USAGE;
        }

        $password = null;

        if ($input->getOption('encrypt') === true) {
            $password = $this->askSecret($input, $output, '加密密码', 'MOLIN_BACKUP_PASSWORD');

            if ($password === '') {
                $output->writeln('加了 --encrypt 就要有密码:交互式会提示输入,脚本请用环境变量 MOLIN_BACKUP_PASSWORD。');

                return ExitCode::USAGE;
            }
        }

        try {
            $backup = $this->service(Backup::class);
            $created = $backup->create(Kernel::VERSION, $type, $password);
            Audit::record('backup.create', $created['name'], true, null, 'cli');

            $output->writeln('已创建 ' . $created['name'] . '(' . $this->sizeText((int) $created['size'])
                . ($created['encrypted'] ? ' · 已加密' : '') . ')');

            $remote = (string) $input->getOption('remote');

            if ($remote !== '') {
                $path = $backup->path($created['name']);

                if ($path === null) {
                    throw new RuntimeException('备份文件不见了:' . $created['name']);
                }

                $output->writeln('已推送到 ' . (new Remote($this->root()))->push($remote, $path, $created['name']));
            }
        } catch (Throwable $e) {
            $output->writeln('备份失败:' . $e->getMessage());

            return ExitCode::GENERIC;
        }

        return ExitCode::OK;
    }

    private function sizeText(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024, 1) . ' KB';
    }
}
