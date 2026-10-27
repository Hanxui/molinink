<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Search;

use Molin\Database\Database;
use Molin\Exception\DatabaseException;
use Throwable;

/**
 * 基础搜索(《28-搜索规范》第 2、3 节:LIKE 起步,标题命中优先)。
 *
 * 全文是**可选加速**而不是唯一依赖:config.php 里 `search.fulltext` 打开、且这次匹配在库里
 * 真能返回结果时走 `MATCH ... AGAINST`;开关关闭、索引缺失、语法不被接受、或者返回 0 行,
 * 一律回落原来的 LIKE —— 「开了开关反而搜不到东西」这个后果不可接受。
 *
 * 排序权重:标题命中 > 发布时间新;命中词在前端用 <mark> 高亮,入库前后都不改内容。
 *
 * 对外契约未变:`__construct(Database)`、`search(string, int)`、`highlight()`、`PER_PAGE`,
 * 返回数组的键与类型与改造前逐字段一致。
 */
final class Searcher
{
    /** 每页条数(《28》未给数值,与后台列表一致) */
    public const PER_PAGE = 10;

    /** 列表与 COUNT 共用的列清单:两处口径必须一致,少一列就是卡片缺一块 */
    private const COLUMNS = 'p.id, p.title, p.slug, p.excerpt, p.content, p.published_at,'
        . ' p.comment_count, p.thumbnail_id, p.view_count';

    /** 前台可见性三条件(《28》§2.1):类型、状态、软删除。两条路径共用,免得口径走偏 */
    private const VISIBLE = "p.type IN ('post', 'page') AND p.status = 'publish' AND p.deleted_at IS NULL";

    /** config.php 的 search.fulltext 在本进程内只读一次(配置是进程常量,不必每次请求读盘) */
    private static ?bool $fulltextSwitch = null;

