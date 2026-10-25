<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

/**
 * 迁移契约(《29-内核升级机制》第 6 节):每个迁移都必须支持回滚。
 */
interface Migration
{
    public function up(): void;

    public function down(): void;
}
