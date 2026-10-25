<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Database;

use Molin\Exception\DatabaseException;
use Molin\Logger\Log;
use Molin\Plugin\CapabilityDeniedException;
use Molin\Plugin\CapabilityGate;
use PDO;
use PDOStatement;
use Throwable;

/**
 * 原生 PDO 薄查询层(《00-总览》第 5 节:不用 ORM,参数化由内核自己控)。
 *
 * 约定:SQL 里的值一律走参数占位符;$table / 列名只允许内核与插件代码传入,
 * 不接受用户输入(列名在写入前做标识符校验)。
 *
 * 本类同时是 `db.direct` 能力的拦截面(《62》第 6 节):查询、事务、原生句柄都从 pdo() 出去,
 * 门卫落在那一处就一处不漏。
 */
final class Database
{
    /** 《26》第 9 节默认阈值 200ms;《57》第 10 节写的是 100ms,口径待作者定,先按本节的日志规范走 */
    public const SLOW_QUERY_MS = 200.0;

    private ?PDO $pdo = null;

    private int $txDepth = 0;

    private ?string $collation = null;

    /**
     * @param array<string, mixed> $config 连接参数,键见 config.example.php(依据《42》第 3 节)
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * 表前缀,默认 molin_(《21-数据模型》第 1 节)。
     */
    public function prefix(): string
    {
        return self::identifier((string) ($this->config['prefix'] ?? 'molin_'));
    }

    /**
     * 建表用的排序规则。utf8mb4_0900_ai_ci 是 MySQL 8 独有,MySQL 5.7 / MariaDB 没有,
     * 写死会让这两个环境建表直接报 1273;所以按服务器能力挑,退回两边都有的 utf8mb4_unicode_ci。
     */
    public function collation(): string
    {
        if ($this->collation !== null) {
            return $this->collation;
        }

        try {
            $row = $this->selectOne("SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'");
        } catch (Throwable) {
            $row = null;
        }

        return $this->collation = $row === null ? 'utf8mb4_unicode_ci' : 'utf8mb4_0900_ai_ci';
    }

    /**
     * 加表前缀。前缀可配置(《21-数据模型》第 1 节)。
     */
    public function table(string $name): string
    {
        return $this->prefix() . self::identifier($name);
    }

    public function pdo(): PDO
    {
        // 拦在连接这一处:select / execute / insert / update / delete 都经 run() 到 pdo(),
        // transaction() 与原生句柄也走这里 —— 一处就把整层 DB 圈住,不必逐个方法贴。
        // 调用方是插件且未声明 db.direct 即被门卫拦下;内核自身(无插件上下文)照常连库。
        CapabilityGate::assert('db.direct');

        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }

        return $this->pdo;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $values 列名 => 值
     */
    public function insert(string $table, array $values): int
    {
        if ($values === []) {
            throw new DatabaseException('insert 至少给出一个列');
        }

        $columns = array_map(static fn (string $col): string => '`' . self::identifier($col) . '`', array_keys($values));
        $marks = array_map(static fn (string $col): string => ':' . $col, array_keys($values));

        $sql = 'INSERT INTO ' . $this->table($table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $marks) . ')';

        $params = [];
        foreach ($values as $col => $value) {
            $params[':' . self::identifier($col)] = $value;
        }

        $this->run($sql, $params);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values 列名 => 值
     * @param array<string|int, mixed> $whereParams
     */
    public function update(string $table, array $values, string $where, array $whereParams = []): int
    {
        if ($values === []) {
            throw new DatabaseException('update 至少给出一个列');
        }

        $sets = [];
        $params = [];
        foreach ($values as $col => $value) {
            $safe = self::identifier($col);
            $sets[] = "`{$safe}` = :set_{$safe}";
            $params[':set_' . $safe] = $value;
        }

        return $this->run(
            'UPDATE ' . $this->table($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            $params + $whereParams
        )->rowCount();
    }

    /**
     * @param array<string|int, mixed> $params
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->run('DELETE FROM ' . $this->table($table) . ' WHERE ' . $where, $params)->rowCount();
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();

        // 已在外层事务里(内核起的、或集成测试起的):用 SAVEPOINT 嵌套,
        // 既不提前提交,也不会因"已有事务"而报错。
        if ($pdo->inTransaction()) {
            $point = 'molin_sp_' . (++$this->txDepth);
            $pdo->exec('SAVEPOINT ' . $point);

            try {
                $result = $fn($this);
                $pdo->exec('RELEASE SAVEPOINT ' . $point);
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $point);
                --$this->txDepth;

                throw $e;
            }

            --$this->txDepth;

            return $result;
        }

        $pdo->beginTransaction();

        try {
            $result = $fn($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    private function connect(): PDO
    {
        $charset = $this->config['charset'] ?? 'utf8mb4';
        $socket = $this->config['socket'] ?? null;

        if ($socket !== null && $socket !== '') {
            $dsn = 'mysql:unix_socket=' . $socket . ';dbname=' . ($this->config['name'] ?? '') . ';charset=' . $charset;
        } else {
            $dsn = 'mysql:host=' . ($this->config['host'] ?? '127.0.0.1')
                . ';port=' . ($this->config['port'] ?? 3306)
                . ';dbname=' . ($this->config['name'] ?? '')
                . ';charset=' . $charset;
        }

        try {
            return new PDO($dsn, (string) ($this->config['user'] ?? ''), (string) ($this->config['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // 真预处理:值永不拼进 SQL(《61-安全基线清单》的第一条防线)
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $e) {
            throw new DatabaseException('数据库连接失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 执行一条参数化语句。数据库报错一律换成项目异常(《26》第 1 节),
     * 调用方只需 catch DatabaseException,不必知道底层是 PDO。
     *
     * @param array<string|int, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $started = microtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);

            foreach ($params as $key => $value) {
                $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
                $statement->bindValue($name, $value);
            }

            $statement->execute();
        } catch (CapabilityDeniedException $e) {
            // 门卫拒绝不是"SQL 执行失败":它由 pdo() 抛出,不能被下面的 catch 收编成 DatabaseException ——
            // 否则调用方只看得到一句数据库报错,找不到"未声明能力"这个真因
            throw $e;
        } catch (Throwable $e) {
            throw new DatabaseException('SQL 执行失败: ' . $e->getMessage(), 0, $e);
        }

        // 《26》第 9 节:慢查询进 db 通道。只记 SQL 模板,参数不进日志 —— 参数里可能是密码
        $elapsed = (microtime(true) - $started) * 1000;

        if ($elapsed > self::SLOW_QUERY_MS) {
            Log::channel('db')->warning('slow_query', ['ms' => round($elapsed, 1), 'sql' => $sql]);
        }

        return $statement;
    }

    /**
     * 标识符白名单:只允许字母、数字、下划线,且不以数字开头。
     */
    private static function identifier(string $name): string
    {
        if (preg_match('#\A[A-Za-z_][A-Za-z0-9_]*\z#', $name) !== 1) {
            throw new DatabaseException("非法标识符: {$name}");
        }

        return $name;
    }
}
