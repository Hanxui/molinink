<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Theme;

use Molin\Comment\CommentRepository;
use Molin\Content\ContentContext;
use Molin\Content\ContentRenderer;
use Molin\Content\Post;
use Molin\Content\PostRepository;
use Molin\Content\PostTypeRegistry;
use Molin\Content\TermAssigner;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Option\Options;
use Molin\Router\PostTypeRoutes;
use Molin\Router\Router;
use Molin\Search\Searcher;
use Molin\User\Roles;
use Molin\User\User;
use Molin\User\UserRepository;
use Psr\SimpleCache\CacheInterface;

/**
 * 列表数据装配:同一条列表,整页与片段端点共用一份取数与组项逻辑。
 *
 * 首页 / 分类 / 标签 / 作者 / 日期 / 自定义类型归档 / 搜索各是一个 context;
 * build() 按 context 走既有的 PostQuery / Searcher 与分页链,返回 items + pager + 页头所需数据。
 * index.php 的整页处理器与 ListFragmentController 的片段端点都只调这一支 ——
 * 查询与组项只此一处,不复制第二套。分页地址一律由 path 生成,不拼字符串。
 */
final class ListSource
{
    /** 支持的列表上下文(与既有规范路由族一一对应),片段端点入参 context 的取值域 */
    public const CONTEXTS = ['home', 'category', 'tag', 'author', 'date', 'archive', 'search'];

    /**
     * 作者取不到时的占位:键始终在,值全空串 —— 与 views 同口径,模板不必做 defined 猜谜。
     * 作者被禁用 / 已删除 / author_id=0 都落这里。
     *
     * @var array{name: string, initial: string, avatar_url: string}
     */
    private const EMPTY_AUTHOR = ['name' => '', 'initial' => '', 'avatar_url' => ''];

    /** 每篇文章带几条评论预览(既有规范items[].comments_preview):卡片上就一小行,3 条够看 */
    private const COMMENT_PREVIEW_LIMIT = 3;

    /** 自动摘要上限(字):作者没写摘要时从正文取这么多字,超了截断加省略号 */
    private const AUTO_EXCERPT_CHARS = 200;

    /** 主色算法版本:算法或取色口径一改就 +1,全站旧结果自然不再被读到,不必手工清缓存 */
    private const COLOR_VERSION = 1;

    /** 主色结果的存活期(30 天);真正的失效靠键里的 mtime,TTL 只防长期没人看的站攒垃圾 */
    private const COLOR_TTL = 2592000;

    /** 解不出颜色的结果也缓存,但只记 1 小时:同一个 mtime 的坏图救不回来,但换图/换算法时别钉住一个月 */
    private const COLOR_TTL_BROKEN = 3600;

    /**
     * 本次请求内已算过的图片主色,键 = 文件路径 + mtime + 算法版本。
     * 跨请求那份在缓存里,这里只是省掉同一页重复读缓存的 IO。
     *
     * @var array<string, string|null>
     */
    private static array $colorCache = [];

    /**
     * @param ?CacheInterface $cache 主色这类单值小结果用的 PSR-16 存储;没注入就直接算(测试与脱装配场景)
     */
    public function __construct(
        private readonly PostRepository $posts,
        private readonly TermAssigner $terms,
        private readonly UserRepository $users,
        private readonly Searcher $searcher,
        private readonly Router $router,
        private readonly Options $options,
        private readonly ContentRenderer $contentRenderer,
        private readonly MediaRepository $media,
        private readonly Store $store,
        private readonly CommentRepository $comments,
        private readonly ?CacheInterface $cache = null
    ) {
    }

    /** 每页条数,与整页口径同源(站点配置 posts_per_page,封顶 100) */
    public function perPage(): int
    {
        return max(1, min(100, (int) $this->options->get('posts_per_page', 10)));
    }

    /**
     * 首页取哪些内容类型:内置 `post` 恒在,其余看登记表声明了 `in_main_list` 的类型。
     *
     * 只影响首页那一条流;分类 / 标签 / 作者 / 日期 / 说说归档仍各自只取自己的类型。
     *
     * @return list<string>
     */
    private function homeTypes(): array
    {
        return array_values(array_unique(array_merge(['post'], PostTypeRegistry::instance()->mainListTypes())));
    }

