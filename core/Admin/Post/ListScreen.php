<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Post;

use Molin\Admin\View;
use Molin\Admin\WebAuth;
use Molin\Content\Post;
use Molin\Content\PostQuery;
use Molin\Content\PostRepository;
use Molin\Content\PostTypeRegistry;
use Molin\Content\StatusMachine;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\User\Authorizer;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 列表屏:筛选 / 页签 / 分页 / 行渲染,以及列表页上的报错回显。
 */
class ListScreen extends PostScreen
{
    /** 《41》第 6.1 节:分页默认 20/页 */
    private const PER_PAGE = 20;

    /** 回收站单成一栏,"全部"里不许混进来 */
    private const VISIBLE = [StatusMachine::DRAFT, StatusMachine::PENDING, StatusMachine::PUBLISH, StatusMachine::PRIVATE];

    public function __construct(
        View $view,
        PostRepository $posts,
        protected readonly UserRepository $users,
        protected readonly TermAssigner $terms,
        Authorizer $can,
        protected readonly Database $db,
        string $managedType
    ) {
        parent::__construct($view, $posts, $can, $managedType);
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function index(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->canAny($user, $this->floorCaps())) {
            return $this->forbidden($menu, $this->floorCaps(), $this->screenLabel());
        }

        $filters = $this->filters($request);
        $page = $request->queryInt('page', 1, 100000);

        $query = $this->listQuery($filters)->limit(self::PER_PAGE)->page($page);
        $posts = $query->get();
        $total = $query->count();
        $ids = array_map(static fn (Post $post): int => $post->id, $posts);

        // 作者名要按作者 id 查,不是按文章 id —— 拿文章 id 去查会把"第 N 篇文章"错当成"第 N 号用户"
        $authorIds = array_map(static fn (Post $post): int => $post->author_id, $posts);
        $authors = $this->users->namesFor($authorIds);
        $categories = $this->terms->namesFor($ids, TermAssigner::CATEGORY);
        $tallies = $this->commentTallies($ids);
        $catIds = $this->categoryIdsFor($ids);

        $rows = [];

        foreach ($posts as $post) {
            $rows[] = $this->row($user, $post, $authors, $categories, $tallies, $catIds);
        }

        // 快速编辑展开哪一行由查询串说了算(?qe=12)—— 这样刷新、后退、分享链接都还在同一态
        $quickId = max(0, (int) $request->input('qe'));

        $isPage = $this->managedType === 'page';
        $isCpt = $this->isCpt();
        // 层级型:页面天然是,hierarchical 的 CPT 也算(《34》§2.2)。列表照《86》口径 —— 平铺
        // 一行一列「层级」,而不是把子行缩进挪到父行下面。
        $hierarchical = $this->isHierarchical();

        $parentTitles = $hierarchical ? $this->parentTitles($ids) : [];
        $pageParentIds = $hierarchical ? $this->parentIds($ids) : [];
        $childCounts = $hierarchical ? $this->childCounts($ids) : [];

        foreach ($rows as $index => $row) {
            $parentId = $pageParentIds[$row['id']] ?? 0;
            $rows[$index]['hierarchy'] = $parentId > 0 ? (($parentTitles[$row['id']] ?? '#' . $parentId) . ' › ' . __('本级')) : __('顶层');

            if ($hierarchical) {
                $children = $childCounts[$row['id']] ?? 0;
                $rows[$index]['sub'] = __('别名') . ' ' . $row['slug']
                    . ($children > 0 ? ' · ' . ($isPage ? __('%s 个子页面', 'molin', $children) : __('%s 个子项', 'molin', $children)) : '');
            }
        }

        // CPT 列表的「分类 / 层级」那一列:非层级型取第一个声明的分类法名;层级型改显层级(to 模板)
        if ($isCpt) {
            $declared = PostTypeRegistry::instance()->taxonomies($this->managedType);

            if ($declared !== []) {
                $names = $this->terms->namesFor($ids, $declared[0]);

                foreach ($rows as $index => $row) {
                    $rows[$index]['categories'] = implode(', ', $names[$row['id']] ?? []);
                }
            }
        }

        $template = $isPage ? 'pages.twig' : ($isCpt ? 'posttype.twig' : 'posts.twig');

        return $this->view->render($template, [
            'page_title' => $this->screenLabel(),
            // CPT 列表复用文章屏那套 CSS(.page-posts 的七列栅格),所以 page_kind 仍是 posts
            'page_kind' => $isPage ? 'posts page-pages' : 'posts',
            // CPT 列表模板要的中文名与单数名;文章 / 页面那两份模板不用这两个变量
            'type_label' => $this->screenLabel(),
            'type_singular' => $this->screenSingular(),
            'is_hierarchical' => $hierarchical,
            'rows' => $rows,
            'quick_id' => $quickId,
            'quick_statuses' => $isPage ? [
                StatusMachine::DRAFT => __('草稿'),
                StatusMachine::PUBLISH => __('已发布'),
            ] : [
                // 四档与整页编辑的状态列同源(《78》§10.7 第 6 条已定,2026-10-03)
                StatusMachine::DRAFT => __('草稿'),
                StatusMachine::PENDING => __('待审'),
                StatusMachine::PUBLISH => __('已发布'),
                StatusMachine::PRIVATE => __('私密'),
            ],
            'tabs' => $this->tabs($filters),
            'filters' => $filters,
            'filters_action' => $this->url('posts'),
            'filter_tail' => $this->tail($filters),
            'category_choices' => $this->terms->choices(TermAssigner::CATEGORY),
            'parent_choices' => $hierarchical ? $this->parentChoices() : [],
            'author_choices' => $this->authorChoices(),
            'month_choices' => $this->monthChoices(),
            'bulk_url' => $this->url('posts/bulk'),
            'can_bulk' => $this->can->canAny($user, [$this->cap('edit.own'), $this->cap('edit.others'), $this->cap('delete.own'), $this->cap('delete.others')]),
            'pagination' => $this->pagination($page, $total, $filters),
            'notice' => $this->flash($request),
            'new_url' => $this->can->can($user, $this->cap('create')) ? $this->url('posts/new') : '',
            // 分类与标签的管理入口(《87》):只给有 category.manage 的人;页面 / CPT 列表不渲染它
            'categories_url' => $this->url('categories'),
            'can_manage_categories' => $this->can->can($user, 'category.manage'),
        ], $menu);
    }

