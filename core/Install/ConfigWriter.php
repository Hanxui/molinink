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
 * config.php 的生成与写入(《42-安装向导》第 5 节)。
 *
 * 写文件走"临时文件 + rename",避免半截配置被下一个请求读到;
 * 装失败时这个文件也要跟着回滚(《42》第 5 节明令),所以删除只允许删自己写的那份。
 */
final class ConfigWriter
{
    public function __construct(private readonly string $root)
    {
    }

    public function path(): string
    {
        return $this->root . '/config.php';
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @param array<string, mixed> $database 键照 config.example.php(《42》第 3 节字段表)
     * @return array<string, mixed>
     */
    public function build(array $database, bool $debug = false, ?string $timezone = null): array
    {
        return [
            'debug' => $debug,
            'timezone' => $timezone ?? 'Asia/Shanghai',
            'database' => $database,
            'secret_key' => self::randomHex(32),
            'salt' => self::randomHex(16),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function write(array $config): string
    {
        $path = $this->path();
        $temp = $path . '.tmp';

        if (!is_writable(dirname($path))) {
            throw new ConfigException('根目录不可写,config.php 写不下去:请先让 Web 用户可写 ' . dirname($path));
        }

        $php = $this->render($config);

        if (file_put_contents($temp, $php) === false) {
            throw new ConfigException('写入 config.php 失败(临时文件阶段)');
        }

        // 0640:比《42》第 7 节提示的 644 更严一档 —— 同机其他用户不该读到数据库口令
        @chmod($temp, 0o640);

        if (!rename($temp, $path)) {
            @unlink($temp);

            throw new ConfigException('写入 config.php 失败(替换阶段)');
        }

        return $path;
    }

    /**
     * 回滚用。真删掉了才回 true —— 回滚结论必须与事实一致(《42》第 5 节)。
     */
    public function remove(): bool
    {
        return Files::delete($this->path());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function render(array $config): string
    {
        return "<?php\n\n"
            . "/**\n"
            . " * 墨林 - Molinink\n"
            . " * @author Kira\n"
            . " * @link   https://www.molinink.com\n"
            . " *\n"
            . " * 由安装向导生成(《42-安装向导》第 5 节)。含数据库凭据与站点密钥,\n"
            . " * 已在 .gitignore 中排除,任何情况下不得入库、不得外发。\n"
            . " */\n\n"
            . "declare(strict_types=1);\n\n"
            . 'return ' . var_export($config, true) . ";\n";
    }

    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }
}
