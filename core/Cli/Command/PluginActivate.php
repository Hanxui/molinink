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
use Molin\Plugin\Loader;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 启用插件(《58》第 1 节)。写序是先表后缓存(《21》第 9 节),且本请求内立即加载生效。
 */
final class PluginActivate extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('plugin:activate')
            ->setDescription('启用一个插件')
            ->addArgument('name', InputArgument::REQUIRED, '插件 name(= 目录名)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $plugin = $this->container->get(Loader::class)->activate($name);

        if ($this->isJson($input)) {
            $this->emitJson($output, ['name' => $name, 'active' => true, 'version' => $plugin->version()]);

            return ExitCode::OK;
        }

        $output->writeln('已启用 ' . $name . '(' . $plugin->version() . ')。');

        return ExitCode::OK;
    }
}
