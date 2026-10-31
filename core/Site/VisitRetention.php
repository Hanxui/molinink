<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Site;

use DateTimeImmutable;
use Molin\Database\Database;
use Molin\Option\Options;

/**
 * 访问计数的保留策略(2026-10-31 增)。
 *
 * `{p}visits` 是日粒度表(一天一行),但只增不删就是无界增长 —— 一年 365 行听着不多,
 * 可它是**永久**的,十年就是 3650 行,且首屏统计条每次都要 SUM 一遍。留个保留期更稳:
 * 统计条最远只用到「总计」,而真要算「年同比」得留满一年,所以默认 400 天
 * (365 + 一个多月的余量,够跨年对齐,又不至于把表养肥)。
 *
 * 为什么挂在 Cron 而不是「写入时低概率剪枝」:
 *   ① 内核的 Cron 子系统本来就是干这个的(cleanup-sessions / cleanup-tokens 同一类),
 *      挂上去等于复用现成的到期判定、dry-run、失败重试和「上次跑过」记录,不必另造一套;
 *   ② 低概率剪枝把 DELETE 塞进访客的请求里 —— 那一趟会明显变慢,而它偏偏是「恰好被抽中」的
 *      那一个访客,用户体验和排查难度都不划算;
 *   ③ 剪枝是纯粹的运维动作,不该跟流量走:站点没人访问时反倒最该有东西在跑。
 *   Cron 的代价是「得有人去挂系统计划任务」(见部署说明),这一点写在交付说明里,不在代码里糊。
 *
 * 只删「早于阈值」的行:今天的行永远留着(阈值 ≤ 今天 - 最少 7 天),也不碰别的表。
 * 幂等:同一条 DELETE 再跑一次删 0 行;`day` 是主键,范围删走索引,并发下不会误删。
 *
 * 剪掉的部分不会被丢掉(2026-10-31 补):删行之前把这一批的 hits 累加进 options 的
 * `visits_pruned_total`,VisitCounter::stats() 的「总访问」= 表内 SUM + 这一份 ——
 * 页脚那条「总访问」是终身值,不能因为剪枝往回掉。
 *
 * **一个说清楚的边界**:这次改动之前已经被手工/旧逻辑删掉的历史行,票数找不回来,
 * 也没有任何地方记过。所以对老站点来说,「总访问」是从本次上线起算的终身值,
 * 而不是开站至今的真值。这里不假装能追溯 —— 想要真值只能从备份里重建那张日计数表。
 */
final class VisitRetention
{
    /**
     * 保留天数所在的 options 键。
     *
     * 按内核 options 的惯例:默认值写在读的地方(这里),库里有值就用库里的。
     * 不走设置页的可写字段 —— 那要连后台模板一起加输入框,而这条数值是运维口径,
     * 改的时机是「站点的库要撑不住了」,不是站长日常调的那些开关。
     */
    public const OPTION = 'visits_retention_days';

    /** 默认 400 天:见类注释 */
    public const DEFAULT_DAYS = 400;

    /**
     * 下限。配成 0 或负数会把「今天以前」整片删掉 —— 今日/昨日/周/月四栏当场归零,
     * 而那恰恰是主题页脚要显示的四个数。留 7 天,至少保住「近 7 天」那一栏。
     */
    public const MIN_DAYS = 7;

    /** 上限 20 年:再长就不叫保留策略了,配错数量级时也能一眼看出被夹过 */
    public const MAX_DAYS = 7300;

    public function __construct(
        private readonly Database $db,
        private readonly Options $options
    ) {
    }

    /** 生效中的保留天数(库里没配 / 配了不是数字 / 越界都夹回合理区间) */
    public function days(): int
    {
        $raw = $this->options->get(self::OPTION, (string) self::DEFAULT_DAYS);
        $days = is_numeric($raw) ? (int) $raw : self::DEFAULT_DAYS;

        return max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
    }

