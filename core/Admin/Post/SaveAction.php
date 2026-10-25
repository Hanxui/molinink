<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Post;

use Molin\Content\StatusMachine;
use Molin\Content\TermAssigner;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Option\Options;
use Molin\User\User;

/**
 * 保存动作:整页保存、按需 auto-draft、自定义字段配对。
 */
final class SaveAction extends EditorScreen
{
    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function save(Request $request, User $user, array $menu, ?int $id): Response
    {
        $post = $id === null ? null : $this->mustFind($id);

        if ($post === null ? !$this->can->can($user, $this->cap('create')) : !$this->canEdit($user, $post)) {
            return $this->forbidden(
                $menu,
                $post === null ? [$this->cap('create')] : [$this->cap('edit.own'), $this->cap('edit.others')],
                $post === null
                    ? ($this->isCpt() ? __('写新') . $this->screenSingular() : __('写新文章'))
                    : ($this->isCpt() ? __('编辑') . $this->screenSingular() : __('编辑这篇文章'))
            );
        }

        if (!$this->tokenOk($request)) {
            return $this->stale($request, $user, $menu, $post);
        }

        // 新建的编辑器里第一次传图 / 第一次进媒体库选图,前端会先来这儿静默建一篇草稿拿 id
        //(#13 的按需 auto-draft)。只在"新建"这条路认这个标记,已有 id 的照常往下保存。
        if ($id === null && $request->input('autodraft') === '1') {
            return $this->autoDraft($request, $user);
        }

        $wanted = $request->input('status', StatusMachine::DRAFT);
        $from = $post === null ? StatusMachine::DRAFT : $post->status;
        $autosave = $request->input('autosave') === '1';

        // 密码保护的关键值:null = 不改动口令;'' = 解除;非空 = 换新哈希。autosave 时保持 null
        $passwordField = null;

        // 新建那篇还没有 id:自动保存一旦允许,每 30s 就多一篇孤儿草稿。先按下不表(前端也不会发这请求)
        if ($autosave && $post === null) {
            return Response::html('');
        }

        // 自动保存只存稿子:状态与可见性由人自己按钮定,不让一次后台落库偷偷改口径
        if ($autosave) {
            $wanted = $from;
        } else {
            // 底部/右栏那两个钮才是意图,状态下拉只是它的投影(《79》§4.7 第 1 盒)
            $intent = $request->input('intent');

            if ($intent === 'draft') {
                $wanted = StatusMachine::DRAFT;
            } elseif ($intent === 'publish') {
                $wanted = StatusMachine::PUBLISH;
            }

            // 可见性那一格选「私密」= 状态走 private(《34》§5)
            if ($request->input('visibility') === StatusMachine::PRIVATE) {
                $wanted = StatusMachine::PRIVATE;
            }

            // 密码保护(P0-2):口令由 PostWriter 密封成哈希落库。编辑已保护文章时空着口令
            // = 保留原口令(不传 password 键即可);新建或从其他可见性切来时,没填口令就拦下来说清。
            if ($request->input('visibility') === 'password') {
                $plain = trim($request->input('password'));

                if ($plain === '') {
                    $already = $id !== null && $this->posts->requiresPassword($id);

                    if (!$already) {
                        return $this->editor($request, $user, $menu, $post, __('密码保护需要先设置一个访问口令。'));
                    }
                } else {
                    $passwordField = $plain;
                }
            } elseif ($id !== null) {
                // 从密码保护切回公开/私密 = 解除保护,把原口令清掉
                $passwordField = '';
            }
        }

        if (!StatusMachine::isKnown($wanted)) {
            return $this->editor($request, $user, $menu, $post, __('没有这个状态:') . $wanted);
        }

        if ($post !== null && !StatusMachine::canGo($from, $wanted)) {
            return $this->editor($request, $user, $menu, $post, __('状态不能从「%s」直接改成「%s」', 'molin', self::LABELS[$from] ?? $from, self::LABELS[$wanted] ?? $wanted));
        }

        $note = '';

        // 《32》第 3 节:contributor 有自己的内容但没有 post.publish —— 提交的就是待审,不是发布
        if (($wanted === StatusMachine::PUBLISH || $wanted === StatusMachine::PRIVATE) && !$this->can->can($user, $this->cap('publish'))) {
            $wanted = StatusMachine::PENDING;
            $note = __('你没有发布权限,这篇已提交待审。');
        }

        // 分类勾选:有 id 的照挂;前台现敲的新名字带 «new:» 头,先建后用(《79》§4.7 第 2 盒)
        $categories = [];

        foreach ((array) ($request->inputs()['categories'] ?? []) as $value) {
            $value = (string) $value;

            if (str_starts_with($value, 'new:')) {
                $name = trim(substr($value, 4));

                if ($name !== '') {
                    $categories[] = $this->terms->findOrCreate(TermAssigner::CATEGORY, $name);
                }
            } elseif (is_numeric($value)) {
                $categories[] = (int) $value;
            }
        }

        // 没有 JS 时退回那格输入框:回车交表走这条路,一并按新分类建
        foreach ($this->names(implode(',', (array) ($request->inputs()['new_category'] ?? []))) as $name) {
            $categories[] = $this->terms->findOrCreate(TermAssigner::CATEGORY, $name);
        }

        // 新建文章且没勾任何分类:按「默认分类」设置兜底(《76》§12 常规组)。页面归父页,不受此设影响
        if ($this->managedType === 'post' && $post === null && $categories === []) {
            $default = (int) (new Options($this->db))->get('default_category', '0');

            if ($default > 0) {
                $categories[] = $default;
            }
        }

        $isCpt = $this->isCpt();

        $data = [
            'type' => $this->managedType,
            'status' => $wanted,
            'title' => $request->input('title'),
            'slug' => $request->input('slug'),
            'excerpt' => $request->input('excerpt'),
            'content' => $request->input('content'),
            'published_at' => $request->input('published_at'),
            // 讨论开关:内置文章两型照旧;CPT 按 supports 走,不支持就保持原值(《34》§2.2)
            'comment_status' => $this->commentStatusFor($request, $post),
            'thumbnail_id' => max(0, (int) $request->input('thumbnail_id', (string) ($post === null ? 0 : $post->thumbnail_id))),
            'meta' => $this->pairs($request),
        ];

        // 内置分类法(category / post_tag)只对文章屏有意义;CPT 的分类法在 writer 之后按登记表另挂
        if (!$isCpt) {
            $data['categories'] = $categories;
            $data['tags'] = $this->names($request->input('tags'));
        }

        // null = 这次没动口令(保留原值);'' = 清除;非空 = 换新哈希(PostWriter 密封)
        if ($passwordField !== null) {
            $data['password'] = $passwordField;
        }

        // 层级型(页面与 hierarchical 的 CPT)写父级;「序号」只页面有(前台导航与子页列表按它排)
        if ($this->isHierarchical()) {
            $parentId = max(0, (int) $request->input('parent_id', '0'));

            if ($parentId > 0 && !in_array($parentId, array_column($this->parentChoicesFor($id), 'id'), true)) {
                return $this->editor(
                    $request,
                    $user,
                    $menu,
                    $post,
                    $this->managedType === 'page'
                        ? __('「父页面」不能选自己、自己的子页,或回收站里的页面。')
                        : __('「父级」不能选自己、自己的子项,或回收站里的内容。')
                );
            }

            $data['parent_id'] = $parentId;
        }

        // 「序号」层级型内容都有(页面天然是;hierarchical 的 CPT 也靠它排目录,与《34》§2.2
        // page-attributes 同口径);脏输入在这里收成 0(界面写着 min 0)
        if ($this->isHierarchical()) {
            $data['menu_order'] = max(0, (int) $request->input('menu_order', '0'));
        }

        try {
            $saved = $this->writer->save($data, $user->id, $id);
        } catch (ValidationException $e) {
            return $this->editor($request, $user, $menu, $post, $this->why($e));
        }

        // CPT 声明的分类法:PostWriter 只认内置两件,这里按登记表逐条全量替换
        if ($isCpt) {
            $this->assignTypeTaxonomies($saved->id, $request);
        }

        // 页面模板(《114》):表单选中的模板过当前主题白名单,命中才写 meta;脏输入 / 空值 / 路径穿越
        // 一律按「默认模板」清掉,不报错中断保存。放在 writer->save 之后才拿得到新建页的真实 id。
        if ($this->managedType === 'page') {
            $resolved = $this->theme?->resolvePageTemplate($request->input('page_template'));

            if ($resolved === null) {
                $this->meta->delete($saved->id, 'page_template');
            } else {
                $this->meta->set($saved->id, 'page_template', $resolved);
            }
        }

        if ($autosave) {
            // 自动保存只要一条带子的文案,别把人整页刷走(《41》§6.2 每 30s + 《79》§10.3)
            return Response::html(__('已于 %s 自动保存 · 修订 %s 份', 'molin', substr($saved->updated_at, 11, 5), count($this->writer->revisions($saved->id))));
        }

        Audit::record(
            $id === null ? 'post.create' : 'post.update',
            'post:' . $saved->id . ' status:' . $saved->status,
            true,
            $request,
            'user:' . $user->id
        );

        // 「发布」是句号:存完就回列表看结果(带一条成功提示)。「保存草稿」留在这页接着改。
        // 没有发布权的人提交 publish 会被降到待审(见上),那就不算发布成功,照旧走"已保存"。
        if ($request->input('intent') === 'publish') {
            $done = $saved->status === StatusMachine::PUBLISH ? 'published' : 'saved';

            return Response::redirect($this->url('posts') . '?done=' . $done . ($note === '' ? '' : '&note=pending'));
        }

        return Response::redirect($this->url('posts/' . $saved->id . '/edit') . '?done=saved' . ($note === '' ? '' : '&note=pending'));
    }

