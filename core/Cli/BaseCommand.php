<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Cli;

use Molin\Container\Container;
use Molin\Content\MetaStore;
use Molin\Content\PostRepository;
use Molin\Content\PostWriter;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Exception\ConfigException;
use Molin\Migrations\Migrator;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Theme\ThemeSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * 命令基类:统一挂《58-CLI工具》第 3 节的全局选项,并把子类的工作集中在 handle()。
 *
 * 命令名一旦发布不改(《58》第 9 节),所以名字写死在这里、不靠约定生成。
 */
abstract class BaseCommand extends Command
{
    public function __construct(protected readonly Container $container)
    {
        parent::__construct();
    }

    abstract protected function define(): void;

    /**
     * @return int ExitCode 常量
     */
    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    protected function configure(): void
    {
        $this->define();

        $this->addOption('json', null, InputOption::VALUE_NONE, '输出 JSON,供脚本消费');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, '只演练,不落库');
        $this->addOption('force', null, InputOption::VALUE_NONE, '非交互下执行破坏性动作');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->handle($input, $output);
    }

    protected function isJson(InputInterface $input): bool
    {
        return (bool) $input->getOption('json');
    }

    protected function isDryRun(InputInterface $input): bool
    {
        return (bool) $input->getOption('dry-run');
    }

    protected function isForce(InputInterface $input): bool
    {
        return (bool) $input->getOption('force');
    }

    /**
     * 密码类输入的取法(《58》§7:优先提示输入,脚本走环境变量,不写进 shell 历史)。
     */
    protected function askSecret(InputInterface $input, OutputInterface $output, string $label, string $env): string
    {
        if ($input->isInteractive()) {
            $question = new Question($label . ': ');
            $question->setHidden(true);
            $helper = $this->getHelper('question');

            if ($helper instanceof QuestionHelper) {
                return (string) $helper->ask($input, $output, $question);
            }

            return '';
        }

        $value = getenv($env);

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function emitJson(OutputInterface $output, array $payload): void
    {
        $output->writeln((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ));
    }

    protected function database(): Database
    {
        return $this->service(Database::class);
    }

    protected function options(): Options
    {
        return $this->service(Options::class);
    }

    protected function root(): string
    {
        return (string) $this->container->get('root');
    }

    /**
     * 建表只有一条来源(约定),CLI 与安装向导共用同一个执行器。
     */
    protected function migrator(): Migrator
    {
        return new Migrator($this->database(), $this->root() . '/core/Migrations');
    }

    protected function posts(): PostRepository
    {
        return new PostRepository($this->database(), $this->service(Router::class));
    }

    /**
     * 主题设置器(《36》§8 的导出/导入命令与后台配置器读同一份声明与取值)。
     */
    protected function themeSettings(): ThemeSettings
    {
        return $this->service(ThemeSettings::class);
    }

    protected function postWriter(): PostWriter
    {
        $db = $this->database();

        return new PostWriter(
            $db,
            $this->posts(),
            new MetaStore($db),
            new TermAssigner($db)
        );
    }

    /**
     * 容器里有没有这个服务。可选依赖先问这一句 —— "没注册"和"站点没配置"是两回事。
     *
     * @param class-string $id
     */
    protected function hasService(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * 站点没配置时容器是空的。这里给出可执行的提示,而不是"服务未注册"这种内核内部话。
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        if (!$this->container->has($id)) {
            throw new ConfigException('站点尚未配置:请先运行 molin install,或复制 config.example.php 为 config.php。');
        }

        $object = $this->container->get($id);

        if (!$object instanceof $id) {
            throw new ConfigException("服务类型不符: {$id}");
        }

        return $object;
    }
}
