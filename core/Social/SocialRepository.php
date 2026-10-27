<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Social;

/**
 * 关注 / 赞踩的数据口（既有规范作者页、既有规范评论与互动）。
 *
 * 2026-10-22（0.97.0）：实现搬到内置插件 molin-social，内核只留这张接口。
 * 控制器按接口调用；插件没启用时装配层传 null，页面照常渲染，社交块由 theme 的
 * `features.social` 决定要不要画（见 core/Theme/Renderer.php 的 features 全局）。
 */
interface SocialRepository
{
    /**
     * @return array{followers:int, following:int, likes:int, dislikes:int}
     */
    public function statsForAuthor(int $userId): array;

    /**
     * 当前用户是否已关注该作者。
     */
    public function isFollowing(int $followerId, int $followingId): bool;

    /**
     * 关注 / 取消关注（同一按钮再点一次就是取消）。
     *
     * @return array{following:bool, followers:int, following_count:int}
     */
    public function toggleFollow(int $followerId, int $followingId): array;

    /**
     * 赞/踩数 + 当前投票人投过什么。`action`(投票接口地址)由 PostController 事后补,
     * 不属于仓储返回的数据形状。
     *
     * @return array{like:int, dislike:int, mine:string}
     */
    public function ratingFor(int $postId, int $userId, string $voterKey = ''): array;

    /**
     * @return array{like:int, dislike:int, mine:string}
     */
    public function rate(int $postId, int $userId, string $type, string $voterKey): array;
}