    /**
     * 列表页的查询条件(《78》§4.2 页签 + §4.3 筛选;《86》§4 页面多一个「父页面」)。
     * 认不过的值一律回落到空,不留"看着像筛选其实没生效"的状态。
     *
     * @return array{status:string,q:string,cat:string,author:int,month:string,parent:string,order:string}
     */
    protected function filters(Request $request): array
    {
        $status = $request->input('status');
        $month = $request->input('month');
        $parent = trim($request->input('parent'));
        $order = $request->input('order');

        return [
            'status' => $status !== '' && !isset(self::LABELS[$status]) ? '' : $status,
            'q' => trim($request->input('q')),
            'cat' => trim($request->input('cat')),
            'author' => max(0, (int) $request->input('author')),
            'month' => preg_match('#\A\d{4}-\d{2}\z#', $month) === 1 ? $month : '',
            'parent' => $parent === '' ? '' : (string) max(0, (int) $parent),
            // 层级型列表的排序入口(《86》口径的补充):menu = 按序号(menu_order),空 = 按最近修改
            'order' => $order === 'menu' ? 'menu' : '',
        ];
    }

    /**
     * @param array{status:string,q:string,cat:string,author:int,month:string,parent:string,order:string} $filters
     */
    private function listQuery(array $filters): PostQuery
    {
        // 层级型内容(页面 / hierarchical 的 CPT)天然是按序号组织的目录(《34》§2.2):
        // 选了「按序号」就按 menu_order 升序、并列按创建先后;其余(含默认)维持最近修改在前。
        $query = $this->isHierarchical() && $filters['order'] === 'menu'
            ? $this->posts->query()->type($this->managedType)->orderBy('menu_order', 'asc', 'asc')
            : $this->posts->query()->type($this->managedType)->orderBy('updated_at', 'desc');

        $query = $query->search($filters['q'])->author($filters['author'])->inMonth($filters['month']);

        if ($filters['cat'] !== '') {
            $query = $query->category($filters['cat']);
        }

        if ($filters['parent'] !== '') {
            $query = $query->parent((int) $filters['parent']);
        }

        if ($filters['status'] === '') {
            return $query->statuses(self::VISIBLE);
        }

        // 回收站这一栏要看得见被软删的行,否则还原与真删都没有入口(《41》第 6.1 节)
        return $query->status($filters['status'])->trashed($filters['status'] === StatusMachine::TRASH);
    }

