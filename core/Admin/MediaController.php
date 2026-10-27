<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Content\PostRepository;
use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Media\MediaRepository;
use Molin\Media\Remover;
use Molin\Media\Store;
use Molin\Media\Upload;
use Molin\Media\Uploader;
use Molin\Option\Options;
use Molin\Security\Csrf;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\User;
use Throwable;

/**
 * 媒体库屏。
 *
 * 三件事:浏览(五页签 + 筛选 + 分页,网格/列表两视图)、上传(既有规范九步)、
 * 删(既有规范软删 + 既有规范二次确认)。校验全在 UploadPolicy,
 * 落盘全在 Store,这里只按设计稿把结果摆出来。
 *
 * 界面照既有规范与两张设计稿逐字实现;所有状态都能不用 JS 走通:
 * 选中走 `?sel=`,视图走 `?view=`,删除确认走 `?confirm=trash`,页签/筛选/分页都是 GET。
 */
final class MediaController
{
    use AdminGuards;

    /**
     * 每页项数的桌面默认:网格一屏 20(移动端两列也就一屏 20),列表 24。
     * 前端按视口给 `?per=` 时以它为准(移动端给 20 就固定 20 张),上限 60 兜底。
     */
    private const PER_PAGE = ['grid' => 20, 'list' => 24];

    /** `?per=` 的上限,拦住把整库一次拉出来的请求 */
    private const PER_MAX = 60;

    /** 页签(既有规范):键是查询参数 `tab`,`all` 与 `unref`/`removed` 在 SQL 里另有含义 */
    private const TABS = ['all' => '全部', 'image' => '图片', 'doc' => '文档', 'av' => '音视频', 'unref' => '未引用', 'removed' => '待清理'];

    /** 排序下拉,值对上 MediaRepository::ORDERS 的键 */
    private const ORDERS = ['new' => '最新上传', 'old' => '最早上传', 'name' => '文件名', 'size' => '文件大小'];

    /**
     * 拒收原因文案(既有规范钉死的八条,逐字照抄,逗号也是全角)。
     * 键来自 UploadPolicy 给 ValidationException 打的 rule;表里没有的规则就用策略原话。
     */
    private const REJECTIONS = [
        'ext_not_allowed' => '扩展名不在允许清单，已拒绝',
        'mime_mismatch' => '真实类型与扩展名不符，已拒绝',
        'image_unreadable' => '不是可解析的图片文件，已拒绝',
        'double_extension' => '双扩展名，一律拒绝',
        'null_byte' => '文件名含非法字符，已拒绝',
        'pixel_limit' => '像素超过上限 8000×8000',
        'svg_default' => 'SVG 默认不允许上传，可在配置中开启并强制净化',
    ];

    public function __construct(
        private readonly View $view,
        private readonly MediaRepository $media,
        private readonly Uploader $uploader,
        private readonly Remover $remover,
        private readonly Store $store,
        private readonly Options $options,
        private readonly Authorizer $can,
        private readonly PostRepository $posts
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        $parts = explode('/', trim($path, '/'));
        array_shift($parts);

        if ($parts === []) {
            return $request->isPost()
                ? $this->upload($request, $user, $menu)
                : $this->page($request, $user, $menu);
        }

        $head = $parts[0];

        if (!ctype_digit($head)) {
            throw new NotFoundException('媒体屏没有这个地址:' . $path);
        }

        $id = (int) $head;
        $verb = $parts[1] ?? '';

        if ($verb === 'trash' && $request->isPost()) {
            return $this->trash($request, $user, $menu, $id);
        }

        if ($verb === 'restore' && $request->isPost()) {
            return $this->restore($request, $user, $menu, $id);
        }

        if ($verb === 'destroy' && $request->isPost()) {
            return $this->destroy($request, $user, $menu, $id);
        }

        if ($verb === 'edit' && $request->isPost()) {
            return $this->edit($request, $user, $menu, $id);
        }

        // 无刷新换栏:点一张图不回整页,只把详情片段取回去换掉右栏
        if ($verb === 'detail' && $request->isPost()) {
            return $this->detailFragment($request, $user, $menu, $id);
        }

        throw new NotFoundException('媒体屏没有这个操作:' . $verb);
    }

