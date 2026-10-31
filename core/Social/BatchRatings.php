<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Social;

/**
 * 列表层的赞踩批量口(2026-10-31 增)。
 *
 * `SocialRepository::ratingFor()` 是「一篇」的口径 —— 文章页只要那一篇,够用。
 * 但卡片列表上每一条都要显示赞数,逐条调它就是 N+1,踩了内核对列表页的红线。
 * 所以这里单开一个批量口:整页一次查询,返回 post_id => {like, dislike}。
 *
 * **为什么另立一个接口,而不是往 SocialRepository 里加方法**:接口加方法等于让所有
 * 已发布的社交插件升级前直接致命错误。这里用 `instanceof` 判一下,老插件(乃至根本没有
 * 社交插件)都只是拿不到赞数,整列按 0 渲染,页面照常。装饰器模式的代价最小形态。
 */
interface BatchRatings
{
    /**
     * 一批文章各自的赞/踩数。没有票的文章可以不出现在返回里,调用方按 0 兜底。
     *
     * @param list<int> $postIds
     * @return array<int, array{like:int, dislike:int}>
     */
    public function ratingsFor(array $postIds): array;
}
