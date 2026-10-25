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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 删文章(《58》第 1 节的 post:delete)。
 *
 * 默认进回收站(软删,30 天后才真删);--force 才是彻底删除 ——
 * 非交互环境的删除必须显式授权(《58》第 7 节)。
 */
final class PostDelete extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('post:delete')
            ->setDescription('把文章移入回收站(--force 则彻底删除)')
            ->addArgument('id', InputArgument::REQUIRED, '文章 id');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $id = (int) $input->getArgument('id');

        try {
            if ($this->isForce($input)) {
                $this->postWriter()->forceDelete($id, 'cli');

                if ($this->isJson($input)) {
                    $this->emitJson($output, ['id' => $id, 'purged' => true]);

                    return ExitCode::OK;
                }

                $output->writeln('已彻底删除 #' . $id . '(含 meta、关系与修订)。');

                return ExitCode::OK;
            }

            $post = $this->postWriter()->trash($id, 'cli');

            if ($this->isJson($input)) {
                $this->emitJson($output, ['id' => $post->id, 'status' => $post->status, 'purged' => false]);

                return ExitCode::OK;
            }

            $output->writeln('已移入回收站 #' . $post->id . '(30 天后才真删;要立刻清干净请加 --force)。');

            return ExitCode::OK;
        } catch (ValidationException $e) {
            $output->writeln($e->getMessage());

            foreach ($e->errors() as $field => $messages) {
                $output->writeln('  ' . $field . ':' . implode(';', $messages));
            }

            return ExitCode::GENERIC;
        }
    }
}