    /**
     * 整屏。上传被拒或点了没规格的操作时,也回到这一屏,只是在队列块里说清为什么。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     * @param list<array<string, string>> $rows 队列行
     */
    private function page(Request $request, User $user, array $menu, array $rows = [], int $status = 200): Response
    {
        $view = $request->input('view') === 'list' ? 'list' : 'grid';
        $tab = isset(self::TABS[$request->input('tab')]) ? $request->input('tab') : 'all';
        $order = $request->input('order');
        $order = isset(self::ORDERS[$order]) ? $order : '';
        $filters = [
            // `all` 不加条件,`unref` 在 clause() 里换成"没有引用"的判定
            'tab' => $tab === 'all' ? '' : $tab,
            'month' => $request->input('month'),
            'q' => $request->input('q'),
            'order' => $order,
        ];
        $page = max(1, (int) $request->input('page', '1'));
        $per = $this->perOf($request);
        $perPage = $per > 0 ? $per : self::PER_PAGE[$view];
        $list = $this->media->browse($filters, $page, $perPage);
        $tally = $this->media->tally();
        $items = $list['items'];
        $selected = $this->selected($request, $items);
        $query = $this->contextQuery($request);
        $kept = array_diff_key($query, ['tab' => 1]);
        $mode = in_array($request->input('confirm'), ['trash', 'destroy'], true) ? $request->input('confirm') : '';
        $dialog = $mode !== '' && $selected > 0;

        return $this->view->render('media.twig', [
            'page_title' => __('媒体'),
            'eyebrow' => __('内容'),
            'heading' => __('媒体'),
            'lede' => __('图片、文档与音视频都收在这里。上传即生成多档尺寸，随用随取。'),
            'upload_url' => $this->url('media'),
            'view' => $view,
            'active_tab' => $tab,
            'per' => (string) $perPage,
            'tabs' => $this->tabs($tab, $tally, $kept, $view),
            // 切视图带着选中项走:列表视图的详情是行下展开块,没有 sel 就展开不出来
            'views' => [
                ['label' => __('网格'), 'url' => $this->selectUrl($selected, 'grid', $query, $page), 'on' => $view === 'grid'],
                ['label' => __('列表'), 'url' => $this->selectUrl($selected, 'list', $query, $page), 'on' => $view === 'list'],
            ],
            'filters' => [
                'action' => $this->url('media'),
                'q' => $filters['q'],
                'month' => $filters['month'],
                'order' => $order,
                'months' => $this->monthChoices(),
                'orders' => array_map(static fn (string $label): string => __($label), self::ORDERS),
            ],
            'stats' => ['count' => (int) $tally[$tab]['n'], 'used' => self::bytes((int) $tally[$tab]['bytes'])],
            'items' => array_map(fn (array $item): array => $this->card($item, (int) $item['id'] === $selected, $user, $view, $query, $page), $items),
            'queue' => $this->rowsWithUrl($rows, $view, $query),
            'pagination' => $this->pagination($page, $list['total'], $perPage, $query, $view),
            'detail' => $selected === 0 ? null : $this->detail($selected, $user, $view, $query, $page),
            'confirm' => $dialog ? $this->confirmOf($selected, $mode, $view, $query, $page) : null,
            'dialog_open' => $dialog,
            // 无 JS 保存走整页提交,答谢句由服务端带上
            'edited' => $request->input('done') === 'edited',
        ], $menu, $status);
    }

