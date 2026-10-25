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
use Molin\User\ApiKeys;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 列 API 密钥(《25》第 2 节)。永远不显示明文 —— 明文只在发放那一次出现。
 */
final class ApiKeyList extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('api:key:list')
            ->setDescription('列出 API 密钥(含已撤回)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        /** @var ApiKeys $keys */
        $keys = $this->container->get(ApiKeys::class);
        $rows = $keys->list();

        if ($this->isJson($input)) {
            $this->emitJson($output, ['data' => $rows]);

            return ExitCode::OK;
        }

        if ($rows === []) {
            $output->writeln('还没有发过 API 密钥。用 molin api:key:issue <用户> 发放。');

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '#%d  %s  用户:%s(%d)  创建:%s  最近使用:%s  %s',
                $row['id'],
                $row['name'] === '' ? '(未备注)' : $row['name'],
                $row['username'],
                $row['user_id'],
                $row['created_at'],
                $row['last_used_at'] ?? '从未',
                $row['revoked_at'] === null ? '' : '已撤回 ' . $row['revoked_at']
            ));
        }

        return ExitCode::OK;
    }
}
