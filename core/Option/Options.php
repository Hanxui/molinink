<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Option;

use Molin\Database\Database;
use Molin\Logger\Audit;
use Molin\Plugin\CapabilityGate;

/**
 * 站点配置读写(options 表,《21-数据模型》第 7 节)。
 *
 * autoload=1 的项启动时一次性预载;其余用时再取,取过的记在本请求内,不再回库。
 * option() 全局助手等内核服务定位器就绪后再接(《52》第 5 节)。
 *
 * set() 同时是 `setting.write` 能力的拦截面(《62》第 6 节):设置写入(站点设置与插件自己的
 * `plugin.*` 键)都只有这一处出口,门卫落在这一处就一处不漏。内核自身(无插件上下文)照常写。
 */
final class Options
{
    /** @var array<string, mixed> */
    private array $preloaded = [];

    private bool $preloadedOnce = false;

    /**
     * 非预载项查过一次的结果:键 => 值。模板一页会问几十次同样的键,每次都回库不值当。
     *
     * @var array<string, mixed>
     */
    private array $fetched = [];

    /**
     * 查过但库里没有的键。单独记,不能并进 fetched 记成 null ——
     * 那样后面带别的默认值的 get() 会拿到 null,而库里没有该给调用方的默认值。
     *
     * @var array<string, true>
     */
    private array $missing = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $this->preload();

        if (array_key_exists($name, $this->preloaded)) {
            return $this->preloaded[$name];
        }

        if (array_key_exists($name, $this->fetched)) {
            return $this->fetched[$name];
        }

        if (isset($this->missing[$name])) {
            return $default;
        }

        $row = $this->db->selectOne(
            'SELECT option_value FROM ' . $this->db->table('options') . ' WHERE option_name = :name LIMIT 1',
            ['name' => $name]
        );

        if ($row === null) {
            $this->missing[$name] = true;

            return $default;
        }

        return $this->fetched[$name] = $row['option_value'];
    }

    public function set(string $name, mixed $value, bool $autoload = true): void
    {
        // 拦在最底层:站点设置与插件自己的配置都经这里落库,门卫一处圈住整层。
        // 调用方是插件且未声明 setting.write 即被拦下;内核自身(无插件上下文)照常写 ——
        // 后台保存设置、安装写初始值、插件配置保存(经 Settings)都在各自的上下文里各按各的算。
        CapabilityGate::assert('setting.write');

        $flag = $autoload ? 1 : 0;
        $table = $this->db->table('options');
        $existing = $this->db->selectOne(
            'SELECT option_id FROM ' . $table . ' WHERE option_name = :name LIMIT 1',
            ['name' => $name]
        );

        if ($existing === null) {
            $this->db->insert('options', [
                'option_name'  => $name,
                'option_value' => $value,
                'autoload'     => $flag,
            ]);
        } else {
            $this->db->update(
                'options',
                ['option_value' => $value, 'autoload' => $flag],
                'option_name = :name',
                ['name' => $name]
            );
        }

        if ($autoload) {
            $this->preloaded[$name] = $value;
        } else {
            // 未预载项本来读的是库里那一行、回来是字符串;记忆照同一形态落,
            // 同一次请求里 set 前后两次 get 才不会一个给 int 一个给 string
            $this->fetched[$name] = $value === null ? null : (string) $value;
        }

        unset($this->missing[$name]);

        // 《26》第 7 节:设置修改必须留痕。只记键名,值不入库日志 —— 值可能是密钥或邮箱
        Audit::record('setting.update', $name);
    }

    private function preload(): void
    {
        if ($this->preloadedOnce) {
            return;
        }

        $this->preloadedOnce = true;
        $rows = $this->db->select(
            'SELECT option_name, option_value FROM ' . $this->db->table('options') . ' WHERE autoload = 1'
        );

        foreach ($rows as $row) {
            $this->preloaded[(string) $row['option_name']] = $row['option_value'];
        }
    }
}
