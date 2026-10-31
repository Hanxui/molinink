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
use Molin\Content\FieldRegistry;
use Molin\Content\HtmlSanitizer;
use Molin\Content\MetaStore;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\PostTypeRegistry;
use Molin\Content\PostWriter;
use Molin\Content\StatusMachine;
use Molin\Content\TaxonomyRegistry;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Theme\Theme;
use Molin\User\Authorizer;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 编辑屏:正文表单、右栏各盒、分类法盒与自定义字段归一,以及媒体选择器。
 */
class EditorScreen extends ListScreen
{
    /** 特色图片选择器一页六张:桌面两行三列正好铺满对话框 */
    private const PICKER_PER_PAGE = 6;

    /** 既有规范/ 控件词表(14 种);词表外的名字一律回落 text,不自造控件 */
    private const CONTROLS = [
        'text', 'textarea', 'bool', 'select', 'radio', 'color', 'range',
        'number', 'image', 'file', 'url', 'code', 'group', 'repeater',
    ];

    public function __construct(
        View $view,
        PostRepository $posts,
        UserRepository $users,
        TermAssigner $terms,
        Authorizer $can,
        Database $db,
        protected readonly PostWriter $writer,
        protected readonly MetaStore $meta,
        protected readonly MediaRepository $media,
        protected readonly Store $store,
        string $managedType,
        protected readonly ?Theme $theme
    ) {
        parent::__construct($view, $posts, $users, $terms, $can, $db, $managedType);
    }

