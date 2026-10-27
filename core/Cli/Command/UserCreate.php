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
use Molin\Exception\ValidationException;
use Molin\Logger\Audit;
use Molin\User\Roles;
use Molin\User\UserRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 建用户(既有规范的 user:create)。口令优先环境变量,别留在 shell 历史里。
 */
final class UserCreate extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('user:create')
            ->setDescription('新建一个用户')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, '用户名')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, '邮箱')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, '口令(优先用 MOLIN_USER_PASS)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, '角色', Roles::SUBSCRIBER);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $fromEnv = static function (string $option, string $env) use ($input): string {
            $value = getenv($env);

            return $value !== false && $value !== '' ? (string) $value : (string) $input->getOption($option);
        };

        $username = trim($fromEnv('user', 'MOLIN_USER_NAME'));
        $email = trim($fromEnv('email', 'MOLIN_USER_EMAIL'));
        $password = $fromEnv('password', 'MOLIN_USER_PASS');
        $role = (string) $input->getOption('role');

        if ($username === '' || $email === '' || $password === '') {
            $output->writeln('要 --user / --email / --password 三项(口令也可用环境变量 MOLIN_USER_PASS)。');

            return ExitCode::USAGE;
        }

        try {
            $user = $this->container->get(UserRepository::class)->create($username, $email, $password, $role);
        } catch (ValidationException $e) {
            $output->writeln($e->getMessage());

            foreach ($e->errors() as $field => $messages) {
                $output->writeln('  ' . $field . ':' . implode(';', $messages));
            }

            return ExitCode::GENERIC;
        }

        Audit::record('user.create', 'user:' . $user->id, true, null, 'cli');

        if ($this->isJson($input)) {
            $this->emitJson($output, ['id' => $user->id, 'username' => $user->username, 'role' => $user->role]);

            return ExitCode::OK;
        }

        $output->writeln('已建用户 #' . $user->id . ' ' . $user->username . '(' . Roles::label($user->role) . ')');

        return ExitCode::OK;
    }
}
