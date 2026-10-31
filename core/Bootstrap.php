<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin;

use Composer\Autoload\ClassLoader;
use Molin\Admin\AdminController;
use Molin\Admin\AppearanceController;
use Molin\Admin\CategoriesController;
use Molin\Admin\CommentsController;
use Molin\Admin\Dashboard;
use Molin\Admin\Health;
use Molin\Admin\HealthController;
use Molin\Admin\ImportExportController;
use Molin\Admin\LogsController;
use Molin\Admin\MediaController;
use Molin\Admin\MenuController;
use Molin\Admin\PostsController;
use Molin\Admin\ProfileController;
use Molin\Admin\RevisionController;
use Molin\Admin\RolesController;
use Molin\Admin\UpdateController;
use Molin\Admin\UserEditController;
use Molin\Admin\View as AdminView;
use Molin\Admin\WebAuth;
use Molin\Api\ApiController;
use Molin\Backup\Backup;
use Molin\Backup\DownloadTokens;
use Molin\Backup\Remote;
use Molin\Backup\Restore;
use Molin\Cache\CacheManager;
use Molin\Cache\PageCache;
use Molin\Cache\Psr16\FileStore;
use Molin\Cache\Psr16\NullStore;
use Molin\Cache\Purger;
use Molin\Comment\CommentRepository;
use Molin\Comment\Moderator;
use Molin\Comment\Submitter;
use Molin\Comment\Thread;
use Molin\Container\Container;
use Molin\Content\ContentRenderer;
use Molin\Content\MetaStore;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\PostWriter;
use Molin\Content\RevisionRepository;
use Molin\Content\TermAssigner;
use Molin\Cron\Cron;
use Molin\Database\Database;
use Molin\Exception\ConfigException;
use Molin\Hook\HookBus;
use Molin\Language\Translator;
use Molin\Logger\Log;
use Molin\Logger\LogReader;
use Molin\Media\MediaRepository;
use Molin\Media\Remover;
use Molin\Media\Store;
use Molin\Media\Uploader;
use Molin\Migrations\Migrator;
use Molin\Notification\Notifier;
use Molin\Option\Options;
use Molin\Plugin\Loader;
use Molin\Plugin\Settings;
use Molin\Router\Router;
use Molin\Search\Searcher;
use Molin\Security\Csrf;
use Molin\Security\LoginThrottle;
use Molin\Security\RateLimiter;
use Molin\Theme\Renderer;
use Molin\Theme\Theme;
use Molin\Theme\ThemeSettings;
use Molin\Theme\Uploader as ThemeUploader;
use Molin\Update\Feed;
use Molin\User\ApiKeys;
use Molin\User\AppTokens;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\Avatar;
use Molin\User\PasswordReset;
use Molin\User\Registrar;
use Molin\User\RememberTokens;
use Molin\User\Session;
use Molin\User\SessionStore;
use Molin\User\UserRepository;
use Molin\Site\VisitCounter;
use Molin\Site\VisitRetention;
use Molin\Social\DatabaseRatings;
use Psr\SimpleCache\CacheInterface;

/**
 * 启动装配(既有规范的顺序)。
 *
 * 单入口与 CLI 共用同一条装配路径 —— 两条路若各接一遍服务,行为迟早分叉。
 * 这里只负责"把容器接好",不注册路由、不分发请求。
 */
final class Bootstrap
{
    public static function isConfigured(string $root): bool
    {
        return is_file($root . '/config.php');
    }