    /**
     * 早于这一天(不含)的行该删。
     *
     * 按站点时区的「今天 00:00」往回数,而不是拿 $now 的时分秒直接减:
     * 表是 DATE 粒度,拿带时间的时刻去比会让边界那天的行忽删忽留。
     * 站点时区由 Bootstrap 的 `date_default_timezone_set()` 定好,与 VisitCounter::stats 同一口径。
     */
    public function cutoff(int $now): string
    {
        $today = new DateTimeImmutable(date('Y-m-d', $now));

        return $today->modify('-' . $this->days() . ' days')->format('Y-m-d');
    }

    /**
     * 剪掉过保留期的行,返回删掉几行。dry-run 只数不删、也不碰终身计数(Cron 的演练口径)。
     *
     * 剪掉的行里那部分 hits 不能就这么丢了 —— 页脚的「总访问」是终身值。所以整件事在
     * 一个事务里做三件事:锁住这批行并读出它们的票数 → 累加进 options 的终身计数 →
     * 删行。任何一步失败整体回滚,不会出现「行删了计数没加」(total 掉)或「计数加了行还在」
     * (下一轮再重复加一遍)。
     *
     * 为什么敢说并发下不会重复累加:第一条是 `SELECT ... FOR UPDATE`,两趟剪枝撞在一起时
     * 后到的那趟在第一步就被挡住;等它拿到锁时行已经没了(COUNT=0),连计数都不会碰。
     * 锁读永远读最新已提交版本,不读 REPEATABLE READ 的快照,所以它看到的行与票数一定是准的。
     */
    public function prune(int $now, bool $dryRun = false): int
    {
        $table = $this->db->table('visits');
        $options = $this->db->table('options');
        $params = ['cutoff' => $this->cutoff($now)];

        if ($dryRun) {
            $row = $this->db->selectOne(
                'SELECT COUNT(*) AS stale FROM ' . $table . ' WHERE day < :cutoff',
                $params
            );

            return (int) ($row['stale'] ?? 0);
        }

        return $this->db->transaction(fn (Database $db): int => $this->trim($db, $table, $options, $params));
    }

    /**
     * prune() 的事务体。单独拆出来只是为了让上面那段注释贴着调用点。
     *
     * @param array<string, string> $params
     */
    private function trim(Database $db, string $table, string $options, array $params): int
    {
        // 一次范围扫描同时拿到「几行」与「多少票」;锁住这些行,挡住并发的第二趟剪枝
        $stale = $db->selectOne(
            'SELECT COUNT(*) AS rows_count, COALESCE(SUM(hits), 0) AS hits FROM ' . $table
            . ' WHERE day < :cutoff FOR UPDATE',
            $params
        );

        $rows = (int) ($stale['rows_count'] ?? 0);
        $hits = (int) ($stale['hits'] ?? 0);

        // 空剪枝:一行不动、计数不碰 —— 重复执行不可能让 total 漂移,就靠这一条
        if ($rows === 0) {
            return 0;
        }

        // 终身计数也用锁读拿基线(FOR UPDATE),再写回绝对值:不依赖 MySQL 8.0.20 起才有的
        // 行别名语法,也不依赖已被标记废弃的 VALUES() 自引用,5.7 / 8.x / MariaDB 都能跑
        $counter = $db->selectOne(
            'SELECT option_value FROM ' . $options . ' WHERE option_name = :name FOR UPDATE',
            ['name' => VisitCounter::PRUNED_OPTION]
        );

        $total = ($counter === null ? 0 : (int) $counter['option_value']) + $hits;

        if ($counter === null) {
            // autoload=0:这只是个运维计数,不该被每次请求预载进内存
            $db->insert('options', [
                'option_name' => VisitCounter::PRUNED_OPTION,
                'option_value' => (string) $total,
                'autoload' => 0,
            ]);
        } else {
            $db->execute(
                'UPDATE ' . $options . ' SET option_value = :value WHERE option_name = :name',
                ['value' => (string) $total, 'name' => VisitCounter::PRUNED_OPTION]
            );
        }

        // 条件只有一条范围比较,`day` 是主键:走索引,不锁全表,也不会错删边界内外的行
        return $db->execute('DELETE FROM ' . $table . ' WHERE day < :cutoff', $params);
    }
}
