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
use Molin\Content\StatusMachine;
use Molin\Exception\ConfigException;
use Molin\Exception\ValidationException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 新建文章(既有规范的 post:create)。后台编辑页落地前,这是内容的正门。
 */
final class PostCreate extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('post:create')
            ->setDescription('新建一篇文章')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, '标题')
            ->addOption('content', null, InputOption::VALUE_REQUIRED, '正文(HTML)', '')
            ->addOption('excerpt', null, InputOption::VALUE_REQUIRED, '摘要', '')
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, '短名(不填由标题生成)', '')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, '状态:' . implode('/', StatusMachine::CREATABLE), StatusMachine::DRAFT)
            ->addOption('author', null, InputOption::VALUE_REQUIRED, '作者用户名(不填用最早注册的那个)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '分类名,可重复')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '标签名,可重复');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $title = trim((string) $input->getOption('title'));

        if ($title === '') {
            $output->writeln('标题不能空(--title=…)');

            return ExitCode::USAGE;
        }

        $authorId = $this->authorId($input);
        $status = (string) $input->getOption('status');

        try {
            $post = $this->postWriter()->save([
                'type' => 'post',
                'status' => $status,
                'title' => $title,
                'content' => (string) $input->getOption('content'),
                'excerpt' => (string) $input->getOption('excerpt'),
                'slug' => (string) $input->getOption('slug'),
                'categories' => (array) $input->getOption('category'),
                'tags' => (array) $input->getOption('tag'),
            ], $authorId);
        } catch (ValidationException $e) {
            $output->writeln($e->getMessage());

            foreach ($e->errors() as $field => $messages) {
                $output->writeln('  ' . $field . ':' . implode(';', $messages));
            }

            return ExitCode::GENERIC;
        }

        if ($this->isJson($input)) {
            $this->emitJson($output, [
                'id' => $post->id,
                'slug' => $post->slug,
                'status' => $post->status,
                'url' => $post->url,
                'author_id' => $authorId,
            ]);

            return ExitCode::OK;
        }

        $output->writeln('已保存 #' . $post->id . ' [' . $post->status . '] ' . $post->title);
        $output->writeln('短名:' . $post->slug);
        $output->writeln('地址:' . $post->url);

        return ExitCode::OK;
    }

    private function authorId(InputInterface $input): int
    {
        $db = $this->database();
        $username = trim((string) $input->getOption('author'));

        if ($username !== '') {
            $row = $db->selectOne(
                'SELECT id FROM ' . $db->table('users') . ' WHERE username = :u',
                ['u' => $username]
            );

            if ($row === null) {
                throw new ConfigException('没有这个用户:' . $username);
            }

            return (int) $row['id'];
        }

        $row = $db->selectOne('SELECT id FROM ' . $db->table('users') . ' ORDER BY id LIMIT 1');

        if ($row === null) {
            throw new ConfigException('库里还没有用户 —— 先跑 molin install 建管理员,再来写文章。');
        }

        return (int) $row['id'];
    }
}
