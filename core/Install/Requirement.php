<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Install;

/**
 * 一条环境检查结果。state=fail 会中止安装,warn 只提示。
 */
final class Requirement
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $state,
        public readonly string $detail = '',
        public readonly string $hint = ''
    ) {
    }

    public static function ok(string $key, string $label, string $detail = ''): self
    {
        return new self($key, $label, self::OK, $detail);
    }

    public static function warn(string $key, string $label, string $detail = '', string $hint = ''): self
    {
        return new self($key, $label, self::WARN, $detail, $hint);
    }

    public static function fail(string $key, string $label, string $detail = '', string $hint = ''): self
    {
        return new self($key, $label, self::FAIL, $detail, $hint);
    }

    public function isBlocker(): bool
    {
        return $this->state === self::FAIL;
    }
}
