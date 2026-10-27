<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Logger;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;

/**
 * 多通道日志。
 *
 * 用法照文档:`Log::channel('error')->error(...)`。按天切分、留 30 天;
 * 审计通道单独存放且不脱敏(IP 要留全),常规通道一律过 RedactProcessor。
 */
final class Log
{
    public const CHANNELS = ['app', 'error', 'security', 'plugin', 'db', 'audit', 'cron'];

    /** @var array<string, Monolog> */
    private static array $channels = [];

    public static function channel(string $name = 'app'): Monolog
    {
        if (!in_array($name, self::CHANNELS, true)) {
            $name = 'app';
        }

        return self::$channels[$name] ??= self::build($name);
    }

    /**
     * 丢掉已建的 handler。测试与改配置后用。
     */
    public static function reset(): void
    {
        self::$channels = [];
    }

    private static function build(string $name): Monolog
    {
        // 日志根可被环境变量重定向:测试进程与线上服务共用一棵目录时,
        // Windows 会因文件锁互斥(既有规范没规定路径只许一处)
        $env = getenv('MOLIN_LOG_ROOT');
        $root = is_string($env) && $env !== '' ? $env : MOLIN_ROOT . '/storage/logs';

        if (!is_dir($root)) {
            mkdir($root, 0o755, true);
        }

        $level = self::minLevel();
        $logger = new Monolog($name);

        if ($name === 'audit') {
            $auditDir = $root . '/audit';

            if (!is_dir($auditDir)) {
                mkdir($auditDir, 0o755, true);
            }

            // audit/audit-2026-09.log,按月且不随常规清理
            $logger->pushHandler(self::withFormat(
                new StreamHandler($auditDir . '/audit-' . date('Y-m') . '.log', $level, true)
            ));

            return $logger;
        }

        $logger->pushHandler(self::withFormat(new RotatingFileHandler($root . '/' . $name . '.log', 30, $level, true, null, true)));
        $logger->pushProcessor(new RedactProcessor());

        return $logger;
    }

    /**
     * 记录格式的示例:[2026-09-28 16:33:12] app.INFO: 正文 {"k":"v"} []
     */
    private static function withFormat(RotatingFileHandler|StreamHandler $handler): RotatingFileHandler|StreamHandler
    {
        $handler->setFormatter(new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s'
        ));

        return $handler;
    }

    private static function minLevel(): Level
    {
        // 开发 DEBUG 起全记,生产 WARNING 起
        return APP_DEBUG ? Level::Debug : Level::Warning;
    }
}
