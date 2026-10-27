<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Install;

use Molin\Comment\CommentRepository;
use Molin\Content\HtmlSanitizer;
use Molin\Content\Slug;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Exception\ConfigException;
use Molin\Exception\InstallFailedException;
use Molin\Exception\ValidationException;
use Molin\Hook\HookMap;
use Molin\Logger\Audit;
use Molin\Migrations\Migrator;
use Molin\Option\Options;
use Molin\Security\Password;
use Molin\Support\Files;
use Throwable;

/**
 * 安装引擎(既有规范的七步)。
 *
 * 装失败必须回到"没装过"的样子 —— 连 config.php 一起回滚,不留半装状态。
 * 建表唯一来源是 core/Migrations/,这里不写任何 DDL(约定)。
 * Web 向导与 `molin install` 共用本类,两条路的装法不许分叉。
 */
final class Installer
{
    /** 既有规范⑦ 步 */
    private const STORAGE_DIRS = ['cache/data', 'cache/pages', 'cache/twig', 'logs', 'logs/audit', 'uploads', 'backups', 'plugins', 'trash'];

    /**
     * 装完自带的第一篇文章(既有规范⑤ 步,可关)。像 Typecho / WordPress 那样,
     * 让新站一打开就有东西可看 —— 空站的新手常以为装坏了。
     *
     * 不写"Hello world!":中文站里那句看着隔应,也不拿第一篇文章打广告。
     */
    private const WELCOME_TITLE = '欢迎来到你的新站点';

    /** 正文是过白名单的富文本,与后台发文同一条路,所以这里写成 HTML */
    private const WELCOME_BODY = <<<'HTML'
<p>这是安装时为你创建的第一篇文章，让你一进来就有东西可看。</p>
<p>它不是什么模板，只是几张便利贴：</p>
<ul>
<li>想写点什么，去后台「文章 → 写文章」。</li>
<li>想让文章有个归属，用「分类」；想贴几个关键词，用「标签」。</li>
<li>有人留言了，去后台「评论」里通过或删除。</li>
</ul>
<p>把这篇删掉，你的站点就算正式开张了。</p>
HTML;

    /** 署名用创建站点时那个管理员的用户名,不伪装成路人 —— 一看就知道是安装自带的 */
    private const WELCOME_COMMENT = '这是一条示例评论。你可以回复它、审核它，或者直接删掉 —— 就跟处理其他评论一样。';

    private readonly ConfigWriter $configWriter;

    /** 重装授权只影响提示口径,绝不放松"不许动别人的表" */
    private bool $force = false;

    /**
     * @param array<string, mixed> $server 透传给环境检测(内核不碰裸超全局)
     */
    public function __construct(private readonly string $root, private readonly array $server = [])
    {
        $this->configWriter = new ConfigWriter($root);
    }

    public function isLocked(): bool
    {
        return is_file($this->root . '/install/lock');
    }

    public function isInstalled(): bool
    {
        if (!$this->configWriter->exists()) {
            return false;
        }

        $config = (array) require $this->root . '/config.php';

        try {
            $db = new Database((array) ($config['database'] ?? []));

            return (new Migrator($db, $this->root . '/core/Migrations'))->version() !== '';
        } catch (Throwable) {
            // 配置在但库连不上:算"装过但坏了",交给重装分支处理,不要谎报成没装
            return true;
        }
    }

