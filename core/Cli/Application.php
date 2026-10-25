<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli;

use Molin\Cli\Command\ApiKeyIssue;
use Molin\Cli\Command\ApiKeyList;
use Molin\Cli\Command\ApiKeyRevoke;
use Molin\Cli\Command\BackupCreate;
use Molin\Cli\Command\BackupList;
use Molin\Cli\Command\BackupRestore;
use Molin\Cli\Command\CoreVersion;
use Molin\Cli\Command\CronList;
use Molin\Cli\Command\CronRun;
use Molin\Cli\Command\DbMigrate;
use Molin\Cli\Command\DbRollback;
use Molin\Cli\Command\I18nCheck;
use Molin\Cli\Command\I18nExtract;
use Molin\Cli\Command\Install;
use Molin\Cli\Command\KeyGenerate;
use Molin\Cli\Command\PluginActivate;
use Molin\Cli\Command\PluginDeactivate;
use Molin\Cli\Command\PluginList;
use Molin\Cli\Command\PostCreate;
use Molin\Cli\Command\PostDelete;
use Molin\Cli\Command\PostList;
use Molin\Cli\Command\SiteInfo;
use Molin\Cli\Command\ThemeExportSettings;
use Molin\Cli\Command\ThemeImportSettings;
use Molin\Cli\Command\UserCreate;
use Molin\Cli\Command\UserList;
use Molin\Cli\Command\UserPassword;
use Molin\Container\Container;
use Molin\Error\ErrorHandler;
use Molin\Kernel;
use Molin\Support\ErrorId;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * molin 命令表(《58-CLI工具》第 1 节)。
 *
 * 自己接管异常与退出码:Console 默认只会给 0/1,而《58》第 4 节要求 3=配置、4=数据库、5=权限,
 * 脚本要按这些码分支。
 */
final class Application extends ConsoleApplication
{
    public function __construct(Container $container)
    {
        parent::__construct('molin', Kernel::VERSION);

        // 退出码由本类统一决定,不能让 Console 自己 exit
        $this->setCatchExceptions(false);
        $this->setAutoExit(false);

        foreach (self::commands($container) as $command) {
            $this->add($command);
        }
    }

    /**
     * @return list<BaseCommand>
     */
    private static function commands(Container $container): array
    {
        return [
            new Install($container),
            new DbMigrate($container),
            new DbRollback($container),
            new SiteInfo($container),
            new CoreVersion($container),
            new KeyGenerate($container),
            new ApiKeyIssue($container),
            new ApiKeyList($container),
            new ApiKeyRevoke($container),
            new PostCreate($container),
            new PostList($container),
            new PostDelete($container),
            new PluginList($container),
            new PluginActivate($container),
            new PluginDeactivate($container),
            new UserList($container),
            new UserCreate($container),
            new UserPassword($container),
            new BackupCreate($container),
            new BackupList($container),
            new BackupRestore($container),
            new ThemeExportSettings($container),
            new ThemeImportSettings($container),
            new I18nCheck($container),
            new I18nExtract($container),
            new CronRun($container),
            new CronList($container),
        ];
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $out = $output ?? new ConsoleOutput();

        try {
            return parent::run($input, $out);
        } catch (Throwable $e) {
            $errorId = ErrorId::generate();
            ErrorHandler::log($e, $errorId);
            // 错误走 stderr,便于脚本 2>/dev/null 后只取正常输出
            ($out instanceof ConsoleOutput ? $out->getErrorOutput() : $out)
                ->writeln(ErrorHandler::plainText($e, $errorId));

            return ExitCode::fromException($e);
        }
    }
}
