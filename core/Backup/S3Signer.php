<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Backup;

/**
 * AWS Signature V4 签名(既有规范⑦:S3 兼容对象存储)。
 *
 * 只做签名这一步、不碰网络:密钥推导与规范化请求全部是纯函数,
 * 拿官方文档的那组测试向量就能钉死(见单元测试)。S3 的 PUT 直接用。
 */
final class S3Signer
{
    /**
     * @param array<string, string> $headers 参与签名的头(必须含 host、x-amz-date;S3 还要 x-amz-content-sha256)
     * @param array<string, string> $query   查询参数(S3 PUT 传空数组)
     * @return array<string, string> 调用方原样带上的头(含 Authorization)
     */
    public static function sign(
        string $method,
        string $path,
        array $query,
        array $headers,
        string $payloadHash,
        string $service,
        string $region,
        string $accessKey,
        string $secretKey,
        string $amzDate
    ): array {
        $lower = [];

        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }

        ksort($lower);
        $canonicalHeaders = '';
        $signedNames = [];

        foreach ($lower as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
            $signedNames[] = $name;
        }

        $signedHeaders = implode(';', $signedNames);

        $canonicalRequest = $method . "\n"
            . self::encodePath($path) . "\n"
            . self::encodeQuery($query) . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $date = substr($amzDate, 0, 8);
        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac(
            'sha256',
            $service,
            hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4' . $secretKey, true), true),
            true
        ), true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return $headers;
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $path)
        ));
    }

    /**
     * @param array<string, string> $query
     */
    private static function encodeQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }

        ksort($query);
        $pairs = [];

        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }
}