    /**
     * 组装一条列表。
     *
     * 返回的形状固定:status / error / context / term / author / items / pager / posts / pagination / search。
     * status ≠ 200 时 error 给中文原因,其余键仍是空值占位。
     *
     * @param array<string, string|int> $params slug / type / year / month / q;
     *        home 另收 route(分页地址用哪组路由名,全站文章页给 `home.archive`,缺省 `home`)
     * @return array<string, mixed>
     */
    public function build(string $context, array $params = [], int $page = 1): array
    {
        $page = max(1, $page);

        return match ($context) {
            'home' => $this->home($page, (string) ($params['route'] ?? 'home')),
            'category' => $this->taxonomy('category', TermAssigner::CATEGORY, $params, $page),
            'tag' => $this->taxonomy('tag', TermAssigner::TAG, $params, $page),
            'author' => $this->author($params, $page),
            'date' => $this->date($params, $page),
            'archive' => $this->cptArchive($params, $page),
            'search' => $this->search($params, $page),
            default => self::fail($context, 400, '未知的列表类型'),
        };
    }

    /**
     * 首页那一条流。/blog 与首页同一份查询,只是分页地址挂在 `home.archive` 一族上。
     *
     * @return array<string, mixed>
     */
    private function home(int $page, string $route = 'home'): array
    {
        $perPage = $this->perPage();
        // 首页 = post + 声明了 in_main_list 的类型;归档族不受这条影响
        $query = $this->posts->query()->types($this->homeTypes())->status('publish');

        if ($this->listingFull()) {
            $query->content();
        }

        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($page > $totalPages) {
            return self::fail('home', 404, '页码超出范围');
        }

        $found = $query->orderBy('published_at', 'desc')->limit($perPage)->page($page)->get();

        return self::ok('home', [
            'items' => $this->items($found, $page, $perPage, true),
            'pager' => $this->pager($route, [], $page, $totalPages, $total, $perPage, false, true, 'full'),
            'posts' => $found,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
        ]);
    }

    /**
     * 分类 / 标签归档:同一壳(`$context` 兼作页型与路由名),差异只在查询口径与行元信息。
     *
     * @param array<string, string|int> $params
     * @return array<string, mixed>
     */
    private function taxonomy(string $context, string $taxonomy, array $params, int $page): array
    {
        $slug = (string) ($params['slug'] ?? '');

        if ($slug === '') {
            return self::fail($context, 400, '缺少分类别名 slug');
        }

        $term = $this->terms->findBySlug($taxonomy, $slug);

        if ($term === null) {
            return self::fail($context, 404, '分类或标签不存在');
        }

        $perPage = $this->perPage();
        $query = $this->posts->query()->type('post')->status('publish');

        if ($taxonomy === TermAssigner::TAG) {
            $query->tag([$slug]);
        } else {
            $query->category($slug);
        }

        if ($this->listingFull()) {
            $query->content();
        }

        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($page > $totalPages) {
            return self::fail($context, 404, '页码超出范围');
        }

        $found = $query->orderBy('published_at', 'desc')->limit($perPage)->page($page)->get();

        return self::ok($context, [
            'term' => $term,
            // 标签跨分类,行元信息回带分类名;分类页自身不回带
            'items' => $this->items($found, $page, $perPage, $taxonomy === TermAssigner::TAG),
            'pager' => $this->pager($context, ['slug' => $slug], $page, $totalPages, $total, $perPage, $totalPages > 1, true, 'full'),
        ]);
    }

