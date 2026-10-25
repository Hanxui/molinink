<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugin;

use Molin\Exception\PluginLoadException;
use Molin\Exception\PluginValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 本地 zip 插件包的安装/升级落盘助手:校验 → 解压 → 复校清单 → 备份 → 原子替换。
 *
 * 安全面全在这里(《92》§8 的离线上传链):
 *  - 只收 .zip、限上传包大小;解压前先遍历条目累加未压缩字节数,超上限即拒(防解压炸弹);
 *  - 每个条目名过穿越校验(禁 ../、绝对路径、反斜杠、盘符、NUL),落盘目录再以 realpath 前缀兜底;
 *  - 插件名以 zip 内 plugin.json 的 name 为准(不信任压缩包顶层目录名),解压后由 Manifest::load 全量复校。
 * 任一步失败都回滚已落的目录,不留半成品。
 *
 * 升级的编排(停用/迁移/钩子/恢复启用)在 Loader —— 这里没有 DB,只做文件系统这一段。
 */
final class ZipInstaller
{
    /** 上传包大小上限(32 MB) */
    private const MAX_UPLOAD_BYTES = 32 * 1048576;

    /** 解压后未压缩字节总数上限(256 MB,防 zip bomb) */
    private const MAX_EXPAND_BYTES = 256 * 1048576;

    private const NAME_PATTERN = '#\A[a-z][a-z0-9-]{1,49}\z#';

    /** 解压中转目录:与 plugins/ 同盘,替换时 rename 才是原子操作 */
    private const STAGING_DIR = '.staging';

    /** 同一插件只留最近几份升级备份:每次升级都整目录复制,不设上限会一直堆 */
    private const KEEP_BACKUPS = 3;

    private readonly string $backupRoot;

    public function __construct(
        private readonly string $pluginsRoot,
        ?string $backupRoot = null
    ) {
        $this->backupRoot = rtrim($backupRoot ?? dirname($pluginsRoot) . '/storage/backups', '/\\');
    }

    /**
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     * @return array{action: string, slug: string, old_version: string|null, new_version: string, backup: string|null}
     */
    public function install(array $upload, bool $replace = false): array
    {
        $slug = $this->inspect($upload);
        $target = $this->pluginsRoot . '/' . $slug;

        if (is_dir($target)) {
            if (!$replace) {
                throw new PluginValidationException("插件 {$slug} 已存在,不能重复安装。");
            }

            return $this->upgrade($slug, $upload);
        }

        if (file_exists($target)) {
            throw new PluginValidationException("插件 {$slug} 的落点被同名文件占着,无法安装。");
        }

        $prepared = $this->prepare($upload);

        if ($prepared['slug'] !== $slug) {
            $this->discardStaging($prepared['staging']);

            throw new PluginValidationException('压缩包在两次读取之间变了,已中止。');
        }

        $this->replace($slug, $prepared['staging']);

        return [
            'action' => 'installed',
            'slug' => $slug,
            'old_version' => null,
            'new_version' => $prepared['version'],
            'backup' => null,
        ];
    }

    /**
     * 升级前的校验与解包:目录存在/可写、旧清单可读、包内 slug 一致、版本必须更高。
     *
     * 拒绝规则只写在这一处 —— Loader 要按「停用 → 备份 → 换目录」插自己的活儿,不能直接调用
     * upgrade(),两边的版本闸门必须同一套,否则迟早一边放宽一边收紧。
     *
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     * @return array{old_version: string, new_version: string, staging: string}
     */
    public function prepareUpgrade(string $slug, array $upload): array
    {
        $target = $this->pluginsRoot . '/' . $slug;

        if (!is_dir($target)) {
            throw new PluginLoadException("插件 {$slug} 不存在,无法升级。");
        }

        if (!is_writable($target)) {
            throw new PluginLoadException("插件目录不可写,无法升级: {$target}");
        }

        // 旧清单读不出来就没有可靠的旧版本号 —— 拒绝盲盖,让人先修好或先删掉
        $old = Manifest::load($target);
        $prepared = $this->prepare($upload);

        if ($prepared['slug'] !== $slug) {
            $this->discardStaging($prepared['staging']);

            throw new PluginValidationException("包内插件名 {$prepared['slug']} 与待升级插件 {$slug} 不符。");
        }

        $oldVersion = $old->version();
        $newVersion = $prepared['version'];
        $compare = Manifest::compareVersions($newVersion, $oldVersion);

        if ($compare < 0) {
            $this->discardStaging($prepared['staging']);

            throw new PluginValidationException("不能降级:{$slug} 已装 {$oldVersion},包内是 {$newVersion}。");
        }

        if ($compare === 0) {
            $this->discardStaging($prepared['staging']);

            throw new PluginValidationException("插件 {$slug} 已存在,且已是同版本 {$oldVersion} —— 同版本无需升级。");
        }

        return ['old_version' => $oldVersion, 'new_version' => $newVersion, 'staging' => $prepared['staging']];
    }

