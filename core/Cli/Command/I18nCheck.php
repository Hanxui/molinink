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
use Molin\Language\SourceScanner;
use Molin\Language\Translator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 缺译检测:扫描代码里的源文案,列出语言包里还缺的条目。
 * 查缺就是非零退出(GENERIC),方便接 CI;全齐才回 0。
 */
final class I18nCheck extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('i18n:check')
            ->setDescription('列出语言包里还缺的条目')
            ->addArgument('locale', InputArgument::OPTIONAL, '只查一种语言(默认查全部非源语言)')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, '只查这个域(主题用 theme,缺省查全部域)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->root();
        $translator = new Translator($root, Translator::readCatalog($root));
        $available = $translator->available();
        $sources = SourceScanner::scanByDomain($root);
        $totalSources = 0;

        // --domain 只查一个域(取值同 i18n:extract:molin / theme / 插件名);不传维持原样(全部域)
        $domainFilter = strtolower(trim((string) $input->getOption('domain')));

        if ($domainFilter !== '') {
            if (!isset($sources[$domainFilter])) {
                $output->writeln('没有这个域:' . $domainFilter . '(可用:molin / theme / 插件名)');

                return ExitCode::USAGE;
            }

            $sources = [$domainFilter => $sources[$domainFilter]];
        }

        /** @var list<string> $flat 本域(或全部域)的源文案 */
        $flat = $sources === [] ? [] : array_merge(...array_values($sources));

        foreach ($sources as $list) {
            $totalSources += count($list);
        }

        $argument = (string) $input->getArgument('locale');
        $locales = [];

        if ($argument !== '') {
            $canonical = $translator->normalize($argument);

            if ($canonical === null) {
                $output->writeln('语言目录里没有这种写法:' . $argument);

                return ExitCode::USAGE;
            }

            $locales = [$canonical];
        } else {
            $locales = array_values(array_filter(
                array_keys(Translator::readCatalog($root)),
                static fn (string $locale): bool => $locale !== Translator::DEFAULT_LOCALE
            ));
        }

        $report = [];
        $missingTotal = 0;

        foreach ($locales as $locale) {
            if (!isset($available[$locale])) {
                $report[$locale] = ['installed' => false, 'sources' => $totalSources, 'translated' => 0, 'missing' => $flat];
                $missingTotal += $totalSources;

                continue;
            }

            // 每个域比各自的包:molin 走 core/Language,theme 走当前主题,其余按插件目录
            $row = ['installed' => true, 'sources' => 0, 'translated' => 0, 'missing' => [], 'domains' => []];
            $domains = [];

            foreach ($sources as $domain => $list) {
                $missing = array_values(array_diff($list, array_keys($translator->rawPack($domain, $locale))));
                $entry = [
                    'sources' => count($list),
                    'translated' => count($list) - count($missing),
                    'missing' => $missing,
                ];
                $missingTotal += count($missing);

                if ($domain === 'molin') {
                    $row = $entry + ['installed' => true, 'domains' => []];
                } else {
                    $domains[$domain] = $entry;
                }
            }

            $row['domains'] = $domains;
            $report[$locale] = $row;
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, $report);

            return $missingTotal === 0 ? ExitCode::OK : ExitCode::GENERIC;
        }

        foreach ($report as $locale => $row) {
            if (!$row['installed']) {
                $output->writeln(sprintf('%s: 语言包还没装(缺全部 %d 条)', $locale, $row['sources']));

                continue;
            }

            $output->writeln(sprintf(
                '%s: 已译 %d / 源 %d%s',
                $locale,
                $row['translated'],
                $row['sources'],
                $row['missing'] === [] ? '(齐)' : ''
            ));

            foreach ($row['missing'] as $text) {
                $output->writeln('  缺  ' . $text);
            }

            foreach ($row['domains'] as $domain => $sub) {
                $output->writeln(sprintf(
                    '%s · %s: 已译 %d / 源 %d%s',
                    $locale,
                    $domain,
                    $sub['translated'],
                    $sub['sources'],
                    $sub['missing'] === [] ? '(齐)' : ''
                ));

                foreach ($sub['missing'] as $text) {
                    $output->writeln('  缺  [' . $domain . '] ' . $text);
                }
            }
        }

        return $missingTotal === 0 ? ExitCode::OK : ExitCode::GENERIC;
    }
}
