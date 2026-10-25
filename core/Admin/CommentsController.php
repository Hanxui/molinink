<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Comment\CommentRepository;
use Molin\Comment\Moderator;
use Molin\Content\HtmlSanitizer;
use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Router\Router;
use Molin\User\Authorizer;
use Molin\User\User;

/**
 * 后台评论管理屏(《31》§3、§9;版式照 后台评论管理-定稿-2026-09-30)。
 *
 * 审核动作全部走 POST;仪表盘那两颗快捷动作也归到这里,审核链只有一条。
 */
final class CommentsController
{
    use AdminGuards;

    private const PER_PAGE = 20;

    private const LABELS = [
        'pending' => '待审',
        'approved' => '已通过',
        'spam' => '垃圾',
        'trash' => '回收站',
    ];

    private const ST_CLASS = [
        'pending' => 'pend',
        'approved' => 'ok',
        'spam' => 'trash',
        'trash' => 'trash',
    ];

    public function __construct(
        private readonly View $view,
        private readonly CommentRepository $comments,
        private readonly Authorizer $can,
        private readonly Database $db,
        private readonly Router $router,
        private readonly Moderator $moderator
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'comment.moderate')) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __('评论'),
                'capability' => 'comment.moderate',
            ], $menu, 403);
        }

        $parts = explode('/', trim($path, '/'));
        array_shift($parts);
        $head = $parts[0] ?? '';

        if ($head === '') {
            return $this->index($request, $menu);
        }

        if ($head === 'bulk') {
            return $this->bulk($request, $user, $menu);
        }

        if (ctype_digit($head) && $request->isPost()) {
            $verb = $parts[1] ?? '';

            if (in_array($verb, ['approve', 'spam', 'trash', 'restore', 'destroy', 'edit', 'reply'], true)) {
                return $this->act($request, (int) $head, $verb, $user, $menu);
            }
        }

        throw new NotFoundException('评论屏没有这个地址:' . $path);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function index(Request $request, array $menu): Response
    {
        $filters = $this->filters($request);
        $page = $request->queryInt('page', 1, 100000);

        $query = $this->listQuery($filters);
        $rows = $this->hydrate($query->rows(self::PER_PAGE, $page), $query->count());

        return $this->view->render('comments.twig', [
            'page_title' => __('评论'),
            'page_kind' => 'comments',
            'rows' => $rows,
            'tabs' => $this->tabs($filters),
            'filters' => $filters,
            'filter_tail' => $this->tail($filters),
            'post_choices' => $this->postChoices(),
            'bulk_url' => $this->url('comments/bulk'),
            'pagination' => $this->pagination($page, $query->count(), $filters),
            'notice' => $this->flash($request),
        ], $menu);
    }

    /**
     * @return array{status:string,q:string,post:int,who:string,period:string}
     */
    /**
     * @return array{status:string, q:string, post:int, who:string, period:string}
     */
    private function filters(Request $request): array
    {
        $status = $request->input('status');

        return [
            'status' => isset(self::LABELS[$status]) ? $status : 'all',
            'q' => trim($request->input('q')),
            'post' => max(0, (int) $request->input('post')),
            'who' => in_array($request->input('who'), ['user', 'guest'], true) ? $request->input('who') : '',
            'period' => in_array($request->input('period'), ['today', 'week'], true) ? $request->input('period') : '',
        ];
    }

    /**
     * 列表查询(《31》§2 的状态口径 + §5 的筛选维度)。列表与计数共用同一份 WHERE。
     * @param array{status:string, q:string, post:int, who:string, period:string} $filters
     * @param array{status:string, q:string, post:int, who:string, period:string} $filters
     */
    private function listQuery(array $filters): CommentListQuery
    {
        $where = [];
        $params = [];

        if ($filters['status'] !== 'all') {
            $where[] = 'c.status = :status';
            $params['status'] = $filters['status'];
        }

        if ($filters['q'] !== '') {
            $where[] = '(c.content LIKE :q OR c.author_name LIKE :q2)';
            $params['q'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
        }

        if ($filters['post'] > 0) {
            $where[] = 'c.post_id = :post';
            $params['post'] = $filters['post'];
        }

        if ($filters['who'] === 'user') {
            $where[] = 'c.user_id > 0';
        } elseif ($filters['who'] === 'guest') {
            $where[] = 'c.user_id = 0';
        }

        if ($filters['period'] === 'today') {
            $where[] = 'c.created_at >= :since';
            $midnight = strtotime('today');
            $params['since'] = gmdate('Y-m-d H:i:s', $midnight === false ? time() : $midnight);
        } elseif ($filters['period'] === 'week') {
            $where[] = 'c.created_at >= :since';
            $params['since'] = gmdate('Y-m-d H:i:s', time() - 604800);
        }

        $whereSql = $where === [] ? 'WHERE 1' : 'WHERE ' . implode(' AND ', $where);

        return new CommentListQuery($this->db, $whereSql, $params);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows, int $total): array
    {
        $links = $this->postLinks($rows);
        $out = [];

        foreach ($rows as $row) {
            $postId = (int) $row['post_id'];
            $status = (string) $row['status'];
            $stamp = strtotime((string) $row['created_at'] . ' UTC');
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $row['content'])) ?? '');
            $link = $links[$postId] ?? null;

            $out[] = [
                'id' => (int) $row['id'],
                'q' => mb_strlen($plain) > 80 ? mb_substr($plain, 0, 80) . '…' : $plain,
                'post_title' => $link['title'] ?? ('#' . $postId),
                'post_url' => $link === null || $link['slug'] === '' ? '' : $this->router->path('post', [
                    'slug'         => $link['slug'],
                    'post_id'      => $postId,
                    'type'         => $link['type'],
                    'published_at' => $link['published_at'],
                ]),
                'when' => $stamp === false ? '' : date('Y-m-d H:i', $stamp),
                'who' => $row['author_name'],
                'mail' => $row['author_email'],
                'status' => $status,
                'status_label' => __(self::LABELS[$status] ?? $status),
                'st_class' => self::ST_CLASS[$status] ?? 'pend',
                'approve_url' => $this->url('comments/' . (int) $row['id'] . '/approve'),
                'spam_url' => $this->url('comments/' . (int) $row['id'] . '/spam'),
                'trash_url' => $this->url('comments/' . (int) $row['id'] . '/trash'),
                'restore_url' => $this->url('comments/' . (int) $row['id'] . '/restore'),
                'destroy_url' => $this->url('comments/' . (int) $row['id'] . '/destroy'),
                'edit_url' => $this->url('comments/' . (int) $row['id'] . '/edit'),
                'reply_url' => $this->url('comments/' . (int) $row['id'] . '/reply'),
                // 行内编辑框的原样正文(库里存的已是白名单后的形态)
                'content' => (string) $row['content'],
            ];
        }

        return $out;
    }

    /**
     * 评论行要回带的文章信息(标题 + 生成地址要的原样字段),一次 IN 查全,不给每行单开一次。
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array{title:string, slug:string, type:string, published_at:string}>
     */
    private function postLinks(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[(int) $row['post_id']] = true;
        }

        $ids = array_keys($ids);

        if ($ids === []) {
            return [];
        }

        $marks = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $key = 't' . $index;
            $marks[] = ':' . $key;
            $params[$key] = $id;
        }

        $links = [];

        foreach ($this->db->select('SELECT id, title, slug, type, published_at FROM ' . $this->db->table('posts') . ' WHERE id IN (' . implode(',', $marks) . ')', $params) as $row) {
            $links[(int) $row['id']] = [
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'type' => (string) $row['type'],
                'published_at' => (string) ($row['published_at'] ?? ''),
            ];
        }

        return $links;
    }

    /**
     * @return list<array{id:int, title:string}>
     */
    private function postChoices(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
        ], $this->db->select(
            'SELECT DISTINCT p.id, p.title FROM ' . $this->db->table('posts') . ' p'
            . ' JOIN ' . $this->db->table('comments') . ' c ON c.post_id = p.id'
            . " WHERE c.status <> 'trash' ORDER BY p.id DESC LIMIT 50"
        ));
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = [];
        $all = 0;

        foreach ($this->comments->countsByStatus() as $status => $n) {
            $counts[$status] = $n;
            $all += $n;
        }

        $counts['all'] = $all;

        return $counts;
    }

    /**
     * @return list<array{label:string, url:string, active:bool, count:int}>
     * @param array{status:string, q:string, post:int, who:string, period:string} $filters
     * @param array{status:string, q:string, post:int, who:string, period:string} $filters
     */
    private function tabs(array $filters): array
    {
        $counts = $this->statusCounts();
        $tabs = [['label' => __('全部'), 'url' => $this->url('comments'), 'active' => $filters['status'] === 'all', 'count' => $counts['all']]];

        foreach (self::LABELS as $value => $label) {
            $tabs[] = [
                'label' => __($label),
                'url' => $this->url('comments?status=' . $value),
                'active' => $filters['status'] === $value,
                'count' => $counts[$value] ?? 0,
            ];
        }

        return $tabs;
    }

    /**
     * @param array{status:string,q:string,post:int,who:string,period:string} $filters
     */
    private function tail(array $filters, bool $withStatus = true): string
    {
        $parts = [];

        if ($withStatus && $filters['status'] !== 'all') {
            $parts[] = 'status=' . rawurlencode($filters['status']);
        }

        if ($filters['q'] !== '') {
            $parts[] = 'q=' . rawurlencode($filters['q']);
        }

        if ($filters['post'] > 0) {
            $parts[] = 'post=' . $filters['post'];
        }

        if ($filters['who'] !== '') {
            $parts[] = 'who=' . $filters['who'];
        }

        if ($filters['period'] !== '') {
            $parts[] = 'period=' . $filters['period'];
        }

        return $parts === [] ? '' : '&' . implode('&', $parts);
    }

    /**
     * @return array<string, mixed>
     * @param array{status:string, q:string, post:int, who:string, period:string} $filters
     * @param array{status:string, q:string, post:int, who:string, period:string} $filters
     */
    private function pagination(int $page, int $total, array $filters): array
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $first = max(1, min($page - 2, $pages - 9));
        $links = [];

        for ($number = $first; $number <= min($pages, $first + 9); $number++) {
            $links[] = [
                'page' => $number,
                'url' => $this->url('comments?page=' . $number) . $this->tail($filters),
                'active' => $number === $page,
            ];
        }

        return [
            'page' => $page,
            'total_pages' => $pages,
            'total' => $total,
            'from' => $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1,
            'to' => min($total, $page * self::PER_PAGE),
            'links' => $links,
        ];
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function bulk(Request $request, User $user, array $menu): Response
    {
        if (!$request->isPost()) {
            throw new NotFoundException('批量操作只认 POST:' . $request->method());
        }

        if (!$this->tokenOk($request)) {
            return Response::redirect($this->url('comments'));
        }

        $map = ['approve' => 'approved', 'spam' => 'spam', 'trash' => 'trash'];
        $verb = $request->input('bulk');
        $done = 0;

        if ($verb === 'destroy') {
            foreach ($this->ints($request, 'ids') as $id) {
                $row = $this->comments->find($id);

                if ($row !== null && $row['status'] === 'trash' && $this->comments->deleteTree($id) > 0) {
                    $this->comments->recomputeCount((int) $row['post_id']);
                    $done++;
                }
            }
        } elseif (isset($map[$verb])) {
            foreach ($this->ints($request, 'ids') as $id) {
                if ($this->apply($id, $map[$verb], $user)) {
                    $done++;
                }
            }
        }

        if ($done > 0) {
            Audit::record('comment.bulk', 'comments:' . $done, true, $request, 'user:' . $user->id);
        }

        return Response::redirect($this->url('comments') . '?done=' . ($done > 0 ? 'saved' : 'denied') . $this->tail($this->filters($request)));
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function act(Request $request, int $id, string $verb, User $user, array $menu): Response
    {
        if (!$this->tokenOk($request)) {
            return Response::redirect($this->url('comments'));
        }

        // 「编辑」「回复」不是状态转移,不走 Moderator;各自的守卫在方法里
        if ($verb === 'edit') {
            $ok = $this->editContent($request, $id, $user);

            return Response::redirect($this->url('comments') . '?done=' . ($ok ? 'saved' : 'denied'));
        }

        if ($verb === 'reply') {
            $ok = $this->replyComment($request, $id, $user);

            return Response::redirect($this->url('comments') . '?done=' . ($ok ? 'saved' : 'denied'));
        }

        $map = [
            'approve' => 'approved',
            'spam' => 'spam',
            'trash' => 'trash',
            'restore' => 'pending',
            'destroy' => 'destroy',
        ];

        if (!isset($map[$verb])) {
            throw new NotFoundException('评论屏没有这个操作:' . $verb);
        }

        $status = $map[$verb];
        $ok = $status === 'destroy'
            ? $this->destroy($id)
            : $this->apply($id, $status, $user);

        return Response::redirect($this->url('comments') . '?done=' . ($ok ? 'saved' : 'denied'));
    }

    /**
     * 行内改正文(《82》§6)。回收站里的不给改(那个状态的操作只有恢复与永久删除);
     * 正文照提交同一条白名单(《31》§11),改完记审计。
     */
    private function editContent(Request $request, int $id, User $user): bool
    {
        $row = $this->comments->find($id);

        if ($row === null || $row['status'] === 'trash') {
            return false;
        }

        $content = HtmlSanitizer::clean($request->input('content'), HtmlSanitizer::COMMENT_TAGS);

        if ($this->plain($content) === '') {
            return false;
        }

        if ($content === (string) $row['content']) {
            return false;
        }

        $ok = $this->comments->updateContent($id, $content);

        if ($ok) {
            Audit::record('comment.edit', 'comment:' . $id, true, $request, 'user:' . $user->id);
        }

        return $ok;
    }

    /**
     * 行内回复(《82》§6):以当前后台用户的身份直接发一条已通过的回复,写入 parent_id。
     * 两级嵌套(《31》§2):回复"回复"时挂回它所在的顶层楼层。
     */
    private function replyComment(Request $request, int $id, User $user): bool
    {
        $row = $this->comments->find($id);

        if ($row === null || !in_array($row['status'], ['pending', 'approved'], true)) {
            return false;
        }

        $content = HtmlSanitizer::clean($request->input('content'), HtmlSanitizer::COMMENT_TAGS);

        if ($this->plain($content) === '') {
            return false;
        }

        $parentId = (int) $row['parent_id'] > 0 ? (int) $row['parent_id'] : $id;

        $commentId = $this->comments->insert([
            'post_id' => (int) $row['post_id'],
            'parent_id' => $parentId,
            'user_id' => $user->id,
            'author_name' => $user->display_name !== '' ? $user->display_name : $user->username,
            'author_email' => $user->email,
            'author_ip' => $request->ip(),
            'author_ua' => mb_substr($request->userAgent(), 0, 255),
            'content' => $content,
            'status' => 'approved',
            'type' => 'comment',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->comments->recomputeCount((int) $row['post_id']);
        Audit::record('comment.reply', 'comment:' . $commentId . ' parent:' . $parentId, true, $request, 'user:' . $user->id);

        // 新评论入库的对外事件(《31》§4 ⑨):通知、索引交插件
        do_action('comment/submit/after', $commentId, (int) $row['post_id']);

        return true;
    }

    /**
     * 白名单后的纯文本(与提交流同一条口径:过滤标签再判空)。
     */
    private function plain(string $content): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($content)));
    }

    /**
     * 状态转移的唯一入口(仪表盘快捷动作与列表动作都走这里);逻辑在 Moderator,
     * comment/moderate/before|after 两个钩子(《31》§9)也只在那里触发。
     */
    public function apply(int $id, string $status, User $user): bool
    {
        return $this->moderator->apply($id, $status, $user);
    }

    private function destroy(int $id): bool
    {
        $row = $this->comments->find($id);

        // 只有回收站里的能真删(《31》§3),免得手一抖没了后悔药
        if ($row === null || $row['status'] !== 'trash') {
            return false;
        }

        // 整棵回复树一并清掉:只删一行会留下浮空孤儿,再以「父级不可见」重新冒头
        $deleted = $this->comments->deleteTree($id) > 0;
        $this->comments->recomputeCount((int) $row['post_id']);

        return $deleted;
    }

    private function flash(Request $request): string
    {
        return $request->input('done') === 'saved' ? __('已完成。') : ($request->input('done') === 'denied' ? __('这一步没有成功 —— 状态可能已经变过。') : '');
    }

    /**
     * @return list<int>
     */
    private function ints(Request $request, string $key): array
    {
        $raw = $request->inputs()[$key] ?? null;
        $ids = [];

        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    private function url(string $sub): string
    {
        // `comments?status=…` 这类子串:路径交给路由,查询串原样拼接 ——
        // 整体塞进 {path} 会被逐段 rawurlencode,'?' 变成 %3F 匹配不到(与媒体库同款 bug)
        $position = strpos($sub, '?');

        return $this->router->path('admin', ['path' => $position === false ? $sub : substr($sub, 0, $position)])
            . ($position === false ? '' : substr($sub, $position));
    }
}