    /**
     * 升级落盘:校验包内清单、比较版本、备份旧目录、原子替换。不做停用/迁移/钩子(那些在 Loader)。
     *
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     * @return array{action: string, slug: string, old_version: string, new_version: string, backup: string}
     */
    public function upgrade(string $slug, array $upload): array
    {
        $prepared = $this->prepareUpgrade($slug, $upload);

        $backup = $this->backup($slug);

        try {
            $this->replace($slug, $prepared['staging']);
        } catch (Throwable $e) {
            $this->discardStaging($prepared['staging']);

            throw new PluginLoadException("插件 {$slug} 升级落盘失败,旧目录已保留:" . $e->getMessage(), 0, $e);
        }

        return [
            'action' => 'upgraded',
            'slug' => $slug,
            'old_version' => $prepared['old_version'],
            'new_version' => $prepared['new_version'],
            'backup' => $backup,
        ];
    }

    /**
     * 只读 zip 里的插件名,给调用方判"新装还是升级"。
     *
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     */
    public function inspect(array $upload): string
    {
        $this->assertUpload($upload);
        $zip = new ZipArchive();

        if (@$zip->open($upload['tmp_name']) !== true) {
            throw new RuntimeException('无法打开 zip 包(文件已损坏或不是合法 zip)。');
        }

        try {
            return $this->slugFrom($zip, $this->basePath($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * 解压到 plugins/.staging/{slug} 并跑全量清单校验。返回的 staging 就是待落盘的目录。
     *
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     * @return array{slug: string, version: string, staging: string}
     */
    public function prepare(array $upload): array
    {
        $this->assertUpload($upload);

        if (!is_dir($this->pluginsRoot) || !is_writable($this->pluginsRoot)) {
            throw new PluginLoadException("插件目录不可写: {$this->pluginsRoot}");
        }

        $zip = new ZipArchive();

        if (@$zip->open($upload['tmp_name']) !== true) {
            throw new RuntimeException('无法打开 zip 包(文件已损坏或不是合法 zip)。');
        }

        try {
            $entries = $this->entries($zip);
            $base = $this->basePath($zip);
            $slug = $this->slugFrom($zip, $base);

            $this->guard($entries, $base);

            $staging = $this->stagingPath($slug);
            $this->removeDir($staging);
            $this->extract($zip, $entries, $base, $staging);
        } finally {
            $zip->close();
        }

        try {
            // 全量清单复校:name==目录、字段、能力词表、入口/迁移路径、契约
            $manifest = Manifest::load($staging);
        } catch (Throwable $e) {
            $this->removeDir($staging);

            throw $e;
        }

        return ['slug' => $slug, 'version' => $manifest->version(), 'staging' => $staging];
    }

    /**
     * 备份旧目录到 storage/backups/plugin-{slug}-{时间}/。升级失败要能原样拿回来,所以是复制不是改名。
     */
    public function backup(string $slug): string
    {
        $target = $this->pluginsRoot . '/' . $slug;

        if (!is_dir($target)) {
            throw new PluginLoadException("插件 {$slug} 不存在,无从备份。");
        }

        if (!is_dir($this->backupRoot) && !@mkdir($this->backupRoot, 0o755, true) && !is_dir($this->backupRoot)) {
            throw new RuntimeException("无法创建备份目录 {$this->backupRoot},请检查权限。");
        }

        $base = $this->backupRoot . '/plugin-' . $slug . '-' . date('Ymd-His');
        $dir = $base;

        for ($i = 2; is_dir($dir); $i++) {
            $dir = $base . '-' . $i;
        }

        if (!@mkdir($dir, 0o755, true)) {
            throw new RuntimeException("无法创建备份目录 {$dir},请检查权限。");
        }

        try {
            $this->copyDir($target, $dir);
        } catch (Throwable $e) {
            $this->removeDir($dir);

            throw new RuntimeException("备份插件 {$slug} 失败:" . $e->getMessage(), 0, $e);
        }

        $this->pruneBackups($slug);

        return $dir;
    }

    /**
     * 只留最近 KEEP_BACKUPS 份。目录名是 plugin-{slug}-{Ymd-His}[-n],字典序即时间序;
     * 正则锚定到时间戳,避免 slug 互为前缀时误删别人的备份(如 note 与 note-pro)。
     * 清理失败不影响本次升级:备份已经落好,删旧的是节流不是正确性。
     */
    private function pruneBackups(string $slug): void
    {
        $pattern = '#\\Aplugin-' . preg_quote($slug, '#') . '-\\d{8}-\\d{6}(-\\d+)?\\z#';
        $dirs = [];

        $entries = scandir($this->backupRoot);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $this->backupRoot . '/' . $entry;

            if (is_dir($path) && preg_match($pattern, $entry) === 1) {
                $dirs[] = $path;
            }
        }

        rsort($dirs, SORT_STRING);

        foreach (array_slice($dirs, self::KEEP_BACKUPS) as $old) {
            try {
                $this->removeDir($old);
            } catch (Throwable) {
                // 删不掉就留着,下次升级再试
            }
        }
    }

    /**
     * 原子替换:旧目录先改名退役,再把 staging 改名到位;第二步失败就把旧目录改回去。
     */
    public function replace(string $slug, string $staging): void
    {
        $target = $this->pluginsRoot . '/' . $slug;

        if (!is_dir($staging)) {
            throw new RuntimeException("待落盘的临时目录不存在: {$staging}");
        }

        if (!is_dir($target)) {
            if (!@rename($staging, $target)) {
                throw new RuntimeException("无法把插件目录移到 {$target},请检查权限。");
            }

            $this->removeEmptyStagingRoot($staging);

            return;
        }

        $retired = $target . '.bak-' . bin2hex(random_bytes(4));

        if (!@rename($target, $retired)) {
            throw new RuntimeException("旧插件目录改名失败(可能被占用或不可写): {$target}");
        }

        if (!@rename($staging, $target)) {
            @rename($retired, $target);

            throw new RuntimeException("新插件目录落位失败,已还原旧目录: {$target}");
        }

        $this->removeDir($retired);
        $this->removeEmptyStagingRoot($staging);
    }

    /**
     * 从备份还原旧目录(升级后迁移失败时用)。备份本身保留,便于事后排查。
     */
    public function restore(string $slug, string $backup): void
    {
        $target = $this->pluginsRoot . '/' . $slug;

        if (!is_dir($backup)) {
            throw new RuntimeException("备份目录不存在,无法还原: {$backup}");
        }

        $this->removeDir($target);

        if (is_dir($target)) {
            throw new RuntimeException("新插件目录删不掉,无法还原: {$target}");
        }

        $this->copyDir($backup, $target);
    }

    /**
     * 安装/激活失败后清掉已落的目录,交给调用方兜底(不留半成品)。
     */
    public function discard(string $slug): void
    {
        $this->removeDir($this->pluginsRoot . '/' . $slug);
    }

    /**
     * 清理解压中转目录。只认 plugins/.staging/ 下的路径,别的地方一概不动。
     */
    public function discardStaging(string $staging): void
    {
        $prefix = str_replace('\\', '/', $this->pluginsRoot . '/' . self::STAGING_DIR . '/');

        if (!str_starts_with(str_replace('\\', '/', $staging), $prefix)) {
            return;
        }

        $this->removeDir($staging);
    }

    /**
     * @param array{name:string, tmp_name:string, size:int, error:int, type:string} $upload
     */
    private function assertUpload(array $upload): void
    {
        // zip 扩展不是 PHP 默认编译进去的,缺了 new ZipArchive() 会抛 Error,给出的提示毫无指向
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('当前 PHP 没装 zip 扩展,无法解包插件。装上 ext-zip 后再试。');
        }

        if ($upload['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('上传失败(浏览器或服务器没收到完整文件)。');
        }

        if (strtolower((string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('只接受 .zip 插件包。');
        }

        if ($upload['size'] < 1 || $upload['size'] > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('插件包为空或超过 32 MB 上限。');
        }
    }

    private function stagingPath(string $slug): string
    {
        return $this->pluginsRoot . '/' . self::STAGING_DIR . '/' . $slug;
    }

    /**
     * @return list<array{index:int, name:string, size:int, isDir:bool}>
     */
    private function entries(ZipArchive $zip): array
    {
        $count = $zip->numFiles;
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false || !isset($stat['name'])) {
                throw new RuntimeException('zip 里有读不出的条目,已中止。');
            }

            $name = (string) $stat['name'];

            $entries[] = [
                'index' => $i,
                'name' => $name,
                'size' => (int) ($stat['size'] ?? 0),
                'isDir' => str_ends_with($name, '/'),
            ];
        }

        return $entries;
    }

    /**
     * 顶层目录:zip 根上有 plugin.json 就按平铺处理,否则剥掉唯一顶层文件夹。
     */
    private function basePath(ZipArchive $zip): string
    {
        if ($zip->locateName('plugin.json') !== false) {
            return '';
        }

        $levels = [];

        foreach ($this->entries($zip) as $entry) {
            $first = explode('/', $entry['name'])[0];

            if ($first !== '' && !str_contains($first, '..') && !str_starts_with($first, '.')) {
                $levels[$first] = 1;
            }
        }

        if (count($levels) !== 1) {
            throw new PluginValidationException('zip 里找不到 plugin.json(多是压缩时把文件直接放在根下,请打包成含单独根目录的 zip)。');
        }

        $base = array_key_first($levels);

        if ($zip->locateName($base . '/plugin.json') === false) {
            throw new PluginValidationException('zip 里找不到 plugin.json。');
        }

        return (string) $base;
    }

    private function slugFrom(ZipArchive $zip, string $base): string
    {
        $path = $base === '' ? 'plugin.json' : $base . '/plugin.json';
        $raw = $zip->getFromName($path);

        if ($raw === false || trim($raw) === '') {
            throw new PluginValidationException('zip 里的 plugin.json 读不出来或为空。');
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['name']) || !is_string($decoded['name'])) {
            throw new PluginValidationException('plugin.json 里没有合法的 name。');
        }

        $name = trim($decoded['name']);

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new PluginValidationException("插件名 {$name} 不合规(^ [a-z][a-z0-9-]{1,49} $)。");
        }

        return $name;
    }

    /**
     * 解压前过一遍全部条目:累加未压缩字节数防炸弹,同时顺路拒掉任何越界条目。
     *
     * @param list<array{index:int, name:string, size:int, isDir:bool}> $entries
     */
    private function guard(array $entries, string $base): void
    {
        $total = 0;

        foreach ($entries as $entry) {
            $relative = $this->relative($entry['name'], $base);

            if (!$entry['isDir'] && $entry['size'] > 0) {
                $total += $entry['size'];

                if ($total > self::MAX_EXPAND_BYTES) {
                    throw new RuntimeException('解压后超过 256 MB 上限(疑似压缩炸弹),已中止。');
                }
            }
        }
    }

    /**
     * 按条目落盘。目录先建,文件写"目标根 + 相对名",落盘目录再以 realpath 前缀兜底。
     *
     * @param list<array{index:int, name:string, size:int, isDir:bool}> $entries
     */
    private function extract(ZipArchive $zip, array $entries, string $base, string $target): void
    {
        if (!is_dir($target) && !@mkdir($target, 0o755, true) && !is_dir($target)) {
            throw new RuntimeException("无法创建插件目录 {$target},请检查权限。");
        }

        $root = (string) realpath($target);

        foreach ($entries as $entry) {
            $relative = $this->relative($entry['name'], $base);
            $destination = $target . '/' . $relative;
            $directory = $entry['isDir'] ? $destination : dirname($destination);

            if ($directory !== $target && !is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw new RuntimeException("无法创建目录 {$directory},请检查权限。");
            }

            // 最终防线:任何落盘位置必须仍在插件目录内
            $resolved = (string) realpath($directory);

            if ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                $this->removeDir($target);

                throw new RuntimeException('压缩包内含越界路径,已中止,不留半成品。');
            }

            if ($entry['isDir']) {
                continue;
            }

            $content = $zip->getFromIndex($entry['index']);

            if ($content === false) {
                $this->removeDir($target);

                throw new RuntimeException('解压内容读取失败(条目可能加密或损坏),已中止。');
            }

            if (@file_put_contents($destination, $content) === false) {
                $this->removeDir($target);

                throw new RuntimeException("无法写入 {$relative},请检查插件目录权限。");
            }
        }
    }

