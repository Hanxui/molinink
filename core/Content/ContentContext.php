<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Content;

/**
 * 正文渲染上下文 ——既有规范。
 *
 * 只带四项:作者、时间、封面一律从 post 上取,不在这里复制第二份。
 * 以后加字段只增不改。
 */
final class ContentContext
{
    public const MODE_FULL = 'full';

    public const MODE_EXCERPT = 'excerpt';

    public const MODE_FEED = 'feed';

    public function __construct(
        public readonly ?Post $post,
        public readonly string $type,
        public readonly string $mode,
        public readonly bool $listing
    ) {
    }
}
