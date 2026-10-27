<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

use Molin\Database\Database;

/**
 * 迁移基类:把数据层与表前缀递给具体迁移。命名规范。
 */
abstract class AbstractMigration implements Migration
{
    public function __construct(protected readonly Database $db)
    {
    }

    /**
     * 表前缀,用于把既有规范里的 `{p}` 落成真实表名。
     */
    protected function prefix(): string
    {
        return $this->db->prefix();
    }

    protected function execute(string $sql): void
    {
        $this->db->execute($sql);
    }

    /**
     * 建表排序规则,由服务器能力决定(Migrator 之外也走这里,插件迁移同样适用)。
     */
    protected function collation(): string
    {
        return $this->db->collation();
    }
}
