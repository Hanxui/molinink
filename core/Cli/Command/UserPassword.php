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
use Molin\User\UserRepository;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 改口令(《58》第 1 节的 user:password)。
 *
 * 改完 session_version 会 +1,该用户手上的会话当场失效(《32》第 8 节)。
 */
final class UserPassword extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('user:password')
            ->setDescription('修改用户口令(旧会话随之失效)')
            ->addArgument('user', InputArgument::REQUIRED, '用户名')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, '新口令(优先用 MOLIN_USER_PASS)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $fromEnv = getenv('MOLIN_USER_PASS');
        $password = $fromEnv !== false && $fromEnv !== '' ? (string) $fromEnv : (string) $input->getOption('password');

        if ($password === '') {
            $output->writeln('要新口令(--password=… 或环境变量 MOLIN_USER_PASS)。');

            return ExitCode::USAGE;
        }

        $repo = $this->container->get(UserRepository::class);
        $user = $repo->findByUsername(trim((string) $input->getArgument('user')));

        if ($user === null) {
            $output->writeln('没有这个用户。');

            return ExitCode::GENERIC;
        }

        try {
            $changed = $repo->changePassword($user->id, $password);
        } catch (ValidationException $e) {
            $output->writeln($e->getMessage() . ' → ' . implode(';', $e->errors()['password'] ?? []));

            return ExitCode::GENERIC;
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, ['id' => $changed->id, 'session_version' => $changed->sessionVersion]);

            return ExitCode::OK;
        }

        $output->writeln('已改密。会话版本号现在是 ' . $changed->sessionVersion . '(此前的登录状态全部作废)。');

        return ExitCode::OK;
    }
}