    /**
     * 按需 auto-draft(#13):新建的编辑器第一次传图 / 第一次选图时,先静默落一篇草稿拿 id
     * —— 图得挂在真实 post 上(媒体库的件也要 post_id)。以后这个 id 就当作这篇的 id 用,
     * 前端会把表单的 action / id 一并换到 "posts/{id}/edit"。
     *
     * 只建草稿、不碰状态机:状态给 draft,列表照旧只在「草稿」栏看得到。
     * 标题与正文不能同时为空(PostWriter 的底):两个都空就给标题填个占位,否则空草稿建不出来。
     * 正文按当前编辑器里的稿子存,免得去媒体库那一趟把已经写的字冲掉。
     */
    private function autoDraft(Request $request, User $user): Response
    {
        $title = trim($request->input('title'));
        $content = $request->input('content');

        if ($title === '' && trim(strip_tags($content)) === '') {
            $title = __('(无标题)');
        }

        try {
            $draft = $this->writer->save([
                'type' => $this->managedType,
                'status' => StatusMachine::DRAFT,
                'title' => $title,
                'content' => $content,
            ], $user->id, null);
        } catch (ValidationException $e) {
            return Response::json(['ok' => false, 'error' => $this->why($e)], 422);
        }

        Audit::record('post.create', 'post:' . $draft->id . ' status:draft', true, $request, 'user:' . $user->id);

        return Response::json([
            'ok' => true,
            'id' => $draft->id,
            'edit_url' => $this->url('posts/' . $draft->id . '/edit'),
            'upload_url' => $this->url('posts/' . $draft->id . '/image-upload'),
            'pick_url' => $this->url('posts/' . $draft->id . '/edit?pick=1'),
            'body_pick_url' => $this->url('posts/' . $draft->id . '/edit?pick=body'),
        ]);
    }

    /**
     * 自定义字段那几行:键与值成对提交,空键丢掉(《79》§4.7 第 6 盒)。
     *
     * @return array<string, string>
     */
    private function pairs(Request $request): array
    {
        $keys = (array) ($request->inputs()['meta_key'] ?? []);
        $values = (array) ($request->inputs()['meta_value'] ?? []);
        $pairs = [];

        foreach (array_keys($keys) as $index) {
            $key = trim((string) ($keys[$index] ?? ''));

            if ($key === '') {
                continue;
            }

            $pairs[$key] = (string) ($values[$index] ?? '');
        }

        return $pairs;
    }
}