    /**
     * 上传。一次可以来多个文件:收下的直接进网格,
     * 拒下的排成队列行,原因用那份钉死的文案表。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    private function upload(Request $request, User $user, array $menu): Response
    {
        if (!$this->can->can($user, 'media.upload')) {
            return $this->forbidden($menu, ['media.upload'], __('上传文件'));
        }

        if (!$this->tokenOk($request)) {
            return $this->page($request, $user, $menu, [self::staleRow()], 403);
        }

        $rows = [];

        foreach ($request->uploads('files') as $file) {
            try {
                $this->uploader->upload(Upload::fromRequest($file), $user->id);
            } catch (ValidationException $e) {
                $rows[] = [
                    'name' => (string) $file['name'],
                    'status' => __('✗ 已拒绝'),
                    'why' => $this->rejection($e),
                    'remove' => __('移除'),
                ];
            } catch (Throwable $e) {
                // 过了校验却没能落盘(目录不可写、磁盘满……):说清失败,别甩半张错误页
                $rows[] = [
                    'name' => (string) $file['name'],
                    'status' => __('✗ 没存上'),
                    'why' => $e->getMessage(),
                    'remove' => __('移除'),
                ];
            }
        }

        // 拖拽与多选走 XHR:每个文件一个请求,收下就回空 body(前端自己收行),拒下就回那一行 HTML(§10.2)
        if ($request->input('xhr') === '1') {
            if ($rows === []) {
                return Response::html('');
            }

            $rows[0]['url'] = $this->url('media');

            return Response::html($this->view->fragment('media-queue-row.twig', ['row' => $rows[0]]), 422);
        }

        if ($rows === []) {
            return Response::redirect($this->url('media'));
        }

        return $this->page($request, $user, $menu, $rows, 422);
    }

    /**
     * 改 alt / 标题 / 说明。
     *
     * 既有规范的 19 项能力里没有 media.edit,所以门槛用"能上传"——
     * 但只能改自己传的那些;别人的件要有 setting.manage(管理员)才动得了。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    private function edit(Request $request, User $user, array $menu, int $id): Response
    {
        $record = $this->media->find($id);

        if ($record === null) {
            throw new NotFoundException('没有这条媒体:' . $id);
        }

        $mine = (int) $record['uploaded_by'] === $user->id;

        // 作者 2026-10-01 定:改文字是"自己改自己的"。既有规范没有 media.edit 这项能力,
        // 也不增设 —— 管理员要动别人的附件,走内容层,不在媒体库开第二条口子。
        if (!$mine || !$this->can->can($user, 'media.upload')) {
            return $this->forbidden($menu, ['media.upload'], __('改这条媒体的文字'));
        }

        if (!$this->tokenOk($request)) {
            return Response::html(__(WebAuth::STALE), 403);
        }

        $this->media->updateText($id, [
            'alt' => $request->input('alt'),
            'title' => $request->input('title'),
            'caption' => $request->input('caption'),
        ]);
        Audit::record('media.update', 'media:' . $id, true, $request, 'user:' . $user->id);

        if ($request->input('xhr') === '1') {
            return Response::html('');
        }

        // 无 JS 的整页提交:回到提交前那一屏(视图/页签/筛选都在),并让「已保存」露出来
        return Response::redirect($this->landOn($request) . '&done=edited');
    }

    /**
     * 移到回收站(既有规范软删,不物理删文件)。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    private function trash(Request $request, User $user, array $menu, int $id): Response
    {
        $record = $this->media->find($id);

        if ($record === null) {
            throw new NotFoundException('没有这条媒体:' . $id);
        }

        // 自己的件要 media.delete.own(带归属判定),别人的件要 media.delete.others
        if (!$this->canTrash($user, (int) $record['uploaded_by'])) {
            return $this->forbidden($menu, ['media.delete.own', 'media.delete.others'], __('移到回收站'));
        }

        if (!$this->tokenOk($request)) {
            return $this->page($request, $user, $menu, [self::staleRow()], 403);
        }

        $this->remover->trash($id, $user->id);

        return Response::redirect($this->url('media'));
    }

    /**
     * 从"待清理"里捞回来(软删的意义就是还能反悔)。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    private function restore(Request $request, User $user, array $menu, int $id): Response
    {
        $record = $this->media->find($id);

        if ($record === null) {
            throw new NotFoundException('没有这条媒体:' . $id);
        }

        if (!$this->canTrash($user, (int) $record['uploaded_by'])) {
            return $this->forbidden($menu, ['media.delete.own', 'media.delete.others'], __('还原这条媒体'));
        }

        if (!$this->tokenOk($request)) {
            return $this->page($request, $user, $menu, [self::staleRow()], 403);
        }

        $this->remover->restore($id, $user->id);

        return Response::redirect($this->url('media?tab=removed'));
    }

    /**
     * 彻底删除(手动执行清理任务那一刀)。被引用时不动手,理由的队列行讲清。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    private function destroy(Request $request, User $user, array $menu, int $id): Response
    {
        $record = $this->media->find($id);

        if ($record === null) {
            throw new NotFoundException('没有这条媒体:' . $id);
        }

        if (!$this->canTrash($user, (int) $record['uploaded_by'])) {
            return $this->forbidden($menu, ['media.delete.own', 'media.delete.others'], __('彻底删除'));
        }

        if (!$this->tokenOk($request)) {
            return $this->page($request, $user, $menu, [self::staleRow()], 403);
        }

        $done = $this->remover->destroy($id, $user->id);

        if (!$done['done']) {
            return $this->page($request, $user, $menu, [[
                'name' => (string) $record['file_name'],
                'status' => __('✗ 没删'),
                'why' => $done['why'],
                'remove' => __('移除'),
            ]], 422);
        }

        return Response::redirect($this->url('media?tab=removed'));
    }

    /**
     * 卡片+ 列表行的那几列(第 10.1 节)。
     * 缩略图有真文件就出真图,没有就留灰描边线稿 —— 线稿在模板里,不在这里。
     *
     * @param array<string, mixed> $item
     * @param array<string, string> $query 现屏的筛选,选中链接要原样带着走
     * @return array<string, mixed>
     */
    private function card(array $item, bool $selected, User $user, string $view, array $query, int $page): array
    {
        $refs = (int) ($item['ref_count'] ?? 0);
        $url = $this->store->url((string) $item['file_path']);

        return [
            'id' => (int) $item['id'],
            'url' => $this->selectUrl((int) $item['id'], $view, $query, $page),
            // 无刷新换栏的端点地址:带上现屏上下文,取回的详情片段里那些跳转才回得来
            'detail_url' => $this->url('media/' . (int) $item['id'] . '/detail?' . http_build_query($this->backParams($view, $query, $page))),
            'selected' => $selected,
            'kind' => self::extensionOf((string) $item['file_name']),
            'art' => self::artOf((string) $item['mime_type']),
            'thumb' => $this->bestUrl($item),
            'name' => (string) $item['file_name'],
            'title' => (string) $item['title'],
            'dims' => self::dims($item),
            'size_text' => self::bytes((int) $item['file_size']),
            'unreferenced' => $refs === 0,
            'removed' => ($item['removed'] ?? false) === true,
            'category' => self::categoryOf((string) $item['mime_type']),
            'date' => substr((string) $item['created_at'], 0, 10),
            'refs_text' => $refs === 0 ? __('未引用') : __('%s 处', 'molin', $refs),
            'download_url' => $url,
            'trash_url' => $this->selectUrl((int) $item['id'], $view, $query, $page, ['confirm' => 'trash']),
            'can_trash' => $this->canTrash($user, (int) $item['uploaded_by']),
        ];
    }

