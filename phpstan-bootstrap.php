<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 *
 * 静态分析用的最小引导：只定义内核在运行时由入口脚本定义的常量。
 */

declare(strict_types=1);

define('MOLIN_ROOT', __DIR__);
define('MOLIN_CONTRACT', '1.0');
define('APP_DEBUG', true);