    /**
     * 页签计数一次查完(《78》§9)。"全部"不含回收站,私密算在"全部"里但不单开页签 ——
     * 基准图上就是五档(§4.2)。
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS n FROM ' . $this->db->table('posts')
            . " WHERE type = '" . $this->managedType . "' AND (deleted_at IS NULL OR status = 'trash') GROUP BY status"
        );

        $counts = [];
        $all = 0;

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $counts[$status] = (int) $row['n'];

            if ($status !== StatusMachine::TRASH) {
                $all += (int) $row['n'];
            }
        }

        $counts['all'] = $all;

        return $counts;
    }

    /**
     * 本页文章的评论数(只数已通过的那几条)。
     *
     * @param list<int> $postIds
     *
     * @return array<int, int>
     */
    private function commentTallies(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $marks = [];
        $params = [];

        foreach (array_values($postIds) as $index => $id) {
            $key = 'p' . $index;
            $marks[] = ':' . $key;
            $params[$key] = $id;
        }

        $rows = $this->db->select(
            'SELECT post_id, COUNT(*) AS n FROM ' . $this->db->table('comments')
            . ' WHERE post_id IN (' . implode(',', $marks) . ") AND status = 'approved' GROUP BY post_id",
            $params
        );

        $tallies = [];

        foreach ($rows as $row) {
            $tallies[(int) $row['post_id']] = (int) $row['n'];
        }

        return $tallies;
    }

