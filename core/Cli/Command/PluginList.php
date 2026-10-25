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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 列插件(《58》第 1 节)。状态读的是 {p}plugins 表 —— 权威源(《21》第 9 节)。
 */
final class PluginList extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('plugin:list')
            ->setDescription('列出 plugins/ 下的插件与启用状态');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $loader = $this->container->get(Loader::class);
        $active = $loader->activeNames();
        $rows = [];

        foreach ($loader->available() as $name => $plugin) {
            $rows[] = [
                'name' => $name,
                'version' => $plugin->version(),
                'active' => in_array($name, $active, true),
                'loaded' => $plugin->isLoaded(),
            ];
        }

        foreach ($loader->broken() as $dir => $reason) {
            $rows[] = ['name' => (string) $dir, 'version' => '-', 'active' => false, 'loaded' => false, 'error' => $reason];
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, ['data' => $rows]);

            return ExitCode::OK;
        }

        if ($rows === []) {
            $output->writeln('plugins/ 里没有插件。');

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $state = $row['active'] ? '启用' : '停用';
            $output->writeln(sprintf('%-22s %-8s %s%s', $row['name'], $row['version'], $state, isset($row['error']) ? ' | 装不上:' . $row['error'] : ''));
        }

        return ExitCode::OK;
    }
}
