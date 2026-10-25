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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 导出主题设置(《36》第 8 节):`molin theme:export-settings --to=settings.json`。
 * 与后台「导出设置」走同一套读写(ThemeSettings::export)。
 */
final class ThemeExportSettings extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('theme:export-settings')
            ->setDescription('导出主题设置(JSON)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, '主题名,缺省为当前启用的主题')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, '写入文件,缺省打印到标准输出');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $theme = Theme::load($this->root() . '/themes', $this->slug($input));
        $json = $this->themeSettings()->export($theme);
        $to = trim((string) $input->getOption('to'));

        if ($to === '') {
            $output->writeln($json);

            return ExitCode::OK;
        }

        if ($this->isDryRun($input)) {
            $output->writeln('演练:将写入 ' . $to . '(' . strlen($json) . ' 字节)');

            return ExitCode::OK;
        }

        if (@file_put_contents($to, $json . "\n") === false) {
            $output->writeln('写不进去:' . $to);

            return ExitCode::GENERIC;
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, ['theme' => $theme->slug(), 'file' => $to, 'bytes' => strlen($json)]);

            return ExitCode::OK;
        }

        $output->writeln('已导出:' . $to . '(主题 ' . $theme->slug() . ')');

        return ExitCode::OK;
    }

    private function slug(InputInterface $input): string
    {
        $slug = trim((string) $input->getOption('theme'));

        return $slug === '' ? (string) $this->options()->get('active_theme', 'molin-default') : $slug;
    }
}