    /**
     * 作者下拉只列"真写过文章的人",否则一个 200 人的站会给出 200 个空选项。
     *
     * @return list<array{id:int, name:string}>
     */
    private function authorChoices(): array
    {
        $rows = $this->db->select(
            'SELECT u.id, u.display_name, u.username FROM ' . $this->db->table('users') . ' u'
            . ' WHERE u.id IN (SELECT DISTINCT author_id FROM ' . $this->db->table('posts')
            . " WHERE type = '" . $this->managedType . "' AND deleted_at IS NULL) ORDER BY u.display_name"
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) ($row['display_name'] !== '' ? $row['display_name'] : $row['username']),
        ], $rows);
    }

    /**
     * 时间下拉按文章实际分布的月份给(《78》§9),最多回溯 24 个月。
     * 标签写成「2026 年 9 月」是基准图上的字样。
     *
     * @return list<array{value:string, label:string}>
     */
    private function monthChoices(): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT DATE_FORMAT(COALESCE(published_at, created_at), \'%Y-%m\') AS m FROM '
            . $this->db->table('posts') . " WHERE type = '" . $this->managedType . "' AND deleted_at IS NULL"
            . ' ORDER BY m DESC LIMIT 24'
        );

        $choices = [];

        foreach ($rows as $row) {
            $value = (string) $row['m'];

            if (preg_match('#\A(\d{4})-(\d{2})\z#', $value, $m) !== 1) {
                continue;
            }

            $choices[] = ['value' => $value, 'label' => format_date($value . '-01', 'month')];
        }

        return $choices;
    }

    /**
     * 链接尾巴:页签与分页都要把当前筛选带上,否则点一下页签就把搜索条件丢了。
     *
     * @param array{status:string,q:string,cat:string,author:int,month:string,parent:string,order:string} $filters
     */
    protected function tail(array $filters, bool $withStatus = true): string
    {
        $parts = [];

        if ($withStatus && $filters['status'] !== '') {
            $parts[] = 'status=' . rawurlencode($filters['status']);
        }

        if ($filters['q'] !== '') {
            $parts[] = 'q=' . rawurlencode($filters['q']);
        }

        if ($filters['cat'] !== '') {
            $parts[] = 'cat=' . rawurlencode($filters['cat']);
        }

        if ($filters['author'] > 0) {
            $parts[] = 'author=' . $filters['author'];
        }

        if ($filters['month'] !== '') {
            $parts[] = 'month=' . rawurlencode($filters['month']);
        }

        if ($filters['parent'] !== '') {
            $parts[] = 'parent=' . rawurlencode($filters['parent']);
        }

        // 排序入口也要跟着页签 / 分页走,换页不会把「按序号」丢掉
        if ($filters['order'] !== '') {
            $parts[] = 'order=' . rawurlencode($filters['order']);
        }

        return $parts === [] ? '' : '&' . implode('&', $parts);
    }

    /**
     * 页面的 parent_id 映射。
     *
     * @param list<int> $postIds
     *
     * @return array<int, int>
     */
    protected function parentIds(array $postIds): array
    {
        $out = [];

        foreach ($this->db->select(
            'SELECT id, parent_id FROM ' . $this->db->table('posts')
            . ' WHERE id IN (' . implode(',', array_map('intval', $postIds === [] ? [0] : $postIds)) . ') AND parent_id > 0'
        ) as $row) {
            $out[(int) $row['id']] = (int) $row['parent_id'];
        }

        return $out;
    }

    /**
     * 页面「序号」那一格的当前值(posts.menu_order)。Post 值对象不带这一格,单查。
     */
    protected function menuOrderOf(int $id): int
    {
        $row = $this->db->selectOne(
            'SELECT menu_order FROM ' . $this->db->table('posts') . ' WHERE id = :id',
            ['id' => $id]
        );

        return (int) ($row['menu_order'] ?? 0);
    }

    /**
     * 页面的父页面标题(《125》配套基准 后台页面列表 的层级列)。
     *
     * @param list<int> $postIds
     *
     * @return array<int, string> page id => 父标题
     */
    private function parentTitles(array $postIds): array
    {
        $parents = $this->db->select(
            'SELECT c.id AS child, c.parent_id, p.title FROM ' . $this->db->table('posts') . ' c'
            . ' JOIN ' . $this->db->table('posts') . ' p ON p.id = c.parent_id'
            . ' WHERE c.id IN (' . implode(',', array_map('intval', $postIds === [] ? [0] : $postIds)) . ') AND c.parent_id > 0'
        );

        $titles = [];

        foreach ($parents as $row) {
            $titles[(int) $row['child']] = (string) $row['title'];
        }

        return $titles;
    }

    /**
     * 每个页面挂着的直接子页数(《86》§4 副行「N 个子页面」)。
     *
     * @param list<int> $postIds
     *
     * @return array<int, int>
     */
    private function childCounts(array $postIds): array
    {
        $rows = $this->db->select(
            'SELECT parent_id, COUNT(*) AS n FROM ' . $this->db->table('posts')
            . ' WHERE type = :type AND deleted_at IS NULL AND parent_id IN ('
            . implode(',', array_map('intval', $postIds === [] ? [0] : $postIds)) . ') GROUP BY parent_id',
            ['type' => $this->managedType]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['parent_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * 「全部父页面」下拉的候选(《86》§4)。回收站里的页不做候选:
     * 挂到一个看不见的父页下面,列表里就再也找不到它了。
     *
     * @return list<array{id:int, title:string}>
     */
    private function parentChoices(): array
    {
        $rows = $this->db->select(
            'SELECT id, title FROM ' . $this->db->table('posts')
            . " WHERE type = :type AND status <> 'trash' AND deleted_at IS NULL ORDER BY id ASC",
            ['type' => $this->managedType]
        );

        $choices = [];

        foreach ($rows as $row) {
            $choices[] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
        }

        return $choices;
    }

    /**
     * 每篇的分类 id(快速编辑那格要标出"当前选的是哪一个",其余的得原样带回去,
     * 不然一篇挂了两个分类的文章改一次标题就掉一个)。
     *
     * @param list<int> $postIds
     *
     * @return array<int, list<int>>
     */
    private function categoryIdsFor(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $marks = [];
        $params = [];

        foreach (array_values($postIds) as $index => $id) {
            $key = 'c' . $index;
            $marks[] = ':' . $key;
            $params[$key] = $id;
        }

        $rows = $this->db->select(
            'SELECT tr.post_id, tt.term_taxonomy_id FROM ' . $this->db->table('term_relationships') . ' tr'
            . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id'
            . ' WHERE tr.post_id IN (' . implode(',', $marks) . ") AND tt.taxonomy = 'category'"
            . ' ORDER BY tt.term_taxonomy_id',
            $params
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['post_id']][] = (int) $row['term_taxonomy_id'];
        }

        return $map;
    }

    /**
     * @param array<int, string> $authors     user_id => 显示名
     * @param array<int, list<string>> $categories post_id => 分类名
     * @param array<int, int> $tallies        post_id => 已通过评论数
     * @param array<int, list<int>> $catIds   post_id => 分类 term_taxonomy_id
     *
     * @return array<string, mixed>
     */
    private function row(User $user, Post $post, array $authors, array $categories, array $tallies, array $catIds): array
    {
        $editable = $this->can->can($user, $this->cap('edit.others'))
            || $this->can->can($user, $this->cap('edit.own'), $post);
        $deletable = $this->can->can($user, $this->cap('delete.others'))
            || $this->can->can($user, $this->cap('delete.own'), $post);
        // 《34》第 5 节只画了 `publish → trash`,而《41》第 6.1 节把"回收站"列成每行的通用操作 ——
        // 两份文档对不上。这里先按状态机(数据层的权威)办事:不该出现的按钮就不出现,
        // 差异已写进待办清单的待拍板清单,等作者定"草稿/待审怎么删"。
        $inTrash = $post->status === StatusMachine::TRASH;
        // 《78》§4.5:回收站行的行内操作"替换为"还原 + 删除,编辑/快速编辑/查看/回收站都不出现。
        // canGo(from===to) 为真是给"保存但不改状态"用的,不能当"还能再进一次回收站"(2026-10-03 真跑抓出)。
        $movable = $deletable && !$inTrash && StatusMachine::canGo($post->status, StatusMachine::TRASH);
        $stamp = strtotime($post->updated_at . ' UTC');

        return [
            'id' => $post->id,
            'title' => $post->title,
            // 行标识:无标题的类型(说说)在列表里也得认得出是哪一条(见 labelFor)
            'label' => $this->labelFor($post),
            'status' => $post->status,
            'status_label' => __(self::LABELS[$post->status] ?? $post->status),
            'author' => $authors[$post->author_id] ?? ('#' . $post->author_id),
            'categories' => implode(', ', $categories[$post->id] ?? []),
            'comments' => $tallies[$post->id] ?? 0,
            'date' => $stamp === false ? '' : date('Y-m-d', $stamp),
            'sub' => __('最后修改于') . ' ' . ($stamp === false ? '—' : self::ago($stamp)),
            'updated_at' => $post->updated_at,
            'view_url' => $inTrash ? '' : $post->url,
            'edit_url' => $editable && !$inTrash ? $this->url('posts/' . $post->id . '/edit') : '',
            'quick_url' => $this->url('posts/' . $post->id . '/quick'),
            'slug' => $post->slug,
            'trash_url' => $movable ? $this->url('posts/' . $post->id . '/trash') : '',
            'restore_url' => $deletable && $inTrash ? $this->url('posts/' . $post->id . '/restore') : '',
            'destroy_url' => $deletable && $inTrash ? $this->url('posts/' . $post->id . '/destroy') : '',
            'can_edit' => $editable && !$inTrash,
            'can_delete' => $deletable,
            'st_class' => match ($post->status) {
                StatusMachine::TRASH => 'trash',
                StatusMachine::DRAFT, StatusMachine::PENDING => 'draft',
                default => 'pub',
            },
            'cat_ids' => $catIds[$post->id] ?? [],
        ];
    }

    /**
     * @param array{status:string,q:string,cat:string,author:int,month:string,parent:string,order:string} $filters
     *
     * @return list<array{label:string, url:string, active:bool, count:int}>
     */
    private function tabs(array $filters): array
    {
        $counts = $this->statusCounts();
        $tabs = [[
            'label' => __('全部'),
            'url' => $this->url('posts') . $this->tail($filters, false),
            'active' => $filters['status'] === '',
            'count' => $counts['all'],
        ]];

        // 页签顺序钉死《78》§4.2:全部 / 已发布 / 草稿 / 待审 / 回收站(私密不单开)。
        // 页面没有 pending 态(《34》§1、《86》§4),那一档就不摆出来。
        $kinds = $this->managedType === 'page'
            ? [StatusMachine::PUBLISH, StatusMachine::DRAFT, StatusMachine::TRASH]
            : [StatusMachine::PUBLISH, StatusMachine::DRAFT, StatusMachine::PENDING, StatusMachine::TRASH];

        foreach ($kinds as $value) {
            $tabs[] = [
                'label' => __(self::LABELS[$value]),
                'url' => $this->url('posts?status=' . $value) . $this->tail($filters, false),
                'active' => $filters['status'] === $value,
                'count' => $counts[$value] ?? 0,
            ];
        }

        return $tabs;
    }

    /**
     * @param array{status:string,q:string,cat:string,author:int,month:string,parent:string,order:string} $filters
     *
     * @return array<string, mixed>
     */
    private function pagination(int $page, int $total, array $filters): array
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $first = max(1, min($page - 2, $pages - 9));
        $links = [];

        // 上一页 / 下一页是基准图上的两件(《78》§4.6)。到头那一侧不给 href,
        // 但保留同一个 <a> 的样子 —— 少一件会让这一排的高度与间距跟着变。
        $links[] = [
            'page' => __('上一页'),
            'url' => $page > 1 ? $this->url('posts?page=' . ($page - 1)) . $this->tail($filters) : '',
            'active' => false,
        ];

        for ($number = $first; $number <= min($pages, $first + 9); $number++) {
            $links[] = [
                'page' => $number,
                'url' => $this->url('posts?page=' . $number) . $this->tail($filters),
                'active' => $number === $page,
            ];
        }

        $links[] = [
            'page' => __('下一页'),
            'url' => $page < $pages ? $this->url('posts?page=' . ($page + 1)) . $this->tail($filters) : '',
            'active' => false,
        ];

        return [
            'page' => $page,
            'total_pages' => $pages,
            'total' => $total,
            'from' => $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1,
            'to' => min($total, $page * self::PER_PAGE),
            'gap' => $pages > min($pages, $first + 9) ? true : false,
            'links' => $links,
        ];
    }

    /**
     * 「最后修改于 2 小时前」那种说法(《78》§4.5 副行)。基准图上出现过 昨天 / 1 周前 / 2 周前,
     * 再往后就退回具体日期 —— 不编造"几个月前"这种没人画过的档。
     */
    private static function ago(int $timestamp): string
    {
        $seconds = time() - $timestamp;

        if ($seconds < 60) {
            return __('刚刚');
        }

        if ($seconds < 3600) {
            return __('%s 分钟前', 'molin', intdiv($seconds, 60));
        }

        if ($seconds < 86400) {
            return __('%s 小时前', 'molin', intdiv($seconds, 3600));
        }

        if ($seconds < 172800) {
            return __('昨天');
        }

        if ($seconds < 604800) {
            return __('%s 天前', 'molin', intdiv($seconds, 86400));
        }

        if ($seconds < 2419200) {
            return __('%s 周前', 'molin', intdiv($seconds, 604800));
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    protected function listWithError(array $menu, string $notice): Response
    {
        $filters = ['status' => '', 'q' => '', 'cat' => '', 'author' => 0, 'month' => '', 'parent' => '', 'order' => ''];

        $isPage = $this->managedType === 'page';
        $isCpt = $this->isCpt();
        $hierarchical = $this->isHierarchical();

        return $this->view->render($isPage ? 'pages.twig' : ($isCpt ? 'posttype.twig' : 'posts.twig'), [
            'page_title' => $this->screenLabel(),
            'page_kind' => $isPage ? 'posts page-pages' : 'posts',
            'type_label' => $this->screenLabel(),
            'type_singular' => $this->screenSingular(),
            'is_hierarchical' => $hierarchical,
            'rows' => [],
            'tabs' => $this->tabs($filters),
            'filters' => $filters,
            'filters_action' => $this->url('posts'),
            'filter_tail' => '',
            'quick_id' => 0,
            'quick_statuses' => [],
            'category_choices' => [],
            'parent_choices' => [],
            'author_choices' => [],
            'month_choices' => [],
            'bulk_url' => $this->url('posts/bulk'),
            'can_bulk' => false,
            'pagination' => ['page' => 1, 'total_pages' => 1, 'total' => 0, 'from' => 0, 'to' => 0, 'links' => []],
            'notice' => $notice,
            'new_url' => '',
            // 这一屏只说明"令牌过期",不给任何动作 —— 分类入口自然也不出
            'categories_url' => '',
            'can_manage_categories' => false,
        ], $menu, 403);
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    protected function refused(array $menu): Response
    {
        return $this->listWithError($menu, __(WebAuth::STALE));
    }
}