    /**
     * 附件详情栏;列表视图的行下展开块(第 10.1 节)用的是同一份数据。
     *
     * @param array<string, string> $query
     * @return array<string, mixed>|null
     */
    private function detail(int $id, User $user, string $view, array $query, int $page): ?array
    {
        $record = $this->media->find($id);

        if ($record === null) {
            return null;
        }

        $url = $this->store->url((string) $record['file_path']);
        $refs = $this->refsOf($id);

        return [
            'id' => $id,
            'name' => (string) $record['file_name'],
            'kind' => self::extensionOf((string) $record['file_name']),
            'art' => self::artOf((string) $record['mime_type']),
            'preview' => $this->bestUrl($record),
            'dims' => $record['width'] === null ? '—' : $record['width'] . ' × ' . $record['height'],
            'size_text' => self::bytes((int) $record['file_size']),
            'mime' => (string) $record['mime_type'],
            'uploaded' => substr((string) $record['created_at'], 0, 16),
            'url' => $url,
            'alt' => (string) $record['alt'],
            'caption' => (string) $record['caption'],
            'title_text' => (string) $record['title'],
            'edit_url' => $this->url('media/' . $id . '/edit?' . http_build_query(['sel' => $id] + $this->backParams($view, $query, $page))),
            'refs' => $refs,
            'download_url' => $url,
            'trash_url' => $this->selectUrl($id, $view, $query, $page, ['confirm' => 'trash']),
            'restore_url' => $this->url('media/' . $id . '/restore'),
            'destroy_url' => $this->selectUrl($id, $view, $query, $page, ['confirm' => 'destroy']),
            'removed' => $record['removed'] === true,
            'can_trash' => $this->canTrash($user, (int) $record['uploaded_by']),
        ];
    }

