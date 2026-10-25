<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Backup;

use Molin\Database\Database;
use Molin\Support\Files;
use RuntimeException;

/**
 * 备份创建与备份列表(《35-备份与导入导出》§1–§4、§3 的最小可用版)。
 *
 * 打包用 PharData(纯 PHP,不碰 exec —— 《61》§4.8 禁 shell 拼用户输入):
 * 库逐表导出为 SQL,上传目录与 config.php 进包,manifest 带版本与校验和。
 * 加密可以选(第六步,密码由用户设);远端上传在 Remote 类里,创建后由调用方决定推不推。
 */
final class Backup
{
    /** 备份文件名规则,删除与读取都先过这道正则,不给路径穿越留门。pre = 恢复前的自动留底 */
    /**
     * 文件名形态:backup-molin-<日期>-<时刻>-<8 位随机>-<full|pre>.tar.gz
     *
     * 中间那段随机是 2026-10-17 加的:文件在 storage/backups/ 里,而 storage 在站点根目录内 ——
     * 部署侧万一没加 deny 规则,纯时间戳的名字是可以被逐个猜着下载的。随机段让"猜"这件事失效。
     * 旧名字(没有随机段)仍然认,老站的备份列表不会因此消失。
     */
    public const FILENAME = '#\Abackup-molin-\d{8}-\d{6}-(?:[0-9a-f]{8}-)?(full|pre)\.tar\.gz\z#';

    /**
     * 本地留存份数上限,超过就删最旧的(2026-10-13 作者定:备份只留最近 5 份)。
     * 口径是"留最近 5 份",不区分来源 —— 升级前留底(pre)、恢复前留底(pre)、手动备份(full)一起算。
     */
    public const KEEP = 5;

    /**
     * 后台(网页)备份的库体积上限。再大,导出/压缩/落盘会先撞上 PHP-FPM 的
     * max_execution_time 与 memory_limit,半路死掉的残局比一开始就说清楚难收拾。
     * CLI 没有这道上限 —— 大库走 `molin backup:create`。
     */
    private const WEB_DB_LIMIT = 200 * 1024 * 1024;

    /** 备份期间至少给自己留的内存;ini 里已经更大就按大的来 */
    private const MEMORY_LIMIT = 512 * 1024 * 1024;

    public function __construct(
        private readonly Database $db,
        private readonly string $root
    ) {
    }