    /** ngram 解析器探测结果;null = 还没探过 */
    private ?bool $ngram = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, ms: float, page: int, pages: int, keyword: string}
     */
    public function search(string $keyword, int $page = 1): array
    {
        $keyword = trim($keyword);
        $page = max(1, $page);
        $started = microtime(true);

        if ($keyword === '') {
            // 空关键词不落全表扫描(《28》§2.1)
            return ['items' => [], 'total' => 0, 'ms' => 0.0, 'page' => 1, 'pages' => 1, 'keyword' => ''];
        }

        // 先全文、后 LIKE。全文这一路自己负责"走不通就认输"(返回 null),
        // 所以这里不需要 try/catch,也不需要把两条 SQL 各写两遍。
        if ($this->fulltextAvailable($keyword)) {
            $hit = $this->searchFulltext($keyword, $page, $started);

            if ($hit !== null) {
                return $hit;
            }
        }

        return $this->searchLike($keyword, $page, $started);
    }

    /**
     * 全文一路。返回 null = 这条路此刻走不通,调用方回落 LIKE。
     *
     * COUNT 与列表共用同一份 where / params(只构造一次),两条 SQL 不会各自漂移。
     *
     * @return array{items: list<array<string, mixed>>, total: int, ms: float, page: int, pages: int, keyword: string}|null
     */
    private function searchFulltext(string $keyword, int $page, float $started): ?array
    {
        $criterion = self::fulltextCriterion($keyword);
        $from = ' FROM ' . $this->db->table('posts') . ' p WHERE ' . $criterion['where'];

        try {
            $row = $this->db->selectOne('SELECT COUNT(*) AS n' . $from, $criterion['whereParams']);
            $total = (int) ($row['n'] ?? 0);

            if ($total <= 0) {
                // 分词器不认这个词 —— 默认分词器遇到整段中文就是这个症状(一个字都切不出来)。
                // 空结果不算"搜完了",交给 LIKE 再答一次。
                return null;
            }

            $rows = $this->db->select(
                'SELECT ' . self::COLUMNS . $from . $criterion['order']
                . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
                $criterion['whereParams'] + $criterion['orderParams']
            );
        } catch (DatabaseException) {
            // 索引不在 / 服务器不接受 FULLTEXT / 参数化后的语法被拒:任何数据库侧拒绝都只说明
            // "这条路不通",不是查询失败。回落 LIKE 才是对用户负责的答案。
            return null;
        }

        return self::present($rows, $total, $page, $keyword, $started);
    }

    /**
     * LIKE 一路(改造前的原路径,行为逐字保留,只是把条件抽出来复用)。
     *
     * @return array{items: list<array<string, mixed>>, total: int, ms: float, page: int, pages: int, keyword: string}
     */
    private function searchLike(string $keyword, int $page, float $started): array
    {
        $criterion = self::likeCriterion($keyword);
        $from = ' FROM ' . $this->db->table('posts') . ' p WHERE ' . $criterion['where'];

        $totalRow = $this->db->selectOne('SELECT COUNT(*) AS n' . $from, $criterion['whereParams']);
        $total = (int) ($totalRow['n'] ?? 0);

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . $from . $criterion['order']
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $criterion['whereParams'] + $criterion['orderParams']
        );

        return self::present($rows, $total, $page, $keyword, $started);
    }

    /**
     * 这条关键词此刻值不值得走全文:开关 → 分词器。
     *
     * 中文现实:默认分词器按空白/标点切词,整段中文会被当成一个"词",MATCH 只会返回 0 行;
     * 只有 `WITH PARSER ngram` 才切得出二元组。所以对含中日韩文字的关键词先探一次解析器,
     * 探不到就直接走 LIKE —— 省掉一次注定空结果的查询,也不指望"空结果回落"每次都兜住。
     */
    private function fulltextAvailable(string $keyword): bool
    {
        if (!self::fulltextEnabled()) {
            return false;
        }

        if (self::hasCjk($keyword) && !$this->ngramParser()) {
            return false;
        }

        return true;
    }

    /**
     * posts 上的全文索引是否带 ngram 解析器(结果按实例缓存)。
     *
     * `SHOW INDEX` / information_schema.STATISTICS 都不报解析器,只有 SHOW CREATE TABLE 的
     * 索引定义里有 `/*!50100 WITH PARSER `ngram` *\/`。探不出来(表不存在 / 权限不足 / 老版本)
     * 一律当"没有":宁可慢一点,不可搜不到。
     */
    private function ngramParser(): bool
    {
        if ($this->ngram !== null) {
            return $this->ngram;
        }

        try {
            $row = $this->db->selectOne('SHOW CREATE TABLE ' . $this->db->table('posts'));
            $ddl = '';

            // SHOW CREATE TABLE 返回两列(表名, 建表语句),列名随版本变,所以按内容取
            foreach ($row ?? [] as $value) {
                if (is_string($value) && stripos($value, 'FULLTEXT') !== false) {
                    $ddl = $value;
                    break;
                }
            }

            $this->ngram = preg_match('/WITH\s+PARSER\s+`?ngram`?/i', $ddl) === 1;
        } catch (Throwable) {
            $this->ngram = false;
        }

        return $this->ngram;
    }

    /**
     * config.php 的 `search.fulltext`,缺省 false(= 改造前的 LIKE 行为)。
     *
     * 为什么不从容器取:构造函数 `Searcher(Database)` 是既成对外契约(Bootstrap 与集成测试都这么 new),
     * 本工包不改装配面,所以按 MOLIN_ROOT 自己读 config.php。读不到 / 读坏了 / 键缺失,一律 false ——
     * **缺省必须与改造前完全等价**。
     */
    private static function fulltextEnabled(): bool
    {
        if (self::$fulltextSwitch !== null) {
            return self::$fulltextSwitch;
        }

        $enabled = false;

        try {
            $root = defined('MOLIN_ROOT') ? (string) constant('MOLIN_ROOT') : dirname(__DIR__, 2);
            $file = $root . '/config.php';

            if (is_file($file)) {
                $config = require $file;

                if (is_array($config)) {
                    $search = $config['search'] ?? [];
                    $flag = is_array($search) ? ($search['fulltext'] ?? false) : false;
                    $enabled = is_bool($flag) ? $flag : (bool) filter_var($flag, FILTER_VALIDATE_BOOLEAN);
                }
            }
        } catch (Throwable) {
            $enabled = false;
        }

        return self::$fulltextSwitch = $enabled;
    }

    /**
     * LIKE 路的条件:关键词只转义、不解释成模式(《28》§2.1)。
     *
     * @return array{where: string, whereParams: array<string, string>, order: string, orderParams: array<string, string>}
     */
    private static function likeCriterion(string $keyword): array
    {
        $escaped = self::escapeLike($keyword);
        $like = '%' . $escaped . '%';

        return [
            'where' => self::VISIBLE
                . ' AND (p.title LIKE :kw_title OR p.excerpt LIKE :kw_excerpt OR p.content LIKE :kw_content)',
            // 同一个占位符不能绑两次、也不能多绑:PDO 原生预处理要求"绑的 = 语句里用的"
            'whereParams' => ['kw_title' => $like, 'kw_excerpt' => $like, 'kw_content' => $like],
            'order' => ' ORDER BY (p.title LIKE :kw_prefix) DESC, p.published_at DESC, p.id DESC',
            'orderParams' => ['kw_prefix' => $escaped . '%'],
        ];
    }

    /**
     * 全文路的条件:自然语言模式,关键词原样进参数、不进 SQL。
     *
     * 为什么选 NATURAL LANGUAGE MODE 而不是 BOOLEAN MODE:`+ - > < ( ) ~ * " @` 在布尔模式里
     * 是语法,要么逐个转义(漏一个就是 1064,搜索结果直接 500),要么让用户随手打个引号就炸。
     * 自然语言模式没有运算符,关键词天然安全;InnoDB 也不吃 MyISAM 那条 50% 阈值。
     * 代价是失去 +必须 / -排除 这类语法 —— 而产品只承诺"关键词搜索"。
     *
     * 排序保持产品口径:标题整词命中 > 相关度 > 发布时间新。两个 MATCH 各绑一个参数名
     * (`:ft_match` / `:ft_score`),因为同名占位符复用不被 PDO 原生预处理支持。
     *
     * @return array{where: string, whereParams: array<string, string>, order: string, orderParams: array<string, string>}
     */
    private static function fulltextCriterion(string $keyword): array
    {
        $escaped = self::escapeLike($keyword);

        return [
            'where' => self::VISIBLE
                . ' AND MATCH(p.title, p.content) AGAINST (:ft_match IN NATURAL LANGUAGE MODE)',
            'whereParams' => ['ft_match' => $keyword],
            'order' => ' ORDER BY (p.title LIKE :ft_prefix) DESC,'
                . ' MATCH(p.title, p.content) AGAINST (:ft_score IN NATURAL LANGUAGE MODE) DESC,'
                . ' p.published_at DESC, p.id DESC',
            'orderParams' => ['ft_prefix' => $escaped . '%', 'ft_score' => $keyword],
        ];
    }

    /**
     * 关键词里是否有中日韩文字(决定默认分词器能不能切)。
     */
    private static function hasCjk(string $text): bool
    {
        // 汉字扩展 A、汉字基本区、平假名/片假名、谚文
        return preg_match(
            '/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u',
            $text
        ) === 1;
    }

    /**
     * LIKE 通配符转义(% _ 与反斜杠本身),防 LIKE 注入(《28》§2.1)。
     */
    private static function escapeLike(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }

    /**
     * 行 → 返回结构。两条路径共用,保证返回字段与改造前逐项一致。
     *
     * @param list<array<string, mixed>> $rows
     * @return array{items: list<array<string, mixed>>, total: int, ms: float, page: int, pages: int, keyword: string}
     */
    private static function present(array $rows, int $total, int $page, string $keyword, float $started): array
    {
        $items = [];

        foreach ($rows as $row) {
            // 与列表页同一套口径:没写摘要就从正文取一段,否则搜索结果卡片会少一行摘要
            $excerpt = trim((string) ($row['excerpt'] ?? ''));

            if ($excerpt === '') {
                $excerpt = self::clip((string) ($row['content'] ?? ''));
            }

            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'excerpt' => $excerpt,
                'published_at' => (string) ($row['published_at'] ?? ''),
                'comments' => (int) $row['comment_count'],
                // 特色图由渲染层按这个 id 去媒体库取(《27》§5);0 = 没配图
                'thumbnail_id' => (int) ($row['thumbnail_id'] ?? 0),
                'views' => (int) ($row['view_count'] ?? 0),
                'title_hit' => stripos((string) $row['title'], $keyword) !== false,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'ms' => round((microtime(true) - $started) * 1000, 1),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'keyword' => $keyword,
        ];
    }

    /**
     * 高亮:先按纯文本转义,再把关键词包进 <mark> —— 高亮永远不碰原文,不存在注入面。
     */
    public static function highlight(string $text, string $keyword): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $needle = htmlspecialchars($keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($needle === '' || mb_stripos($safe, $needle) === false) {
            return $safe;
        }

        return (string) preg_replace(
            '#' . preg_quote($needle, '#') . '#iu',
            '<mark>$0</mark>',
            $safe
        );
    }

    /**
     * 正文摘要:去标签、收白,最多 200 字(与列表页 ListSource 同一个上限,主题再按 140 截)。
     */
    private static function clip(string $text): string
    {
        // 实体先还原再收白:交给模板时它还会转义一次,不还原就会漏出 `&amp;quot;`
        $text = trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ));

        if ($text === '' || mb_strlen($text, 'UTF-8') <= 200) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, 200, 'UTF-8')) . '…';
    }
}