    /**
     * 自动加载器必须由入口先准备好(加载本类正需要它),这里只负责接服务与配置。
     *
     * @return Container 已注册内核服务;配置缺失时抛 ConfigException(退出码 3)
     */
    public static function start(string $root, ClassLoader $autoloader): Container
    {
        if (!self::isConfigured($root)) {
            throw new ConfigException('站点尚未配置:请复制 config.example.php 为 config.php,或运行安装向导。');
        }

        /** @var array<string, mixed> $config */
        $config = require $root . '/config.php';

        // 环境判断只认这一个开关
        if (!defined('APP_DEBUG')) {
            define('APP_DEBUG', (bool) ($config['debug'] ?? false));
        }

        // 库里时间戳一律按 UTC 存(gmdate),展示与"今天/昨天"按站点时区算。
        // 不设就落到 php.ini 的 date.timezone(通常是 UTC),后台时间会整体偏 8 小时。
        $timezone = (string) ($config['timezone'] ?? 'Asia/Shanghai');
        if (in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }

        $container = new Container();
        $container->instance('config', $config);
        $container->instance(ClassLoader::class, $autoloader);
        $container->instance('root', $root);
        // 钩子总线是进程内单例:注册成服务,渲染器按容器取同一份
        $container->instance(HookBus::class, HookBus::instance());

        // 缓存统一入口(既有规范/§4):按 config.php 的 cache 段装配,并 boot 成全局实例。
        // yac / redis 扩展缺失或适配器未落地时 fromConfig 会**如实降级为文件后端**,健康屏显示真状态。
        $container->set(CacheManager::class, static function (Container $c): CacheManager {
            /** @var array<string, mixed> $config */
            $config = (array) $c->get('config');
            $manager = CacheManager::fromConfig((array) ($config['cache'] ?? []), (string) $c->get('root'));
            CacheManager::boot($manager);

            return $manager;
        });
        // 页面静态缓存:目录 storage/cache/pages,测试经 MOLIN_STATE_ROOT 换根。
        // TTL 跟数据缓存同一个口径,取 config.php 的 `cache.ttl` —— 配置里没这一节(或写成非正数)时
        // 回落 CacheManager::DEFAULT_TTL,不再由 PageCache 自己写死 3600。
        $container->set(PageCache::class, static function (Container $c): PageCache {
            /** @var array<string, mixed> $config */
            $config = (array) $c->get('config');
            $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
            $ttl = (int) ($cache['ttl'] ?? 0);

            return new PageCache(
                self::stateRoot((string) $c->get('root')) . '/storage/cache/pages',
                $ttl > 0 ? $ttl : CacheManager::DEFAULT_TTL
            );
        });
        // 立刻装配一次:PostQuery 等业务侧只经 CacheManager::instance() 取用,不等第一次读取
        $container->get(CacheManager::class);

        // 单值小结果(目前是封面主色)要的键值原语:CacheManager 只对外给 remember(),
        // 命中一条"存进去的就是 null"时无法与未命中区分。所以另起一份裸存储,
        // 后端判定与目录都跟 CacheManager 走同一份结论(它降级为文件后端时这里也是文件后端)。
        $container->set(CacheInterface::class, static function (Container $c): CacheInterface {
            $manager = $c->get(CacheManager::class);
            $directory = $manager->directory();

            if (!$manager->enabled() || $directory === '') {
                return new NullStore();
            }

            return new FileStore($directory);
        });

        $container->set(Database::class, static fn (): Database => new Database((array) ($config['database'] ?? [])));
        $container->set(Options::class, static function (Container $c): Options {
            $options = new Options($c->get(Database::class));
            // 插件配置存储与 plugin_setting 全局助手都要内核这一份 Options
            Settings::boot($options);

            return $options;
        });
        $container->set(Router::class, static function (Container $c): Router {
            $options = $c->get(Options::class);

            return new Router(
                (string) $options->get('site_url', ''),
                (string) $options->get('permalink_structure', '/%postname%/')
            );
        });
        $container->set(PostRepository::class, static fn (Container $c): PostRepository => new PostRepository(
            $c->get(Database::class),
            $c->get(Router::class)
        ));
        $container->set(ContentRenderer::class, static fn (Container $c): ContentRenderer => new ContentRenderer(
            $c->get(CacheManager::class),
            // 渲染缓存键里的插件指纹:启用的插件名 + 版本。懒加载没触发的插件也必须算进去,
            // 否则它一注册回调就换了输出而键没变 —— 用启用清单,不用"此刻已注册的回调"。
            static function () use ($c): string {
                $parts = [];

                foreach ($c->get(Loader::class)->ordered() as $plugin) {
                    $parts[] = $plugin->name() . '@' . $plugin->version();
                }

                return implode('|', $parts);
            }
        ));
        $container->set(Theme::class, static function (Container $c): Theme {
            $options = $c->get(Options::class);

            return Theme::load(
                $c->get('root') . '/themes',
                (string) $options->get('active_theme', 'molin-default')
            );
        });
        $container->set(Renderer::class, static fn (Container $c): Renderer => new Renderer(
            $c->get(Theme::class),
            $c->get(Router::class),
            $c->get(Options::class),
            $c->get(ThemeSettings::class),
            Renderer::pagesProvider($c->get(PostRepository::class)),
            Renderer::archiveProvider($c->get(PostRepository::class)),
            $c->get(HookBus::class),
            // 主题设置里的 image 字段存附件 id,模板 image(id) 要有一条取图通道
            Renderer::mediaProvider($c->get(MediaRepository::class), $c->get(Store::class)),
            // 模板 post_meta(文章 id, 键) 的数据源
            Renderer::metaProvider($c->get(MetaStore::class))
        ));
        $container->set(ThemeSettings::class, static function (Container $c): ThemeSettings {
            $settings = new ThemeSettings(
                $c->get(Database::class),
                $c->get(Options::class),
                $c->get('root') . '/themes'
            );
            // theme_setting 全局助手与 Twig 同名同源,启动时接上实例
            ThemeSettings::boot($settings);

            return $settings;
        });
        $container->set(ThemeUploader::class, static fn (Container $c): ThemeUploader => new ThemeUploader(
            $c->get('root') . '/themes',
            $c->get('root') . '/storage/trash'
        ));
        $container->set(Loader::class, static fn (Container $c): Loader => new Loader(
            $c->get(Options::class),
            $c->get(Database::class),
            $c->get('root') . '/plugins',
            static function (string $namespace, string $directory) use ($autoloader): void {
                $autoloader->addPsr4($namespace, $directory);
            }
        ));
        $container->set(RememberTokens::class, static fn (Container $c): RememberTokens => new RememberTokens($c->get(Database::class)));
        $container->set(UserRepository::class, static fn (Container $c): UserRepository => new UserRepository(
            $c->get(Database::class),
            $c->get(RememberTokens::class)
        ));
        $container->set(SessionStore::class, static fn (Container $c): SessionStore => new SessionStore($c->get(Database::class)));
        // CSRF 令牌第一次生成时必须立刻落库:否则它只活在这一趟请求的内存里,
        // 表单发出去的令牌下一次请求就查不到,任何表单都会永远判 stale(2026-10-23 修)
        Csrf::boot(static fn (Session $session): mixed => $container->get(SessionStore::class)->save($session));
        // 内建定时任务:cron:run 的取数口,到期状态落 storage/cache/data/cron.json
        $container->set(Cron::class, static fn (Container $c): Cron => new Cron(
            $c->get(SessionStore::class),
            $c->get(RememberTokens::class),
            (string) $c->get('root'),
            // 访问计数的保留策略:cleanup-visits 每天剪一次过期行
            $c->get(VisitRetention::class)
        ));
        $container->set(ApiKeys::class, static fn (Container $c): ApiKeys => new ApiKeys($c->get(Database::class)));
        $container->set(AppTokens::class, static fn (Container $c): AppTokens => new AppTokens($c->get(Database::class)));
        $container->set(LoginThrottle::class, static fn (Container $c): LoginThrottle => new LoginThrottle((string) $c->get('root')));
        // 接口限速:计数落 storage/cache/data,测例经 MOLIN_STATE_ROOT 换根
        $container->set(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter((string) $c->get('root')));
        $container->set(Auth::class, static fn (Container $c): Auth => new Auth(
            $c->get(UserRepository::class),
            $c->get(SessionStore::class),
            $c->get(LoginThrottle::class),
            $c->get(RememberTokens::class)
        ));
        $container->set(Authorizer::class, static fn (): Authorizer => new Authorizer());
        // 写入链也要在 Web 侧可用:后台文章屏靠它,不能再像 CLI 那样手搓一遍
        $container->set(MetaStore::class, static fn (Container $c): MetaStore => new MetaStore($c->get(Database::class)));
        $container->set(TermAssigner::class, static fn (Container $c): TermAssigner => new TermAssigner($c->get(Database::class)));
        $container->set(PostWriter::class, static fn (Container $c): PostWriter => new PostWriter(
            $c->get(Database::class),
            $c->get(PostRepository::class),
            $c->get(MetaStore::class),
            $c->get(TermAssigner::class)
        ));
        $container->set(Registrar::class, static fn (Container $c): Registrar => new Registrar(
            $c->get(UserRepository::class),
            $c->get(Options::class)
        ));
        // 后台是内核能力,视图不放主题:换前台皮肤不该动管理界面,主题模板里也不许写业务
        $container->set(AdminView::class, static fn (Container $c): AdminView => new AdminView(
            $c->get(Router::class),
            $c->get(Options::class)
        ));
        // 媒体库:落盘目录跟着站点根走,storage/uploads 不对外(约定)
        $container->set(MediaRepository::class, static fn (Container $c): MediaRepository => new MediaRepository($c->get(Database::class)));
        $container->set(Store::class, static fn (Container $c): Store => new Store($c->get('root')));
        $container->set(Uploader::class, static fn (Container $c): Uploader => new Uploader(
            $c->get(Store::class),
            $c->get(MediaRepository::class),
            $c->get(Options::class)
        ));
        $container->set(Remover::class, static fn (Container $c): Remover => new Remover($c->get(MediaRepository::class), $c->get(Store::class)));
        $container->set(MediaController::class, static fn (Container $c): MediaController => new MediaController(
            $c->get(AdminView::class),
            $c->get(MediaRepository::class),
            $c->get(Uploader::class),
            $c->get(Remover::class),
            $c->get(Store::class),
            $c->get(Options::class),
            $c->get(Authorizer::class),
            $c->get(PostRepository::class)
        ));
        // 评论:存取与提交流各一个类;反垃圾的"最低限度"在 Submitter,智能判定交插件
        $container->set(CommentRepository::class, static fn (Container $c): CommentRepository => new CommentRepository($c->get(Database::class)));

        // 按天访问计数(2026-10-31 增):主题的访问统计条与 /stats/visits 端点共用
        $container->set(VisitCounter::class, static fn (Container $c): VisitCounter => new VisitCounter($c->get(Database::class)));
        // 访问计数的保留策略(2026-10-31 增):只增不删就是无界增长,cron 的 cleanup-visits 用这一份
        $container->set(VisitRetention::class, static fn (Container $c): VisitRetention => new VisitRetention(
            $c->get(Database::class),
            $c->get(Options::class)
        ));

        // 赞/踩(2026-10-31 增):内核自带实现,赞不再依赖插件才能用。
        // 装配层(FrontRoutes)的取法是「插件在就用插件的,不在就用这一份」。
        $container->set(DatabaseRatings::class, static fn (Container $c): DatabaseRatings => new DatabaseRatings($c->get(Database::class)));

        // 关注/粉丝统计、站内通知的实现仍由插件经 social/services/collect 注入,内核不注册实现类

        // 状态迁移唯一门(mark 批准/垃圾/回收):后台屏与接口层共用,钩子只在这里触发一次
        $container->set(Moderator::class, static fn (Container $c): Moderator => new Moderator($c->get(CommentRepository::class)));
        // 前台评论树(既有规范/§3):已通过 + "仅你可见"的待审,按 comment_nest_depth 压平
        $container->set(Thread::class, static fn (Container $c): Thread => new Thread(
            $c->get(CommentRepository::class),
            $c->get(Options::class),
            $c->get(Avatar::class)
        ));
        $container->set(Submitter::class, static fn (Container $c): Submitter => new Submitter(
            $c->get(CommentRepository::class),
            $c->get(PostRepository::class),
            $c->get(Options::class),
            $c->get(Authorizer::class)
        ));
        // 仪表盘的取数与缓存清理:取数只读,清理只删编译产物,别的一概不碰
        $container->set(Dashboard::class, static fn (Container $c): Dashboard => new Dashboard(
            $c->get(Database::class),
            $c->get(PostRepository::class),
            $c->get(UserRepository::class),
            $c->get(Router::class),
            // 仪表盘的「程序版本」行只读「更新」页检查后的清单缓存(不联网)
            new Feed($c->get(Options::class))
        ));
        $container->set(Backup::class, static fn (Container $c): Backup => new Backup($c->get(Database::class), $c->get('root')));
        $container->set(Restore::class, static fn (Container $c): Restore => new Restore($c->get(Backup::class), $c->get(Database::class), $c->get('root')));
        $container->set(DownloadTokens::class, static fn (Container $c): DownloadTokens => new DownloadTokens($c->get('root')));
        $container->set(Remote::class, static fn (Container $c): Remote => new Remote((string) $c->get('root')));
        // i18n:语言目录常年只读,启动就位;__ / _e 全局助手靠 boot
        $container->set(Translator::class, static function (Container $c): Translator {
            $translator = new Translator(
                (string) $c->get('root'),
                Translator::readCatalog((string) $c->get('root')),
                $c->get(Options::class)
            );
            Translator::boot($translator);

            return $translator;
        });
        // 头像助手:模板随时会叫 avatar,boot 放在装配期,省得第一屏拿到降级实例
        $container->set(Avatar::class, static function (Container $c): Avatar {
            $avatar = new Avatar(
                $c->get(UserRepository::class),
                $c->get(MediaRepository::class),
                $c->get(Store::class),
                $c->get(Options::class)
            );
            Avatar::boot($avatar);

            return $avatar;
        });
        $container->get(Avatar::class);
        $container->set(Purger::class, static fn (Container $c): Purger => new Purger($c->get('root') . '/storage/cache/twig'));
        $container->set(PostsController::class, static fn (Container $c): PostsController => new PostsController(
            $c->get(AdminView::class),
            $c->get(PostRepository::class),
            $c->get(PostWriter::class),
            $c->get(UserRepository::class),
            $c->get(TermAssigner::class),
            $c->get(Authorizer::class),
            $c->get(MediaRepository::class),
            $c->get(Store::class),
            $c->get(MetaStore::class),
            $c->get(Database::class),
            'post',
            $c->get(Theme::class)
        ));
        $container->set(AdminController::class, static fn (Container $c): AdminController => new AdminController(
            $c->get(AdminView::class),
            $c->get(Options::class),
            $c->get(Authorizer::class),
            $c->get(Loader::class),
            $c->get(UserRepository::class),
            $c->get(Router::class),
            $c->get(Database::class),
            $c->get(PostsController::class),
            $c->get(MediaController::class),
            $c->get(Dashboard::class),
            $c->get(Purger::class),
            $c->get(CommentsController::class),
            $c->get(Backup::class),
            $c->get(Restore::class),
            $c->get(DownloadTokens::class),
            $c->get(Remote::class),
            $c->get(AppearanceController::class),
            new PostsController(
                $c->get(AdminView::class),
                $c->get(PostRepository::class),
                $c->get(PostWriter::class),
                $c->get(UserRepository::class),
                $c->get(TermAssigner::class),
                $c->get(Authorizer::class),
                $c->get(MediaRepository::class),
                $c->get(Store::class),
                $c->get(MetaStore::class),
                $c->get(Database::class),
                'page',
                $c->get(Theme::class)
            ),
            $c->get(ProfileController::class),
            $c->get(LogsController::class),
            $c->get(HealthController::class),
            $c->get(UserEditController::class),
            $c->get(CategoriesController::class),
            $c->get(RolesController::class),
            $c->get(RevisionController::class),
            $c->get(MenuController::class),
            $c->get(ImportExportController::class),
            $c->get(UpdateController::class),
            $c->get(TermAssigner::class)
        ));
        $container->set(AppearanceController::class, static fn (Container $c): AppearanceController => new AppearanceController(
            $c->get(AdminView::class),
            $c->get(Options::class),
            $c->get(Router::class),
            $c->get(ThemeSettings::class),
            $c->get(ThemeUploader::class),
            $c->get(MediaRepository::class),
            $c->get(Store::class),
            $c->get(Authorizer::class),
            $c->get('root') . '/themes',
            $c->get('root') . '/storage/trash'
        ));
        // 个人资料屏:只经手登录者自己的数据
        $container->set(ProfileController::class, static fn (Container $c): ProfileController => new ProfileController(
            $c->get(AdminView::class),
            $c->get(UserRepository::class),
            $c->get(Avatar::class),
            $c->get(Uploader::class),
            $c->get(Remover::class),
            $c->get(Translator::class),
            $c->get(Options::class),
            $c->get(SessionStore::class),
            $c->get(Router::class)
        ));
        // 日志查看器:只读屏,数据源是 storage/logs 的文件(读取根可被 MOLIN_LOG_ROOT 重定向)
        $container->set(LogReader::class, static fn (): LogReader => new LogReader());
        $container->set(LogsController::class, static fn (Container $c): LogsController => new LogsController(
            $c->get(AdminView::class),
            $c->get(LogReader::class),
            $c->get(Router::class)
        ));
        // 站点健康:只读体检屏;18 项检查的取数在 Health,结构版本对着 core/Migrations 的清单数
        $container->set(Migrator::class, static fn (Container $c): Migrator => new Migrator(
            $c->get(Database::class),
            $c->get('root') . '/core/Migrations'
        ));
        $container->set(Health::class, static fn (Container $c): Health => new Health(
            $c->get(Database::class),
            $c->get(Migrator::class),
            $c->get(Backup::class),
            $c->get(LogReader::class),
            $c->get(Router::class),
            // 「更新可用」行只读「更新」页检查后的清单缓存(不联网)
            new Feed($c->get(Options::class))
        ));
        $container->set(HealthController::class, static fn (Container $c): HealthController => new HealthController(
            $c->get(AdminView::class),
            $c->get(Health::class),
            $c->get(Router::class),
            // 2026-10-17:健康屏改成"按了才测",检测结果要存一份快照给下一次进页面读
            $c->get(Options::class)
        ));
        // 用户新建与编辑:进页/保存保守挂在 user.create(既有规范待裁)
        $container->set(UserEditController::class, static fn (Container $c): UserEditController => new UserEditController(
            $c->get(AdminView::class),
            $c->get(UserRepository::class),
            $c->get(Authorizer::class),
            $c->get(Router::class)
        ));
        // 分类与标签:一页两态,写动作三条 POST;门控 category.manage 由页面表声明
        $container->set(CategoriesController::class, static fn (Container $c): CategoriesController => new CategoriesController(
            $c->get(AdminView::class),
            $c->get(TermAssigner::class),
            $c->get(Router::class)
        ));
        // 角色能力矩阵:只读屏,数据全走 Roles/Capabilities 登记表;「来源:」认已启用插件的清单申报
        $container->set(RolesController::class, static fn (Container $c): RolesController => new RolesController(
            $c->get(AdminView::class),
            $c->get(Loader::class),
            $c->get(Router::class)
        ));
        // 修订版本比对:属「文章」组;post_revisions 只读,"恢复"本轮只留痕(§11-4 语义待裁)
        $container->set(RevisionRepository::class, static fn (Container $c): RevisionRepository => new RevisionRepository(
            $c->get(Database::class)
        ));
        $container->set(RevisionController::class, static fn (Container $c): RevisionController => new RevisionController(
            $c->get(AdminView::class),
            $c->get(PostRepository::class),
            $c->get(RevisionRepository::class),
            $c->get(UserRepository::class),
            $c->get(Router::class),
            $c->get(Authorizer::class)
        ));
        // 菜单编辑器:属「外观」组,配置写进 Options;门控 theme.switch
        $container->set(MenuController::class, static fn (Container $c): MenuController => new MenuController(
            $c->get(AdminView::class),
            $c->get(Options::class),
            $c->get(Router::class),
            $c->get(PostRepository::class),
            $c->get(TermAssigner::class)
        ));
        // 导入与导出:属「工具」组;走白名单表结构,不做任意 SQL;门控 setting.manage
        $container->set(ImportExportController::class, static fn (Container $c): ImportExportController => new ImportExportController(
            $c->get(AdminView::class),
            $c->get(Database::class),
            $c->get(Options::class),
            $c->get(Router::class),
            $c->get(Authorizer::class)
        ));
        // 内核更新:属「设置」组,门控 update.core;清单源与九步执行链都接在这里
        $container->set(UpdateController::class, static fn (Container $c): UpdateController => new UpdateController(
            $c->get(AdminView::class),
            $c->get(Options::class),
            $c->get(Router::class),
            $c->get(Health::class),
            new Feed($c->get(Options::class)),
            $c->get(Database::class)
        ));
        $container->set(Searcher::class, static fn (Container $c): Searcher => new Searcher($c->get(Database::class)));
        $container->set(ApiController::class, static fn (Container $c): ApiController => new ApiController(
            $c->get(PostRepository::class),
            $c->get(UserRepository::class),
            $c->get(Options::class),
            $c->get(Searcher::class),
            $c->get(Router::class),
            $c->get(PostWriter::class),
            $c->get(Authorizer::class),
            $c->get(Moderator::class),
            $c->get(CommentRepository::class),
            $c->get(ApiKeys::class),
            $c->get(AppTokens::class),
            $c->get(Auth::class),
            $c->get(RateLimiter::class),
            $c->get(MediaRepository::class),
            $c->get(Uploader::class),
            $c->get(Remover::class),
            $c->get(Store::class),
            $c->get(TermAssigner::class),
            $c->get(Submitter::class)
        ));
        $container->set(CommentsController::class, static fn (Container $c): CommentsController => new CommentsController(
            $c->get(AdminView::class),
            $c->get(CommentRepository::class),
            $c->get(Authorizer::class),
            $c->get(Database::class),
            $c->get(Router::class),
            $c->get(Moderator::class)
        ));
        $container->set(PasswordReset::class, static fn (Container $c): PasswordReset => new PasswordReset(
            $c->get(Database::class),
            $c->get(UserRepository::class),
            $c->get(Router::class)
        ));

        $container->set(WebAuth::class, static fn (Container $c): WebAuth => new WebAuth(
            $c->get(AdminView::class),
            $c->get(Auth::class),
            $c->get(Registrar::class),
            $c->get(SessionStore::class),
            $c->get(PasswordReset::class)
        ));

        /*
         * 缓存失效接线(既有规范/§6.3),按影响范围分三档 —— 能从事件里认出"只动了哪一篇"的
         * 就只清那一篇,认不出来的保持整片清。原则:宁可多清一份,不能留下脏页。
         *
         *   事件                                 数据缓存                        整页缓存
         *   评论提交 / 评论审核                  不动                           只删该篇文章的页面
         *   内容保存 / 删除 / 状态迁移           post 标签                       整片清(列表、首页、归档、上下篇都变)
         *   插件启停                             全清                           整片清(内容类型、路由、模板都可能变)
         *   cache/invalidate/after               带了标签按标签清,没带全清       整片清
         */
        $contentChanged = static function (mixed $post = null) use ($container): void {
            CacheManager::instance()->invalidateTags(['post']);
            $container->get(PageCache::class)->purge();
        };

        foreach ([
            'content/save/after',      // 文章 / 页面保存(带上 $post)
            'content/delete/before',   // 文章 / 页面删除(带上 $post)
            'post/status/after',       // 回收站 / 状态迁移(带上 $post)
        ] as $hook) {
            add_action($hook, $contentChanged);
        }

        /*
         * 评论只影响它所在的那一篇文章页:评论区在文章页里,列表页与首页不显示评论数
         * (主题的 badge 只出现在单篇模板),PostQuery 的查询缓存也没有评论相关键。
         * 所以这里按地址精确删,不清全站、不 flushAll —— 一条评论不再把整站缓存打掉。
         */
        $purgeCommentPages = static function (int $postId) use ($container): void {
            $pageCache = $container->get(PageCache::class);

            // 认不出是哪一篇(文章已删 / 接口没带 post_id)就退回整片清,不留脏页
            $post = $postId > 0 ? $container->get(PostRepository::class)->find($postId, true) : null;

            if ($post === null) {
                $pageCache->purge();

                return;
            }

            $pageCache->purgeUris(self::pageUris($container, $post));
        };

        // 提交:(comment_id, post_id)
        add_action('comment/submit/after', static function (mixed $commentId, mixed $postId = 0) use ($purgeCommentPages): void {
            $purgeCommentPages((int) $postId);
        }, 10, 2);

        // 审核通过 / 驳回 / 垃圾:(comment_id, status) —— 没有 post_id,回查一次评论行
        add_action('comment/moderate/after', static function (mixed $commentId, mixed $status = '') use ($container, $purgeCommentPages): void {
            $id = (int) $commentId;
            $row = $id > 0 ? $container->get(CommentRepository::class)->find($id) : null;

            $purgeCommentPages((int) ($row['post_id'] ?? 0));
        }, 10, 2);

        // 插件启停会改内容类型、路由、模板,影响范围推不出来:数据缓存与整页缓存都整片清
        $everythingChanged = static function () use ($container): void {
            CacheManager::instance()->flushAll();
            $container->get(PageCache::class)->purge();
        };

        foreach ([
            'plugin/activate/after',
            'plugin/deactivate/after',
        ] as $hook) {
            add_action($hook, $everythingChanged);
        }

        /*
         * 主题启停 / 主题设置 / 菜单更新带一个标签来:能按标签清就只清这一批。
         * 标签从没登记过时 invalidateTags 自己会退回全清,不会漏。
         */
        add_action('cache/invalidate/after', static function (mixed $tag = null) use ($container): void {
            $label = is_scalar($tag) ? trim((string) $tag) : '';

            if ($label === '') {
                CacheManager::instance()->flushAll();
            } else {
                CacheManager::instance()->invalidateTags([$label]);
            }

            $container->get(PageCache::class)->purge();
        });

        // 新评论/回复写入站内通知（接收人 = 文章作者，原版口径）。
        // 实现由插件经 social/services/collect 注入；插件未启用时这里什么也不做。
        // 通知写失败不能让评论提交跟着 500：错误进日志，评论本身照常成功。
        add_action('comment/submit/after', static function (int $id, int $postId): void {
            $services = apply_filters('social/services/collect', ['social' => null, 'notifications' => null, 'notifier' => null]);
            $notifier = is_array($services) ? ($services['notifier'] ?? null) : null;

            if (!$notifier instanceof Notifier) {
                return;
            }

            try {
                $notifier->comment($id, $postId);
            } catch (\Throwable $e) {
                Log::channel('app')->error('notification.comment_failed', [
                    'comment_id' => $id,
                    'post_id' => $postId,
                    'error' => $e->getMessage(),
                ]);
            }
        }, 10, 2);

        return $container;
    }

