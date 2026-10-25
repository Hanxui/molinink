<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Backup;

use Molin\Database\Database;
use Molin\Exception\ValidationException;
use Molin\Support\Files;
use RuntimeException;

/**
 * 恢复流程(《35-备份与导入导出》§5):
 * 解密(加密包) → 校验和 → 恢复前自动备份当前状态 → 停外键、清表 → 重放 database.sql → 还原文件 → 清缓存。
 *
 * 逐语句解析尊重引号(数据里的分号与换行都安全);只认自家命名的包。
 * 解包前先逐条认条目名、落盘后再按 realpath 复核一遍:备份包可能来自别人手里,
 * PharData 自己的判断不能当唯一防线。
 */
final class Restore
{
    public function __construct(
        private readonly Backup $backup,
        private readonly Database $db,
        private readonly string $root
    ) {
    }

    /**
     * @param string|null $password 加密包的解密密码(《35》§4 ⑥);未加密包传 null
     * @param Progress|null $progress 传了就往它报进度;调用方负责 begin() / finish(),这里只 advance()
     * @return list<string> 每步一句的反馈,调用方直接展示
     */
    public function restore(string $name, string $version, ?string $password = null, ?Progress $progress = null): array
    {
        $steps = [];

        if (preg_match(Backup::FILENAME, $name) !== 1) {
            throw new RuntimeException('备份文件名不对:' . $name);
        }

        $path = $this->backup->path($name);

        if ($path === null) {
            throw new RuntimeException('备份文件不存在:' . $name);
        }

        // 解密与后续解包都放临时目录,别在备份目录里留下影子文件
        $work = $this->backup->directoryPath() . '/restore-' . bin2hex(random_bytes(4));

        if (!is_dir($work)) {
            mkdir($work, 0o755, true);
        }

        try {
            $plainPath = $path;

            if (Cipher::isEncryptedFile($path)) {
                if ($password === null || $password === '') {
                    throw new RuntimeException('这份备份是加密的:请提供解密密码。');
                }

                $progress?->advance(0, 1, __('正在解密备份包…'), 'decrypt');

                // 密码不对在这一步就拦下 —— 后续一步都还没动
                file_put_contents($work . '/payload.tar.gz', Cipher::open((string) file_get_contents($path), $password));
                $plainPath = $work . '/payload.tar.gz';
                $progress?->advance(1, 1, __('正在解密备份包…'), 'decrypt');
            }

            $progress?->advance(0, 0, __('正在备份当前状态…'), 'pre');

            // 《35》§5:恢复前先给当前状态留一份,防"恢复错了更糟";加密包沿用同一个密码
            $pre = $this->backup->create($version, 'pre', $password, $progress);
            $steps[] = '已把当前状态备份为 ' . $pre['name'];

            $progress?->advance(0, 1, __('正在解压备份包…'), 'unpack');

            // .gz 解回 .tar 再开
            $tarPath = $work . '/restore-' . bin2hex(random_bytes(4)) . '.tar';
            $tar = gzdecode((string) file_get_contents($plainPath));

            if ($tar === false) {
                throw new RuntimeException('备份包解压失败');
            }

            file_put_contents($tarPath, $tar);
            $phar = new \PharData($tarPath);
            $progress?->advance(1, 1, __('正在解压备份包…'), 'unpack');

            try {
                $manifest = (array) json_decode((string) $phar['manifest.json']->getContent(), true);
                $sql = (string) $phar['database.sql']->getContent();

                if (isset($manifest['checksum']) && $manifest['checksum'] !== hash('sha256', $sql)) {
                    throw new RuntimeException('校验和不匹配 —— 备份包可能损坏或被改动');
                }

                $steps[] = '校验和通过';

                // 条目名先过一遍再动数据库:名字越界的包连"清表"这一步都不该跨过去
                $this->assertEntryNames($phar, $tarPath);

                $this->dropAllTables($progress);
                $steps[] = '已清空现有表';

                $progress?->advance(0, 0, __('正在导入数据库…'), 'import');

                $executed = 0;
                $statements = $this->statements($sql);
                $total = max(1, count($statements));

                foreach ($statements as $i => $statement) {
                    $this->db->execute($statement);
                    $executed++;

                    if ($i % 25 === 0 || $i === $total - 1) {
                        $progress?->advance($i + 1, $total, __('正在导入数据库…'), 'import');
                    }
                }

                $steps[] = '数据库已还原(' . $executed . ' 条语句)';

                $this->extractFiles($phar, $tarPath, $steps, $progress);
            } finally {
                unset($phar);
            }
        } finally {
            $this->removeTree($work);
        }

        $progress?->advance(0, 0, __('正在清理缓存…'), 'purge');
        $this->clearCaches();
        $steps[] = '缓存已清理';

        return $steps;
    }

