<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Site;

use Molin\Database\Database;

/**
 * 访问计数(2026-10-31 增)。
 *
 * 一张 `{p}visits` 日计数表:一天一行、只存当天命中数,不记 IP / UA / 来源页。
 * 主题的访问统计条要的五个数(今日 / 昨日 / 近 7 天 / 近 30 天 / 总计)全从这里聚合。
 *
 * 「今天」按站点时区算 —— Bootstrap 已经 `date_default_timezone_set()` 到 config.timezone,
 * 所以 `date('Y-m-d')` 就是站长眼里的一天。这与显示层的口径一致:数据库里时间存 UTC,
 * 但「哪天」这种分桶必须按站点时区,否则跨零点后今日/昨日会整体错位。
 *
 * 记录失败一律静默:统计条是锦上添花,不该因为它让页面 500 或变慢。
 *
 * 「总访问」是终身值(2026-10-31 补):表里只留保留期内的行(Molin\Site\VisitRetention
 * 每天剪一次),所以总计 = 表内 SUM + `visits_pruned_total` 里累计的「已剪掉的那部分」。
 * 少了这一份,剪枝当天页脚的总数会当场往回掉 —— 那不是访客变少,是我们自己删的。
 */
final class VisitCounter
{
    /**
     * 「已经被保留策略剪掉」的历史命中累计在这个 options 键里(autoload=0,不该被每次请求预载)。
     *
     * 写入方只有 Molin\Site\VisitRetention::prune(),读取方只有下面的 prunedTotal()。
     * 键名要改就两边一起改 —— 它与 visits 表的「在册部分」加起来才是终身总计。
     * 两边都直接读写这一行、都不经 Options 门面,理由见 prunedTotal() 的注释。
     */
    public const PRUNED_OPTION = 'visits_pruned_total';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * 记一次访问。落在今天那一行,自增 1。
     *
     * `INSERT ... ON DUPLICATE KEY UPDATE` 一条语句搞定,不需要先查后写,
     * 并发下也不会丢计数(唯一键 day 保证只有一行)。
     */
    public function hit(?string $day = null): void
    {
        $day ??= date('Y-m-d');

        $this->db->execute(
            'INSERT INTO ' . $this->db->table('visits') . ' (day, hits) VALUES (:day, 1)'
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['day' => $day]
        );
    }

    /**
     * 统计条要的五个数。表还没建(老站没跑迁移)时全给 0,主题画出来是五个 0 而不是报错。
     *
     * @return array{today:int, yesterday:int, week:int, month:int, total:int}
     */
    public function stats(): array
    {
        $empty = ['today' => 0, 'yesterday' => 0, 'week' => 0, 'month' => 0, 'total' => 0];

        $today = new \DateTimeImmutable('today');
        $monthStart = $today->modify('-29 days');

        try {
            // 一次范围扫描同时算出四个区间;日期是 DATE 列,直接比较即可
            $row = $this->db->selectOne(
                'SELECT'
                . ' COALESCE(SUM(CASE WHEN day = :today THEN hits END), 0) AS today,'
                . ' COALESCE(SUM(CASE WHEN day = :yesterday THEN hits END), 0) AS yesterday,'
                . ' COALESCE(SUM(CASE WHEN day >= :week THEN hits END), 0) AS week,'
                . ' COALESCE(SUM(hits), 0) AS month'
                . ' FROM ' . $this->db->table('visits')
                . ' WHERE day >= :month',
                [
                    'today' => $today->format('Y-m-d'),
                    'yesterday' => $today->modify('-1 day')->format('Y-m-d'),
                    'week' => $today->modify('-6 days')->format('Y-m-d'),
                    'month' => $monthStart->format('Y-m-d'),
                ]
            );

            // 总计单独一条:它不受上面那个 30 天窗口限制
            $totalRow = $this->db->selectOne(
                'SELECT COALESCE(SUM(hits), 0) AS total FROM ' . $this->db->table('visits')
            );

            // 已剪掉的那部分不在这张表里,但「总访问」是终身值,得把它加回来
            $pruned = $this->prunedTotal();
        } catch (\Throwable) {
            return $empty;
        }

        return [
            'today' => (int) ($row['today'] ?? 0),
            'yesterday' => (int) ($row['yesterday'] ?? 0),
            'week' => (int) ($row['week'] ?? 0),
            'month' => (int) ($row['month'] ?? 0),
            'total' => (int) ($totalRow['total'] ?? 0) + $pruned,
        ];
    }

