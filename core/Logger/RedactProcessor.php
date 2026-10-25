<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Logger;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * 统一脱敏(《26-错误处理与日志规范》第 6 节:强制,不许各处手写)。
 *
 * 邮箱 → a***@b.com;IPv4/IPv6 → 掩掉末段。
 * 密码/token/密钥这类键整个删掉 —— 它们根本不该进日志。
 */
final class RedactProcessor implements ProcessorInterface
{
    /** 命中即整值替换,不给还原余地 */
    private const SCRUB_KEYS = ['password', 'passwd', 'secret', 'token', 'api_key', 'authorization', 'cookie', 'session_id'];

    public function __invoke(LogRecord $record): LogRecord
    {
        // Monolog 3 的 LogRecord 是只读的,只能克隆出新的
        return $record->with(
            message: $this->maskText($record->message),
            context: $this->walk($record->context),
            extra: []
        );
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private function walk(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SCRUB_KEYS, true)) {
                $values[$key] = '[已脱敏]';

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->walk($value);

                continue;
            }

            if (is_string($value)) {
                $values[$key] = $this->maskText($value);
            }
        }

        return $values;
    }

    private function maskText(string $text): string
    {
        $text = (string) preg_replace_callback(
            '#[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}#',
            fn (array $m): string => self::maskEmail($m[0]),
            $text
        );

        return (string) preg_replace_callback(
            '#\b(?:\d{1,3}\.){3}\d{1,3}\b|\b(?:[0-9a-fA-F]{1,4}:){3,7}[0-9a-fA-F]{1,4}\b#',
            static function (array $m): string {
                $ip = $m[0];

                return str_contains($ip, ':')
                    ? (preg_replace('#:[^:]+$#', ':*', $ip) ?? $ip)
                    : (preg_replace('#\.\d{1,3}$#', '.*', $ip) ?? $ip);
            },
            $text
        );
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1) . '***@' . $domain;
    }
}