    /**
     * 门面按地址进编辑屏的入口:取哪一篇、带哪条提示都是编辑屏自己的事,门面只认地址。
     *
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    public function show(Request $request, User $user, array $menu, ?int $id): Response
    {
        return $this->editor($request, $user, $menu, $id === null ? null : $this->mustFind($id), $this->flash($request));
    }

    /**
     * 编辑页「父页面」盒的候选(排除自己与后代,防成环)。
     * 回收站里的页同样不做候选 —— 挂过去就成了列表里找不回的子页。
     *
     * @return list<array{id:int, title:string}>
     */
    protected function parentChoicesFor(?int $selfId): array
    {
        $rows = $this->db->select(
            'SELECT id, parent_id, title FROM ' . $this->db->table('posts')
            . " WHERE type = :type AND status <> 'trash' AND deleted_at IS NULL ORDER BY id ASC",
            ['type' => $this->managedType]
        );

        $parentOf = [];
        $titleOf = [];

        foreach ($rows as $row) {
            $parentOf[(int) $row['id']] = (int) $row['parent_id'];
            $titleOf[(int) $row['id']] = (string) $row['title'];
        }

        $blocked = [];

        if ($selfId !== null && isset($titleOf[$selfId])) {
            $blocked[$selfId] = true;

            do {
                $grew = false;

                foreach ($parentOf as $id => $parentId) {
                    if (!isset($blocked[$id]) && isset($blocked[$parentId])) {
                        $blocked[$id] = true;
                        $grew = true;
                    }
                }
            } while ($grew);
        }

        $choices = [];

        foreach ($titleOf as $id => $title) {
            if (!isset($blocked[$id])) {
                $choices[] = ['id' => $id, 'title' => $title];
            }
        }

        return $choices;
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    protected function editor(Request $request, User $user, array $menu, ?Post $post, string $notice): Response
    {
        $isNew = $post === null;
        $isPage = $this->managedType === 'page';
        // CPT:内置 post / page 各有自己的模板形态,CPT 照登记表声明的 supports 与 taxonomies 现搭
        $isCpt = $this->isCpt();
        $hierarchical = $this->isHierarchical();
        $singular = $this->screenSingular();
        $newDoing = $isCpt ? __('写新') . $singular : __('写新文章');
        $editDoing = $isCpt ? __('编辑') . $singular : __('编辑这篇文章');

        if ($isNew && !$this->can->can($user, $this->cap('create'))) {
            return $this->forbidden($menu, [$this->cap('create')], $newDoing);
        }

        if (!$isNew && !$this->can->can($user, $this->cap('edit.others')) && !$this->can->can($user, $this->cap('edit.own'), $post)) {
            return $this->forbidden($menu, [$this->cap('edit.own'), $this->cap('edit.others')], $editDoing);
        }

        $canPublish = $this->can->can($user, $this->cap('publish'));
        // 分类盒里那颗「×」是删整个分类(全局动作),门控走 category.manage ——
        // 与既有规范管理屏同口径:能改分类的人才看得见删的入口
        $canManageCategories = $this->can->can($user, 'category.manage');
        // 新建默认「发布」(有发布权的人本来就是来发文章的);没有发布权的落到草稿,免得白填一页再被打回
        $current = $post === null ? ($canPublish ? StatusMachine::PUBLISH : StatusMachine::DRAFT) : $post->status;

        $statuses = [];

        foreach (StatusMachine::CREATABLE as $value) {
            $reachable = $isNew
                ? true
                : StatusMachine::canGo($current, $value);

            if (!$reachable || (!$canPublish && ($value === StatusMachine::PUBLISH || $value === StatusMachine::PRIVATE))) {
                continue;
            }

            $statuses[] = ['value' => $value, 'label' => __(self::LABELS[$value])];
        }

        $chosen = $current;

        if (!in_array($chosen, array_column($statuses, 'value'), true)) {
            $chosen = $statuses === [] ? StatusMachine::DRAFT : $statuses[0]['value'];
        }

        $categories = $isNew ? [] : $this->terms->termsOf($post->id, TermAssigner::CATEGORY);
        $tags = $isNew ? [] : $this->terms->termsOf($post->id, TermAssigner::TAG);
        $body = $post === null ? '' : HtmlSanitizer::clean((string) $post->content);
        $parentId = $post === null ? 0 : ($this->parentIds([$post->id])[$post->id] ?? 0);
        $menuOrder = $post === null ? 0 : $this->menuOrderOf($post->id);

        // 「页面模板」盒:当前页 meta 里的选择 + 主题声明的候选清单(非页面屏不取)
        $pageTemplateCurrent = $isPage && $post !== null ? ($this->meta->get($post->id, 'page_template') ?? '') : '';
        $pageTemplates = $isPage ? $this->pageTemplateChoices($pageTemplateCurrent) : [];

        // 「地址」那格下面那行完整地址(站点 URL + 固定链接的产物就是 Post::$url)。
        // 前缀单给一份:前端改了 slug 能就地重算,不必再问一次服务端。新建还没有 slug,那格给空。
        $slugNote = '';
        $slugBase = '';

        if ($post !== null && $post->slug !== '') {
            $slugNote = $post->url;
            $at = strrpos($post->url, $post->slug);
            $slugBase = $at === false ? '' : substr($post->url, 0, $at);
        }

        // 新建默认给站点时区的当下(与 PostWriter 留空时存的 UTC `$now` 是同一时刻),
        // 这样"默认值"与"留空"两条路存的是一个时间;库里存 UTC,回显前先换回站点时区
        $publishedInput = $post === null ? date('Y-m-d\TH:i') : '';

        if ($post !== null && $post->published_at !== null) {
            $publishedInput = date('Y-m-d\TH:i', (int) strtotime($post->published_at . ' UTC'));
        }

        // 自定义字段:先归一,再给图片字段挂上「从媒体库选择」的去处(新建那篇还没有 id,不给口子)
        $fieldGroups = $this->collectFields($post);

        if ($post !== null) {
            $fieldGroups = $this->attachFieldPickers($fieldGroups, $post->id);
        }

        // 媒体选择器:特色图片 / 正文插图 / 图片字段三种用途共用同一层(既有规范盒)
        $picker = $isNew ? null : $this->picker($request, (int) $post->id, $this->thumbnailOf($post)['id']);

        $editorExt = $this->editorExtensions();

        return $this->view->render('editor.twig', [
            'page_title' => $isNew
                ? ($isPage ? __('写新页面') : $newDoing)
                : __('编辑:') . $this->labelFor($post),
            'page_kind' => 'editor',
            'is_page' => $isPage,
            'is_cpt' => $isCpt,
            'list_label' => $this->screenLabel(),
            'parent_id' => $parentId,
            'menu_order' => $menuOrder,
            'parent_choices' => $hierarchical ? $this->parentChoicesFor($post?->id) : [],
            'page_templates' => $pageTemplates,
            'page_template_current' => $pageTemplateCurrent,
            // supports 生效:不支持就不出对应控件,不假装
            'show_title' => $this->hasSupport('title'),
            'show_editor' => $this->hasSupport('editor'),
            'show_thumbnail' => $this->hasSupport('thumbnail'),
            'show_comments_box' => !$isPage && $this->hasSupport('comments'),
            'show_meta_box' => $this->hasSupport('custom-fields'),
            // 分类 / 标签两盒是内置文章形态;CPT 改用登记表声明的分类法盒
            'show_classic_taxboxes' => !$isPage && !$isCpt,
            'show_parent_box' => $hierarchical,
            // 序号:层级型都有(页面天然是;hierarchical 的 CPT 靠它排目录)—— 与列表「按序号」同一来源
            'show_menu_order' => $hierarchical,
            'show_page_template' => $isPage,
            'show_slug_row' => $isPage,
            'taxonomy_boxes' => $isCpt ? $this->taxonomyBoxes($post) : [],
            'field_groups' => $fieldGroups,
            'panel_count' => count($fieldGroups['panel']),
            // 编辑器扩展(既有规范):按钮、资产、面板三段都来自插件,内核只负责摆到位
            'editor_buttons' => $editorExt['buttons'],
            'editor_assets' => $editorExt['assets'],
            'editor_panels' => $editorExt['panels'],
            'type_singular' => $singular,
            'post' => $post,
            'is_new' => $isNew,
            'fields' => [
                // 新建那篇还没有 id:空串比 "0" 好使 —— 前端 if (!form.dataset.id) 直接判得出未保存
                'id' => $post === null ? '' : (string) $post->id,
                'title' => $post === null ? '' : $post->title,
                'slug' => $post === null ? '' : $post->slug,
                'excerpt' => $post === null ? '' : (string) $post->excerpt,
                // 富文本区把正文原样摆进去(它是可编辑的稿子),所以这里就得是白名单后的 HTML:
                // 落库时已经过了一道,但库里也可能留着之前写下的旧稿
                'content' => $body,
                'published_at' => $post === null ? '' : (string) $post->published_at,
            ],
            'statuses' => $statuses,
            'chosen' => $chosen,
            'categories' => $this->categoryChoices($post === null ? [] : $categories, $canManageCategories),
            // 分类盒头那条「管理分类与标签」:去既有规范管理屏(增删改都在那边)
            'categories_url' => $this->url('categories'),
            'can_manage_categories' => $canManageCategories,
            // 分类盒每行那颗「×」的落点:管理屏那条删除端点,XHR 送(成不成回 JSON)
            'term_delete_url' => $this->url('categories/delete'),
            'new_category' => is_array($request->inputs()['new_category'] ?? null)
                ? implode(', ', $request->inputs()['new_category'])
                : $request->input('new_category'),
            'tags' => array_map(static fn (array $tag): array => ['name' => $tag['name']], $tags),
            'tag_names' => implode(', ', array_column($tags, 'name')),
            'can_publish' => $canPublish,
            // 可见性三档:私密映射状态,密码保护看是否存了口令哈希(P0-2)。口令输入框永远留空,
            // 编辑已保护文章时不动它 = 保持原口令;重新填才换新的
            'visibility' => $post === null ? 'public'
                : ($post->status === StatusMachine::PRIVATE ? 'private'
                    : ($this->posts->requiresPassword($post->id) ? 'password' : 'public')),
            'post_password_edit' => $post !== null && $this->posts->requiresPassword($post->id),
            'comment_open' => $post === null || $post->comment_status === 'open',
            'thumbnail' => $this->thumbnailOf($post),
            'fields_meta' => $post === null ? [] : $this->metaRows($post->id),
            'word_count' => self::wordCount($body),
            'saved_text' => $post === null ? __('尚未保存') : __('已自动保存') . ' ' . substr($post->updated_at, 11, 5),
            // 自动保存那条带子中场才写文案:没存过就不该显示
            'autosave_text' => '',
            // 时间那格可点可改(既有规范盒):控件值要 Y-m-d\TH:i,库里存的是 Y-m-d H:i:s
            'published_input' => $publishedInput,
            'slug_note' => $slugNote,
            'slug_base' => $slugBase,
            'list_url' => $this->url('posts'),
            'form_action' => $this->url($isNew ? 'posts/new' : 'posts/' . $post->id . '/edit'),
            'autosave_url' => $isNew ? '' : $this->url('posts/' . $post->id . '/edit'),
            'pick_url' => $isNew ? '' : $this->url('posts/' . $post->id . '/edit?pick=1'),
            // 正文「图片」钮的那条选择器地址(#13):同一个选择器,换一档用途
            'body_pick_url' => $isNew ? '' : $this->url('posts/' . $post->id . '/edit?pick=body'),
            // 编辑器里的本地上传(#13)。新建那篇还没落库,存不下图,所以这条口子直接不给
            'img_upload_url' => $isNew ? '' : $this->url('posts/' . $post->id . '/image-upload'),
            'picker' => $picker,
            'dialog_open' => $picker !== null,
            'trash_url' => !$isNew && $this->canDelete($user, $post) && StatusMachine::canGo($post->status, StatusMachine::TRASH)
                ? $this->url('posts/' . $post->id . '/trash') : '',
            'restore_url' => !$isNew && $this->canDelete($user, $post) && $post->status === StatusMachine::TRASH
                ? $this->url('posts/' . $post->id . '/restore') : '',
            'preview_url' => $isNew ? '' : $post->url,
            'revisions' => $isNew ? 0 : count($this->writer->revisions($post->id)),
            'notice' => $notice,
        ], $menu);
    }

    /**
     * 编辑器「页面模板」下拉的候选:当前台主题声明的页面模板清单,标出当前选中项。
     *
     * @return list<array{file: string, name: string, selected: bool}>
     */
    private function pageTemplateChoices(string $current): array
    {
        if ($this->theme === null) {
            return [];
        }

        $choices = [];

        foreach ($this->theme->pageTemplates() as $template) {
            $choices[] = [
                'file' => $template['file'],
                'name' => $template['name'],
                'selected' => $template['file'] === $current,
            ];
        }

        return $choices;
    }

    /**
     * 分类那一盒:候选项 + 已勾 + 计数(既有规范盒右边的灰数字)。
     * can_delete 决定每行尾那颗「×」出不出:要 category.manage,且兜底项「未分类」一概不给 ——
     * 与既有规范管理屏的删除口径逐条对齐(那边也是这两条)。
     *
     * @param list<array<string, mixed>> $checked
     * @return list<array{term_taxonomy_id:int, name:string, slug:string, count:int, checked:bool, can_delete:bool}>
     */
    private function categoryChoices(array $checked, bool $canManage): array
    {
        $mine = array_map('intval', array_column($checked, 'term_taxonomy_id'));

        $rows = array_map(
            static fn (array $choice): array => $choice + [
                'checked' => in_array($choice['term_taxonomy_id'], $mine, true),
                'can_delete' => $canManage && $choice['slug'] !== TermAssigner::UNCATEGORIZED,
            ],
            $this->terms->choices(TermAssigner::CATEGORY)
        );

        // 设计稿那列顺序:勾着的排最前,其余按文章数从多到少(既有规范盒)
        usort($rows, static function (array $a, array $b): int {
            if ($a['checked'] !== $b['checked']) {
                return $a['checked'] ? -1 : 1;
            }

            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return $a['name'] <=> $b['name'];
        });

        return $rows;
    }

    /**
     * 特色图片盒要的那三样:附件 post_id、给人看的文件名、能显示的缩略图地址。
     *
     * @return array{id:int, name:string, preview:string}
     */
    private function thumbnailOf(?Post $post): array
    {
        if ($post === null || $post->thumbnail_id === 0) {
            return ['id' => 0, 'name' => '', 'preview' => ''];
        }

        $record = $this->media->byPostId($post->thumbnail_id);

        if ($record === null) {
            return ['id' => $post->thumbnail_id, 'name' => '', 'preview' => ''];
        }

        return [
            'id' => (int) $record['post_id'],
            'name' => (string) $record['file_name'],
            'preview' => $this->previewOf($record),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function previewOf(array $record): string
    {
        foreach (['thumb', 'medium', 'large'] as $name) {
            $file = (string) ($record['sizes'][$name]['file'] ?? '');

            if ($file !== '' && $this->store->exists($file)) {
                return $this->store->url($file);
            }
        }

        $path = (string) ($record['file_path'] ?? '');

        return $path === '' || !$this->store->exists($path) ? '' : $this->store->url($path);
    }

    /**
     * 自定义字段盒:meta 里能直接给人看的标量项(既有规范盒)。
     *
     * @return list<array{key:string, value:string}>
     */
    private function metaRows(int $postId): array
    {
        $rows = [];

        foreach ($this->meta->all($postId) as $key => $value) {
            // page_template 归「页面模板」下拉独占,不在这里再露一次 —— 两个入口都能改会长出两份真相
            if ($key === 'page_template') {
                continue;
            }

            if (is_scalar($value)) {
                $rows[] = ['key' => (string) $key, 'value' => (string) $value];
            }
        }

        return $rows;
    }

    /**
     * 保存时讨论开关的落点:内置文章两型照旧;CPT 没声明 comments 就保持原值,不偷偷改口径。
     */
    protected function commentStatusFor(Request $request, ?Post $post): string
    {
        if ($this->managedType === 'page' || !$this->hasSupport('comments')) {
            return $post === null ? 'closed' : $post->comment_status;
        }

        return $request->input('comment_status') === 'open' ? 'open' : 'closed';
    }

    /**
     * CPT 声明的分类法分配(既有规范taxonomies)。PostWriter 只认内置 category / post_tag,
     * 所以按登记表逐条走 TermAssigner 的全量替换。
     */
    protected function assignTypeTaxonomies(int $postId, Request $request): void
    {
        $inputs = $request->inputs();
        $taxInputs = $inputs['taxonomies'] ?? [];
        $taxInputs = is_array($taxInputs) ? $taxInputs : [];

        foreach (PostTypeRegistry::instance()->taxonomies($this->managedType) as $taxonomy) {
            $raw = $taxInputs[$taxonomy] ?? null;
            $ids = [];

            foreach (is_array($raw) ? $raw : [] as $value) {
                if (is_numeric($value)) {
                    $ids[] = (int) $value;
                }
            }

            // 全量替换语义:这次表单没给某分类法 = 清空它的归属
            $this->terms->assign($postId, $taxonomy, array_values(array_unique($ids)));
        }
    }

    /**
     * CPT 编辑屏的分类法盒:每个声明过的分类法一盒、多选;层级型按父子缩进给出候选。
     *
     * @return list<array{taxonomy:string, label:string, hierarchical:bool, choices:list<array{term_taxonomy_id:int, name:string, count:int, depth:int, checked:bool}>}>
     */
    private function taxonomyBoxes(?Post $post): array
    {
        $registry = TaxonomyRegistry::instance();
        $boxes = [];

        foreach (PostTypeRegistry::instance()->taxonomies($this->managedType) as $taxonomy) {
            $meta = $registry->get($taxonomy);
            $checked = $post === null ? [] : array_map(
                static fn (array $term): int => $term['term_taxonomy_id'],
                $this->terms->termsOf($post->id, $taxonomy)
            );

            $choices = [];

            foreach ($this->taxonomyTerms($taxonomy) as $choice) {
                $choice['checked'] = in_array($choice['term_taxonomy_id'], $checked, true);
                $choices[] = $choice;
            }

            $boxes[] = [
                'taxonomy' => $taxonomy,
                'label' => $meta === null ? $taxonomy : $meta['label'],
                'hierarchical' => $meta !== null && $meta['hierarchical'],
                'choices' => $choices,
            ];
        }

        return $boxes;
    }

    /**
     * 一个分类法下的候选词条:层级型按树序展平(子行带 depth),平铺型按名称排。
     * 计数只数本类型里未删除的内容 —— 与编辑页那个灰数字同一口径。
     *
     * @return list<array{term_taxonomy_id:int, name:string, count:int, depth:int}>
     */
    private function taxonomyTerms(string $taxonomy): array
    {
        $rows = $this->db->select(
            'SELECT tt.term_taxonomy_id AS tt_id, t.name AS name, tt.parent AS parent,'
            . ' (SELECT COUNT(*) FROM ' . $this->db->table('term_relationships') . ' tr'
            . '  JOIN ' . $this->db->table('posts') . ' p ON p.id = tr.post_id'
            . '  WHERE tr.term_taxonomy_id = tt.term_taxonomy_id AND p.deleted_at IS NULL AND p.type = :type) AS n'
            . ' FROM ' . $this->db->table('term_taxonomy') . ' tt'
            . ' JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id'
            . ' WHERE tt.taxonomy = :tax',
            ['tax' => $taxonomy, 'type' => $this->managedType]
        );

        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'term_taxonomy_id' => (int) $row['tt_id'],
                'name' => (string) $row['name'],
                'parent' => (int) $row['parent'],
                'count' => (int) ($row['n'] ?? 0),
                'depth' => 0,
            ];
        }

        $meta = TaxonomyRegistry::instance()->get($taxonomy);

        if ($meta === null || !$meta['hierarchical']) {
            usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

            return array_map(static fn (array $item): array => [
                'term_taxonomy_id' => $item['term_taxonomy_id'],
                'name' => $item['name'],
                'count' => $item['count'],
                'depth' => 0,
            ], $items);
        }

        // 层级型:按父子关系展平成「父在前、子紧随」的顺序,子行带 depth 供模板缩进
        $byParent = [];

        foreach ($items as $index => $item) {
            $byParent[$item['parent']][] = $index;
        }

        $flat = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$flat, $byParent, $items): void {
            $group = array_map(static fn (int $index): array => $items[$index], $byParent[$parent] ?? []);
            usort($group, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

            foreach ($group as $item) {
                $item['depth'] = $depth;
                $flat[] = $item;
                $walk($item['term_taxonomy_id'], $depth + 1);
            }
        };
        $walk(0, 1);

        return array_map(static fn (array $item): array => [
            'term_taxonomy_id' => $item['term_taxonomy_id'],
            'name' => $item['name'],
            'count' => $item['count'],
            'depth' => $item['depth'],
        ], $flat);
    }

    /**
     * 编辑器自定义字段(既有规范):插件经 `admin/editor-fields/collect` 交出定义,
     * 这里按 position(side / main / panel)与 priority 归一好交给模板,控件一律复用既有规范词表。
     * 未登记(或形状不合)的一律丢,一条坏数据不该把整页打崩。
     *
     * @return array{side:list<array<string,mixed>>, main:list<array<string,mixed>>, panel:list<array<string,mixed>>}
     */
    private function collectFields(?Post $post): array
    {
        $groups = ['side' => [], 'main' => [], 'panel' => []];
        $values = $post === null ? [] : $this->meta->all($post->id);
        // 登记表里的字段(register_post_meta / register_post_field)当参照值,插件仍可经钩子追加
        $collected = apply_filters('admin/editor-fields/collect', FieldRegistry::instance()->uiFor($this->managedType), $this->managedType);

        foreach ((array) $collected as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            /** @var array<string, mixed> $spec */
            $name = is_string($key) && !ctype_digit($key) ? $key : (string) ($spec['key'] ?? '');

            if ($name === '') {
                continue;
            }

            $position = (string) ($spec['position'] ?? 'side');
            $position = in_array($position, ['side', 'main', 'panel'], true) ? $position : 'side';
            $raw = $values[$name] ?? '';
            $field = $this->normalizeField($name, $spec, is_scalar($raw) ? (string) $raw : '');
            $field['priority'] = (int) ($spec['priority'] ?? 10);
            $groups[$position][] = $field;
        }

        foreach ($groups as $position => $list) {
            usort($list, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
            $groups[$position] = $list;
        }

        return $groups;
    }

    /**
     * 一条字段定义归一成模板直接能用的形状(既有规范参数表)。
     * control 必须落在词表内,否则回落 text(词表外不得装载);
     * group / repeater 的嵌套定义递归归一,并把已有 JSON 值解析成可渲染的行。
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    /**
     * 编辑器扩展(既有规范):按钮与资产由 `admin/editor/collect` 交出,面板 HTML 由 `admin/editor/render` 现产出。
     *
     * 插件是能跑 PHP 的可信代码,面板不再过滤;但按钮与资产要活着进页面,所以形状先过一道 ——
     * 坏条目丢掉而不是整页打崩(与 collectFields 同口径)。
     *
     * @return array{buttons:list<array{id:string,label:string,title:string,svg:string}>,assets:list<array{type:string,src:string}>,panels:string}
     */
    private function editorExtensions(): array
    {
        $collected = apply_filters('admin/editor/collect', [], $this->managedType);

        $buttons = [];
        $assets = [];

        foreach (is_array($collected) ? (array) ($collected['assets'] ?? []) : [] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $type = (string) ($asset['type'] ?? '');
            $src = (string) ($asset['src'] ?? '');

            if (!in_array($type, ['style', 'script'], true) || !self::isRootRelative($src)) {
                continue;
            }

            $assets[] = ['type' => $type, 'src' => $src];
        }

        foreach (is_array($collected) ? (array) ($collected['buttons'] ?? []) : [] as $button) {
            if (!is_array($button)) {
                continue;
            }

            $id = (string) ($button['id'] ?? '');

            if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $id) !== 1) {
                continue;
            }

            $label = mb_substr(trim((string) ($button['label'] ?? '')), 0, 8);
            $svg = self::safeSvg((string) ($button['svg'] ?? ''));

            if ($label === '' && $svg === '') {
                continue;
            }

            $buttons[] = [
                'id' => $id,
                'label' => $label,
                'title' => trim((string) ($button['title'] ?? '')) !== '' ? trim((string) $button['title']) : $label,
                'svg' => $svg,
            ];
        }

        // 面板走 action:插件 echo 什么就是什么,收成一段 HTML 交给模板
        ob_start();
        do_action('admin/editor/render', $this->managedType);
        $panels = (string) ob_get_clean();

        return ['buttons' => $buttons, 'assets' => $assets, 'panels' => $panels];
    }

