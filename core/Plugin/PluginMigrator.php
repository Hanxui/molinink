<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Database\Database;
use Molin\Exception\PluginLoadException;
use Molin\Migrations\Migration;
use Molin\Support\Str;
use Throwable;

/**
 * 插件数据表迁移的执行器(《12》§7.3)。
 *
 * 迁移文件的形状与内核 core/Migrations/ 一模一样:一个实现 Molin\Migrations\Migration 的类
 * (通常继承 AbstractMigration,拿 prefix() 与 execute()),类名由文件名按内核同一套规则推出来
 * —— 插件作者只学一套。唯一的差别在加载:插件 migrations/ 不进 classmap / PSR-4,所以先
 * require_once 文件,再按推出来的类名实例化。
 *
 * 「跑过没有」记在 options 表的 plugin_migrations.{插件名},仿内核把 db_version 记进 options 的做法;
 * 键名带下划线,与插件自己的设置键(plugin.{name}.*)不在一族,插件盖不着。
 *
 * MySQL 的 DDL 会隐式提交,事务根本包不住建表 —— 这不是取舍,是 MySQL 的规矩。所以 up 中途失败
 * 靠「倒序补偿回退」(对已建的表执行 down + DROP IF EXISTS)收拾,事务只用来兜住记录写入这类纯 DML。
 */
final class PluginMigrator
{
    /** 记录已跑迁移的 option 键前缀 */
    private const RECORD_PREFIX = 'plugin_migrations.';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 启用:按 tables 声明顺序补跑未记录过的 up。任一步失败即整体回退,并拒绝启用。
     */
    public function enable(Plugin $plugin): void
    {
        $this->run($plugin, false);
    }

    /**
     * 升级:只补跑未记录过的 up,不碰启用状态(升级流程自己管停用与恢复)。
     *
     * @return list<string> 本次真正执行过的迁移(失败时已尝试补偿回退,DDL 是否留残看数据库)
     */
    public function upgrade(Plugin $plugin): array
    {
        return $this->run($plugin, true);
    }