    /**
     * @param array<string, string|int> $params
     * @return array<string, mixed>
     */
    private function author(array $params, int $page): array
    {
        $slug = (string) ($params['slug'] ?? '');

        if ($slug === '') {
            return self::fail('author', 400, '缺少作者别名 slug');
        }

        $user = $this->users->findByUsername($slug);

        if ($user === null || $user->status !== User::ACTIVE) {
            return self::fail('author', 404, '作者不存在');
        }

        $perPage = $this->perPage();
        $query = $this->posts->query()->type('post')->status('publish')->author($user->id);

        if ($this->listingFull()) {
            $query->content();
        }

        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($page > $totalPages) {
            return self::fail('author', 404, '页码超出范围');
        }

        $found = $query->orderBy('published_at', 'desc')->limit($perPage)->page($page)->get();
        $meta = $this->users->meta($user->id);
        $name = $user->display_name !== '' ? $user->display_name : $user->username;
        $bio = trim((string) ($meta['bio'] ?? ''));

        // 侧栏照原版列出全部分类（含 0），数字换成该作者名下的篇数
        $authorCounts = array_column($this->terms->categoryCountsForAuthor($user->id), 'count', 'slug');
        $categories = [];

        foreach ($this->terms->choices(TermAssigner::CATEGORY) as $term) {
            $categories[] = [
                'name' => $term['name'],
                'slug' => $term['slug'],
                'count' => $authorCounts[$term['slug']] ?? 0,
            ];
        }

        return self::ok('author', [
            'term' => ['name' => $name, 'slug' => $user->username, 'description' => $bio],
            'author' => [
                'id' => $user->id,
                'name' => $name,
                'username' => $user->username,
                'initial' => mb_substr($name, 0, 1),
                'avatar_url' => $user->email === '' ? '' : avatar($user->email, 200),
                'bio' => $bio,
                'role' => Roles::label($user->role),
                'count' => $total,
            ],
            'categories' => $categories,
            'items' => $this->items($found, $page, $perPage, true),
            // 作者页只有页码、没有上/下页;计数两个断点都带「每页 N 篇」
            'pager' => $this->pager('author', ['slug' => $user->username], $page, $totalPages, $total, $perPage, true, false, 'numbers'),
        ]);
    }

    /**
     * @param array<string, string|int> $params
     * @return array<string, mixed>
     */
    private function date(array $params, int $page): array
    {
        $year = (int) ($params['year'] ?? 0);
        $month = (int) ($params['month'] ?? 0);

        if ($year < 1000 || $month < 1 || $month > 12) {
            return self::fail('date', 404, '日期归档不存在');
        }

        $perPage = $this->perPage();
        $query = $this->posts->query()->type('post')->status('publish')->inMonth(sprintf('%04d-%02d', $year, $month));

        if ($this->listingFull()) {
            $query->content();
        }

        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($page > $totalPages) {
            return self::fail('date', 404, '页码超出范围');
        }

        $found = $query->orderBy('published_at', 'desc')->limit($perPage)->page($page)->get();

        return self::ok('date', [
            'term' => ['name' => $year . ' 年 ' . $month . ' 月', 'slug' => '', 'description' => '这个月发出的 ' . $total . ' 篇。'],
            'items' => $this->items($found, $page, $perPage, false),
            // 日期页单页只留计数;计数两个断点都带「每页 N 篇」
            'pager' => $this->pager('date', ['year' => $year, 'month' => sprintf('%02d', $month)], $page, $totalPages, $total, $perPage, true, false, 'plain'),
        ]);
    }