    /**
     * 把 SQL 文本切成逐条语句:引号里的分号与 \ 转义都不算边界。
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $out = [];
        $buffer = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $buffer .= $char;

            if ($inString && $char === '\\') {
                $i++;
                $buffer .= $sql[$i] ?? '';

                continue;
            }

            if ($char === "'") {
                $inString = !$inString;

                continue;
            }

            if ($char === ';' && !$inString) {
                $stripped = preg_replace('/^--[^\n]*\n?/m', '', $buffer);
                $statement = trim((string) $stripped);

                if ($statement !== '') {
                    $out[] = $statement;
                }

                $buffer = '';
            }
        }

        return $out;
    }

    private function dropAllTables(?Progress $progress = null): void
    {
        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');

        $tables = $this->tables();
        $total = max(1, count($tables));
        $index = 0;

        foreach ($tables as $table) {
            $this->db->execute('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            $index++;
            $progress?->advance($index, $total, __('正在清空现有数据表', 'molin') . ' ' . $index . '/' . $total, 'drop');
        }

        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $out = [];

        foreach ($this->queryAll('SHOW TABLES') as $row) {
            $out[] = (string) array_values($row)[0];
        }

        return $out;
    }

    /**
     * @return list<array<int|string, string|null>>
     */
    private function queryAll(string $statement): array
    {
        return $this->db->select($statement);
    }

    /**
     * 包里除 database.sql / manifest.json 外的内容:config.php 回站点根,其余回 uploads。
     */
    /**
     * @param list<string> $steps
     */
    private function extractFiles(\PharData $phar, string $tarPath, array &$steps, ?Progress $progress = null): void
    {
        $progress?->advance(0, 0, __('正在还原文件…'), 'files');
        $tmp = $this->root . '/storage/backups/restore-' . bin2hex(random_bytes(4));

        if (!is_dir($tmp)) {
            mkdir($tmp, 0o755, true);
        }

        try {
            $this->unpack($phar, $tarPath, $tmp);
            $copied = 0;

            foreach ($this->entries($tmp) as $name) {
                if ($name === '.' || $name === '..' || $name === 'database.sql' || $name === 'manifest.json') {
                    continue;
                }

                $from = $tmp . '/' . $name;

                if ($name === 'config.php') {
                    // 配置为空的包不覆盖现有配置(历史教训:空配置会把整个站点写哑)
                    if (filesize($from) > 0) {
                        $this->copyFile($from, $this->root . '/config.php', 'config.php');
                        $steps[] = 'config.php 已还原';
                    }

                    continue;
                }

                $copied += $this->copyTree($from, $this->root . '/storage/uploads/' . $name);
            }

            // 按实际件数说话:包里一个都没有时不许报"已还原"(那是报喜不报忧)
            $steps[] = $copied > 0 ? '上传文件已还原(' . $copied . ' 个)' : '包内没有上传文件';
        } finally {
            $this->removeTree($tmp);
        }
    }

    /**
     * 解包:先认条目名 → 再落盘 → 最后按真实路径复核。
     *
     * 顺序不能反。tar 条目名是包里自带的字符串,`../../x`、`/etc/cron.d/x`、
     * `C:\Windows\x` 这类名字一旦被解包器照着写,写出去的位置就在站点之外 ——
     * 那一步之后再检查,文件已经落地了。所以先枚举、逐条校验,全过了才 extractTo。
     *
     * 落盘后再复核一遍是为了兜住"名字看着干净、落点却被软链接带偏"的情况。
     */
    private function unpack(\PharData $phar, string $tarPath, string $tmp): void
    {
        // restore() 里已经过了一遍;这里再来一次是因为"校验"和"落盘"之间隔了清表与导库,
        // 中间那一大段跑完再相信一次结论,比假设它没变过便宜
        $this->assertEntryNames($phar, $tarPath);

        $phar->extractTo($tmp, null, true);
        $this->assertTreeContained($tmp);
    }

    /**
     * 逐条认条目名。任何一条不合规就整包拒绝 —— 不挑着解,也不"先解一半再说"。
     */
    private function assertEntryNames(\PharData $phar, string $tarPath): void
    {
        foreach (new \RecursiveIteratorIterator($phar) as $entry) {
            if (!$entry instanceof \PharFileInfo || $entry->isDir()) {
                continue;
            }

            $name = $this->entryName((string) $entry->getPathname(), $tarPath);

            if (!self::entryNameOk($name)) {
                throw $this->unsafeEntry($name);
            }
        }
    }

    /**
     * 条目在包内的相对路径。
     *
     * 不同 PHP 构建交出来的条目路径有两种常见形态:`phar://<压缩包绝对路径>/<条目>`,
     * 或者已经是相对名。前缀对不上时按压缩包文件名(自己起的随机名)切一刀。
     * 三种都对不上就按不安全处理 —— 认不出来的一律不猜。
     */
    private function entryName(string $pathname, string $tarPath): string
    {
        $normalized = str_replace('\\', '/', $pathname);
        $archive = str_replace('\\', '/', $tarPath);
        $prefix = 'phar://' . $archive . '/';

        if (str_starts_with($normalized, $prefix)) {
            return substr($normalized, strlen($prefix));
        }

        $marker = '/' . basename($archive) . '/';
        $at = strpos($normalized, $marker);

        if ($at !== false) {
            return substr($normalized, $at + strlen($marker));
        }

        // 没有 phar:// 前缀、也不是绝对路径:已经是包内相对名
        if (!str_starts_with($normalized, 'phar://') && !str_starts_with($normalized, '/')) {
            return $normalized;
        }

        throw $this->unsafeEntry($normalized);
    }

