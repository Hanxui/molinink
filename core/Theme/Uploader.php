<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Theme;

use Molin\Exception\MolinException;
use Twig\Source;

/**
 * 主题包上传(《16》第 8 节:先解包审查,再移入;《13》第 10 节七项校验)。
 *
 * 解包到 storage/trash 下的临时区,逐个 zip 条目写文件(路径穿越、盘符、反斜杠当场拒掉),
 * 七项校验全过才 rename 进 themes/{slug}/。失败一律清掉临时区再报错,不落半成品。
 */
final class Uploader
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MAX_FILES = 2000;

    /** 主题包只该是模板与静态件:任何可执行脚本一律拒(《16》§4) */
    private const FORBIDDEN_EXT = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'pht', 'inc', 'cgi', 'pl', 'py', 'sh', 'bat', 'exe'];

    public function __construct(
        private readonly string $themesRoot,
        private readonly string $trashRoot
    ) {
    }

    /**
     * @param array{name:string, tmp_name:string, size:int, error:int} $file
     * @return array{ok:bool, slug:string, errors:list<string>}
     */
    public function install(array $file): array
    {
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || $file['tmp_name'] === '') {
            return $this->fail(['没有收到文件,或上传中断了,再试一次。']);
        }

        if ($file['size'] > self::MAX_BYTES) {
            return $this->fail(['主题包不能超过 5 MB。']);
        }

        if (strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
            return $this->fail(['主题包要是 .zip 文件。']);
        }

        if (!class_exists(\ZipArchive::class)) {
            return $this->fail(['当前 PHP 没装 zip 扩展,无法解包主题。装上 ext-zip 后再试。']);
        }

        $tmp = rtrim($this->trashRoot, '/') . '/theme-upload-' . bin2hex(random_bytes(6));

        if (!mkdir($tmp, 0o755, true)) {
            return $this->fail(['临时区建不出来,检查 storage/trash 的权限。']);
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($file['tmp_name']);

        if ($opened !== true) {
            $this->removeTree($tmp);

            return $this->fail(['这个 zip 打不开,可能文件损坏。']);
        }

        try {
            $entries = $this->extract($zip, $tmp);
        } catch (MolinException $e) {
            $this->removeTree($tmp);

            return $this->fail([$e->getMessage()]);
        } finally {
            $zip->close();
        }

        $root = $this->themeRoot($tmp);

        if ($root === null) {
            $this->removeTree($tmp);

            return $this->fail(['包里没有找到 theme.json。']);
        }

        $errors = $this->inspect($root);

        if ($errors !== []) {
            $this->removeTree($tmp);

            return $this->fail($errors);
        }

        $manifest = $this->manifest($root);
        $slug = (string) ($manifest['name'] ?? '');
        $target = rtrim($this->themesRoot, '/') . '/' . $slug;

        if (is_dir($target)) {
            $this->removeTree($tmp);

            return $this->fail(['同名主题已经装过了:' . $slug]);
        }

        if (!rename($root, $target)) {
            $this->removeTree($tmp);

            return $this->fail(['移入 themes 目录失败,检查目录权限。']);
        }

        $this->removeTree($tmp);

        return ['ok' => true, 'slug' => $slug, 'errors' => []];
    }

    // ---- 解包 ----

    /**
     * @return list<string> 解出来的文件相对路径
     */
    private function extract(\ZipArchive $zip, string $tmp): array
    {
        $files = [];
        $total = 0;

        if ($zip->numFiles > self::MAX_FILES) {
            throw new MolinException('包里的文件太多了(超过 ' . self::MAX_FILES . ' 个)。');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (str_ends_with($name, '/')) {
                continue;
            }

            $this->assertPathSafe($name);
            $relative = str_replace('\\', '/', $name);
            $target = $tmp . '/' . $relative;
            $dir = dirname($target);

            if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
                throw new MolinException('解包时建目录失败。');
            }

            $stream = $zip->getStream($name);

            if ($stream === false) {
                throw new MolinException('有一个条目读不出来,zip 可能损坏。');
            }

            $data = (string) stream_get_contents($stream);
            fclose($stream);
            $total += strlen($data);

            if ($total > self::MAX_BYTES * 4) {
                throw new MolinException('解出来的体积异常,已中止。');
            }

            $ext = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));

            if (in_array($ext, self::FORBIDDEN_EXT, true)) {
                throw new MolinException('主题包里不许带脚本文件:' . $relative);
            }

            if (file_put_contents($target, $data) === false) {
                throw new MolinException('解包写文件失败:' . $relative);
            }

            $files[] = $relative;
        }

        if ($files === []) {
            throw new MolinException('这个 zip 是空的。');
        }

        return $files;
    }

    private function assertPathSafe(string $name): void
    {
        $probe = str_replace('\\', '/', $name);

        if (str_starts_with($probe, '/') || str_contains($probe, ':') || in_array('..', explode('/', $probe), true)) {
            throw new MolinException('包里有可疑路径:' . $name);
        }
    }

    /**
     * 找 theme.json:允许放在根上,或整套裹在唯一一层目录里。
     */
    private function themeRoot(string $tmp): ?string
    {
        if (is_file($tmp . '/theme.json')) {
            return $tmp;
        }

        $found = [];
        $entries = glob($tmp . '/*', GLOB_ONLYDIR);

        if (is_array($entries)) {
            foreach ($entries as $dir) {
                if (is_file($dir . '/theme.json')) {
                    $found[] = $dir;
                }
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    // ---- 七项校验(《13》§10) ----

    /**
     * @return list<string>
     */
    private function inspect(string $root): array
    {
        $errors = [];
        $manifest = $this->manifest($root);
        $slug = (string) ($manifest['name'] ?? '');

        if (preg_match('#\A[a-z][a-z0-9-]*\z#', $slug) !== 1) {
            $errors[] = 'theme.json 的 name 要小写字母开头、只含小写字母数字与连字符。';
        }

        if (trim((string) ($manifest['title'] ?? '')) === '' || trim((string) ($manifest['version'] ?? '')) === '') {
            $errors[] = 'theme.json 缺 title 或 version。';
        }

        if (!is_file($root . '/templates/index.twig')) {
            $errors[] = '主题包缺 templates/index.twig。';
        }

        $contract = (string) ($manifest['contract'] ?? '');

        if (!$this->contractOk($contract)) {
            $errors[] = __('兼容版本 %s 与当前程序 %s 不匹配。', 'molin', $contract === '' ? __('未标') : $contract, MOLIN_CONTRACT);
        }

        $parent = $manifest['parent'] ?? null;

        if (is_string($parent) && $parent !== '') {
            $seen = [];
            $walk = $parent;

            while ($walk !== '') {
                if (in_array($walk, $seen, true)) {
                    $errors[] = '父主题链成环:' . $parent;

                    break;
                }

                $seen[] = $walk;

                if (!is_file(rtrim($this->themesRoot, '/') . '/' . $walk . '/theme.json')) {
                    $errors[] = '父主题不存在:' . $walk;

                    break;
                }

                $walk = (string) ($this->manifest(rtrim($this->themesRoot, '/') . '/' . $walk)['parent'] ?? '');
            }
        }

        foreach ($this->twigErrors($root . '/templates') as $error) {
            $errors[] = $error;
        }

        foreach ($this->defaultsMissing((array) ($manifest['settings'] ?? [])) as $field) {
            $errors[] = '设置字段没有默认值:' . $field;
        }

        return $errors;
    }

    private function contractOk(string $contract): bool
    {
        if ($contract === '') {
            return false;
        }

        $want = explode('.', $contract)[0];
        $have = explode('.', MOLIN_CONTRACT)[0];

        return $want === $have;
    }

    /**
     * @return list<string>
     */
    private function twigErrors(string $templatesDir): array
    {
        if (!is_dir($templatesDir)) {
            return [];
        }

        // 用与前台同一份白名单的空实现环境:裸 Twig 没注册 `theme_setting` / `inline_style`
        // 这类内核函数,`Unknown function` 会把所有真主题(含官方主题)挡在门外
        // —— 2026-10-13 第一次真跑第三方主题的安装校验时暴露。
        $twig = Renderer::syntaxEnvironment();
        $errors = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templatesDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), strlen($templatesDir) + 1);

            try {
                $twig->parse($twig->tokenize(new Source($source, $name)));
            } catch (\Twig\Error\Error $e) {
                $errors[] = sprintf('模板语法有错(%s):%s', $name, $e->getMessage());
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function defaultsMissing(array $settings): array
    {
        $missing = [];

        foreach ($settings as $groupKey => $group) {
            $fields = is_array($group) ? ($group['fields'] ?? []) : [];

            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldKey => $field) {
                if (!is_array($field) || !array_key_exists('default', $field)) {
                    $missing[] = $groupKey . '.' . $fieldKey;
                }
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $root): array
    {
        $decoded = json_decode((string) file_get_contents($root . '/theme.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $errors
     * @return array{ok:bool, slug:string, errors:list<string>}
     */
    private function fail(array $errors): array
    {
        return ['ok' => false, 'slug' => '', 'errors' => $errors];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach (is_array($entries) ? $entries : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;

            if (is_dir($path)) {
                $this->removeTree($path);

                continue;
            }

            for ($try = 0; $try < 5; $try++) {
                if (@unlink($path)) {
                    break;
                }

                usleep(80000);
            }
        }

        @rmdir($dir);
    }
}
