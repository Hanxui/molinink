<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Media;

use Molin\Logger\Audit;

/**
 * 删除媒体(《27-媒体库规范》第 8.1 节)。
 *
 * **不立即物理删。** 附件虽然占一行 posts,但它不走内容的状态机(状态是 inherit、可见性随父),
 * 删除归媒体库自己管:这里只打"待清理"标记(`posts.deleted_at`),
 * 文件与衍生档留在原地,等定时任务扫到"无引用且过了保留期"再一起清 ——
 * 与"文章进回收站"同一套心智,删错了还能还原。
 */
final class Remover
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly Store $store
    ) {
    }

    /**
     * 移入待清理。返回这条 media 记录,调用方要拿它提示"被 N 处引用"。
     *
     * @return array<string, mixed>|null
     */
    public function trash(int $id, int $userId): ?array
    {
        $record = $this->media->softDelete($id);

        if ($record === null) {
            return null;
        }

        Audit::record('media.trash', 'media:' . $id . ' post:' . $record['post_id'], true, null, 'user:' . $userId);

        // 《27》第 8.2 节钉了这一个钩子,参数就是这条 media
        do_action('media/delete/after', $record);

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restore(int $id, int $userId): ?array
    {
        $record = $this->media->restore($id);

        if ($record === null) {
            return null;
        }

        Audit::record('media.restore', 'media:' . $id, true, null, 'user:' . $userId);

        return $record;
    }

    /**
     * 彻底删除:文件 + 衍生档 + 两行账一起清(《27》第 8.1 节清理动作的手动版)。
     *
     * 还有引用就不动手 —— 文档的口径是"确认无引用后移除文件与记录",
     * 手动点也不该把文章里正在用的图挖掉。
     *
     * @return array{done:bool, why:string}
     */
    public function destroy(int $id, int $userId): array
    {
        $record = $this->media->find($id);

        if ($record === null) {
            return ['done' => false, 'why' => '没有这条媒体,可能已经清掉了。'];
        }

        $refs = $this->media->references($id);

        if ($refs > 0) {
            return ['done' => false, 'why' => '还有 ' . $refs . ' 处引用,先处理引用再彻底删除。'];
        }

        $paths = array_merge([(string) $record['file_path']], array_column((array) $record['sizes'], 'file'));

        foreach ($paths as $path) {
            if ((string) $path !== '') {
                $this->store->remove((string) $path);
            }
        }

        $this->media->purge($id);
        Audit::record('media.destroy', 'media:' . $id . ' post:' . $record['post_id'], true, null, 'user:' . $userId);

        return ['done' => true, 'why' => ''];
    }

    /**
     * 被引用处数(《41》第 7 节:删除时先提示"被 N 处引用",别让人点了才知道)。
     */
    public function references(int $id): int
    {
        return $this->media->references($id);
    }
}
