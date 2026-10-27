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
 * 停用插件。
 *
 * 已经注册进本请求的回调收不回(PHP 撤不掉闭包),所以"完全不再参与"从下一个请求开始 ——
 * 这点必须说给用户听,否则他会以为刷新一下就够了。
 */
final class PluginDeactivate extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('plugin:deactivate')
            ->setDescription('停用一个插件')
            ->addArgument('name', InputArgument::REQUIRED, '插件 name(= 目录名)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $this->container->get(Loader::class)->deactivate($name);

        if ($this->isJson($input)) {
            $this->emitJson($output, ['name' => $name, 'active' => false]);

            return ExitCode::OK;
        }

        $output->writeln('已停用 ' . $name . '(本请求已注册的回调收不回,从下一个请求起完全不再参与)。');

        return ExitCode::OK;
    }
}
