<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Update;

/**
 * 升级源地址(既有规范的两条通道 → 两个源)。
 *
 * 程序内置官方源;管理员可以在设置里填自定义源(私有部署),留空就用内置的。
 * 通道只有两种:**正式版 stable** 与**开发版 dev** —— 2026-10-10 作者定,去掉预发版。
 */
final class Source
{
    /**
     * 官方源(同一台机器上分两条目录,"更新清单可由官方源或自定义源提供")。
     *
     * @var array<string, string>
     */
    public const OFFICIAL = [
        'stable' => 'https://update.molinink.com/stable',
        'dev' => 'https://update.molinink.com/dev',
    ];

    /** 内置信得过的几个主机(既有规范"仅允许 https;可配域名白名单") */
    public const OFFICIAL_HOSTS = ['update.molinink.com'];

    /** @return list<string> */
    public static function channels(): array
    {
        return array_keys(self::OFFICIAL);
    }

    public static function isChannel(string $channel): bool
    {
        return isset(self::OFFICIAL[$channel]);
    }

    /**
     * 这条通道实际用的源地址:设置里填了自定义源就优先用它。
     */
    public static function base(string $channel, string $custom = ''): string
    {
        $custom = trim($custom);

        if ($custom !== '') {
            return rtrim($custom, '/');
        }

        return self::OFFICIAL[$channel] ?? self::OFFICIAL['dev'];
    }

    public static function manifestUrl(string $base): string
    {
        return rtrim($base, '/') . '/manifest.json';
    }

    /** 只走 https。自定义源也不例外。 */
    public static function isHttps(string $url): bool
    {
        return strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? '')) === 'https';
    }

    /**
     * 只放行 https + 白名单主机。
     *
     * 这是防"清单被换成指向别处"的关键一道:清单里的 download 只能落在**清单自己的主机**
     * 或官方主机上,否则一个被动的清单就能把包指到别人的域名去。
     */
    public static function allows(string $url, string $manifestUrl): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if ($host === '' || $scheme !== 'https') {
            return false;
        }

        $manifestHost = strtolower((string) (parse_url($manifestUrl, PHP_URL_HOST) ?? ''));

        return $host === $manifestHost || in_array($host, self::OFFICIAL_HOSTS, true);
    }
}
