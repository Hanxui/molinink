<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Backup;

use RuntimeException;

/**
 * 备份包加密容器(《35》§4 ⑥:可选 AES-256,密码由用户设)。
 *
 * 自订一个最小容器而不是 tar 里塞密文:加密后的文件要能被整包识别、整包解密,
 * 容器头带 KDF 参数,日后换算法也能读得回来。分块 AEAD(AES-256-GCM):
 * 每块独立认证,内存里任何时候只驻留一块;块序写进 nonce,打乱或截断都会认证失败。
 *
 * 头之后是二进制,布局:每块 = 4 字节大端密文长度 + 密文 + 16 字节认证标签。
 */
final class Cipher
{
    public const MAGIC = "MOLINENC1\n";

    private const CHUNK = 1048576;

    private const ITERATIONS = 210000;

    /**
     * 只看文件头:MAGIC 齐全才算加密包。列表页的「已加密」标签走这一个口。
     */
    public static function isEncryptedFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $head === self::MAGIC;
    }

    public static function seal(string $plain, string $password): string
    {
        $salt = random_bytes(16);
        $noncePrefix = random_bytes(4);
        $key = self::key($password, $salt);

        $header = (string) json_encode([
            'algo' => 'aes-256-gcm',
            'kdf' => 'pbkdf2-sha256',
            'iterations' => self::ITERATIONS,
            'salt' => base64_encode($salt),
            'chunk' => self::CHUNK,
            'nonce' => base64_encode($noncePrefix),
        ], JSON_UNESCAPED_SLASHES);

        $out = self::MAGIC . $header . "\n";
        $index = 0;

        for ($offset = 0, $length = strlen($plain); $offset < $length; $offset += self::CHUNK, $index++) {
            $chunk = substr($plain, $offset, self::CHUNK);
            $tag = '';
            $sealed = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($noncePrefix, $index), $tag, '', 16);

            if ($sealed === false) {
                throw new RuntimeException('加密失败:openssl 拒绝了这个包');
            }

            $out .= pack('N', strlen($sealed)) . $sealed . $tag;
        }

        return $out;
    }

    public static function open(string $sealed, string $password): string
    {
        try {
            return self::read($sealed, $password);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            // 容器被剪过 / 头不是 JSON 之类,统一按"解不开"回报,不把内部细节漏出去
            throw new RuntimeException('解密失败:密码不对,或备份包已损坏');
        }
    }

    private static function read(string $sealed, string $password): string
    {
        if (!str_starts_with($sealed, self::MAGIC)) {
            throw new RuntimeException('这不是墨林的加密备份包');
        }

        $end = strpos($sealed, "\n", strlen(self::MAGIC));

        if ($end === false) {
            throw new RuntimeException('解密失败:密码不对,或备份包已损坏');
        }

        $header = json_decode(substr($sealed, strlen(self::MAGIC), $end - strlen(self::MAGIC)), true);

        if (!is_array($header) || ($header['algo'] ?? '') !== 'aes-256-gcm' || ($header['kdf'] ?? '') !== 'pbkdf2-sha256') {
            throw new RuntimeException('加密容器头无法识别');
        }

        $iterations = (int) ($header['iterations'] ?? 0);
        $chunkSize = (int) ($header['chunk'] ?? 0);
        $salt = base64_decode((string) ($header['salt'] ?? ''), true);
        $noncePrefix = base64_decode((string) ($header['nonce'] ?? ''), true);

        // 上限防御:头是外部输入,不许让它逼我们用超长时间或超大块去"解密"
        if ($iterations < 1000 || $iterations > 1000000 || $chunkSize < 1024 || $chunkSize > 16 * self::CHUNK) {
            throw new RuntimeException('加密容器参数超出允许范围');
        }

        if (!is_string($salt) || strlen($salt) !== 16 || !is_string($noncePrefix) || strlen($noncePrefix) !== 4) {
            throw new RuntimeException('加密容器头无法识别');
        }

        $key = self::key($password, $salt, $iterations);
        $body = substr($sealed, $end + 1);
        $out = '';
        $offset = 0;
        $index = 0;

        while ($offset < strlen($body)) {
            if ($offset + 4 > strlen($body)) {
                throw new RuntimeException('解密失败:密码不对,或备份包已损坏');
            }

            $size = unpack('N', substr($body, $offset, 4));
            $size = is_array($size) ? (int) $size[1] : -1;
            $offset += 4;

            if ($size < 0 || $offset + $size + 16 > strlen($body)) {
                throw new RuntimeException('解密失败:密码不对,或备份包已损坏');
            }

            $plain = openssl_decrypt(
                substr($body, $offset, $size),
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                self::nonce($noncePrefix, $index),
                substr($body, $offset + $size, 16)
            );

            if ($plain === false) {
                throw new RuntimeException('解密失败:密码不对,或备份包已损坏');
            }

            $out .= $plain;
            $offset += $size + 16;
            $index++;
        }

        if ($index === 0) {
            throw new RuntimeException('解密失败:密码不对,或备份包已损坏');
        }

        return $out;
    }

    /**
     * @param int<1, max> $iterations
     */
    private static function key(string $password, string $salt, int $iterations = self::ITERATIONS): string
    {
        return hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    }

    private static function nonce(string $prefix, int $index): string
    {
        return $prefix . pack('J', $index);
    }
}