    /**
     * @return list<string>
     */
    private function run(Plugin $plugin, bool $upgrading): array
    {
        $tables = $plugin->manifest->tables();

        if ($tables === []) {
            return [];
        }

        $recorded = $this->recorded($plugin->name());
        $pending = array_values(array_filter(
            $tables,
            static fn (array $table): bool => !in_array($table['migration'], $recorded, true)
        ));

        if ($pending === []) {
            return [];
        }

        $done = [];
        $running = null;

        try {
            foreach ($pending as $table) {
                $running = $table;
                $this->migration($plugin, $table['migration'])->up();
                $done[] = $table;
                $running = null;
            }
        } catch (Throwable $e) {
            // 正在跑的那条可能建到一半才炸,这个名字也一并清;已跑完的倒序回退
            if ($running !== null) {
                $this->safeDrop($running['name']);
            }

            $this->rollback($plugin, $done);

            if ($upgrading) {
                $ran = array_column($done, 'migration');

                throw new PluginLoadException(
                    sprintf(
                        '插件 %s 的升级迁移失败(已跑:%s;失败时已尝试回退):%s',
                        $plugin->name(),
                        $ran === [] ? '无' : implode(', ', $ran),
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }

            throw new PluginLoadException(
                sprintf('插件 %s 的数据表迁移失败,已回退,未启用:%s', $plugin->name(), $e->getMessage()),
                0,
                $e
            );
        }

        $this->db->transaction(function () use ($plugin, $recorded, $pending): void {
            $list = $recorded;

            foreach ($pending as $table) {
                if (!in_array($table['migration'], $list, true)) {
                    $list[] = $table['migration'];
                }
            }

            $this->saveRecord($plugin->name(), $list);
        });

        return array_column($pending, 'migration');
    }

    /**
     * 删除:先跑清单声明的 uninstall.php,再倒序回滚已跑过的 down,最后按 tables 声明清表。
     *
     * down 只跑记录在案的那些 —— 一个从没启用过的插件,其 down() 不该被无中生有地触发。
     * 表则一律 DROP IF EXISTS:即便记录丢了,声明里的表名也要清干净。
     */
    public function purge(Plugin $plugin): void
    {
        $this->uninstall($plugin);

        $recorded = $this->recorded($plugin->name());

        foreach (array_reverse($plugin->manifest->tables()) as $table) {
            if (in_array($table['migration'], $recorded, true)) {
                $this->migration($plugin, $table['migration'])->down();
            }

            $this->dropTable($table['name']);
        }

        $this->db->delete('options', 'option_name = :name', ['name' => $this->recordKey($plugin->name())]);
    }

    /**
     * @param list<array{name: string, migration: string}> $done
     */
    private function rollback(Plugin $plugin, array $done): void
    {
        foreach (array_reverse($done) as $table) {
            try {
                $this->migration($plugin, $table['migration'])->down();
            } catch (Throwable) {
                // 补偿阶段不能再抛:原始失败原因才是要报给用户的那条
            }

            $this->safeDrop($table['name']);
        }
    }

    private function migration(Plugin $plugin, string $relative): Migration
    {
        $manifest = $plugin->manifest;
        $path = $manifest->directory() . '/' . $relative;

        if (!is_file($path)) {
            throw new PluginLoadException("插件 {$plugin->name()} 缺迁移文件:{$relative}");
        }

        require_once $path;

        $class = $manifest->namespacePrefix()
            . 'Migrations\\'
            . self::className(basename($path, '.php'), $plugin->name());

        if (!class_exists($class)) {
            throw new PluginLoadException(
                "插件 {$plugin->name()} 的迁移 {$relative} 里找不到类 {$class};"
                . '迁移类要建在 Molin\Plugins\{StudlyName}\Migrations\ 下,类名由文件名推出(见《12》§7.3)'
            );
        }

        $migration = new $class($this->db);

        if (!$migration instanceof Migration) {
            throw new PluginLoadException(
                "插件 {$plugin->name()} 的迁移 {$relative} 必须实现 Molin\Migrations\Migration(up/down)"
            );
        }

        return $migration;
    }

    /**
     * 与 Migrator::className() 同一套规则:001_create_orders → CreateOrders001。
     */
    private static function className(string $stem, string $plugin): string
    {
        if (preg_match('#\A(\d+)_([a-z0-9_]+)\z#', $stem, $m) !== 1) {
            throw new PluginLoadException(
                "插件 {$plugin} 的迁移文件名不合规({$stem}.php):要写成 序号_小写标签.php,例如 001_create_orders.php"
            );
        }

        return Str::studly($m[2]) . $m[1];
    }

    private function uninstall(Plugin $plugin): void
    {
        $script = $plugin->manifest->uninstallScript();

        if ($script === null) {
            return;
        }

        if (!is_file($script)) {
            throw new PluginLoadException("插件 {$plugin->name()} 声明的卸载脚本不存在:{$script}");
        }

        try {
            require $script;
        } catch (Throwable $e) {
            throw new PluginLoadException("插件 {$plugin->name()} 的卸载脚本执行失败:{$e->getMessage()}", 0, $e);
        }
    }

    private function dropTable(string $name): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `' . $this->db->table($name) . '`');
    }

    private function safeDrop(string $name): void
    {
        try {
            $this->dropTable($name);
        } catch (Throwable) {
            // 兜底清理失败就算了,别盖住原始错误
        }
    }

    /**
     * @return list<string>
     */
    private function recorded(string $plugin): array
    {
        $row = $this->db->selectOne(
            'SELECT option_value FROM ' . $this->db->table('options') . ' WHERE option_name = :name',
            ['name' => $this->recordKey($plugin)]
        );

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) $row['option_value'], true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * @param list<string> $migrations
     */
    private function saveRecord(string $plugin, array $migrations): void
    {
        $key = $this->recordKey($plugin);
        $value = (string) json_encode(array_values($migrations), JSON_UNESCAPED_SLASHES);
        $existing = $this->db->selectOne(
            'SELECT option_id FROM ' . $this->db->table('options') . ' WHERE option_name = :name',
            ['name' => $key]
        );

        if ($existing === null) {
            $this->db->insert('options', [
                'option_name'  => $key,
                'option_value' => $value,
                'autoload'     => 0,
            ]);

            return;
        }

        $this->db->update('options', ['option_value' => $value], 'option_name = :name', ['name' => $key]);
    }

    private function recordKey(string $plugin): string
    {
        return self::RECORD_PREFIX . $plugin;
    }
}
