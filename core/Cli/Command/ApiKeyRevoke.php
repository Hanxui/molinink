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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 撤回一把 API 密钥。不删行,留痕(撤回了哪把、什么时候)。
 */
final class ApiKeyRevoke extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('api:key:revoke')
            ->setDescription('撤回一把 API 密钥(按编号,api:key:list 可查)')
            ->addArgument('id', InputArgument::REQUIRED, '密钥编号');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = max(0, (int) $input->getArgument('id'));

        /** @var ApiKeys $keys */
        $keys = $this->container->get(ApiKeys::class);

        if (!$keys->revoke($id)) {
            $output->writeln('没有这把待撤回的密钥(不存在,或已经撤回过了):#' . $id);

            return ExitCode::USAGE;
        }

        Audit::record('api.key.revoke', 'key:' . $id);

        if ($this->isJson($input)) {
            $this->emitJson($output, ['id' => $id, 'revoked' => true]);

            return ExitCode::OK;
        }

        $output->writeln('已撤回密钥 #' . $id . ',使用它的请求从现在起回 401。');

        return ExitCode::OK;
    }
}