    /**
     * 站点状态根:MOLIN_STATE_ROOT 优先(测试 / 隔离运行换根),否则就是站点根。
     * 与 RateLimiter / LoginThrottle / Cron 同一套口径。
     */
    private static function stateRoot(string $root): string
    {
        $state = getenv('MOLIN_STATE_ROOT');

        return is_string($state) && $state !== '' ? $state : $root;
    }

    /**
     * 一篇文章在整页缓存里可能占的地址(PageCache 按 REQUEST_URI 原样哈希,所以这里给候选)。
     *
     * 固定链接结构、site_url 的子路径只有 Router / 配置知道,所以候选在装配线生成:
     * 主地址、带子目录前缀的地址(REQUEST_URI 含 /blog 这类前缀时)、尾斜杠两种写法,
     * 以及查询式入口 `/?p={id}`。正文分页(`/{slug}/2/`)内核还没实现,先不猜 ——
     * 真要做时在这里补一条就行,页面缓存本身不用改。
     *
     * @return list<string>
     */
    private static function pageUris(Container $container, Post $post): array
    {
        $path = $container->get(Router::class)->relative($post->url);
        $site = (string) $container->get(Options::class)->get('site_url', '');
        $base = rtrim((string) (parse_url($site, PHP_URL_PATH) ?? ''), '/');

        $candidates = [$path];

        if ($base !== '' && str_starts_with($path, '/')) {
            $candidates[] = $base . $path;
        }

        foreach ($candidates as $candidate) {
            // 带查询串的地址没有"尾斜杠写法"这回事,跳过
            if ($candidate === '' || str_contains($candidate, '?')) {
                continue;
            }

            $candidates[] = str_ends_with($candidate, '/') ? rtrim($candidate, '/') : $candidate . '/';
        }

        $candidates[] = $base . '/?p=' . $post->id;

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $uri): bool => $uri !== ''
        )));
    }
}
