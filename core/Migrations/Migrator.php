<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Migrations;

use Molin\Database\Database;
use Molin\Exception\DatabaseException;
use Molin\Support\Str;

/**
 * 迁移执行器 —— 建表的唯一来源(约定:禁 schema.sql,首次安装与升级同一条路,)。
 *
 * 版本记在 options.db_version。
 * 迁移文件名 `{timestamp}_{name}.php` 由既有规范规定,类名取 Studly(name)+timestamp;
 * 该目录靠 composer classmap 载入,不走 PSR-4 的"文件名==类名"。
 */
final class Migrator
{
    private const VERSION_OPTION = 'db_version';

    public function __construct(private readonly Database $db, private readonly string $directory)
    {
    }

    /**
     * @return list<string> 全部迁移名,按时间升序
     */
    public function all(): array
    {
        $files = glob($this->directory . '/*.php');
        $names = [];

        foreach ($files === false ? [] : $files as $file) {
            $stem = basename($file, '.php');

            if (preg_match('#\A\d{8,14}_[a-z0-9_]+\z#', $stem) === 1) {
                $names[] = $stem;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * 当前结构版本;空串 = 空库,一个迁移都没跑。
     * 首次安装时 options 表还不存在(建表就是从空库跑迁移),所以先探表再读值。
     */
    public function version(): string
    {
        if (!$this->hasOptionsTable()) {
            return '';
        }

        $row = $this->db->selectOne(
            'SELECT option_value FROM ' . $this->db->table('options') . ' WHERE option_name = :name',
            ['name' => self::VERSION_OPTION]
        );

        if ($row === null) {
            return '';
        }

        return (string) $row['option_value'];
    }

    /**
     * @return list<string> 还没跑的迁移
     */
    public function pending(): array
    {
        $current = $this->version();

        if ($current === '') {
            return $this->all();
        }

        return array_values(array_filter($this->all(), static fn (string $name): bool => $name > $current));
    }

    /**
     * 逐条执行待跑迁移;每条成功后立刻记录版本,便于失败时知道跑到哪一条。
     *
     * @return list<string> 本次执行的迁移
     */
    public function migrate(): array
    {
        $done = [];

        foreach ($this->pending() as $name) {
            $this->load($name)->up();
            $this->setVersion($name);
            $done[] = $name;
        }

        return $done;
    }

    /**
     * 倒序回滚(必须支持回滚)。
     *
     * @return list<string> 本次回滚的迁移
     */
    public function rollback(int $steps = 1): array
    {
        $current = $this->version();

        if ($current === '') {
            return [];
        }

        $applied = array_values(array_filter($this->all(), static fn (string $name): bool => $name <= $current));
        $undone = [];
        $left = max(1, $steps);

        for ($i = count($applied) - 1; $i >= 0 && $left > 0; $i--, $left--) {
            $name = $applied[$i];
            $this->load($name)->down();
            $this->setVersion($applied[$i - 1] ?? '');
            $undone[] = $name;
        }

        return $undone;
    }

    private function setVersion(string $version): void
    {
        // 回滚到空库时 options 表已被 down() 删掉,没地方记,也不用记
        if (!$this->hasOptionsTable()) {
            return;
        }

        if ($version === '') {
            $this->db->delete('options', 'option_name = :name', ['name' => self::VERSION_OPTION]);

            return;
        }

        $row = $this->db->selectOne(
            'SELECT option_id FROM ' . $this->db->table('options') . ' WHERE option_name = :name',
            ['name' => self::VERSION_OPTION]
        );

        if ($row === null) {
            $this->db->insert('options', [
                'option_name'  => self::VERSION_OPTION,
                'option_value' => $version,
                'autoload'     => 0,
            ]);

            return;
        }

        $this->db->update(
            'options',
            ['option_value' => $version],
            'option_name = :name',
            ['name' => self::VERSION_OPTION]
        );
    }

    private function hasOptionsTable(): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name',
            ['name' => $this->db->table('options')]
        );

        if ($row === null) {
            return false;
        }

        return (int) $row['total'] > 0;
    }

    private function load(string $name): Migration
    {
        $class = 'Molin\\Migrations\\' . self::className($name);

        if (!class_exists($class)) {
            throw new DatabaseException("迁移类不存在: {$class}");
        }

        $object = new $class($this->db);

        if (!$object instanceof Migration) {
            throw new DatabaseException("迁移必须实现 Migration 接口: {$class}");
        }

        return $object;
    }

    private static function className(string $name): string
    {
        [$timestamp, $label] = explode('_', $name, 2);

        return Str::studly($label) . $timestamp;
    }
}