    /**
     * 被保留策略剪掉的历史命中总数。老站/从没剪过 = 0。
     *
     * 这里**直接读 options 那一行**,不走 Options 门面,三条理由:
     *   ① 它是运维计数,不是站长配置 —— 与写入端(VisitRetention::prune)对称:两边都不经 Options,
     *      也就不往 `setting.update` 审计里灌垃圾(那一条是 Cron 类自己的既有口径);
     *   ② Options 会在本进程内缓存读过的键,而剪枝是从另一条路径直写库的 —— 同一个进程里
     *      「先剪枝、后取数」会拿到剪枝前的值,total 于是少算。2026-10-31 在 cs1 上就是这么
     *      现场演出来的(84 → 68)。一次 selectOne 换掉这个陷阱;
     *   ③ 代价与走 Options 完全相同:这一行 autoload=0,本来也不会被预载,读它照样是一次查询。
     *
     * 读不懂(被手工改坏、写成了非数字)或表还没建,一律当 0:一个运维计数不该让统计条整块挂掉。
     */
    public function prunedTotal(): int
    {
        try {
            $row = $this->db->selectOne(
                'SELECT option_value FROM ' . $this->db->table('options') . ' WHERE option_name = :name LIMIT 1',
                ['name' => self::PRUNED_OPTION]
            );
        } catch (\Throwable) {
            return 0;
        }

        $raw = $row['option_value'] ?? null;

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * 这条请求算不算一次访问。
     *
     * 只认「人打开一个页面」:GET、非静态资源、非片段/订阅/地图这类机器端点、
     * 且不是明显的爬虫、**也不是已登录的人**(2026-10-31 增,见下)。
     * 计数口径宁可保守 —— 把 API 轮询和预取算进去,统计条的数字会虚高到没有参考价值。
     *
     * @param int $userId 当前登录用户编号,0 = 访客。由调用方从内核登录态取(Auth::id()),
     *                    本方法只做判定、不自己去查会话 —— 它是个纯函数,好不好测就看这一点。
     */
    public static function countable(string $method, string $uri, string $userAgent = '', int $userId = 0): bool
    {
        // 已登录的账号(管理员 / 编辑 / 任何注册用户)都不算访客:站长自己刷前台页面
        // 不该给自己涨数,编辑预览也不该。注意只是「不记账」—— `/stats/visits` 的读接口
        // 照旧对所有人返回数字,登录与否都能看见(读的是聚合值,跟谁在看无关)。
        if ($userId > 0) {
            return false;
        }

        if ($method !== 'GET') {
            return false;
        }

        $parsed = parse_url($uri, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/';

        // 机器读的端点:片段翻页、订阅、站点地图、接口、后台、上传文件
        foreach (['/list/fragment', '/feed', '/sitemap', '/robots', '/api/', '/stats/', '/admin', '/uploads/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        // 带点号且不是页面路径的,基本是静态资源
        if (preg_match('#\.(css|js|mjs|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|map|txt|xml|json)$#i', $path) === 1) {
            return false;
        }

        return !self::isBot($userAgent);
    }

    /**
     * 给「装配前」的出口用的一次性记录:整页缓存快速通道在内核启动之前就 return 了,
     * 那时还没有容器,只能拿 config.php 现拼一个只连一次的实例。
     *
     * 登录态在这里**取不到也不该取**:本方法跑在 Bootstrap::start() 之前,会话还没挂上来,
     * 硬读 Auth::id() 只会永远拿到 0(一个看起来对、其实没生效的判定)。
     * 好在它唯一的调用点(index.php 的快速通道)本身就要求请求不带 `molin_session` /
     * `molin_remember`,而这两条 cookie 缺失就意味着这条请求不可能是登录态 ——
     * 换句话说,「已登录不计数」这一条在这里是由调用点的门槛保证的,不是漏了。
     * 装配之后的两个记录出口另带 Auth::id() 进来,那两处的判据才是明写的。
     *
     * 失败一律吞掉 —— 统计条是锦上添花,不该因为计数让页面挂掉。
     *
     * ⚠ 正因为下面那只 catch 什么都吞,这里的**构造参数写错不会报错、只会静默不计数**
     * (2026-10-31 就踩过:构造函数一度加了 Options,这里漏传,ArgumentCountError 被吞掉,
     * 缓存命中的访问一个都没记)。现在这个类只依赖一个 Database,没有第二件要传的东西了;
     * 但道理不变 —— 改构造签名时务必连着 tests/Integration/Site/VisitCounterFastPathTest
     * 一起想,那是唯一走这一段的路(也是唯一抓得住这毛病的用例)。
     *
     * @param array<string, mixed> $config config.php 的返回值
     */
    public static function recordFromConfig(array $config): void
    {
        try {
            if (!self::countable(
                (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                (string) ($_SERVER['REQUEST_URI'] ?? '/'),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            )) {
                return;
            }

            // 只连一次:这段跑在内核装配之前,没有容器,也拿不到任何请求级服务
            (new self(new Database((array) ($config['database'] ?? []))))->hit();
        } catch (\Throwable) {
            // 快速通道只该更快,不该更脆
        }
    }

    /** 常见爬虫/监控 UA。判不准就照常计数,这里只挡一眼能认出来的 */
    private static function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return true;
        }

        return preg_match('#bot|spider|crawl|slurp|headless|lighthouse|pingdom|uptime|curl|wget|python-requests|go-http#i', $userAgent) === 1;
    }
}
