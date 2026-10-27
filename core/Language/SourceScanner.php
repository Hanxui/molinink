<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Language;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 源文案扫描器:把内核与后台里走过 __ / _e 的字符串抠出来,
 * 供 `molin i18n:extract` 产骨架、`molin i18n:check` 查缺译。
 *
 * 只认单引号字面量 —— 变量与表达式(__($x))扫不出来,那几个口子本来也不是给翻译用的。
 */
final class SourceScanner
{
    /** JS 界面词由 molinT()/molinTf() 出,没有域参数,一律归 molin */
    private const SCRIPT_PATTERN = "#(?<![\w$.])(?:molinT|molinTf)\(\s*'([^']+)'#";

    /**
     * @return list<string> 去重、按字符序
     */
    public static function scan(string $root): array
    {
        $found = [];

        foreach (self::scanByDomain($root) as $sources) {
            foreach ($sources as $source) {
                $found[$source] = true;
            }
        }

        $list = array_keys($found);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * 按域分组的源文案(第 2 参是域,缺省 molin)。
     *
     * 主题与插件各有各的语言包(i18n:check 要拿对包比),所以域必须分出来 ——
     * 域写成变量那种(`__($x, $domain)`)认不出来,一律按 molin 计。
     *
     * @return array<string, list<string>> 域 => 去重后的源文案(按字符序)
     */
    public static function scanByDomain(string $root): array
    {
        $found = [];

        foreach (self::files($root) as $file) {
            $text = (string) file_get_contents($file);

            $isScript = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'js';

            $hit = $isScript
                ? preg_match_all(self::SCRIPT_PATTERN, $text, $matches, PREG_SET_ORDER)
                : preg_match_all(
                    "#(?<![\w$])(?:__|_e)\(\s*'([^']+)'(?:\s*,\s*'([A-Za-z][A-Za-z0-9_-]{0,31})')?#",
                    $text,
                    $matches,
                    PREG_SET_ORDER
                );

            if ($hit === false || $hit === 0) {
                continue;
            }

            foreach ($matches as $one) {
                $domain = ($one[2] ?? '') === '' ? 'molin' : strtolower($one[2]);
                $found[$domain][$one[1]] = true;
            }
        }

        $out = [];

        foreach ($found as $domain => $set) {
            $list = array_keys($set);
            sort($list, SORT_STRING);
            $out[$domain] = $list;
        }

        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * 单个 JS 文件里的 molinT()/molinTf() 字面量。后台注入表靠它把 admin.js 的词自动收齐,
     * 省得再手抄一份、加词时两边漏同步。
     *
     * @return list<string> 去重、按字符序;文件不存在给空表
     */
    public static function scriptSources(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $hit = preg_match_all(
            self::SCRIPT_PATTERN,
            (string) file_get_contents($file),
            $matches,
            PREG_SET_ORDER
        );

        if ($hit === false || $hit === 0) {
            return [];
        }

        $found = [];

        foreach ($matches as $one) {
            $found[$one[1]] = true;
        }

        $list = array_keys($found);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * @return list<string>
     */
    private static function files(string $root): array
    {
        $files = [];

        foreach ([['/core', 'php'], ['/admin/templates', 'twig'], ['/admin/assets', 'js'], ['/themes', 'twig'], ['/plugins', 'php'], ['/plugins', 'twig']] as [$dir, $extension]) {
            $base = $root . $dir;

            if (!is_dir($base)) {
                continue;
            }

            $walker = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walker as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === $extension) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
