<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Update;

use Molin\Plugin\CapabilityGate;

/**
 * 升级源要的那一次 GET(《29》§5:只走 https、要能带 If-None-Match 拿 304)。
 *
 * 只做最薄的一件事:发出去、把状态码/正文/ETag 拿回来。判断与验签不在这里。
 * 证书照常校验 —— 为了"能连上"而跳过校验,等于把防掉包这件事自己拆了。
 *
 * 本类是 `http.outbound` 能力的拦截面(《62》第 6 节):内核的出网 GET 从这一处走,
 * 门卫放在发出去之前 —— 内核自身(无插件上下文)照常发,插件未声明即被拦下。
 */
final class Http
{
    /**
     * @return array{status: int, body: string, etag: string, error: string}
     */
    public static function get(string $url, ?string $ifNoneMatch = null, int $timeout = 8): array
    {
        CapabilityGate::assert('http.outbound');

        return extension_loaded('curl') && function_exists('curl_init')
            ? self::viaCurl($url, $ifNoneMatch, $timeout)
            : self::viaStream($url, $ifNoneMatch, $timeout);
    }

    /**
     * @return array{status: int, body: string, etag: string, error: string}
     */
    private static function viaCurl(string $url, ?string $ifNoneMatch, int $timeout): array
    {
        $etag = '';
        $handle = curl_init($url);

        if ($handle === false) {
            return self::fail('no_http');
        }

        // 压缩交给 libcurl 自己谈:设了 CURLOPT_ENCODING,它会带上 Accept-Encoding 并把响应**透明解压**。
        // 只手写 Accept-Encoding 而没设 CURLOPT_ENCODING,拿回来的是 gzip 原始字节,清单会当场解析不了 —— 2026-10-11 修。
        $headers = ['Accept: application/json'];

        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            $headers[] = 'If-None-Match: ' . $ifNoneMatch;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'MolinInk/' . \Molin\Kernel::VERSION,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$etag): int {
                if (stripos($line, 'etag:') === 0) {
                    $etag = trim(substr($line, strlen('etag:')));
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errno !== 0) {
            return self::fail(match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'timeout',
                CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST => 'unreachable',
                CURLE_SSL_CACERT, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CONNECT_ERROR => 'tls',
                default => 'failed',
            });
        }

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'etag' => $etag,
            'error' => '',
        ];
    }

    /**
     * 没装 curl 时的退路:allow_url_fopen 开了也能拿(《51》把 curl 列在推荐栏,不是必需)。
     *
     * @return array{status: int, body: string, etag: string, error: string}
     */
    private static function viaStream(string $url, ?string $ifNoneMatch, int $timeout): array
    {
        if (ini_get('allow_url_fopen') === '') {
            return self::fail('no_http');
        }

        $headers = "Accept: application/json\r\n";

        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            $headers .= 'If-None-Match: ' . $ifNoneMatch . "\r\n";
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => $headers,
        ]]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return self::fail('failed');
        }

        $status = 0;
        $etag = '';

        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (stripos($line, 'etag:') === 0) {
                $etag = trim(substr($line, strlen('etag:')));
            }
        }

        return ['status' => $status, 'body' => $body, 'etag' => $etag, 'error' => ''];
    }

    /**
     * @return array{status: int, body: string, etag: string, error: string}
     */
    private static function fail(string $reason): array
    {
        return ['status' => 0, 'body' => '', 'etag' => '', 'error' => $reason];
    }
}
