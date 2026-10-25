<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Site;

use Molin\Option\Options;

/**
 * 爬虫规则(《23》§4:`/robots.txt`)。
 *
 * 口径(2026-10-05 自定,记录在案):对外可见时只挡后台(`/admin/`)与站内搜索页(`/search`),
 * 并给出站点地图地址;`blog_public=0`(安装向导的"同时搜索引擎可见"未勾,《125》§6.3)时整站谢客。
 * 站点没配地址时省略 Sitemap 行 —— 协议要求绝对地址,宁缺毋滥。
 */
final class Robots
{
    public function __construct(private readonly Options $options)
    {
    }

    public function text(bool $public): string
    {
        if (!$public) {
            return "User-agent: *\nDisallow: /\n";
        }

        $text = "User-agent: *\nDisallow: /admin/\nDisallow: /search\n";
        $base = rtrim((string) $this->options->get('site_url', ''), '/');

        if ($base !== '') {
            $text .= "\nSitemap: " . $base . "/sitemap.xml\n";
        }

        return $text;
    }
}
