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
use Molin\User\Roles;
use Molin\User\UserRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 列用户(《58》第 1 节的 user:list)。
 */
final class UserList extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('user:list')
            ->setDescription('列出用户与角色')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, '只看某个角色')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, '每页条数(≤100)', '20')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, '页码', '1');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->container->get(UserRepository::class);
        $role = $input->getOption('role') === null ? null : (string) $input->getOption('role');
        $page = max(1, (int) $input->getOption('page'));
        $limit = max(1, min(100, (int) $input->getOption('limit')));

        if ($role !== null && !Roles::exists($role)) {
            $output->writeln('没有这个角色:' . $role . '(可用:' . implode(', ', Roles::all()) . ')');

            return ExitCode::USAGE;
        }

        $users = $repo->paginate($page, $limit, $role);

        if ($this->isJson($input)) {
            $this->emitJson($output, ['data' => array_map(static fn ($u): array => [
                'id' => $u->id,
                'username' => $u->username,
                'email' => $u->email,
                'role' => $u->role,
                'status' => $u->status,
            ], $users)]);

            return ExitCode::OK;
        }

        foreach ($users as $user) {
            $output->writeln(sprintf('#%-5d %-20s %-14s %s%s', $user->id, $user->username, Roles::label($user->role), $user->email, $user->status === 'active' ? '' : ' [' . $user->status . ']'));
        }

        $output->writeln('共 ' . $repo->count() . ' 个用户。');

        return ExitCode::OK;
    }
}
