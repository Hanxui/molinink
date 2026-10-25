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
 * 生成 SECRET_KEY(《61-安全基线清单》第 7 节)。
 *
 * 只打印、不写文件 —— 配置文件的写入归安装向导(《42》第 4 步),
 * 免得两处都能改 config.php 而互相覆盖。
 */
final class KeyGenerate extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('key:generate')
            ->setDescription('生成一枚新的 SECRET_KEY(不写文件)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $key = bin2hex(random_bytes(32));
        $replacing = (string) ($this->config()['secret_key'] ?? '') !== '';

        // 密钥操作必须审计(《26》第 7 节)。此处只记"生成过",绝不记密钥本身
        Audit::record('key.generate', 'secret_key' . ($replacing ? ' (替换旧值)' : ''));

        if ($this->isJson($input)) {
            $this->emitJson($output, ['key' => $key, 'replacing' => $replacing]);

            return ExitCode::OK;
        }

        $output->writeln($key);

        if ($replacing) {
            $output->writeln('<comment>注意:换密钥会让已加密的凭据与既有会话失效,请确认再写入 config.php。</comment>');
        }

        return ExitCode::OK;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) $this->container->get('config');
    }
}
