<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Language;

use DateTimeInterface;
use IntlDateFormatter;
use Molin\Option\Options;
use NumberFormatter;
use Throwable;

/**
 * 界面文字翻译器(既有规范/§4)。
 *
 * 语言包是 JSON:键 = 源文案,值 = 译文。内核的源语言是简体中文(源文案就是中文),
 * 回退链:当前 locale → 语言变体(zh_Hant → zh)→ zh_CN → 原文 —— 缺词回落简体中文,
 * 和既有规范说的一致。域(domain)决定去哪找包:'molin' 走 core/Language,
 * 'theme' 走当前主题,其他名字按插件目录 plugins/{domain}/languages 找。
 */
final class Translator
{
    public const DEFAULT_LOCALE = 'zh_CN';

    /** 个人资料「跟随浏览器」的取值 */
    public const AUTO = 'auto';

    /** 个人资料「跟随站点」的取值:等同"没表过态",判定链往下走(2026-10-09 加) */
    public const SITE = 'site';

    private static ?self $instance = null;

    /** @var array<string, string> locale => 语言本名(界面下拉用) */
    private array $catalog;

    /** @var array<string, array<string, string>> "域|locale" => 语言包 */
    private array $packs = [];

    private string $locale = self::DEFAULT_LOCALE;

    /**
     * @param array<string, string> $catalog locale => 语言本名
     */
    public function __construct(
        private readonly string $root,
        array $catalog,
        private readonly ?Options $options = null
    ) {
        $clean = [];

        foreach ($catalog as $locale => $name) {
            if (is_string($locale) && preg_match('#\A[A-Za-z][A-Za-z0-9_]{0,15}\z#', $locale) === 1
                && is_string($name) && $name !== '') {
                $clean[$locale] = $name;
            }
        }

        $this->catalog = $clean;
    }

    public static function boot(self $translator): void
    {
        self::$instance = $translator;
    }

    /**
     * 还没人 boot 过(测试里手工装配、孤立的模板渲染)就按站根建一个默认实例 ——
     * 界面文字缺服务不该把页面打死,行情就是 zh_CN 原文。
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            $root = defined('MOLIN_ROOT') ? (string) constant('MOLIN_ROOT') : dirname(__DIR__, 2);
            self::$instance = new self($root, self::readCatalog($root));
        }

        return self::$instance;
    }

    /**
     * 读语言目录(locales.json);缺了只保底 zh_CN。
     *
     * @return array<string, string>
     */
    public static function readCatalog(string $root): array
    {
        $path = $root . '/core/Language/locales.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (is_array($decoded) && $decoded !== []) {
            /** @var array<string, string> $catalog */
            $catalog = $decoded;

            return $catalog;
        }

