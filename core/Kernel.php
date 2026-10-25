<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin;

/**
 * 产品版本的唯一来源(《56-版本号策略》第 1 节:产品版本与内核契约版本各自演进)。
 * 升版只改这里 —— index.php 的 MOLIN_CONTRACT 是契约版本,不要混。
 */
final class Kernel
{
    public const VERSION = '0.1.12';
}
