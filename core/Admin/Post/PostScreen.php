<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Post;

use Molin\Admin\AdminGuards;
use Molin\Admin\View;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\PostTypeRegistry;
use Molin\Content\StatusMachine;
use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\User\Authorizer;
use Molin\User\Capabilities;
use Molin\User\User;

/**
 * 文章屏共用的底座:managedType 口径(别名 / 能力 / supports)与跨屏小件。
 *
 * 子屏按屏继承成一条链(列表 → 编辑 → 保存 / 媒体动作),方法体里的 `$this->x()` 才能原样保留。
 */
abstract class PostScreen
{
    use AdminGuards;

    /** 状态中文名 */
    protected const LABELS = [
        StatusMachine::DRAFT => '草稿',
        StatusMachine::PENDING => '待审',
        StatusMachine::PUBLISH => '已发布',
        StatusMachine::PRIVATE => '私密',
        StatusMachine::TRASH => '回收站',
    ];

    /** 跳回列表/编辑页时带在查询串上的结果标记,只认这几个键 */
    private const FLASH = [
        'saved' => '已保存。',
        'published' => '发布成功。',
        'trashed' => '已移入回收站,30 天后才会彻底删除。',
        'restored' => '已还原为草稿。',
        'destroyed' => '已彻底删除,相关的自定义字段、分类与修订一起清掉了。',
        'denied' => '这一步你的账号没有对应能力。',
        'nopick' => '没有勾选文章。',
    ];

    public function __construct(
        protected readonly View $view,
        protected readonly PostRepository $posts,
        protected readonly Authorizer $can,
        protected readonly string $managedType
    ) {
    }

    /**
     * 这一屏挂在后台哪个地址段下:文章屏 posts、页面屏 pages、CPT 用类型名自己。
     */
    private function screenRoot(): string
    {
        return match ($this->managedType) {
            'post' => 'posts',
            'page' => 'pages',
            default => $this->managedType,
        };
    }

    /**
     * 是否是插件登记的自定义类型(内置 post / page 不在登记表里)。
     */
    protected function isCpt(): bool
    {
        return PostTypeRegistry::instance()->has($this->managedType);
    }

    /**
     * @return array{slug: string, label: string, public: bool, has_archive: bool, taxonomies: list<string>, owner: string|null}|null
     */
    private function typeRecord(): ?array
    {
        return PostTypeRegistry::instance()->get($this->managedType);
    }

    /**
     * 这一屏给人看的名字:内置用翻译过的中文名,CPT 直接用插件声明的 label。
     */
    protected function screenLabel(): string
    {
        if ($this->managedType === 'post') {
            return __('文章');
        }

        if ($this->managedType === 'page') {
            return __('页面');
        }

        $record = $this->typeRecord();

        return $record === null ? $this->managedType : $record['label'];
    }

    /**
     * 这一屏的单数名(新建按钮、提示语里用)。
     */
    protected function screenSingular(): string
    {
        return match ($this->managedType) {
            'post' => __('文章'),
            'page' => __('页面'),
            default => PostTypeRegistry::instance()->singular($this->managedType),
        };
    }

    /**
     * 权限能力前缀:内置类型走内核既有的 post.* 一族;CPT 取它的 capability_type
     * ,前缀拼出来的能力没登记就回落 post.*(既有规范为准:不新造能力名)。
     */
    private function capabilityPrefix(): string
    {
        return $this->isCpt() ? PostTypeRegistry::instance()->capabilityType($this->managedType) : 'post';
    }

    /**
     * 某个动作对应的能力名。`$action` 形如 create / edit.own / publish / delete.others。
     */
    protected function cap(string $action): string
    {
        $prefix = $this->capabilityPrefix();

        if ($prefix !== 'post' && Capabilities::isKnown($prefix . '.' . $action)) {
            return $prefix . '.' . $action;
        }

        return 'post.' . $action;
    }

    /**
     * 进这一栏的最低门槛:六个动作能力任取其一。
     *
     * @return list<string>
     */
    protected function floorCaps(): array
    {
        return [
            $this->cap('create'),
            $this->cap('edit.own'),
            $this->cap('edit.others'),
            $this->cap('publish'),
            $this->cap('delete.own'),
            $this->cap('delete.others'),
        ];
    }

    /**
     * 这一类型是否按层级组织(页面天然是;CPT 看 hierarchical)。
     */
    protected function isHierarchical(): bool
    {
        return $this->managedType === 'page'
            || ($this->isCpt() && PostTypeRegistry::instance()->hierarchical($this->managedType));
    }

