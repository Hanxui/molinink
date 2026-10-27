<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Support;

use Molin\Plugin\CapabilityGate;

/**
 * 文件小工具。
 */
final class Files
{
    /** 删除重试次数与间隔:只在瞬时可重试的失败上多等一会儿,不是无底洞 */
    private const DELETE_TRIES = 5;

    private const DELETE_WAIT_US = 80_000;

    /**
     * 写文件。文件系统抽象层是 file.write 能力的拦截面:
     * 调用方是插件且未声明即被门卫拦下;内核自身(无插件上下文)照常写。
     */
    public static function write(string $path, string $contents): bool
    {
        CapabilityGate::assert('file.write');

        return file_put_contents($path, $contents) !== false;
    }

    /**
     * 删文件:不在了就算成功(幂等)。
     *
     * Windows 上刚写出的文件会被杀软 / 索引器短暂锁住,unlink 报
     * "Resource temporarily unavailable";退避重试几次即可。Linux 上第一次就成,
     * 循环不生效 —— 这层重试对生产环境是零成本。
     */
    public static function delete(string $path): bool
    {
        for ($try = 0; $try < self::DELETE_TRIES; $try++) {
            clearstatcache(true, $path);

            if (self::gone($path)) {
                return true;
            }

            if (@unlink($path)) {
                return true;
            }

            usleep(self::DELETE_WAIT_US);
        }

        return self::gone($path);
    }

    /**
     * 递归删目录:文件走 delete()(自带退避重试),最后 rmdir。
     * 符号链接不跟进去,只删链接本身 —— 免得顺着链接删到目录外面去。
     *
     * 传进来是个文件(或者本来就不在)时,退回 delete() 的语义:删掉/当作已删,都算成功。
     */
    public static function deleteTree(string $path): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return self::delete($path);
        }

        $items = scandir($path);

        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . '/' . $item;

            if (is_dir($child) && !is_link($child)) {
                self::deleteTree($child);
            } else {
                self::delete($child);
            }
        }

        // rmdir 同样会被杀软 / 索引器的短锁挡住(现场表现:PHP 警告 "Directory not empty",
        // 目录其实已经空了)—— 退避重试几次,最后仍按 gone() 判定,与 delete() 一个口径
        for ($try = 0; $try < self::DELETE_TRIES; $try++) {
            clearstatcache(true, $path);

            if (@rmdir($path)) {
                break;
            }

            if (self::gone($path)) {
                break;
            }

            usleep(self::DELETE_WAIT_US);
        }

        return self::gone($path);
    }

    /** 已经不在了(文件或目录都算)。隔一层问,免得被类型收窄当成恒假 */
    private static function gone(string $path): bool
    {
        clearstatcache(true, $path);

        return !file_exists($path) && !is_link($path);
    }
}