    /**
     * @param array{database?: array<string, mixed>, site?: array<string, string>, admin?: array<string, string>, debug?: bool, force?: bool, welcome?: bool} $plan
     * @return list<string> 已完成步骤,供逐步反馈
     *
     * @throws InstallFailedException 写到一半失败时抛出;表与 config.php 已回滚,路径与原因在异常上
     */
    public function install(array $plan, bool $dryRun = false): array
    {
        $this->force = (bool) ($plan['force'] ?? false);
        $this->guardAlreadyInstalled($this->force);

        $requirements = (new EnvironmentChecker($this->root, $this->server))->check();

        if (EnvironmentChecker::hasBlocker($requirements)) {
            $blockers = array_map(
                static fn (Requirement $r): string => $r->label . ':' . $r->detail,
                array_values(array_filter($requirements, static fn (Requirement $r): bool => $r->isBlocker()))
            );

            throw new ConfigException('环境不满足:' . implode('; ', $blockers));
        }

        $database = (array) ($plan['database'] ?? []);
        $site = (array) ($plan['site'] ?? []);
        $admin = (array) ($plan['admin'] ?? []);

        $this->validateSite($site);
        $this->validateAdmin($admin);

        /** @var list<string> $steps */
        $steps = ['环境检测通过'];
        $db = $this->testConnection($database, $steps);

        if ($dryRun) {
            $steps[] = '演练模式:未写 config.php、未建表、未写数据';

            return $steps;
        }

        $applied = 0;
        $migrating = false;
        $wroteConfig = false;
        $wroteLock = false;
        // 七项执行清单走到哪了(给向导失败屏标 ✗ 用);写 install/lock 属收尾,不计入
        $completed = 0;

        try {
            $this->configWriter->write($this->configWriter->build($database, (bool) ($plan['debug'] ?? false), isset($plan['timezone']) ? (string) $plan['timezone'] : null));
            $wroteConfig = true;
            $steps[] = '写入 config.php(含随机 secret_key)';
            ++$completed;

            // 从这里开始,库里 {前缀} 表只可能是本次装出来的(前缀撞车已在上面拦下),
            // 所以中途任何失败都可以把它们清干净 —— 既有规范"建表失败要回滚已建的表"。
            $migrating = true;
            $migrated = (new Migrator($db, $this->root . '/core/Migrations'))->migrate();
            $applied = count($migrated);
            $steps[] = '建表:' . ($migrated === [] ? '结构已是最新' : implode(', ', $migrated));
            ++$completed;

            $options = new Options($db);
            $this->seedOptions($options, $site, $admin);
            $steps[] = '写入初始配置';
            ++$completed;

            $adminId = $this->createAdmin($db, $admin);
            $steps[] = '创建管理员(argon2id)';
            ++$completed;

            // 欢迎文章要挂在管理员名下、又要归到「未分类」,所以排在两者之后;
            // 键缺席按"要"处理 —— 网页向导的勾选框默认就是勾上的
            $welcome = (bool) ($plan['welcome'] ?? true);
            $categoryId = $this->createDefaultCategory($db);
            $this->seedWelcomeContent($db, $adminId, $admin, $categoryId, $welcome);
            $steps[] = $welcome ? '创建默认分类「未分类」与欢迎文章、示例评论' : '创建默认分类「未分类」';
            ++$completed;

            HookMap::instance()->rebuild([]);
            $steps[] = '生成钩子懒加载索引';
            ++$completed;

            $this->makeStorageDirs();
            $steps[] = '建 storage 子目录';
            ++$completed;

            $this->writeLock();
            $wroteLock = true;
            $steps[] = '写入 install/lock';
        } catch (Throwable $e) {
            throw new InstallFailedException(
                $e->getMessage(),
                $completed,
                $this->rollback($applied, $migrating, (string) ($database['prefix'] ?? 'molin_'), $wroteConfig, $wroteLock, $db)
            );
        }

        Audit::record('install.run', 'site:' . $site['name'], true, null, 'installer');

        return $steps;
    }

    /**
     * 装完再访问安装页要拒绝;"重装"必须显式授权。
     */
    private function guardAlreadyInstalled(bool $force): void
    {
        if (!$this->isInstalled() && !$this->isLocked()) {
            return;
        }

        if (!$force) {
            throw new ConfigException('站点已安装。要重装必须显式授权(--force),并先做备份。');
        }
    }