    /**
     * 二次确认对话框的数据(既有规范品牌规格「模态件」)。
     * 两种确认共用同一个模态件,只是标题、正文与主按钮不同。
     *
     * @param array<string, string> $query
     * @return array<string, mixed>|null
     */
    private function confirmOf(int $id, string $mode, string $view, array $query, int $page): ?array
    {
        $record = $this->media->find($id);

        if ($record === null) {
            return null;
        }

        $refs = $this->refsOf($id);

        return [
            'mode' => $mode,
            'title' => (string) $record['title'],
            'name' => (string) $record['file_name'],
            'is_image' => str_starts_with((string) $record['mime_type'], 'image/'),
            'refs' => $refs,
            'count_text' => $refs['count'] === 2 ? __('这两处') : __('这 %s 处', 'molin', $refs['count']),
            'cancel_url' => $this->selectUrl($id, $view, $query, $page),
            'action_url' => $this->url('media/' . $id . '/' . ($mode === 'destroy' ? 'destroy' : 'trash')),
            'sel' => $id,
        ];
    }

    /**
     * 「被 N 处引用」+ 那几条链接。
     * 内容与头像两路都算:头像那路指向账号编辑页,否则计数里有它、列表里没它,对不上。
     *
     * @return array{count:int, list:list<array{title:string, url:string}>}
     */
    private function refsOf(int $id): array
    {
        $count = $this->media->references($id);
        $list = [];

        foreach ($this->media->referencedBy($id) as $row) {
            $post = $this->posts->find((int) $row['id'], true);

            if ($post === null) {
                continue;
            }

            $list[] = ['title' => (string) $row['title'], 'url' => $post->url];
        }

        foreach ($this->media->avatarUsers($id) as $row) {
            $list[] = ['title' => $row['title'], 'url' => $this->url('users/' . $row['id'])];
        }

        return ['count' => $count, 'list' => $list];
    }

