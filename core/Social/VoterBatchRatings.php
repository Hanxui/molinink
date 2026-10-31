<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Social;

/**
 * 列表层的赞踩批量口 —— 带上「当前访客」的那一版(2026-10-31 增)。
 *
 * 为什么另开一个接口,而不是给 BatchRatings 加个可选参数:
 * **接口方法加参数对实现方是致命的**。PHP 的实现签名必须兼容接口:老插件里那句
 * `ratingsFor(array $postIds)` 一旦对不上新接口的 `ratingsFor(array $postIds, ?string $voterKey = null)`,
 * 类一加载就是 "Declaration ... must be compatible" 的致命错误 —— 整个站点白屏,
 * 不是降级。这跟当初「另立 BatchRatings 而不是往 SocialRepository 里加方法」是同一条道理
 * (见 BatchRatings 的类注释),照同一套判断走。
 *
 * 所以这里的形状是「子接口 + instanceof」:内核列表页遇到实现了本接口的提供商才多要
 * 那一列 `mine`,老插件(或压根没有社交插件)只是拿不到心形实心态,计数照旧正确。
 *
 * 返回形状与 BatchRatings 保持一致的骨架(like / dislike),每条再补一个 `mine`:
 * '' = 没投过, 'like' / 'dislike' = 这个人自己投的那一票。
 */
interface VoterBatchRatings extends BatchRatings
{
    /**
     * 一批文章各自的赞/踩数,外加 `$voterKey` 这个人的票。
     *
     * 带投票者才多查一次(一条 `IN (...)` 加 `voter_key = :k`);没有票的文章仍可不出现在
     * 返回里,调用方按 0 / '' 兜底 —— 与父接口同口径。
     *
     * @param list<int> $postIds
     * @param string $voterKey 投票标识:`u{用户编号}` 或 `g{访客随机串}`;空串=只要计数
     * @return array<int, array{like:int, dislike:int, mine:string}>
     */
    public function ratingsForVoter(array $postIds, string $voterKey): array;
}