    /**
     * 编辑器支持项(既有规范supports)。内置两型按现有形态给齐,CPT 照登记表。
     *
     * @return list<string>
     */
    private function supportedFeatures(): array
    {
        return match ($this->managedType) {
            'post' => ['title', 'editor', 'excerpt', 'thumbnail', 'comments', 'custom-fields'],
            'page' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            default => PostTypeRegistry::instance()->supports($this->managedType),
        };
    }

    protected function hasSupport(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures(), true);
    }

    /**
     * 无标题内容的行标识(supports 里没有 title 的类型,如「说说」)。
     *
     * 标题 → 摘要 → 正文纯文本 → 「（无标题 #id）」逐级回落:列表与编辑屏页头永远有一个认得出的名字,
     * 不出现空行。
     */
    protected function labelFor(Post $post): string
    {
        if ($post->title !== '') {
            return $post->title;
        }

        $text = self::plainText((string) $post->excerpt);

        if ($text === '') {
            $text = self::plainText((string) $post->content);
        }

        if ($text === '') {
            // 复用后台既有的「(无标题)」那句(见 Dashboard / 快速编辑),再加 id 便于在列表里认人
            return __('(无标题)') . ' #' . $post->id;
        }

        return mb_strlen($text) > 30 ? mb_substr($text, 0, 30) . '…' : $text;
    }

    /** 行标识用的纯文本:去标签、收空白 */
    private static function plainText(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    }

    protected function canEdit(User $user, Post $post): bool
    {
        return $this->can->can($user, $this->cap('edit.others')) || $this->can->can($user, $this->cap('edit.own'), $post);
    }

    protected function canDelete(User $user, Post $post): bool
    {
        return $this->can->can($user, $this->cap('delete.others')) || $this->can->can($user, $this->cap('delete.own'), $post);
    }

    protected function mustFind(int $id): Post
    {
        // 回收站里的文章后台要看得见,所以带 withTrashed
        $post = $this->posts->find($id, true);

        if ($post === null) {
            throw new NotFoundException('没有这篇文章:' . $id);
        }

        return $post;
    }

    /**
     * @return list<int>
     */
    protected function ints(Request $request, string $key): array
    {
        $raw = $request->inputs()[$key] ?? null;
        $list = is_array($raw) ? $raw : [];
        $ids = [];

        foreach ($list as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /**
     * 逗号分隔的名字串 → 名字数组(中英文逗号都认,空串不入库)。
     *
     * @return list<string>
     */
    protected function names(string $given): array
    {
        $parts = preg_split('/[,，]+/u', $given);
        $parts = $parts === false ? [] : $parts;
        $parts = array_map('trim', $parts);

        return array_values(array_unique(array_filter($parts, static fn (string $name): bool => $name !== '')));
    }

    protected function why(ValidationException $e): string
    {
        $fields = [];

        foreach ($e->errors() as $messages) {
            $fields[] = implode(';', array_map(static fn (string $message): string => __($message), $messages));
        }

        return $fields === [] ? __($e->getMessage()) : __($e->getMessage()) . ':' . implode(' / ', $fields);
    }

    protected function flash(Request $request): string
    {
        $done = $request->input('done');
        $note = $request->input('note');
        $parts = [];

        if (isset(self::FLASH[$done])) {
            $parts[] = __(self::FLASH[$done]);
        }

        if ($note === 'pending') {
            $parts[] = __('你没有发布权限,这篇已提交待审。');
        }

        return implode(' ', $parts);
    }

    /**
     * 后台子路径一律由路由名生成站内相对路径(既有规范条:不许拼字符串)。
     */
    protected function url(string $sub): string
    {
        // '?' 之后的查询串不参与路由名生成,原样接在路径后面
        $position = strpos($sub, '?');
        $path = $position === false ? $sub : substr($sub, 0, $position);
        $query = $position === false ? '' : substr($sub, $position);

        // 文章屏挂在 /admin/posts;页面屏与 CPT 屏共用同一套动作,只换首段
        // (既有规范「同壳」;CPT 用类型名自己那段)
        $root = $this->screenRoot();

        if ($root !== 'posts' && str_starts_with($path, 'posts')) {
            $path = $root . substr($path, 5);
        }

        return $this->view->path('admin', ['path' => $path]) . $query;
    }
}