        return ['zh_CN' => '简体中文'];
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $this->normalize($locale) ?? self::DEFAULT_LOCALE;
    }

    /**
     * 把外部来的写法收成目录里的规范写法(zh-cn → zh_CN);不认识回 null。
     */
    public function normalize(string $value): ?string
    {
        $value = str_replace('-', '_', trim($value));

        if ($value === '') {
            return null;
        }

        foreach (array_keys($this->catalog) as $locale) {
            if (strcasecmp($locale, $value) === 0) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * 已安装语言包:locale => 本名(个人资料与设置页的「界面语言」下拉用它)。
     *
     * @return array<string, string>
     */
    public function available(): array
    {
        $out = [];

        foreach ($this->catalog as $locale => $name) {
            if (is_file($this->packPath('molin', $locale) ?? '')) {
                $out[$locale] = $name;
            }
        }

        return $out;
    }

    public function name(string $locale): string
    {
        return $this->catalog[$locale] ?? $locale;
    }

    /**
     * `<html lang>` 的取值:zh_CN → zh-Hans,zh_Hant → zh-Hant,en_US → en。
     */
    public function htmlLang(): string
    {
        $parts = explode('_', $this->locale);

        if ($parts[0] === 'zh') {
            return strcasecmp($parts[1] ?? '', 'Hant') === 0 ? 'zh-Hant' : 'zh-Hans';
        }

        return strtolower($parts[0]);
    }

    /**
     * locale 检测链:① 用户设置(「跟随浏览器」当场查 Accept-Language;
     * 「跟随站点」= 没表过态,继续往下)② 会话/请求开关 ③ 站点默认 ④ 浏览器(站级开关,默认关)。全不落地就 zh_CN。
     *
     * 站点默认与浏览器开关由调用方从 options 取好传进来 —— 这一层不碰数据库。
     */
    public function resolveLocale(
        ?string $userLocale,
        ?string $sessionLocale,
        string $acceptLanguage = '',
        string $siteLocale = '',
        bool $browserEnabled = false
    ): string {
        $userLocale = trim((string) $userLocale);

        if ($userLocale !== '') {
            if (strcasecmp($userLocale, self::AUTO) === 0) {
                $picked = $this->browserLocale($acceptLanguage);

                if ($picked !== null) {
                    return $picked;
                }
            } elseif (strcasecmp($userLocale, self::SITE) !== 0) {
                $canonical = $this->normalize($userLocale);

                if ($canonical !== null) {
                    return $canonical;
                }
            }
        }

        $sessionLocale = trim((string) $sessionLocale);

        if ($sessionLocale !== '') {
            $canonical = $this->normalize($sessionLocale);

            if ($canonical !== null) {
                return $canonical;
            }
        }

        $site = trim($siteLocale);

        if ($site !== '') {
            $canonical = $this->normalize($site);

            if ($canonical !== null) {
                return $canonical;
            }
        }

        if ($browserEnabled) {
            $picked = $this->browserLocale($acceptLanguage);

            if ($picked !== null) {
                return $picked;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    /**
     * 既有规范签名定死:第 2 参数永远是域,占位符实参从第 3 参起(走 sprintf)。
     */
    public function translate(string $text, string $domain = 'molin', mixed ...$args): string
    {
        if ($text === '') {
            return '';
        }

        foreach ($this->chain() as $locale) {
            $pack = $this->pack($domain, $locale);

            if (array_key_exists($text, $pack)) {
                $text = $pack[$text];

                break;
            }
        }

        if ($args !== []) {
            try {
                return sprintf($text, ...$args);
            } catch (Throwable) {
                // 占位符与实参对不上:退未格式化的译文,别把界面打死
                return $text;
            }
        }

        return $text;
    }

    /**
     * 翻译 + 转义,给 _e 与 Twig 用。
     */
    public function escape(string $text, string $domain = 'molin', mixed ...$args): string
    {
        return htmlspecialchars($this->translate($text, $domain, ...$args), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 日期本地化。intl 在就用 IntlDateFormatter;不在用内置模板,
     * 模板可由语言包以 `date:long` 这类键覆盖。
     */
    public function formatDate(mixed $value, string $style = 'long'): string
    {
        $stamp = self::timestamp($value);

        if ($stamp === null) {
            return '';
        }

        $style = in_array($style, ['short', 'medium', 'long', 'full', 'month'], true) ? $style : 'long';

        if (class_exists(IntlDateFormatter::class)) {
            // 'month' 只到月(「2026 年 3 月」):intl 的日期档次最粗也到日,只能走自定义图案
            $formatter = $style === 'month'
                ? IntlDateFormatter::create(
                    $this->locale,
                    IntlDateFormatter::NONE,
                    IntlDateFormatter::NONE,
                    null,
                    null,
                    str_starts_with($this->locale, 'zh') ? 'yyyy 年 M 月' : 'LLLL yyyy'
                )
                : IntlDateFormatter::create($this->locale, match ($style) {
                    'short' => IntlDateFormatter::SHORT,
                    'medium' => IntlDateFormatter::MEDIUM,
                    'full' => IntlDateFormatter::FULL,
                    default => IntlDateFormatter::LONG,
                }, IntlDateFormatter::NONE);

            if ($formatter !== null) {
                $out = $formatter->format($stamp);

                if (is_string($out) && $out !== '') {
                    return $out;
                }
            }
        }

        $template = $this->translate('date:' . $style);

        if ($template === 'date:' . $style) {
            $template = str_starts_with($this->locale, 'zh')
                ? match ($style) {
                    'short' => 'Y-m-d',
                    'month' => 'Y 年 n 月',
                    default => 'Y年n月j日',
                }
            : match ($style) {
                'short' => 'Y-m-d',
                'month' => 'F Y',
                'medium' => 'M j, Y',
                'full' => 'l, F j, Y',
                default => 'F j, Y',
            };
        }

        return date($template, $stamp);
    }

    /**
     * 数字本地化。
     */
    public function formatNumber(int|float $value, int $decimals = 0): string
    {
        $decimals = max(0, min(10, $decimals));

        if (class_exists(NumberFormatter::class)) {
            $formatter = NumberFormatter::create($this->locale, NumberFormatter::DECIMAL);

            if ($formatter !== null) {
                $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
                $out = $formatter->format($value);

                if (is_string($out)) {
                    return $out;
                }
            }
        }

        return number_format($value, $decimals);
    }

    /**
     * 某个域某个 locale 的原始语言包(i18n 检查工具用)。
     *
     * @return array<string, string>
     */
    public function rawPack(string $domain, string $locale): array
    {
        $canonical = $this->normalize($locale);

        return $canonical === null ? [] : $this->pack($domain, $canonical);
    }

    /**
     * @return list<string>
     */
    private function chain(): array
    {
        $chain = [$this->locale];
        $lang = explode('_', $this->locale)[0];

        if ($lang !== $this->locale) {
            $chain[] = $lang;
        }

        $chain[] = self::DEFAULT_LOCALE;

        return array_values(array_unique($chain));
    }

    /**
     * @return array<string, string>
     */
    private function pack(string $domain, string $locale): array
    {
        $key = $domain . '|' . $locale;

        if (array_key_exists($key, $this->packs)) {
            return $this->packs[$key];
        }

        $path = $this->packPath($domain, $locale);
        $pack = [];

        if ($path !== null && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                foreach ($decoded as $source => $translated) {
                    if (is_string($source) && is_string($translated)) {
                        $pack[$source] = $translated;
                    }
                }
            }
        }

        return $this->packs[$key] = $pack;
    }

    private function packPath(string $domain, string $locale): ?string
    {
        if (preg_match('#\A[a-z][a-z0-9_-]{0,31}\z#i', $domain) !== 1) {
            return null;
        }

        if (preg_match('#\A[A-Za-z][A-Za-z0-9_]{0,15}\z#', $locale) !== 1) {
            return null;
        }

        return match (true) {
            $domain === 'molin' => $this->root . '/core/Language/' . $locale . '.json',
            $domain === 'theme' => $this->root . '/themes/' . $this->activeTheme() . '/languages/' . $locale . '.json',
            default => $this->root . '/plugins/' . $domain . '/languages/' . $locale . '.json',
        };
    }

    private function activeTheme(): string
    {
        $slug = $this->options === null ? '' : (string) $this->options->get('active_theme', '');

        return preg_match('#\A[a-z0-9][a-z0-9-]{0,63}\z#i', $slug) === 1 ? $slug : 'molin-default';
    }

    /**
     * Accept-Language 里挑第一个装得上的语言(既有规范的 ④)。
     */
    private function browserLocale(string $acceptLanguage): ?string
    {
        $acceptLanguage = trim($acceptLanguage);

        if ($acceptLanguage === '') {
            return null;
        }

        $entries = [];

        foreach (explode(',', $acceptLanguage) as $index => $part) {
            $bits = explode(';', $part, 2);
            $tag = trim($bits[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $q = 1.0;

            if (isset($bits[1]) && preg_match('#q=([0-9.]+)#', $bits[1], $match) === 1) {
                $q = (float) $match[1];
            }

            $entries[] = ['tag' => $tag, 'q' => $q, 'order' => $index];
        }

        usort($entries, static function (array $a, array $b): int {
            $byQuality = $b['q'] <=> $a['q'];

            return $byQuality !== 0 ? $byQuality : $a['order'] <=> $b['order'];
        });

        foreach ($entries as $entry) {
            $canonical = $this->normalize($entry['tag']);

            if ($canonical !== null) {
                return $canonical;
            }

            $lang = strtolower(explode('_', str_replace('-', '_', $entry['tag']))[0]);

            foreach (array_keys($this->catalog) as $locale) {
                if (strtolower(explode('_', $locale)[0]) === $lang) {
                    return $locale;
                }
            }
        }

        return null;
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $stamp = strtotime($value);

            return $stamp === false ? null : $stamp;
        }

        return null;
    }
}