    /**
     * 既有规范的"测试连接":装向导的这一步与 install 走同一个方法,
     * 两条路的判据不许分叉(能连上、库可建或已存在、前缀不撞别人的表)。
     *
     * @param array<string, mixed>        $database
     * @param list<string>                $steps 逐步反馈的文案,按引用收集
     * @param array<string, mixed>|null   $facts 结构化回执(服务器版本 / 当前用户 / 库状态 / 建表权限),给网页向导的连接反馈块
     *
     * @param-out array<string, mixed> $facts
     */
    public function testConnection(array $database, array &$steps, ?array &$facts = null): Database
    {
        if (isset($database['name']) && preg_match('/\A[A-Za-z0-9_]+\z/', (string) $database['name']) !== 1) {
            throw new ConfigException('数据库名只允许字母、数字、下划线');
        }

        // 先连服务器、后选库:DSN 里带上还不存在的库名,连接会在建库之前就 1049,
        // "库不存在时向导会尝试创建"这条永远走不到 —— 探针必须不带库名
        $server = $database;
        unset($server['name']);
        $db = new Database($server);

        try {
            $probe = (array) $db->selectOne('SELECT VERSION() AS v, CURRENT_USER() AS u');
        } catch (Throwable $e) {
            throw new ConfigException('数据库连不上:' . $e->getMessage() . '(检查主机、端口、账号与口令)');
        }

        $name = (string) ($database['name'] ?? '');
        $exists = $db->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name', ['name' => $name]);
        $created = false;

        if ($exists === null) {
            try {
                $db->execute('CREATE DATABASE `' . $name . '` DEFAULT CHARACTER SET utf8mb4 COLLATE ' . $db->collation());
                $steps[] = '创建数据库 ' . $name;
                $created = true;
            } catch (Throwable) {
                throw new ConfigException('数据库 ' . $name . ' 不存在,当前账号也没有建库权限 —— 请先手工建库再装');
            }
        }

        $db->execute('USE `' . $name . '`');

        // 同库里有别人的表就先拦住,免得前缀撞车把别人的数据当墨林的
        $foreign = $db->select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE :like', [
            'like' => (string) ($database['prefix'] ?? 'molin_') . '%',
        ]);

        if ($foreign !== []) {
            $prefix = (string) ($database['prefix'] ?? 'molin_');

            throw new ConfigException('前缀冲突:' . $name . ' 里已有 ' . count($foreign) . ' 张 ' . $prefix . ' 表。'
                . ($this->force
                    ? '你已授权重装,但内核不会替你删别人的表 —— 请先手工清空该库(或换一个库)再装;动之前先做好备份。'
                    : '换前缀、换库,或明确走重装'));
        }

        $steps[] = '数据库连接正常';

        $serverVersion = (string) ($probe['v'] ?? '');
        $compat = self::versionNote($serverVersion);

        if ($compat !== '') {
            $steps[] = $compat;
        }

        $facts = [
            'server_version' => $serverVersion,
            'current_user' => (string) ($probe['u'] ?? ''),
            'database_exists' => $exists !== null,
            'database_created' => $created,
            'privileges' => $this->tablePrivileges($db, $name),
            'compat' => $compat,
        ];

        return $db;
    }

    /**
     * 数据库版本提示:内核只在 MySQL 8 上验证过。5.7 / MariaDB 不一定装不上,
     * 但排序规则、JSON、窗口函数这些地方会先绊住 —— 与其装到一半报错,不如连上就说清楚。
     */
    public static function versionNote(string $version): string
    {
        if ($version === '') {
            return '';
        }

        if (stripos($version, 'mariadb') !== false) {
            return '服务器是 MariaDB ' . $version . ':内核只在 MySQL 8 上验证过,MariaDB 可尝试安装,装完请把后台各页点一遍,报错按提示改。';
        }

        if (preg_match('/\A(\d+)\.(\d+)/', $version, $m) === 1 && (int) $m[1] < 8) {
            return '服务器是 MySQL ' . $version . ':低于内核验证版本 8.0,可以尝试安装,个别语句可能失败。';
        }

        return '';
    }

    /**
     * 既有规范「权限足够(建表)」的只读探法:读 SHOW GRANTS 原文,看目标库范围里
     * 是否带 CREATE / ALTER / INDEX / DROP。第 2 步承诺"不写入任何数据",所以不试建表;
     * 认不出的授权行(角色、PROXY)跳过,一条都对不上就返回空表 —— 呈现层说"未能确认",不猜。
     *
     * @return list<string> 四项里已确认具备的
     */
    private function tablePrivileges(Database $db, string $name): array
    {
        $needed = ['CREATE', 'ALTER', 'INDEX', 'DROP'];

        try {
            $grants = $db->select('SHOW GRANTS');
        } catch (Throwable) {
            return [];
        }

        $have = [];

        foreach ($grants as $row) {
            $line = (string) (array_values($row)[0] ?? '');

            if (preg_match('/\AGRANT (.+?) ON (.+?) TO /i', $line, $m) !== 1) {
                continue;
            }

            if (!self::scopeCoversDatabase($m[2], $name)) {
                continue;
            }

            $privileges = strtoupper($m[1]);

            if (str_contains($privileges, 'ALL PRIVILEGES')) {
                return $needed;
            }

            foreach ($needed as $privilege) {
                if (str_contains($privileges, $privilege)) {
                    $have[$privilege] = true;
                }
            }
        }

        return array_values(array_intersect($needed, array_keys($have)));
    }

    /**
     * 授权行里的库范围是否覆盖目标库。
     *
     * SHOW GRANTS 的写法:`*.*`、`` `molin_db`.* ``、`` `molin\_test\_p%`.* `` —— 后者是
     * 通配授权(共享主机、面板常这么授),`%` 与 `_` 分别当"任意串 / 任意一个字符"用,
     * 只有反斜杠转义的才是字面值。2026-10-27 装库实测:只认精确库名与 `*.*`,通配授权
     * 一律报"建表权限不完整",用户明明能建表。
     */
    public static function scopeCoversDatabase(string $rawScope, string $dbName): bool
    {
        $scope = str_replace(['`', '\\"'], '', $rawScope);
        $dot = strpos($scope, '.');

        if ($dot === false) {
            return false;
        }

        $dbPart = substr($scope, 0, $dot);

        if ($dbPart === '*') {
            return true;
        }

        $chars = preg_split('//u', $dbPart, -1, PREG_SPLIT_NO_EMPTY);

        if ($chars === false) {
            $chars = [];
        }

        $regex = '';

        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $ch = $chars[$i];

            if ($ch === '\\' && $i + 1 < $n) {
                $regex .= preg_quote($chars[++$i], '/');

                continue;
            }

            if ($ch === '%') {
                $regex .= '.*';

                continue;
            }

            $regex .= $ch === '_' ? '.' : preg_quote($ch, '/');
        }

        return preg_match('/\A' . $regex . '\z/iu', $dbName) === 1;
    }

    /**
     * 既有规范的站点信息校验。公开给装向导在提交前就地报错,规则只有这一份。
     *
     * @param array<string, string> $site
     */
    public function validateSite(array $site): void
    {
        if (trim((string) ($site['name'] ?? '')) === '') {
            throw new ValidationException('站点名称不能为空', ['name' => ['必填']]);
        }
    }

    /**
     * 既有规范的管理员校验(用户名格式、邮箱、口令长度与强度)。
     * 公开给装向导在提交前就地报错,规则只有这一份 —— 网页与命令行两条路不许各有各的严格度。
     *
     * @param array<string, string> $admin
     */
    public function validateAdmin(array $admin): void
    {
        $errors = [];
        $username = (string) ($admin['username'] ?? '');
        $password = (string) ($admin['password'] ?? '');

        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{2,59}\z/i', $username) !== 1) {
            $errors['username'] = ['3–60 位,字母数字开头,可含 . _ -'];
        }

        if (filter_var((string) ($admin['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = ['邮箱格式不对'];
        }

        // 既有规范只写"≥8 位,强度检查":强度定为"字母 + 数字两类以上",不发明更复杂的规则
        if (strlen($password) < 8) {
            $errors['password'] = ['至少 8 位'];
        } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $errors['password'] = ['要同时含字母和数字'];
        }

        if ($errors !== []) {
            throw new ValidationException('管理员信息未通过校验', $errors);
        }
    }

    /**
     * @param Options $options
     * @param array<string, string> $site
     * @param array<string, string> $admin
     */
    private function seedOptions(Options $options, array $site, array $admin): void
    {
        // 只种既有规范列出的内置键;comment_* 等键名待各自模块设计稿时再种,不自创
        $options->set('site_name', (string) $site['name']);
        $options->set('site_description', (string) ($site['description'] ?? ''));
        $options->set('site_url', rtrim((string) ($site['url'] ?? ''), '/'));
        // 搜索引擎可见(既有规范的开关)。键名照 WordPress 惯例 blog_public(2026-10-03 作者定)
        //
        // 2026-10-17:缺省值由「不可见」改成「可见」(作者定:个人博客默认要能被搜到)。
        // 注意这里只看"有没有传这个键"—— 网页向导的勾选框本来就预勾着,
        // 真正把默认带成 0 的是命令行安装(`php molin install` 不带 site_visible)。
        // 不想被收录的站:向导里取消勾选,或装完去后台「设置 → 阅读」关掉。
        $options->set('blog_public', ($site['visible'] ?? '1') === '1' ? '1' : '0');
        $options->set('admin_email', (string) $admin['email']);
        $options->set('permalink_structure', '/%postname%/');
        $options->set('posts_per_page', '10');
        $options->set('active_theme', 'molin-default');
        $options->set('active_plugins', '[]');
    }

    /**
     * @param array<string, string> $admin
     * @return int 新管理员的 id(欢迎文章与示例评论要挂在它名下)
     */
    private function createAdmin(Database $db, array $admin): int
    {
        $now = date('Y-m-d H:i:s');

        $id = $db->insert('users', [
            'username'      => (string) $admin['username'],
            'email'         => (string) $admin['email'],
            'password'      => self::hashPassword((string) $admin['password']),
            'display_name'  => (string) $admin['username'],
            'nickname'      => (string) $admin['username'],
            'status'        => 'active',
            'registered_at' => $now,
        ]);

        // 角色存 usermeta(既有规范内置 meta)
        $db->insert('usermeta', ['user_id' => $id, 'meta_key' => 'role', 'meta_value' => 'administrator']);
        $db->insert('usermeta', ['user_id' => $id, 'meta_key' => 'session_version', 'meta_value' => '1']);

        return $id;
    }

    /**
     * @return int 兜底分类的 term_taxonomy_id(欢迎文章要归到它下面)
     */
    private function createDefaultCategory(Database $db): int
    {
        $termId = $db->insert('terms', ['name' => '未分类', 'slug' => TermAssigner::UNCATEGORIZED, 'term_group' => 0]);

        return $db->insert('term_taxonomy', [
            'term_id'     => $termId,
            'taxonomy'    => TermAssigner::CATEGORY,
            'description' => null,
            'parent'      => 0,
            'count'       => 0,
        ]);
    }

    /**
     * 既有规范⑤ 步的可关项:装完自带一篇欢迎文章 + 一条署名管理员的示例评论。
     *
     * 它按"一篇正常文章和一条正常评论"落库 —— 不在库里留任何"这是示例"的记号,
     * 删文章时评论跟着走,与后台手动删一篇没有区别。
     *
     * 计数与归属一律走 Content / Comment 自己的仓储(TermAssigner::recount、
     * CommentRepository::recomputeCount),不另写一套 SQL:装完的分类计数、评论计数
     * 必须与后台一致,不能新装站就先差一截。
     *
     * @param array<string, string> $admin
     */
    private function seedWelcomeContent(Database $db, int $authorId, array $admin, int $termTaxonomyId, bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        // 时间按库里的通行口径:published_at / created_at 一律 UTC
        $now = gmdate('Y-m-d H:i:s');

        $postId = $db->insert('posts', [
            'type'           => 'post',
            'status'         => 'publish',
            'author_id'      => $authorId,
            'title'          => self::WELCOME_TITLE,
            'slug'           => Slug::fromInput('', self::WELCOME_TITLE, 'post'),
            'excerpt'        => null,
            'content'        => HtmlSanitizer::clean(self::WELCOME_BODY),
            'parent_id'      => 0,
            'menu_order'     => 0,
            'comment_status' => 'open',
            'thumbnail_id'   => 0,
            'password'       => null,
            'published_at'   => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        // 归到装完自带的「未分类」,不新开词条 —— 新站不该一开始就多一个没人认领的分类
        $db->insert('term_relationships', [
            'post_id'          => $postId,
            'term_taxonomy_id' => $termTaxonomyId,
            'order'            => 0,
        ]);
        (new TermAssigner($db))->recount($termTaxonomyId);

        $db->insert('comments', [
            'post_id'      => $postId,
            'parent_id'    => 0,
            'user_id'      => $authorId,
            'author_name'  => (string) $admin['username'],
            'author_email' => (string) $admin['email'],
            'author_url'   => null,
            'author_ip'    => null,
            'author_ua'    => null,
            'content'      => HtmlSanitizer::clean(self::WELCOME_COMMENT, HtmlSanitizer::COMMENT_TAGS),
            'status'       => 'approved',
            'type'         => 'comment',
            'created_at'   => $now,
        ]);
        (new CommentRepository($db))->recomputeCount($postId);
    }

    private function makeStorageDirs(): void
    {
        foreach (self::STORAGE_DIRS as $dir) {
            $path = $this->root . '/storage/' . $dir;

            if (is_dir($path)) {
                continue;
            }

            if (file_exists($path)) {
                throw new ConfigException($path . ' 已存在但不是目录,先清掉再装');
            }

            if (!mkdir($path, 0o755, true)) {
                throw new ConfigException('建目录失败:' . $path . '(检查磁盘权限)');
            }
        }
    }

    private function writeLock(): void
    {
        $dir = $this->root . '/install';

        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new ConfigException('install 路径被一个文件占着,先删掉它才能写安装锁');
            }

            if (!mkdir($dir, 0o755, true)) {
                throw new ConfigException('建 install/ 目录失败,装完请手工删除该目录。');
            }
        }

        if (file_put_contents($dir . '/lock', 'molin installed ' . date('c') . "\n") === false) {
            throw new ConfigException('写 install/lock 失败');
        }
    }

    /**
     * 失败回滚:表 → config → lock,反序执行。每一步单独兜住,别因回滚失败盖掉原始错误。
     *
     * 迁移跑到一半炸时,$applied 还是 0(它只在 migrate() 正常返回后才赋值),
     * 光按迁移步数回滚会留下建了一半的表 —— 所以 $migrating 为真时再把 {前缀} 表扫一遍。
     * 这一步是安全的:前缀撞车在连接阶段就被拦下,能走到这里的 {前缀} 表全是本次建的。
     *
     * @return string 回滚情况说明
     */
    private function rollback(int $applied, bool $migrating, string $prefix, bool $wroteConfig, bool $wroteLock, Database $db): string
    {
        $done = [];
        $failed = [];

        if ($applied > 0) {
            try {
                $undone = (new Migrator($db, $this->root . '/core/Migrations'))->rollback($applied);
                $done[] = '删除已建表(' . count($undone) . ' 步迁移)';
            } catch (Throwable $e) {
                $failed[] = '表回滚失败:' . $e->getMessage();
            }
        }

        if ($migrating) {
            try {
                $left = $db->select(
                    'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE :like',
                    ['like' => $prefix . '%']
                );

                foreach ($left as $row) {
                    $table = (string) ($row['t'] ?? '');

                    if (preg_match('/\A[A-Za-z0-9_]+\z/', $table) === 1) {
                        $db->execute('DROP TABLE `' . $table . '`');
                    }
                }

                if ($left !== []) {
                    $done[] = '清掉建了一半的表(' . count($left) . ' 张)';
                }
            } catch (Throwable $e) {
                $failed[] = '清表失败:' . $e->getMessage();
            }
        }

        if ($wroteLock && is_file($this->root . '/install/lock')) {
            if (Files::delete($this->root . '/install/lock')) {
                @rmdir($this->root . '/install');
                $done[] = '删除 install/lock';
            } else {
                $failed[] = 'install/lock 删除失败(文件被占用),请手工删除';
            }
        }

        if ($wroteConfig) {
            if ($this->configWriter->remove()) {
                $done[] = '删除 config.php';
            } else {
                $failed[] = 'config.php 删除失败(文件被占用),请手工删除';
            }
        }

        $text = $done === [] ? '' : '(' . implode('、', $done) . ')';

        return $failed === [] ? $text : $text . ' 但有项没回滚干净:' . implode(';', $failed) . ',请手工核对';
    }

    /**
     * 哈希只有 `Molin\Security\Password` 一个出口,这里留着只是给老调用方一个转发。
     */
    public static function hashPassword(string $plain): string
    {
        return Password::hash($plain);
    }
}