    /**
     * 无刷新换栏:点一张图只回右栏那一块 HTML,前端就地替换、改地址,不整页跳。
     * 走已有 `admin` 路由的 path 分支(`media/{id}/detail`),CSRF 与页面能力都再校验一次。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    private function detailFragment(Request $request, User $user, array $menu, int $id): Response
    {
        if (!$this->can->canAny($user, ['media.upload', 'media.delete.own'])) {
            return $this->forbidden($menu, ['media.upload', 'media.delete.own'], __('查看附件详情'));
        }

        if (!$this->tokenOk($request)) {
            return Response::html(__(WebAuth::STALE), 403);
        }

        $view = $request->input('view') === 'list' ? 'list' : 'grid';
        $page = max(1, (int) $request->input('page', '1'));
        $query = $this->contextQuery($request);
        $detail = $this->detail($id, $user, $view, $query, $page);

        if ($detail === null) {
            throw new NotFoundException('没有这条媒体:' . $id);
        }

        return Response::html($this->view->fragment('media.twig', [
            'fragment' => true,
            'view' => $view,
            'detail' => $detail,
            'csrf_field' => Csrf::FIELD,
            'csrf_token' => Auth::csrfToken(),
        ]));
    }

    /**
     * 现屏的筛选与每页数:选中、切视图、翻页、换栏的地址都要原样带着走,免得点一下就被甩回默认页。
     *
     * @return array<string, string>
     */
    private function contextQuery(Request $request): array
    {
        $tab = isset(self::TABS[$request->input('tab')]) ? $request->input('tab') : 'all';
        $order = $request->input('order');
        $order = isset(self::ORDERS[$order]) ? $order : '';
        $per = $this->perOf($request);

        return array_filter([
            'tab' => $tab,
            'month' => $request->input('month'),
            'q' => $request->input('q'),
            'order' => $order,
            'per' => $per > 0 ? (string) $per : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * 前端按视口给的每页张数。没给就回 0,交给各自的默认值;给了就夹在上限内。
     */
    private function perOf(Request $request): int
    {
        $per = (int) $request->input('per', '0');

        return $per >= 1 ? min($per, self::PER_MAX) : 0;
    }

    /**
     * @return list<array{value:string, label:string}>
     */
    private function monthChoices(): array
    {
        $choices = [['value' => '', 'label' => __('全部时间')]];

        foreach ($this->media->months() as $ym) {
            $choices[] = ['value' => $ym, 'label' => format_date($ym . '-01', 'month')];
        }

        return $choices;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, array{n:int, bytes:int}> $tally
     * @return list<array{label:string, url:string, active:bool, n:int}>
     */
    private function tabs(string $tab, array $tally, array $query, string $view): array
    {
        $tabs = [];

        foreach (self::TABS as $value => $label) {
            $tabs[] = [
                'label' => __($label),
                'url' => $this->url('media?view=' . $view . '&tab=' . $value . '&' . http_build_query(array_diff_key($query, ['tab' => 1]))),
                'active' => $tab === $value,
                'n' => (int) $tally[$value]['n'],
            ];
        }

        return $tabs;
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function pagination(int $page, int $total, int $perPage, array $query, string $view): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $base = $this->url('media?view=' . $view . '&' . http_build_query($query));
        $links = [];

        for ($number = max(1, $page - 2); $number <= min($pages, $page + 2); $number++) {
            $links[] = ['page' => $number, 'url' => $base . '&page=' . $number, 'active' => $number === $page];
        }

        return [
            'page' => $page,
            // 不足一页就不出分页
            'show' => $pages > 1,
            'info' => __('第 %s–%s 项，共 %s 项', 'molin', ($page - 1) * $perPage + 1, min($total, $page * $perPage), $total),
            'prev' => ['url' => $base . '&page=' . max(1, $page - 1), 'label' => '上一页'],
            'next' => $page < $pages ? ['url' => $base . '&page=' . ($page + 1), 'label' => '下一页'] : null,
            'links' => $links,
            'gap' => $page + 2 < $pages,
        ];
    }

    /**
     * 卡片与详情预览的图源。**只有图片才配 `<img>`** —— 文档/音视频拿文件地址当图源,
     * 浏览器载不动就留一块纯白;它们该走模板里的灰描边线稿。图片里优先小档,
     * 没有就退回原图;盘上没有这个文件同样回空串,不画白块。
     *
     * @param array<string, mixed> $item
     */
    private function bestUrl(array $item): string
    {
        if (!str_starts_with((string) ($item['mime_type'] ?? ''), 'image/')) {
            return '';
        }

        $sizes = (array) ($item['sizes'] ?? []);

        foreach (['thumb', 'medium', 'large', 'xlarge'] as $name) {
            $file = (string) ($sizes[$name]['file'] ?? '');

            if ($file !== '' && $this->store->exists($file)) {
                return $this->store->url($file);
            }
        }

        $path = (string) ($item['file_path'] ?? '');

        return $path === '' || !$this->store->exists($path) ? '' : $this->store->url($path);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function dims(array $item): string
    {
        if ($item['width'] === null || $item['height'] === null) {
            return '';
        }

        return $item['width'] . '×' . $item['height'];
    }

    /**
     * 既有规范的四类摊成既有规范的三个页签名。
     */
    private static function categoryOf(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => __('图片'),
            str_starts_with($mime, 'video/'), str_starts_with($mime, 'audio/') => __('音视频'),
            default => __('文档'),
        };
    }

    /**
     * 线稿选哪一张(图片/影像/音频/文档四种灰描边示意,不用彩图也不用色块)。
     */
    private static function artOf(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'doc',
        };
    }

    private static function extensionOf(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false ? '' : strtoupper(substr($name, $dot + 1));
    }

    /**
     * 与设计稿同一口径:KB 不带小数,MB 留一位(既有规范/4.5 节)。
     */
    private static function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return (int) round($bytes / 1024) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * 选中的那条:`?sel=` 认就跟着认,不认(没给、已删、不在这一页)就用本页第一条。
     *
     * @param list<array<string, mixed>> $items
     */
    private function selected(Request $request, array $items): int
    {
        $want = max(0, (int) $request->input('sel', '0'));

        foreach ($items as $item) {
            if ((int) $item['id'] === $want) {
                return $want;
            }
        }

        if ($want > 0 && $this->media->find($want) !== null) {
            return $want;
        }

        return (int) ($items[0]['id'] ?? 0);
    }

    /**
     * @param ValidationException $e
     */
    private function rejection(ValidationException $e): string
    {
        if ($e->rule() === 'too_large') {
            return __('超过上限 %s MB，已拒绝', 'molin', $this->maxUploadMb());
        }

        return array_key_exists($e->rule(), self::REJECTIONS) ? __(self::REJECTIONS[$e->rule()]) : $e->getMessage();
    }

    /**
     * 单文件上限(既有规范读 `max_upload_mb`,没配就按策略默认的 10MB)。
     */
    private function maxUploadMb(): int
    {
        $mb = (int) $this->options->get('max_upload_mb', (string) (int) round(Upload::MAX_BYTES / 1048576));

        return $mb > 0 ? $mb : (int) round(Upload::MAX_BYTES / 1048576);
    }

    /**
     * 令牌过期:也排一行,别让人以为点了没反应。
     *
     * @return array<string, string>
     */
    private static function staleRow(): array
    {
        return ['name' => __('上传未提交'), 'status' => __('✗ 没提交'), 'why' => __(WebAuth::STALE), 'remove' => __('移除')];
    }

    /**
     * 「移除」那一钮要把人带回没有队列的这一页(移除只清队列项,
     * 被拒的文件本来就没入库,没有"半成品"可清)。
     *
     * @param list<array<string, string>> $rows
     * @param array<string, string> $query
     * @return list<array<string, string>>
     */
    private function rowsWithUrl(array $rows, string $view, array $query): array
    {
        $url = $this->url('media?view=' . $view . '&' . http_build_query($query));

        return array_map(static fn (array $row): array => $row + ['url' => $url], $rows);
    }

    /**
     * 这一屏的上下文参数:视图 / 筛选 / 页码(第 1 页不写,地址不拖泥带水)。
     *
     * @param array<string, string> $query
     * @return array<string, string|int>
     */
    private function backParams(string $view, array $query, int $page): array
    {
        return ['view' => $view] + $query + ($page > 1 ? ['page' => $page] : []);
    }

    /**
     * 选中一条的地址;`extra` 捎上确认态。`sel` 排最前,后面全是"回得来"的上下文 ——
     * 卡片、切视图、二次确认共用这一处拼法,免得点一下就被甩回默认页。
     *
     * @param array<string, string> $query
     * @param array<string, string> $extra
     */
    private function selectUrl(int $id, string $view, array $query, int $page, array $extra = []): string
    {
        $params = $this->backParams($view, $query, $page) + $extra;

        return $this->url('media?' . ($id > 0 ? 'sel=' . $id . '&' : '') . http_build_query($params));
    }

    /**
     * 表单里捎来的上下文(视图 / 页签 / 筛选 / 页码):无 JS 交表后回到原处用。
     */
    private function landOn(Request $request): string
    {
        $tab = $request->input('tab');
        $order = $request->input('order');
        $per = $this->perOf($request);
        $query = array_filter([
            'tab' => isset(self::TABS[$tab]) ? $tab : 'all',
            'month' => $request->input('month'),
            'q' => $request->input('q'),
            'order' => isset(self::ORDERS[$order]) ? $order : '',
            'per' => $per > 0 ? (string) $per : '',
        ], static fn (string $value): bool => $value !== '');

        return $this->selectUrl(
            (int) $request->input('sel', '0'),
            $request->input('view') === 'list' ? 'list' : 'grid',
            $query,
            max(1, (int) $request->input('page', '1'))
        );
    }

    private function url(string $sub): string
    {
        $position = strpos($sub, '?');

        return $this->view->path('admin', ['path' => $position === false ? $sub : substr($sub, 0, $position)])
            . ($position === false ? '' : substr($sub, $position));
    }

    /**
     * 能不能把这条附件移进回收站:
     * 自己的件走 `media.delete.own` + 归属判定,别人的件要 `media.delete.others`(管理员与编辑)。
     * 改文字没这么宽 —— 那里只有"自己改自己的"。
     */
    private function canTrash(User $user, int $uploadedBy): bool
    {
        return $this->can->can($user, 'media.delete.others')
            || $this->can->can($user, 'media.delete.own', (object) ['author_id' => $uploadedBy]);
    }
}
