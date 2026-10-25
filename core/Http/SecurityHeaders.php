<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Http;

/**
 * 安全响应头(《61-安全基线清单》第 4.4–4.7 节)。
 *
 * CSP 默认**不发**:文档只说"可配",没给指令串,而现有界面确实用内联样式 ——
 * 贸然发 `style-src 'self'` 会把整站样式打回裸 HTML。留成站点配置项,由作者定串再生效。
 */
final class SecurityHeaders
{
    /**
     * @param array<string, string> $headers 已有的响应头
     * @return array<string, string>
     */
    public static function apply(array $headers, bool $https, string $csp = ''): array
    {
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options'] = 'SAMEORIGIN';
        $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';

        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        if ($https) {
            // 只在 HTTPS 下发 HSTS:HTTP 环境发了会把站点自己锁死
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * Cookie 三标志(《61》第 2.4 节):HttpOnly + Secure + SameSite=Lax。
     */
    public static function cookie(string $name, string $value, int $maxAge, bool $https): string
    {
        $parts = [
            $name . '=' . rawurlencode($value),
            'Path=/',
            'Max-Age=' . max(0, $maxAge),
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($https) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
