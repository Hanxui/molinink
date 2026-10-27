<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli\Command;

use Molin\Cli\BaseCommand;
use Molin\Cli\ExitCode;
use Molin\Install\Installer;
use Molin\Kernel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 命令行安装。与 Web 向导共用 Installer,两条路的装法不许分叉。
 *
 * 口令优先读环境变量(别把密码写进 shell 历史),命令行参数只作兜底。
 */
final class Install extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('install')
            ->setDescription('从命令行安装站点(建表、写配置、建管理员)')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, '数据库主机', '127.0.0.1')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, '端口', '3306')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, '库名')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, '账号')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, '口令(优先用 MOLIN_DB_PASS)')
            ->addOption('db-prefix', null, InputOption::VALUE_REQUIRED, '表前缀', 'molin_')
            ->addOption('db-socket', null, InputOption::VALUE_REQUIRED, 'unix socket 路径')
            ->addOption('site-name', null, InputOption::VALUE_REQUIRED, '站点名称')
            ->addOption('site-description', null, InputOption::VALUE_REQUIRED, '站点简介')
            ->addOption('site-url', null, InputOption::VALUE_REQUIRED, '站点地址')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, '管理员账号')
            ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, '管理员邮箱')
            ->addOption('admin-pass', null, InputOption::VALUE_REQUIRED, '管理员口令(优先用 MOLIN_ADMIN_PASS)')
            ->addOption('skip-welcome', null, InputOption::VALUE_NONE, '不建装完自带的欢迎文章与示例评论');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $plan = $this->plan($input);
        $missing = array_keys(array_filter($plan['missing'] ?? []));

        if ($missing !== []) {
            $output->writeln('缺少必填项:' . implode(', ', $missing));
            $output->writeln('可用参数或环境变量补(环境变量名见 molin install --help)。');

            return ExitCode::USAGE;
        }

        $installer = new Installer($this->root(), []);
        $dryRun = $this->isDryRun($input);

        if ($this->isJson($input)) {
            $this->emitJson($output, [
                'molin'  => Kernel::VERSION,
                'dry_run' => $dryRun,
                'steps'  => $installer->install($plan['plan'], $dryRun),
            ]);

            return ExitCode::OK;
        }

        foreach ($installer->install($plan['plan'], $dryRun) as $step) {
            $output->writeln('✓ ' . $step);
        }

        if (!$dryRun) {
            $output->writeln('');
            $output->writeln('装好了。后台地址:' . rtrim($plan['plan']['site']['url'], '/') . '/admin');
            $output->writeln('安全提醒:请删除 install/ 目录;config.php 权限建议 640。');
        }

        return ExitCode::OK;
    }

    /**
     * @return array{plan: array<string, mixed>, missing: array<string, bool>}
     */
    private function plan(InputInterface $input): array
    {
        $take = static function (string $option, string $env) use ($input): string {
            $fromEnv = getenv($env);

            return (string) ($fromEnv !== false && $fromEnv !== '' ? $fromEnv : (string) $input->getOption($option));
        };

        $dbPass = $take('db-pass', 'MOLIN_DB_PASS');
        $adminPass = $take('admin-pass', 'MOLIN_ADMIN_PASS');

        $values = [
            'db-name'  => $take('db-name', 'MOLIN_DB_NAME'),
            'db-user'  => $take('db-user', 'MOLIN_DB_USER'),
            'site-name' => $take('site-name', 'MOLIN_SITE_NAME'),
            'site-url' => $take('site-url', 'MOLIN_SITE_URL'),
            'admin-user'  => $take('admin-user', 'MOLIN_ADMIN_USER'),
            'admin-email' => $take('admin-email', 'MOLIN_ADMIN_EMAIL'),
        ];

        $missing = array_map(static fn (string $v): bool => $v === '', $values);

        $plan = [
            'database' => [
                'host'     => $take('db-host', 'MOLIN_DB_HOST'),
                'port'     => (int) $take('db-port', 'MOLIN_DB_PORT'),
                'name'     => $values['db-name'],
                'user'     => $values['db-user'],
                'password' => $dbPass,
                'prefix'   => $take('db-prefix', 'MOLIN_DB_PREFIX'),
                'charset'  => 'utf8mb4',
                'socket'   => self::nullify($take('db-socket', 'MOLIN_DB_SOCKET')),
            ],
            'site' => [
                'name'        => $values['site-name'],
                'description' => $take('site-description', 'MOLIN_SITE_DESCRIPTION'),
                'url'         => $values['site-url'],
            ],
            'admin' => [
                'username' => $values['admin-user'],
                'email'    => $values['admin-email'],
                'password' => $adminPass,
            ],
            'debug' => filter_var(self::envOr('MOLIN_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
            'force' => $this->isForce($input),
            // 与网页向导的「欢迎内容」勾选框同义:默认建,显式 --skip-welcome 才跳过
            'welcome' => !(bool) $input->getOption('skip-welcome'),
        ];

        return ['plan' => $plan, 'missing' => $missing];
    }

    private static function envOr(string $name, string $fallback): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $fallback : $value;
    }

    private static function nullify(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
