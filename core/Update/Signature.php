<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Update;

use Molin\Exception\MolinException;

/**
 * 升级清单的签名与验签(官方包签名,公钥内置;验签失败拒绝安装)。
 *
 * 为什么值得做:HTTPS 只保证"这一跳没被改",保证不了**源站自己被拿下**。
 * 私钥在发布侧、永不上服务器 —— 就算源站整个沦陷,对方也签不出一个能骗过站点的包。
 *
 * 算法 RSA-3072 / SHA-256:各版本 PHP 的 openssl 都稳(Ed25519 在实践中版本差异太大)。
 */
final class Signature
{
    private const ALGO = OPENSSL_ALGO_SHA256;

    private const PUBLIC_KEY_FILE = __DIR__ . '/public.pem';

    /**
     * 内置公钥。读不到就返回 null —— 验签随之失败,不开后门。
     */
    public static function publicKey(): ?string
    {
        $pem = is_file(self::PUBLIC_KEY_FILE) ? file_get_contents(self::PUBLIC_KEY_FILE) : false;

        return is_string($pem) && trim($pem) !== '' ? $pem : null;
    }

    /**
     * 验签。任何一步不对都回 false(缺公钥、签名不是 base64、openssl 说不对)。
     */
    public static function verify(string $payload, string $signature, ?string $publicKey = null): bool
    {
        $pem = $publicKey ?? self::publicKey();

        if ($pem === null || trim($signature) === '') {
            return false;
        }

        $raw = base64_decode(trim($signature), true);

        if (!is_string($raw) || $raw === '') {
            return false;
        }

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return false;
        }

        return openssl_verify($payload, $raw, $key, self::ALGO) === 1;
    }

    /**
     * 签名 —— **只在发布侧用**(打包脚本),程序运行期一次都不会调它。
     * 私钥不进仓库、不上服务器。
     */
    public static function sign(string $payload, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new MolinException('发布私钥读不出来:' . openssl_error_string());
        }

        if (!openssl_sign($payload, $signature, $key, self::ALGO)) {
            throw new MolinException('签名失败:' . openssl_error_string());
        }

        return base64_encode($signature);
    }
}
