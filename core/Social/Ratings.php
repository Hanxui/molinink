<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Social;

/**
 * 赞 / 踩的数据口。
 *
 * 2026-10-31：从 SocialRepository 里拆出来单独成契约。
 *
 * 起因是一个真实的坏结果：赞原本和「关注」绑在同一个可停用的插件上，
 * 插件一停，`POST /social/rate` 就回 404，主题里的赞按钮变成点了没反应的死按钮。
 * 但赞不是社交扩展，是内容主题的最低配——几乎每个主题都要它。
 *
 * 拆开之后：内核自带 DatabaseRatings 兜底，任何站点装上任何主题都开箱有赞；
 * 插件仍在时优先用插件的实现（它同样 implements 本接口），行为与拆分前一致。
 * 「关注 / 粉丝统计」属于真正的社交扩展，仍留在 SocialRepository + 插件里。
 */
interface Ratings
{
    /**
     * 赞/踩数 + 当前投票人投过什么。`action`(投票接口地址)由 PostController 事后补，
     * 不属于仓储返回的数据形状。
     *
     * @return array{like:int, dislike:int, mine:string}
     */
    public function ratingFor(int $postId, int $userId, string $voterKey = ''): array;

    /**
     * 投票：没投过就投，投过同类就算取消，投过另一类就换边。
     *
     * @param string $type like 或 dislike
     *
     * @return array{like:int, dislike:int, mine:string}
     */
    public function rate(int $postId, int $userId, string $type, string $voterKey): array;
}
