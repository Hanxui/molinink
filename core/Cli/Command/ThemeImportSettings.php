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
use Molin\Theme\Theme;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 导入主题设置(《36》第 8 节):`molin theme:import-settings settings.json`。
 * 先按声明校验,通过才落库;失败逐条打印字段级错误,整体不写。
 */
final class ThemeImportSettings extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('theme:import-settings')
            ->setDescription('导入主题设置(JSON)')
            ->addArgument('file', InputArgument::REQUIRED, 'JSON 文件路径')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, '主题名,缺省为当前启用的主题');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');

        if (!is_file($file)) {
            $output->writeln('文件不存在:' . $file);

            return ExitCode::USAGE;
        }

        $slug = trim((string) $input->getOption('theme'));
        $slug = $slug === '' ? (string) $this->options()->get('active_theme', 'molin-default') : $slug;
        $theme = Theme::load($this->root() . '/themes', $slug);
        $settings = $this->themeSettings();

        if ($this->isDryRun($input)) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (!is_array($decoded)) {
                $output->writeln('不是合法的 JSON 文件。');

                return ExitCode::USAGE;
            }

            $output->writeln('演练:文件可读且是 JSON,目标主题 ' . $theme->slug() . ',未写库。');

            return ExitCode::OK;
        }

        $result = $settings->import($theme, (string) file_get_contents($file));

        if (!$result['ok']) {
            foreach ($result['errors'] as $field => $message) {
                $output->writeln($field . ':' . $message);
            }

            return ExitCode::USAGE;
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, ['theme' => $theme->slug(), 'file' => $file]);

            return ExitCode::OK;
        }

        $output->writeln('已导入:' . $file . '(主题 ' . $theme->slug() . ')');

        return ExitCode::OK;
    }
}
