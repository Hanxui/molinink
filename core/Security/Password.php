<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Security;

/**
 * 口令哈希(《32》第 9 节、《61》第 2.1 节)。
 *
 * 全内核只有这一个出口 —— 安装、改密、登录重哈希都走这里，
 * 免得某处偷偷用 md5 或忘了参数。
 */
final class Password
{
    /** memory_cost=65536, time_cost=4, threads=1（并行度 >1 在个别 libargon2 上直接失败,取 1 最稳） */
    private const ARGON2_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    public static function hash(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = self::argon2($plain);

            // 个别 libargon2 会因参数不被支持而失败,这时退到 bcrypt,不能让空值落库
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * 按 mixed 返回:password_hash 在部分 libargon2 上会失败回空值,
     * 直接内联会让静态分析把它当成必然成功。
     *
     * @return mixed
     */
    private static function argon2(string $plain): mixed
    {
        return password_hash($plain, PASSWORD_ARGON2ID, self::ARGON2_OPTIONS);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    /**
     * 算法或参数升级后，登录成功的那一刻顺手重哈希(《61》第 2.1 节)。
     */
    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID') && password_needs_rehash($hash, PASSWORD_ARGON2ID, self::ARGON2_OPTIONS);
    }

    /**
     * 强度口径:《42》第 4 节只写"≥8 位,强度检查",这里定为"至少 8 位 + 字母与数字两类"。
     * 不做复杂度军备竞赛 —— 真正的防线是登录限速与 argon2id 成本参数。
     *
     * @return list<string> 不合格的原因,空数组即通过
     */
    public static function checkStrength(string $plain): array
    {
        $errors = [];

        if (strlen($plain) < 8) {
            $errors[] = '至少 8 位';
        }

        if (preg_match('/[A-Za-z]/', $plain) !== 1 || preg_match('/\d/', $plain) !== 1) {
            $errors[] = '要同时含字母和数字';
        }

        return $errors;
    }
}