    /**
     * 创建一份全量备份,返回文件信息。
     *
     * @param string|null $password 给了密码就整包加密(AES-256-GCM,《35》§4 ⑥)
     * @param Progress|null $progress 传了就往它报进度;调用方负责 begin() / finish(),这里只 advance()
     * @return array{name:string, size:int, tables:int, encrypted:bool}
     */
    public function create(string $version, string $type = 'full', ?string $password = null, ?Progress $progress = null): array
    {
        if (!in_array($type, ['full', 'pre'], true)) {
            throw new RuntimeException('未知的备份类型');
        }

        $this->prepareRun();
        $this->assertWebSizeLimit();

        $dir = $this->directory();

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // 秒级时间戳 + 8 位随机 + 类型:同一秒内连做两次备份也不许互相覆盖,
        // 同时让文件名不可猜(见 FILENAME 的注释:storage 在站点根内,时间戳名字能被顺着猜)
        $stamp = gmdate('Ymd-His');
        $salt = bin2hex(random_bytes(4));
        $name = 'backup-molin-' . $stamp . '-' . $salt . '-' . $type . '.tar.gz';

        // 撞名就往前挪一秒 —— 回退时"先自动留底、再读回目标包",万一落在同一秒里,
        // 留底那一下正好会盖掉要还原的那份,等于白还原(升级失败的兜底就废在这儿)
        $bump = 0;

        while (is_file($dir . '/' . $name)) {
            $bump++;
            $stamp = gmdate('Ymd-His', time() + $bump);
            $salt = bin2hex(random_bytes(4));
            $name = 'backup-molin-' . $stamp . '-' . $salt . '-' . $type . '.tar.gz';
        }

        $archivePath = $dir . '/' . $name;
        $progress?->advance(0, 0, __('正在收集数据…'), 'dump');
        $sql = $this->dump($progress);

        // PHP 的 PharData 按文件名缓存:同进程里删了重建同名包会"打不开",
        // 所以中间 .tar 的名字带一段随机,最终 .gz 改回规格要求的名字
        $archivePath = substr($archivePath, 0, -7) . '-' . bin2hex(random_bytes(4)) . '.tar';

        $progress?->advance(0, 0, __('正在打包备份内容…'), 'pack');

        $phar = null;

        try {
            $phar = new \PharData($archivePath);
            $phar->addFromString('database.sql', $sql);
            $phar->addFromString('manifest.json', (string) json_encode([
                'molin_version' => $version,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'type' => $type,
                'db_size' => strlen($sql),
                'files_size' => $this->treeSize($this->root . '/storage/uploads'),
                'checksum' => hash('sha256', $sql),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            // 上传目录整体进包(files/uploads/…)
            $uploads = $this->root . '/storage/uploads';

            if (is_dir($uploads)) {
                // buildFromDirectory 拿「完整路径」去匹配正则,不锚 ./ 开头 —— 锚了就一个都匹配不上。
                // .gitkeep 只是占位,不进包。
                $phar->buildFromDirectory($uploads, '#^(?!.*\.gitkeep$).+$#');
            }

            $config = $this->root . '/config.php';

            if (is_file($config)) {
                // 立即读入内容而不是交给 Phar 延迟读:配置是整份备份里最不能丢的东西
                $configContent = (string) file_get_contents($config);

                if (trim($configContent) === '') {
                    throw new RuntimeException('config.php 是空的,拒绝打包 —— 请先修复配置文件');
                }

                $phar->addFromString('config.php', $configContent);
            }

            // PharData 的 .gz 会依赖同名 .tar 存在(PHP 的影子文件行为),所以压缩手工做:
            // gzencode 后删掉 .tar,磁盘上只留规格要的 .tar.gz
            $progress?->advance(0, 1, __('正在压缩备份包…'), 'compress');
            $gz = gzencode((string) file_get_contents($archivePath));

            if ($gz === false) {
                throw new RuntimeException('备份包压缩失败');
            }

            $progress?->advance(1, 1, __('正在压缩备份包…'), 'compress');

            $encrypted = $password !== null && $password !== '';

            if ($encrypted) {
                $progress?->advance(0, 1, __('正在加密备份包…'), 'encrypt');
                $gz = Cipher::seal($gz, (string) $password);
                $progress?->advance(1, 1, __('正在加密备份包…'), 'encrypt');
            }

            $progress?->advance(0, 1, __('正在写入备份文件…'), 'write');
            file_put_contents($dir . '/' . $name, $gz);
        } finally {
            // 中间 .tar 只是 PharData 的工作文件,成败都不该留在磁盘上;最终 .tar.gz 不在此列
            unset($phar);
            Files::delete($archivePath);
        }

        $progress?->advance(1, 1, __('正在写入备份文件…'), 'write');

        $gzPath = $dir . '/' . $name;

        if (!is_file($gzPath)) {
            throw new RuntimeException('备份包压缩失败');
        }

        // 留最近 KEEP 份(2026-10-13 作者定):升级前留底、恢复前留底、手动备份一起算。
        // 放在这里而不是某个定时任务里 —— 每建一份就收敛一次,备份目录不会无限涨。
        $this->prune();

        // 「已加密」这一事实以落盘文件的头为准,与列表页(Cipher::isEncryptedFile)同一口径:
        // 调用方要拿它去提醒"这份是明文",不能因为传了密码就假定密文真的写成了
        return ['name' => $name, 'size' => (int) filesize($gzPath), 'tables' => $this->tableCount(), 'encrypted' => Cipher::isEncryptedFile($gzPath)];
    }

    /**
     * 备份是长任务:网页默认的 30 秒、128M 撑不到打包完成。
     * CLI 本来就无时限、内存也常已调大 —— 只在当前值更小时往上抬,不压回去。
     */
    private function prepareRun(): void
    {
        @set_time_limit(0);

        $current = ini_get('memory_limit');

        if ($current === false) {
            return;
        }

        $bytes = ini_parse_quantity($current);

        if ($bytes !== -1 && $bytes < self::MEMORY_LIMIT) {
            ini_set('memory_limit', (string) (self::MEMORY_LIMIT / 1048576) . 'M');
        }
    }

    /**
     * 后台是网页请求,大库在这里只能撞超时;CLI 走同一条 create(),但不受这道闸限制。
     * 探测本身失败(权限不足、连接串没带库名)就跳过,别让体积检查比备份本身更致命。
     */
    private function assertWebSizeLimit(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        try {
            $current = $this->db->selectOne('SELECT DATABASE() AS `name`');
            $name = $current['name'] ?? null;

            if (!is_string($name) || $name === '') {
                return;
            }

            $size = $this->db->selectOne(
                'SELECT SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
        } catch (\Throwable) {
            return;
        }

        $bytes = (int) ($size['bytes'] ?? 0);

        if ($bytes > self::WEB_DB_LIMIT) {
            throw new RuntimeException(sprintf(
                '数据库约 %s MB，超过后台备份上限 200 MB；请改用 CLI：molin backup:create',
                round($bytes / 1048576, 1)
            ));
        }
    }

    /**
     * @return list<array{name:string, size:int, created_at:int, source:string, encrypted:bool}>
     */
    public function list(): array
    {
        $out = [];

        foreach ($this->archives() as $path) {
            $name = basename($path);

            if (preg_match(self::FILENAME, $name, $m) !== 1) {
                continue;
            }

            // 2026-10-13 加 prune() 之后,这一份可能在 glob 与读大小之间被另一次收敛收走
            // (两个后台标签页同时动手就会),那就当它已经不在 —— 不给日志留一条 stat failed。
            $size = @filesize($path);

            if ($size === false) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'size' => $size,
                'created_at' => (int) filemtime($path),
                // 文件名里就带着它是全量还是恢复前的自留底(《109》§4「来源」列)
                'source' => $m[1] === 'pre' ? '恢复前' : '手动',
                // 「已加密」标签只看文件头,不解包(《109》§5.9)
                'encrypted' => Cipher::isEncryptedFile($path),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $out;
    }

    /**
     * 最近一次备份的时间戳;从未备份过返回 null(健康格的"尚无备份"就看它)。
     */
    public function latest(): ?int
    {
        $all = $this->list();

        return $all === [] ? null : $all[0]['created_at'];
    }

    /**
     * 删除一份备份。文件名先过正则,.. 与斜杠一律不认。
     */
    public function delete(string $name): bool
    {
        if (preg_match(self::FILENAME, $name) !== 1) {
            return false;
        }

        $path = $this->directory() . '/' . $name;

        return Files::delete($path);
    }

    /**
     * 只留最近 KEEP 份,更早的删掉(2026-10-13 作者定)。list() 已按时间从新到旧排好,过 KEEP 的全删。
     *
     * @return list<string> 这次删掉的文件名
     */
    private function prune(): array
    {
        $gone = [];

        foreach (array_slice($this->list(), self::KEEP) as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';

            if ($name !== '' && $this->delete($name)) {
                $gone[] = $name;
            }
        }

        return $gone;
    }

    /**
     * 按名字定位备份文件;名字不合规、文件不在,都回 null。
     * 下载与恢复找包的口径只有这一处。
     */
    public function path(string $name): ?string
    {
        if (preg_match(self::FILENAME, $name) !== 1) {
            return null;
        }

        $path = $this->directory() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    private function directory(): string
    {
        return $this->root . '/storage/backups';
    }

    /**
     * 恢复流程要往同一处找包:路径口径只从这里出。
     */
    public function directoryPath(): string
    {
        return $this->directory();
    }

    /**
     * @return list<string>
     */
    private function archives(): array
    {
        $dir = $this->directory();
        $files = is_dir($dir) ? glob($dir . '/*.tar.gz') : false;

        return is_array($files) ? $files : [];
    }

    /**
     * @return list<array<int|string, string|null>>
     */
    private function queryAll(\PDO $pdo, string $statement): array
    {
        $result = $pdo->query($statement);

        if ($result === false) {
            return [];
        }

        $out = [];

        foreach ($result as $record) {
            $out[] = array_map(
                static fn ($value): ?string => $value === null ? null : (string) $value,
                (array) $record
            );
        }

        return $out;
    }

    private function dump(?Progress $progress = null): string
    {
        $pdo = $this->db->pdo();
        $out = '-- 墨林备份 database.sql' . "\n" . '-- created_at ' . gmdate('Y-m-d\TH:i:s\Z') . "\n"
            . 'SET NAMES utf8mb4;' . "\n\n";

        $tables = $this->queryAll($pdo, 'SHOW TABLES');
        $total = max(1, count($tables));
        $index = 0;

        foreach ($tables as $row) {
            $table = (string) array_values($row)[0];
            $safe = str_replace('`', '``', $table);
            $created = $this->queryAll($pdo, 'SHOW CREATE TABLE `' . $safe . '`');

            if ($created === [] || !isset($created[0]['Create Table'])) {
                continue;
            }

            $out .= 'DROP TABLE IF EXISTS `' . $table . '`;' . "\n"
                . $created[0]['Create Table'] . ';' . "\n\n";
            $batch = [];

            foreach ($this->queryAll($pdo, 'SELECT * FROM `' . $safe . '`') as $record) {
                $values = [];

                foreach ($record as $value) {
                    $values[] = $value === null ? 'NULL' : $pdo->quote($value);
                }

                $batch[] = 'INSERT INTO `' . $table . '` VALUES (' . implode(', ', $values) . ');';

                if (count($batch) >= 100) {
                    $out .= implode("\n", $batch) . "\n";
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $out .= implode("\n", $batch) . "\n";
            }

            $out .= "\n";

            $index++;
            $progress?->advance($index, $total, __('正在导出数据表', 'molin') . ' ' . $index . '/' . $total, 'dump');
        }

        return $out;
    }

    private function tableCount(): int
    {
        return count($this->queryAll($this->db->pdo(), 'SHOW TABLES'));
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        $names = scandir($dir);

        return is_array($names) ? $names : [];
    }

    private function treeSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;

        foreach ($this->entries($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            $size += is_dir($path) ? $this->treeSize($path) : (int) filesize($path);
        }

        return $size;
    }
}