    /**
     * @param array<string, string|int> $params
     * @return array<string, mixed>
     */
    private function cptArchive(array $params, int $page): array
    {
        $type = (string) ($params['type'] ?? '');
        $registry = PostTypeRegistry::instance();
        $meta = $type === '' ? null : $registry->get($type);

        // 与前台一致:非 public / 不做重写 / 没归档的类型没有前台地址
        if ($meta === null || !$meta['public'] || !$meta['has_archive'] || $registry->rewriteSlug($type) === null) {
            return self::fail('archive', 404, '内容类型没有前台归档');
        }

        $perPage = $this->perPage();
        $query = $this->posts->query()->type($type)->status('publish');

        if ($this->listingFull()) {
            $query->content();
        }

        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));

        if ($page > $totalPages) {
            return self::fail('archive', 404, '页码超出范围');
        }

        $found = $query->orderBy('published_at', 'desc')->limit($perPage)->page($page)->get();

        return self::ok('archive', [
            'term' => ['name' => (string) $meta['label'], 'slug' => $type, 'description' => ''],
            'items' => $this->items($found, $page, $perPage, false),
            'pager' => $this->pager(PostTypeRoutes::archiveName($type), [], $page, $totalPages, $total, $perPage, $totalPages > 1, true, 'full'),
        ]);
    }

    /**
     * @param array<string, string|int> $params
     * @return array<string, mixed>
     */
    private function search(array $params, int $page): array
    {
        $keyword = trim((string) ($params['q'] ?? ''));
        $found = $this->searcher->search($keyword, $page);
        $perPage = Searcher::PER_PAGE;
        $seq = ($found['page'] - 1) * $perPage;
        $thumbs = $this->thumbs(array_map(static fn (array $item): int => (int) ($item['thumbnail_id'] ?? 0), $found['items']));
        // 搜索项同样整页批量取作者:Searcher 不回 author_id,这里按 post id 收口
        $authors = $this->authors(array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $found['items']));

        $items = [];

        foreach ($found['items'] as $item) {
            $seq++;
            $slug = (string) ($item['slug'] ?? '');
            $item['num'] = str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
            $item['url'] = $slug === '' ? '' : $this->router->path('post', [
                'slug' => $slug,
                'post_id' => (int) ($item['id'] ?? 0),
                'type' => 'post',
                'published_at' => (string) ($item['published_at'] ?? ''),
            ]);
            $item['title_html'] = Searcher::highlight((string) ($item['title'] ?? ''), $found['keyword']);
            $item['excerpt_html'] = Searcher::highlight((string) ($item['excerpt'] ?? ''), $found['keyword']);
            $item['thumbnail'] = $thumbs[(int) ($item['thumbnail_id'] ?? 0)] ?? null;
            $item['author'] = $authors[(int) ($item['id'] ?? 0)] ?? self::EMPTY_AUTHOR;
            $items[] = $item;
        }

        // 无结果态两条建议的落点(既有规范/10 节):最近一篇文章的分类 / 所属月归档;给不了就 null
        $suggestCategory = null;
        $suggestDate = null;

        if ($items === [] && $found['keyword'] !== '') {
            $latest = $this->posts->query()->type('post')->status('publish')->orderBy('published_at', 'desc')->limit(1)->get()[0] ?? null;

            if ($latest instanceof Post) {
                $first = $this->terms->firstTermsFor([$latest->id], TermAssigner::CATEGORY)[$latest->id] ?? null;

                if ($first !== null) {
                    $suggestCategory = $this->router->path('category', ['slug' => (string) $first['slug']]);
                }

                $published = (string) $latest->published_at;

                if (strlen($published) >= 7) {
                    $suggestDate = $this->router->path('date', [
                        'year' => substr($published, 0, 4),
                        'month' => substr($published, 5, 2),
                    ]);
                }
            }
        }

        return self::ok('search', [
            'items' => $items,
            'pager' => $this->searchPager($found['keyword'], $found['page'], $found['pages'], $found['total'], $perPage),
            'search' => [
                'keyword' => $found['keyword'],
                'ms' => $found['ms'],
                'total' => $found['total'],
                'per_page' => $perPage,
                'suggest_category' => $suggestCategory,
                'suggest_date' => $suggestDate,
            ],
        ]);
    }

    /**
     * 列表行的统一形状:编号 / 标题 / 日期 / 评论数 / 特色图 / 浏览量 / 分类 / 作者。
     *
     * @param list<Post> $found
     * @return list<array<string, mixed>>
     */
    private function items(array $found, int $page, int $perPage, bool $withCategory): array
    {
        /** @var list<int> $ids */
        $ids = array_map(static fn (Post $post): int => $post->id, $found);
        $categories = $withCategory ? $this->terms->firstTermsFor($ids, TermAssigner::CATEGORY) : [];
        $thumbs = $this->thumbs($found);
        // 作者也整页批量取(既有规范红线),不逐行查
        $authors = $this->authors($ids);
        // 卡片上的评论预览同理:整页一次窗口查询,不逐篇查
        $previews = $this->comments->previewsFor($ids, self::COMMENT_PREVIEW_LIMIT);
        // 自动摘要要读正文,而列表查询不带 content —— 本页"没写摘要"的行按 id 补一次
        $bodies = $this->listingFull() ? [] : $this->bodies($found);
        $items = [];
        $seq = ($page - 1) * $perPage;

        foreach ($found as $post) {
            $seq++;
            $first = $categories[$post->id] ?? null;

            $items[] = [
                'id' => $post->id,
                // [朋友圈主题补丁 2026-10-25] 列表项带上内容类型：主题要按 type 分别渲染
                // 朋友圈式条目（moments）与普通文章行。纯增量，老主题不看这个键，零影响。
                'type' => $post->type,
                'num' => str_pad((string) $seq, 2, '0', STR_PAD_LEFT),
                'url' => $this->router->relative($post->url),
                'title' => $post->title,
                'published_at' => $post->published_at,
                'comments' => $post->comment_count,
                'comments_preview' => $previews[$post->id] ?? [],
                'thumbnail' => $thumbs[$post->thumbnail_id] ?? null,
                'views' => $post->view_count,
                'author' => $authors[$post->id] ?? self::EMPTY_AUTHOR,
                'category' => $first === null ? null : [
                    'name' => (string) $first['name'],
                    'slug' => (string) $first['slug'],
                    'url' => $this->router->path('category', ['slug' => (string) $first['slug']]),
                ],
            ] + $this->snippet($post, $bodies[$post->id] ?? null);
        }

        return $items;
    }

    /**
     * 每个列表项的 author 键:显示名 / 首字 / 头像 URL。
     *
     * 名与邮箱一次批量取这一页所有作者(列表不许 N+1);头像复用 Avatar 那套按邮箱取图的逻辑。
     * 取不到的行整组给空串(不编默认头像),键始终在。
     *
     * @param list<int> $postIds
     * @return array<int, array{name: string, initial: string, avatar_url: string, url: string}>  post_id => author
     */
    private function authors(array $postIds): array
    {
        $out = [];

        foreach ($this->users->postAuthors($postIds) as $postId => $row) {
            // 显示名优先、回落用户名;都没有给空串(与作者归档页同一口径)
            $name = $row['display_name'] !== '' ? $row['display_name'] : $row['username'];

            $out[$postId] = [
                'name' => $name,
                'initial' => $name === '' ? '' : mb_substr($name, 0, 1),
                'avatar_url' => $row['email'] === '' ? '' : avatar($row['email'], 48),
                // 作者归档地址(卡片/说说流的头像与昵称都链到这里;用户名即 slug)
                'url' => $row['username'] === '' ? '' : $this->router->path('author', ['slug' => $row['username']]),
            ];
        }

        return $out;
    }

    /**
     * 从缩略图取一个主色,给卡片封面当占位背景(对齐原版附件的 dominant_color)。
     * 优先 thumb → medium → large → 原图;缩到 12×12 后按 32 级色阶分桶,取像素最多的桶再求均值。
     *
     * 结果是纯函数式的(同路径同 mtime 必得同一颜色),所以落缓存:每张封面解码 + 缩放的代价
     * 远大于一次缓存读,列表页十张封面就是十次。
     *
     * @param array<string, mixed> $media
     */
    private function dominantColor(array $media): ?string
    {
        $file = '';

        foreach (['thumb', 'medium', 'large'] as $name) {
            $candidate = (string) ($media['sizes'][$name]['file'] ?? '');

            if ($candidate !== '') {
                $file = $candidate;
                break;
            }
        }

        if ($file === '') {
            $file = (string) ($media['file_path'] ?? '');
        }

        if ($file === '') {
            return null;
        }

        $path = $this->store->absolutePath($file);

        if (!is_file($path)) {
            return null;
        }

        // 路径 + mtime + 算法版本:文件一换 mtime 就变,键跟着变,旧颜色再也读不到
        $key = 'theme.cover.color.' . self::COLOR_VERSION . '.' . md5($path . '|' . (string) @filemtime($path));

        if (array_key_exists($key, self::$colorCache)) {
            return self::$colorCache[$key];
        }

        if ($this->cache !== null) {
            $miss = new \stdClass();
            $cached = $this->cache->get($key, $miss);

            if ($cached !== $miss) {
                // 解不出来的图当初也落了一条 null,照实返回,不再喂 GD
                return self::$colorCache[$key] = is_string($cached) ? $cached : null;
            }
        }

        $color = $this->computeDominantColor($path);

        if ($this->cache !== null) {
            $this->cache->set($key, $color, $color === null ? self::COLOR_TTL_BROKEN : self::COLOR_TTL);
        }

        return self::$colorCache[$key] = $color;
    }

    private function computeDominantColor(string $path): ?string
    {
        $raw = @file_get_contents($path);
        $image = $raw === false ? false : @imagecreatefromstring($raw);

        if ($image === false) {
            return null;
        }

        $small = @imagescale($image, 12, 12);
        imagedestroy($image);

        if ($small === false) {
            return null;
        }

        $buckets = [];

        for ($y = 0; $y < 12; $y++) {
            for ($x = 0; $x < 12; $x++) {
                $rgb = imagecolorat($small, $x, $y);

                // 透明像素不参与,避免 PNG 的透明边把颜色拉黑
                if ((($rgb >> 24) & 0x7F) > 100) {
                    continue;
                }

                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $bucket = (($r >> 5) << 6) | (($g >> 5) << 3) | ($b >> 5);
                $buckets[$bucket][] = [$r, $g, $b];
            }
        }

        imagedestroy($small);

        if ($buckets === []) {
            return null;
        }

        uasort($buckets, static fn (array $a, array $b): int => count($b) <=> count($a));
        $pick = reset($buckets);
        $count = max(1, count($pick));
        $r = $g = $b = 0;

        foreach ($pick as $pixel) {
            $r += $pixel[0];
            $g += $pixel[1];
            $b += $pixel[2];
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r / $count),
            (int) round($g / $count),
            (int) round($b / $count)
        );
    }

    /**
     * 一次批量取特色图:一页最多 100 篇,不给每行单开查询。
     *
     * @param list<Post|int> $source
     * @return array<int, array<string, mixed>> 键 = 附件本体 id
     */
    private function thumbs(array $source): array
    {
        $ids = [];

        foreach ($source as $entry) {
            $id = $entry instanceof Post ? $entry->thumbnail_id : (int) $entry;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->media->byPostIds($ids) as $postId => $media) {
            $image = MediaRepository::imageData($media, $this->store);

            if ($image !== null) {
                // 原版卡片占位色取附件的 dominant_color；墨林没有这项元数据,
                // 这里从缩略图算一个主色,模板拿不到时再回落 #CCCCCC。
                $image['color'] = $this->dominantColor($media);
                $out[$postId] = $image;
            }
        }

        return $out;
    }

    /**
     * 本页要补正文的行:写了摘要的不补,其余按 id 一次取回。
     *
     * @param list<Post> $found
     * @return array<int, string>
     */
    private function bodies(array $found): array
    {
        $ids = [];

        foreach ($found as $post) {
            if (trim((string) $post->excerpt) === '') {
                $ids[] = $post->id;
            }
        }

        return $ids === [] ? [] : $this->posts->contentsFor($ids);
    }

    /**
     * 行内摘要:全文模式取渲染后的正文;
     * 摘要模式优先用作者写的摘要字段 —— 没写就从正文取一段($body 由 bodies() 补)。
     * (不兜底的话,导入的老文章没有摘要字段,列表行就是一行空白,主题再想截字也没得截。)
     *
     * @return array{excerpt: string, excerpt_html: bool}
     */
    private function snippet(Post $post, ?string $body = null): array
    {
        if ($this->listingFull()) {
            return [
                'excerpt' => (string) $this->contentRenderer->render($post, ContentContext::MODE_FULL, true)->content,
                'excerpt_html' => true,
            ];
        }

        $excerpt = trim((string) $post->excerpt);

        if ($excerpt === '') {
            // 与 Feed::summary 同一套取法:原始正文去标签收白,再截字。
            // 正文里的 `&quot;` 这类实体必须先还原成字面量,否则模板自动转义会把 & 再转一次,
            // 列表上就会露出 `&amp;quot;` 这块原文
            $plain = html_entity_decode(strip_tags((string) $body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $excerpt = self::clip((string) preg_replace('/\s+/u', ' ', $plain));
        }

        return ['excerpt' => $excerpt, 'excerpt_html' => false];
    }

    /** 截到 AUTO_EXCERPT_CHARS 字,超了补省略号(主题还能在这基础上按自己的「摘要字数」再截短) */
    private static function clip(string $text): string
    {
        $text = trim($text);

        if (mb_strlen($text, 'UTF-8') <= self::AUTO_EXCERPT_CHARS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::AUTO_EXCERPT_CHARS, 'UTF-8')) . '…';
    }

    private function listingFull(): bool
    {
        return (string) $this->options->get('list_display', 'excerpt') === 'full';
    }

    /**
     * 归档分页条的数据,三种版式按各页设计稿走(见 index.php 同款实现)。
     *
     * @param array<string, string|int> $params 路由参数(slug 或 year+month)
     * @return array{prev: ?string, next: ?string, pages: list<array{n: int, url: string, current: bool}>, count: string, count_compact: string}
     */
    private function pager(string $routeName, array $params, int $page, int $totalPages, int $total, int $perPage, bool $withPerPage, bool $compactOnMobile, string $style): array
    {
        $count = $withPerPage ? '共 ' . $total . ' 篇 · 每页 ' . $perPage . ' 篇' : '共 ' . $total . ' 篇';
        $compact = $compactOnMobile ? '共 ' . $total . ' 篇' : $count;
        $base = ['prev' => null, 'next' => null, 'pages' => [], 'count' => $count, 'count_compact' => $compact];

        // 第 1 页回到不带后缀的地址,与 home / home.page 同一套写法
        $link = fn (int $n): string => $n <= 1
            ? $this->router->path($routeName, $params)
            : $this->router->path($routeName . '.page', $params + ['page' => $n]);

        if ($totalPages <= 1) {
            if ($style !== 'full') {
                return $base;
            }

            return [
                'prev' => $link(1),
                'next' => null,
                'pages' => [['n' => 1, 'url' => $link(1), 'current' => true]],
                'count' => $count,
                'count_compact' => $compact,
            ];
        }

        $pages = [];

        for ($n = 1; $n <= $totalPages; $n++) {
            $pages[] = ['n' => $n, 'url' => $link($n), 'current' => $n === $page];
        }

        if ($style === 'numbers') {
            return ['prev' => null, 'next' => null, 'pages' => $pages, 'count' => $count, 'count_compact' => $compact];
        }

        return [
            'prev' => $link(max(1, $page - 1)),
            // 末页没有「下一页」
            'next' => $page < $totalPages ? $link($page + 1) : null,
            'pages' => $pages,
            'count' => $count,
            'count_compact' => $compact,
        ];
    }

    /**
     * 搜索分页:地址是 `/search?q=...&page=N`,与整页同一口径(`path()` 生成)。
     *
     * @return array{prev: ?string, next: ?string, pages: list<array{n: int, url: string, current: bool}>, count: string, count_compact: string}
     */
    private function searchPager(string $keyword, int $page, int $pages, int $total, int $perPage): array
    {
        $base = $this->router->path('search');

        if ($keyword !== '') {
            $base .= '?q=' . rawurlencode($keyword);
        }

        $link = static fn (int $n): string => $n <= 1 ? $base : $base . ($keyword === '' ? '?' : '&') . 'page=' . $n;
        $list = [];

        for ($n = 1; $n <= $pages; $n++) {
            $list[] = ['n' => $n, 'url' => $link($n), 'current' => $n === $page];
        }

        return [
            'prev' => $page > 1 ? $link($page - 1) : null,
            'next' => $page < $pages ? $link($page + 1) : null,
            'pages' => $list,
            'count' => '共 ' . $total . ' 篇 · 每页 ' . $perPage . ' 篇',
            'count_compact' => '共 ' . $total . ' 篇',
        ];
    }

    /**
     * 成功结果:补齐未给键,保证形状恒定(调用方与模板不必做 defined 猜谜)。
     *
     * @param array<string, mixed> $parts
     * @return array<string, mixed>
     */
    private static function ok(string $context, array $parts): array
    {
        return $parts + [
            'status' => 200,
            'error' => '',
            'context' => $context,
            'term' => null,
            'author' => null,
            'items' => [],
            'pager' => ['prev' => null, 'next' => null, 'pages' => [], 'count' => '', 'count_compact' => ''],
            'posts' => [],
            'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 0, 'total_pages' => 1],
            'search' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function fail(string $context, int $status, string $message): array
    {
        return self::ok($context, ['status' => $status, 'error' => $message]);
    }
}
