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
use Molin\Logger\Audit;
use Molin\User\ApiKeys;
use Molin\User\UserRepository;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 发一把 API 密钥(既有规范;发放路径按"命令行是运维正门"落在这里)。
 */
final class ApiKeyIssue extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('api:key:issue')
            ->setDescription('给用户发一把 API 密钥(明文只输出这一次)')
            ->addArgument('user', InputArgument::REQUIRED, '用户名或用户 id')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, '用途备注(便于日后撤回)', '');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $identity = (string) $input->getArgument('user');
        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);
        $user = ctype_digit($identity) ? $users->find((int) $identity) : $users->findByUsername($identity);

        if ($user === null) {
            $output->writeln('找不到用户:' . $identity);

            return ExitCode::USAGE;
        }

        /** @var ApiKeys $keys */
        $keys = $this->container->get(ApiKeys::class);
        $issued = $keys->issue($user->id, (string) $input->getOption('name'));

        // 密钥操作必须审计;只记序号与归属,绝不记明文
        Audit::record('api.key.issue', 'user:' . $user->id . ' key:' . $issued['id']);

        if ($this->isJson($input)) {
            $this->emitJson($output, ['id' => $issued['id'], 'user_id' => $user->id, 'key' => $issued['key']]);

            return ExitCode::OK;
        }

        $output->writeln('给用户 ' . $user->username . '(id ' . $user->id . ') 的密钥已发放。');
        $output->writeln('<comment>明文只显示这一次,请立即保存:</comment>');
        $output->writeln($issued['key']);

        return ExitCode::OK;
    }
}
