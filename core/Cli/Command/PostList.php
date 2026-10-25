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
use Molin\Content\PostQuery;
use Molin\Content\StatusMachine;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 列文章(《58》第 1 节的 post:list)。走的是"列表不取正文"的查询路径(《57》第 5 节)。
 */
final class PostList extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('post:list')
            ->setDescription('列出文章')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, '状态过滤', StatusMachine::PUBLISH)
            ->addOption('type', null, InputOption::VALUE_REQUIRED, '内容类型', 'post')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, '每页条数(≤100)', '20')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, '页码', '1');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $status = (string) $input->getOption('status');
        $type = (string) $input->getOption('type');
        $limit = max(1, min(100, (int) $input->getOption('limit')));
        $page = max(1, (int) $input->getOption('page'));

        $build = fn (): PostQuery => $this->posts()->query()->type($type)->status($status)->orderBy('published_at', 'desc');

        $posts = $build()->limit($limit)->page($page)->get();
        $total = $build()->count();

        if ($this->isJson($input)) {
            $this->emitJson($output, [
                'meta' => ['page' => $page, 'per_page' => $limit, 'total' => $total],
                'data' => array_map(static fn ($post): array => [
                    'id' => $post->id,
                    'title' => $post->title,
                    'slug' => $post->slug,
                    'status' => $post->status,
                    'published_at' => $post->published_at,
                    'url' => $post->url,
                    'has_content' => $post->content !== null,
                ], $posts),
            ]);

            return ExitCode::OK;
        }

        if ($posts === []) {
            $output->writeln('没有状态为「' . $status . '」的' . $type . '。');

            return ExitCode::OK;
        }

        foreach ($posts as $post) {
            $output->writeln(sprintf('#%-5d [%-7s] %s  (%s)', $post->id, $post->status, $post->title, $post->slug));
        }

        $output->writeln('共 ' . $total . ' 篇,第 ' . $page . ' 页,每页 ' . $limit . ' 篇。');

        return ExitCode::OK;
    }
}
