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
use Molin\Exception\ValidationException;
use Molin\Language\SourceScanner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 待译骨架生成:扫描代码里的源文案,产出 `{"源文案": ""}` 的 JSON。
 *
 * --out 指向已存在的语言包时按"并入"处理:已有译文一条不动,只把新扫出来的补成空串 ——
 * 翻译者拿到的永远是最新且不丢旧活的包。
 */
final class I18nExtract extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('i18n:extract')
            ->setDescription('扫描代码产出待译 JSON 骨架')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, '写入这个文件;指向已有语言包时并入,不覆盖已有译文')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, '抽哪个域(__() 的第 2 参;主题用 theme,缺省 molin)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->root();
        $domain = strtolower(trim((string) $input->getOption('domain')));
        $domain = $domain === '' ? 'molin' : $domain;
        $sources = SourceScanner::scanByDomain($root)[$domain] ?? [];
        $out = $input->getOption('out');
        $out = is_string($out) && $out !== '' ? $out : null;

        $skeleton = [];
        $kept = 0;

        if ($out !== null && is_file($out)) {
            $decoded = json_decode((string) file_get_contents($out), true);

            if (!is_array($decoded)) {
                throw new ValidationException('这个文件不是合法的语言包 JSON:' . $out);
            }

            foreach ($decoded as $source => $translated) {
                if (is_string($source)) {
                    $skeleton[$source] = is_string($translated) ? $translated : '';
                    $kept++;
                }
            }
        }

        $added = 0;

        foreach ($sources as $source) {
            if (!array_key_exists($source, $skeleton)) {
                $skeleton[$source] = '';
                $added++;
            }
        }

        ksort($skeleton, SORT_STRING);
        $json = (string) json_encode(
            $skeleton,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";

        if ($out === null) {
            $output->write($json);
            $output->writeln(sprintf('# 源文案 %d 条,新补 %d 条(未落盘)', count($skeleton), $added));

            return ExitCode::OK;
        }

        if (@file_put_contents($out, $json) !== strlen($json)) {
            $output->writeln('写不进去:' . $out);

            return ExitCode::PERMISSION;
        }

        $output->writeln(sprintf('%s: 源文案 %d 条,保留 %d 条,新补 %d 条', $out, count($skeleton), $kept, $added));

        return ExitCode::OK;
    }
}