    /**
     * 条目名的底线(与 `Plugin\ZipInstaller`、`Update\Package` 同一套口径):
     * 不许绝对路径、不许盘符/冒号、不许反斜杠、不许 NUL、不许 `..` 段。
     *
     * 只看"段",不看子串:上传目录里 `2026/a..b.jpg` 这种名字是正常的,
     * 用 str_contains('..') 会把它误杀。
     */
    public static function entryNameOk(string $name): bool
    {
        if ($name === ''
            || str_contains($name, "\0")
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, ':')
        ) {
            return false;
        }

        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * 落盘之后按真实路径复核:每个条目的 realpath 必须仍在临时目录里,符号链接一律不收。
     *
     * 软链接是 tar 能带、但备份包里绝不该有的东西 —— 它会把后面的 copyTree 引到站点外面去。
     */
    private function assertTreeContained(string $root): void
    {
        $base = realpath($root);

        if ($base === false) {
            throw $this->unsafeEntry($root);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            $path = $file->getPathname();

            if (is_link($path)) {
                throw $this->unsafeEntry($path);
            }

            $resolved = realpath($path);

            if ($resolved === false || ($resolved !== $base && !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR))) {
                throw $this->unsafeEntry($path);
            }
        }
    }

    /**
     * 一处说人话的拒绝:后台会把这句话原样显示给操作者。
     */
    private function unsafeEntry(string $name): ValidationException
    {
        return new ValidationException(
            '备份包里有不安全的文件路径,已中止恢复(文件未写入):' . $name,
            [],
            'backup_entry_path'
        );
    }

    /**
     * 拷一棵树,返回真正落盘的文件数 —— 恢复回执要按实际件数说话。
     */
    private function copyTree(string $from, string $to): int
    {
        if (is_file($from)) {
            $this->copyFile($from, $to, '上传文件');

            return 1;
        }

        if (!is_dir($to)) {
            mkdir($to, 0o755, true);
        }

        $copied = 0;

        foreach ($this->entries($from) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $copied += $this->copyTree($from . '/' . $name, $to . '/' . $name);
        }

        return $copied;
    }

    /**
     * 拷单个文件:写同目录临时件 → 改名覆盖目标;改名被拒就退回原地覆写。
     *
     * 三个老问题一起收:① 原来不看 copy() 的返回值 —— 写失败也照报「已还原」,
     * 恢复完站点却缺文件;② 直写目标时 copy() 中途被拒会把目标截成 0 字节,
     * 真事故里那等于把 config.php 写哑(沙箱里见过 0 字节的残骸);
     * ③ Windows 上杀毒/索引器会攥着新文件的句柄 —— 只放读写、不放改名,
     * 那条路等多久都没用,所以退到原地覆写并先留旧内容兜底。
     *
     * 两条路都试、轮着来:改名这条路一旦被攥住,单靠重试等不到解锁;
     * 原地覆写也可能撞上对方独占的那一小会儿,所以每轮都试一遍再歇。
     */
    private function copyFile(string $from, string $to, string $label): void
    {
        $dir = dirname($to);

        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException($label . ' 还原失败:目录建不出来 ' . $dir);
        }

        $data = @file_get_contents($from);

        if ($data === false) {
            throw new RuntimeException($label . ' 还原失败:读不到源文件 ' . $from);
        }

        // 原地覆写要先把旧内容留底:中途失败时还能把目标恢复原样,不留半截文件
        $original = @file_get_contents($to);
        $reason = '';
        $deadline = microtime(true) + 8.0;
        $attempt = 0;

        while (true) {
            $attempt++;

            error_clear_last();
            $tmp = $to . '.restore-' . bin2hex(random_bytes(4));

            if (@file_put_contents($tmp, $data) === strlen($data) && @rename($tmp, $to)) {
                return;
            }

            $reason = error_get_last()['message'] ?? '';
            @unlink($tmp);

            error_clear_last();

            if (@file_put_contents($to, $data) === strlen($data)) {
                return;
            }

            $reason = error_get_last()['message'] ?? $reason;

            if ($original !== false) {
                @file_put_contents($to, $original);
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(min(500_000, 100_000 * $attempt));
        }

        throw new RuntimeException(sprintf('%s 还原失败:%s(%s)', $label, $to, $reason !== '' ? $reason : '写不进去'));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ($this->entries($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeTree($path) : Files::delete($path);
        }

        @rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        $names = scandir($dir);

        return is_array($names) ? $names : [];
    }

    private function clearCaches(): void
    {
        foreach (['cache/data', 'cache/pages', 'cache/twig'] as $sub) {
            $dir = $this->root . '/storage/' . $sub;

            if (!is_dir($dir)) {
                continue;
            }

            foreach ($this->entries($dir) as $name) {
                if ($name === '.' || $name === '..' || $name === '.gitkeep') {
                    continue;
                }

                $path = $dir . '/' . $name;
                is_dir($path) ? $this->removeTree($path) : Files::delete($path);
            }
        }
    }
}
