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
use Molin\Exception\DatabaseException;
use Molin\Kernel;
use Molin\Plugin\Loader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 站点体检(《58》第 1 节)。运维第一眼要看的东西都在这里。
 */
final class SiteInfo extends BaseCommand
{
    protected function define(): void
    {
        $this->setName('site:info')
            ->setDescription('显示站点与环境信息');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $config = (array) $this->container->get('config');
        $database = (array) ($config['database'] ?? []);
        $options = $this->options();

        $info = [
            'molin'     => Kernel::VERSION,
            'contract'  => MOLIN_CONTRACT,
            'php'       => PHP_VERSION,
            'site_url'  => (string) $options->get('site_url', ''),
            'theme'     => (string) $options->get('active_theme', 'molin-default'),
            'plugins'   => $this->container->get(Loader::class)->activeNames(),
            'db_name'   => (string) ($database['name'] ?? ''),
            'db_prefix' => (string) ($database['prefix'] ?? 'molin_'),
            'db_version' => $this->migrator()->version(),
            'pending'   => count($this->migrator()->pending()),
            'storage_writable' => is_writable($this->root() . '/storage'),
        ];

        $info += $this->counts();

        if ($this->isJson($input)) {
            $this->emitJson($output, $info);

            return ExitCode::OK;
        }

        foreach ($info as $key => $value) {
            if (is_array($value)) {
                $value = $value === [] ? '(无)' : implode(', ', $value);
            }

            $output->writeln(sprintf('%-16s %s', $key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }

        return ExitCode::OK;
    }

    /**
     * 空库或表还没建时不能把整条命令拖崩,报"未就绪"即可。
     *
     * @return array<string, mixed>
     */
    private function counts(): array
    {
        $db = $this->database();

        try {
            return [
                'posts_publish' => $this->count($db->table('posts'), 'publish'),
                'posts_draft'   => $this->count($db->table('posts'), 'draft'),
                'users'         => (int) $this->scalar('SELECT COUNT(*) AS c FROM ' . $db->table('users')),
                'comments'      => (int) $this->scalar('SELECT COUNT(*) AS c FROM ' . $db->table('comments')),
            ];
        } catch (DatabaseException) {
            return ['database' => '未就绪(先跑 molin db:migrate 或安装向导)'];
        }
    }

    private function count(string $table, string $status): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) AS c FROM ' . $table . ' WHERE status = :status',
            ['status' => $status]
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scalar(string $sql, array $params = []): mixed
    {
        $row = $this->database()->selectOne($sql, $params);

        return $row === null ? 0 : reset($row);
    }
}