    /** 后台静态资源只从本站根相对地址来:带协议或 `..` 的地址一律不认 */
    private static function isRootRelative(string $src): bool
    {
        return $src !== ''
            && str_starts_with($src, '/')
            && !str_starts_with($src, '//')
            && !str_contains($src, '..')
            && preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) !== 1;
    }

    /**
     * 按钮图标只收一枚自足 `<svg>`:脚本与事件属性一律不认(不是防插件,是防"图标字段被拼进别的东西")。
     */
    private static function safeSvg(string $svg): string
    {
        $svg = trim($svg);

        if ($svg === '' || preg_match('#^<svg[\s\S]*</svg>$#i', $svg) !== 1) {
            return '';
        }

        if (stripos($svg, '<script') !== false || preg_match('#\son[a-z]+\s*=#i', $svg) === 1) {
            return '';
        }

        return $svg;
    }


    private function normalizeField(string $key, array $spec, string $value): array
    {
        $control = (string) ($spec['control'] ?? 'text');
        $control = in_array($control, self::CONTROLS, true) ? $control : 'text';
        $args = is_array($spec['control_args'] ?? null) ? $spec['control_args'] : [];

        $field = [
            'key' => $key,
            'id' => $this->domId($key),
            'label' => (string) ($spec['label'] ?? $key),
            'description' => (string) ($spec['description'] ?? ''),
            'control' => $control,
            'readonly' => (bool) ($spec['readonly'] ?? false),
            'value' => $value,
            'args' => $this->normalizeArgs($args),
            'priority' => 10,
        ];

        if (in_array($control, ['select', 'radio'], true)) {
            $field['options'] = $this->normalizeOptions($args['options'] ?? []);
        }

        if ($control === 'image') {
            [$preview, $mediaName] = $this->imageMeta($value);
            $field['preview'] = $preview;
            $field['media_name'] = $mediaName;
        }

        if ($control === 'group' || $control === 'repeater') {
            $subfields = $this->normalizeSubfields($args['fields'] ?? []);
            $decoded = json_decode($value, true);
            $field['subfields'] = $subfields;
            $field['sub_json'] = (string) json_encode(array_map(
                static fn (array $sub): array => ['key' => $sub['key'], 'label' => $sub['label'], 'control' => $sub['control'], 'options' => $sub['options']],
                $subfields
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $field['rows'] = $this->fieldRows($control, $subfields, is_array($decoded) ? $decoded : []);
        }

        return $field;
    }

    /**
     * control_args 的标量项收成字符串;options / fields 另有去处。
     *
     * @param array<string, mixed> $args
     * @return array<string, string>
     */
    private function normalizeArgs(array $args): array
    {
        $out = [];

        foreach (['placeholder', 'rows', 'maxlength', 'min', 'max', 'step', 'accept', 'lang', 'unit'] as $name) {
            if (isset($args[$name]) && is_scalar($args[$name])) {
                $out[$name] = (string) $args[$name];
            }
        }

        return $out;
    }

    /**
     * select / radio 的 options 归一成 [{value,label}]:既认 key=>label 映射,也认对象数组。
     *
     * @return list<array{value:string, label:string}>
     */
    private function normalizeOptions(mixed $options): array
    {
        if ($options instanceof \Traversable) {
            $options = iterator_to_array($options);
        }

        if (!is_array($options)) {
            return [];
        }

        $out = [];

        foreach ($options as $k => $v) {
            if (is_array($v)) {
                /** @var array<string, mixed> $v */
                $value = (string) ($v['value'] ?? $v['key'] ?? $k);
                $out[] = ['value' => $value, 'label' => (string) ($v['label'] ?? $v['name'] ?? $value)];

                continue;
            }

            // 字符串键 = value=>label;整数键 = 纯列表,值与标签同为该值
            $out[] = is_string($k)
                ? ['value' => $k, 'label' => (string) $v]
                : ['value' => (string) $v, 'label' => (string) $v];
        }

        return $out;
    }

    /**
     * group / repeater 的嵌套字段定义(fields 递归适用本规范)。
     * 嵌套里再套 group / repeater 时,渲染层回落 JSON 文本域 —— 1.0 不做多层嵌套编辑器。
     *
     * @return list<array{key:string, id:string, label:string, control:string, args:array<string,string>, options:list<array{value:string,label:string}>}>
     */
    private function normalizeSubfields(mixed $fields): array
    {
        if ($fields instanceof \Traversable) {
            $fields = iterator_to_array($fields);
        }

        if (!is_array($fields)) {
            return [];
        }

        $out = [];

        foreach ($fields as $k => $sub) {
            if (is_array($sub)) {
                /** @var array<string, mixed> $sub */
                $key = is_string($k) ? $k : (string) ($sub['key'] ?? '');
                $label = (string) ($sub['label'] ?? $key);
                $control = (string) ($sub['control'] ?? $sub['type'] ?? 'text');
                $args = is_array($sub['control_args'] ?? null) ? $sub['control_args'] : [];
            } elseif (is_string($sub)) {
                // '字段名' => 'text' 的简写:键是字段名,值是控件名
                $key = is_string($k) ? $k : $sub;
                $label = $key;
                $control = $sub;
                $args = [];
            } else {
                continue;
            }

            if ($key === '') {
                continue;
            }

            $control = in_array($control, self::CONTROLS, true) ? $control : 'text';

            $out[] = [
                'key' => $key,
                'id' => $this->domId($key),
                'label' => $label,
                'control' => $control,
                'args' => $this->normalizeArgs($args),
                'options' => in_array($control, ['select', 'radio'], true) ? $this->normalizeOptions($args['options'] ?? []) : [],
            ];
        }

        return $out;
    }

    /**
     * 把已存的 JSON 值解析成可渲染的行:repeater 每组一行,group 只有一行。
     *
     * @param list<array<string, mixed>> $subfields
     * @param array<mixed> $decoded
     * @return list<array<string, string>>
     */
    private function fieldRows(string $control, array $subfields, array $decoded): array
    {
        if ($control === 'group') {
            return $decoded === [] ? [] : [$this->rowValues($subfields, $decoded)];
        }

        $rows = [];

        foreach (array_is_list($decoded) ? $decoded : array_values($decoded) as $item) {
            if (is_array($item)) {
                $rows[] = $this->rowValues($subfields, $item);
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $subfields
     * @param array<mixed> $item
     * @return array<string, string>
     */
    private function rowValues(array $subfields, array $item): array
    {
        $out = [];

        foreach ($subfields as $sub) {
            $value = $item[$sub['key']] ?? '';
            $out[$sub['key']] = is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
        }

        return $out;
    }

    /**
     * 图片字段的预览地址与文件名:值是媒体库附件 id。
     *
     * @return array{0:string, 1:string}
     */
    private function imageMeta(string $value): array
    {
        $id = max(0, (int) $value);

        if ($id === 0) {
            return ['', ''];
        }

        $record = $this->media->byPostId($id);

        return $record === null ? ['', '#' . $id] : [$this->previewOf($record), (string) $record['file_name']];
    }

    /** 字段键 → 安全的 DOM id(只留字母数字下划线连字符) */
    private function domId(string $key): string
    {
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '-', $key);

        return is_string($id) && $id !== '' ? $id : 'cf';
    }

    /**
     * 给图片字段挂上「从媒体库选择」的去处:复用编辑页那层选择器,换成 meta 模式
     * (选中的附件 id 由前端写回 meta_value[],不另开写入口)。key 用归一后的 DOM id,
     * 与 editor-field.twig 里的 `cf-<id>` 对得上。
     *
     * @param array<string, list<array<string, mixed>>> $groups
     * @return array<string, list<array<string, mixed>>>
     */
    private function attachFieldPickers(array $groups, int $postId): array
    {
        $out = [];

        foreach ($groups as $position => $list) {
            $picked = [];

            foreach ($list as $field) {
                if ($field['control'] === 'image') {
                    $field['pick_url'] = $this->url('posts/' . $postId . '/edit')
                        . '?pick=meta&key=' . rawurlencode((string) $field['id']);
                }

                $picked[] = $field;
            }

            $out[$position] = $picked;
        }

        return $out;
    }

    /**
     * 「共 N 字 · 预计阅读约 M 分钟」。中文按字数估,300 字一分钟。
     */
    private static function wordCount(string $html): string
    {
        // 字数数的是给人看的字,标签与实体不算(前端 innerText 同口径)
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $chars = mb_strlen(preg_replace('/\s+/u', '', $text) ?? '', 'UTF-8');

        return __('共 %s 字 · 预计阅读约 %s 分钟', 'molin', number_format($chars), max(1, (int) ceil($chars / 300)));
    }

    /**
     * 媒体选择器(既有规范盒点开的那层)。设计稿没有这一层,
     * 模态件 + 既有规范网格那一套拼出来,图已补在设计稿
     *
     * @return array<string, mixed>|null
     */
    private function picker(Request $request, int $postId, int $selected): ?array
    {
        $pick = $request->input('pick');
        $metaKey = $request->input('key');

        // `?pick=1` 挑特色图片(§4.7 第 4 盒),`?pick=body` 是往正文里插一张(§4.6 图片钮),
        // `?pick=meta&key=<id>` 是给图片字段挑附件 id;别的值一律当没点开,不半开着
        if ($pick === 'meta') {
            if (preg_match('/\A[A-Za-z0-9_\-]+\z/', $metaKey) !== 1) {
                return null;
            }

            $mode = 'meta';
        } elseif (in_array($pick, ['1', 'body'], true)) {
            $mode = $pick === 'body' ? 'body' : 'feat';
        } else {
            return null;
        }

        // 翻页链接上的取值与认得的取值一致(meta 模式连 key 一起带上)
        $pickValue = match ($mode) {
            'body' => 'body',
            'meta' => 'meta&key=' . rawurlencode($metaKey),
            default => '1',
        };
        $keyword = $request->input('q');
        $page = max(1, (int) $request->input('page', '1'));
        // 特色图片只能是图:媒体库那六个栏里取「图片」这一栏(既有规范的分类口径)。
        // 一页 6 张 = 对话框里桌面两行三列、移动两行两列(既有规范/ §11.3)
        $list = $this->media->browse(['q' => $keyword, 'tab' => 'image'], $page, self::PICKER_PER_PAGE);
        $total = (int) $list['total'];
        $pages = max(1, (int) ceil($total / self::PICKER_PER_PAGE));
        $links = [];

        for ($number = 1; $number <= min($pages, 5); $number++) {
            $links[] = [
                'page' => $number,
                'url' => $this->url('posts/' . $postId . '/edit') . '?pick=' . $pickValue . '&page=' . $number . ($keyword === '' ? '' : '&q=' . rawurlencode($keyword)),
                'active' => $number === $page,
            ];
        }

        return [
            'action' => $this->url('posts/' . $postId . '/edit'),
            'mode' => $mode,
            'pick_value' => $pickValue,
            // meta 模式:选中的附件 id 由前端写回这个字段的隐藏输入(cf-<key>)
            'key' => $mode === 'meta' ? $metaKey : '',
            'q' => $keyword,
            'items' => array_map(fn (array $item): array => [
                'id' => (int) $item['post_id'],
                'kind' => strtoupper((string) pathinfo((string) $item['file_name'], PATHINFO_EXTENSION)),
                'name' => (string) $item['file_name'],
                // 正文插图交出去的是原图地址(#13):插进去的是文件本身,不是缩略图
                'url' => $this->store->url((string) $item['file_path']),
                'thumb' => $this->previewOf($item),
                'dims' => $item['width'] === null ? '' : $item['width'] . '×' . $item['height'],
                'size_text' => round((int) $item['file_size'] / 1024) . ' KB',
            ], $list['items']),
            'info' => __('第 %s–%s 项，共 %s 项', 'molin', ($page - 1) * self::PICKER_PER_PAGE + 1, min($total, $page * self::PICKER_PER_PAGE), $total),
            'pages' => $links,
            'set_url' => $this->url('posts/' . $postId . '/thumbnail'),
            'cancel_url' => $this->url('posts/' . $postId . '/edit'),
            // 「当前那张」是特色图片才有的概念,正文插图与图片字段都不标
            'selected' => $mode === 'feat' ? $selected : 0,
        ];
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     */
    protected function stale(Request $request, User $user, array $menu, ?Post $post): Response
    {
        return $this->editor($request, $user, $menu, $post, __(WebAuth::STALE));
    }
}