    /**
     * 条目名去掉顶层目录前缀,再做穿越兜底:拒 ../、绝对路径、反斜杠、盘符、NUL。
     */
    private function relative(string $name, string $base): string
    {
        $clean = str_replace('\\', '/', $name);

        if ($base !== '') {
            $prefix = $base . '/';

            if (str_starts_with($clean, $prefix)) {
                $clean = substr($clean, strlen($prefix));
            }
        }

        if (str_contains($clean, '..') || str_contains($clean, "\0")
            || str_starts_with($clean, '/') || str_contains($clean, ':')
        ) {
            throw new RuntimeException('压缩包里含越界路径,已中止,不留半成品。');
        }

        return $clean;
    }

    /**
     * 整棵复制。备份与还原都不许"复制一半"—— 失败时由调用方清掉半截目录。
     */
    private function copyDir(string $from, string $to): void
    {
        if (!is_dir($to) && !@mkdir($to, 0o755, true) && !is_dir($to)) {
            throw new RuntimeException("无法创建目录 {$to}");
        }

        $items = scandir($from);

        if ($items === false) {
            throw new RuntimeException("无法读取目录 {$from}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $source = $from . '/' . $item;
            $destination = $to . '/' . $item;

            if (is_dir($source)) {
                $this->copyDir($source, $destination);

                continue;
            }

            if (!@copy($source, $destination)) {
                throw new RuntimeException("无法复制文件 {$source}");
            }
        }
    }

    private function removeEmptyStagingRoot(string $staging): void
    {
        $root = dirname($staging);

        if (is_dir($root)) {
            @rmdir($root);
        }
    }

    private function removeDir(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }

        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            $items = [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
