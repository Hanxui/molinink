<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Install;

use Molin\Exception\ConfigException;
use Molin\Support\Files;

/**
 * 装向导的分步状态(《42》第 1 节"单页引导式,失败可回退上一步")。
 *
 * 装到一半时数据库还没有会话表可用,内核又不许用 PHP 原生 session —— 所以状态落在
 * `storage/cache/install/` 下一个随机 id 的文件里(storage 不对外暴露,约定)。
 * 里面**有**数据库口令,因此三条硬约束:文件权限 0600、两小时过期、装完即删 ——
 * 成功当场删;写到一半失败时留到两小时(《125》§13.2 的「重新写入」要拿它重跑,口令不必重填),
 * 期间任何一次访问装向导都会把过期的清掉。id 由入口用一个 HttpOnly cookie 带走,猜不到、也不进任何日志。
 */
final class WizardState
{
    /** 状态寿命:超过就当没填过,重来(《42》第 8 节"中途超时支持刷新后续跑"的边界)。入口的 cookie 有效期跟着它 */
    public const TTL = 7200;

    private const DIR = 'storage/cache/install';

    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly string $root,
        private readonly string $id,
        array $data
    ) {
        $this->data = $data;
    }

    /**
     * 按 cookie 里的 id 取状态;id 非法、文件不在或已过期都返回 null(等于重新开始)。
     */
    public static function load(string $root, ?string $id): ?self
    {
        if (!self::validId($id)) {
            return null;
        }

        $path = self::pathFor($root, (string) $id);

        if (!is_file($path)) {
            return null;
        }

        if (self::expired($path)) {
            Files::delete($path);

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            Files::delete($path);

            return null;
        }

        return new self($root, (string) $id, $decoded);
    }

    public static function create(string $root): self
    {
        return new self($root, bin2hex(random_bytes(16)), ['db' => [], 'site' => [], 'admin' => [], 'force' => false, 'welcome' => true]);
    }

    /**
     * 扫掉过期的状态文件 —— 里面带数据库口令,被人放弃的安装不能把口令一直留在盘上。
     *
     * @return int 清掉的份数
     */
    public static function collectGarbage(string $root): int
    {
        $files = glob($root . '/' . self::DIR . '/*.json');

        if ($files === false) {
            return 0;
        }

        $cleared = 0;

        foreach ($files as $path) {
            if (self::expired($path) && Files::delete($path)) {
                ++$cleared;
            }
        }

        return $cleared;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * 防跨站令牌:第一次取时生成,之后一直是同一个。
     */
    public function token(): string
    {
        $token = (string) ($this->data['token'] ?? '');

        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $this->data['token'] = $token;
            $this->save();
        }

        return $token;
    }

    public function matches(string $given): bool
    {
        return $given !== '' && hash_equals($this->token(), $given);
    }

    /**
     * @return array<string, mixed>
     */
    public function db(): array
    {
        return (array) ($this->data['db'] ?? []);
    }

    /**
     * @param array<string, mixed> $db
     */
    public function setDb(array $db): void
    {
        $this->data['db'] = $db;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    public function site(): array
    {
        return (array) ($this->data['site'] ?? []);
    }

    /**
     * @return array<string, string>
     */
    public function admin(): array
    {
        return (array) ($this->data['admin'] ?? []);
    }

    /**
     * @param array<string, string> $site
     * @param array<string, string> $admin
     */
    public function setSiteAndAdmin(array $site, array $admin): void
    {
        $this->data['site'] = $site;
        $this->data['admin'] = $admin;
        $this->save();
    }

    /**
     * 装完要不要顺带建一篇欢迎文章和示例评论(《42》第 4 节,默认要)。
     * 键缺席也按"要"算 —— 与 `Installer::install()` 的判据一致。
     */
    public function welcome(): bool
    {
        return (bool) ($this->data['welcome'] ?? true);
    }

    public function setWelcome(bool $welcome): void
    {
        $this->data['welcome'] = $welcome;
        $this->save();
    }

    public function wantsReinstall(): bool
    {
        return (bool) ($this->data['force'] ?? false);
    }

    public function allowReinstall(): void
    {
        $this->data['force'] = true;
        $this->save();
    }

    /**
     * 装完(或放弃)就把带口令的文件删掉 —— 不留"半装"痕迹(《42》第 5 节)。
     *
     * 先清空再删:Windows 上刚写出的文件可能被杀软短暂锁住删不掉,
     * 内容先归零,删不掉的至多是个空文件,口令不在盘上。
     */
    public function destroy(): void
    {
        $path = self::pathFor($this->root, $this->id);

        if (is_file($path)) {
            @file_put_contents($path, '');
        }

        Files::delete($path);
    }

    private function save(): void
    {
        $dir = $this->root . '/' . self::DIR;

        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new ConfigException('建装向导状态目录失败:' . $dir . '(检查 storage 权限)');
        }

        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents(self::pathFor($this->root, $this->id), $json) === false) {
            throw new ConfigException('写装向导状态失败:' . $dir);
        }

        @chmod(self::pathFor($this->root, $this->id), 0o600);
    }

    private static function expired(string $path): bool
    {
        $stamp = filemtime($path);

        return $stamp === false || $stamp + self::TTL < time();
    }

    private static function validId(?string $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9a-f]{32}\z/', $id) === 1;
    }

    private static function pathFor(string $root, string $id): string
    {
        return $root . '/' . self::DIR . '/' . $id . '.json';
    }
}
