<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Transfer;

use Molin\Admin\WebAuth;
use Molin\Content\HtmlSanitizer;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Option\Options;
use Molin\User\User;

/**
 * 导入侧:只读文件数据,永不执行其中代码;校验扩展名与版本标记后按 100 条一批写入,
 * 同名按 slug 冲突策略处理,作者匹配不上归当前管理员,媒体路径含 `../` 一律拒绝。
 */
final class ImportReader
{
    /** 导入批处理大小 */
    private const BATCH = 100;

    /** 导入允许的冲突策略 */
    private const STRATEGIES = ['create', 'overwrite', 'skip', 'merge'];

    /**
     * 导出结构里的列表字段(既有规范的 categories / tags 是字符串数组)。
     * XML 侧靠它把空的 `<字段名/>` 还原成空数组 —— 光看元素分不出「空列表」与「空标量」。
     */
    private const LIST_FIELDS = ['categories', 'tags'];

    /** 可写入的正文状态;其余一律回落草稿(既有规范的安全档) */
    private const SAFE_STATUSES = ['publish', 'draft', 'pending', 'private'];

    public function __construct(
        private readonly Database $db,
        private readonly Options $options,
        private readonly TransferPage $page
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function import(Request $request, User $user, array $menu): Response
    {
        if (!Toolkit::csrfOk($request)) {
            return $this->page->render($menu, ['error' => __(WebAuth::STALE)], 403);
        }

        $strategy = in_array($request->input('strategy'), self::STRATEGIES, true) ? $request->input('strategy') : 'create';
        $files = $request->uploads('file');
        $file = $files[0] ?? null;

        if ($file === null || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return $this->page->render($menu, ['error' => __('没有收到文件，先选一个导出文件再开始。')]);
        }

        $ext = Toolkit::extensionOf((string) $file['name']);

        if (!in_array($ext, ['json', 'xml', 'tar.gz'], true)) {
            return $this->page->render($menu, ['error' => __('只认 .json / .xml / .tar.gz 三种导出文件。')]);
        }

        if ($ext === 'tar.gz') {
            // tar.gz 打包规格未定(既有规范未列此项;既有规范只点了「含媒体打包 tar.gz」,未给包内清单),不自造 —— 只解读 JSON 与 XML
            return $this->page->render($menu, ['error' => __('tar.gz 的打包解析待接入，本轮先支持 JSON 与 XML 导入。')]);
        }

        $path = (string) $file['tmp_name'];

        // 读进内存之前先按字节挡一道:整份文件要进 json_decode / DOM,几百 MB 就能把 PHP 撑死 ——
        // 而那多半是传错了文件,不是真来导入的。
        $size = @filesize($path);

        if ($size === false) {
            return $this->page->render($menu, ['error' => __('读不到这个文件，重新选一次再开始。')]);
        }

        $limit = Toolkit::importLimitBytes($this->options);

        if ($size > $limit) {
            return $this->page->render($menu, ['error' => __(
                '这份文件 %1$s，超过了导入上限 %2$s —— 上限跟站点设置里的「上传大小上限」同源，要导就先把它调大。',
                'molin',
                Toolkit::sizeText($size),
                Toolkit::sizeText($limit)
            )]);
        }

        $payload = $ext === 'xml'
            ? $this->fromXml((string) @file_get_contents($path))
            : json_decode((string) @file_get_contents($path), true);

        if (!is_array($payload) || !isset($payload['molin_export'])) {
            return $this->page->render($menu, ['error' => __('文件里没有墨林的导出标记，不是墨林的导出包。')]);
        }

        $posts = is_array($payload['posts'] ?? null) ? array_values($payload['posts']) : [];
        $total = count($posts);
        $processed = 0;
        $fails = [];
        $line = 0;
        $authorMap = $this->authorMap();
        // 顶层 categories / tags 段是导出包里唯一带显示名的地方:收成 slug => 名字,
        // 新建词条时才不会把 slug 当成名字 —— 导进全新空站正是这条路的正用法。
        $termNames = [
            TermAssigner::CATEGORY => $this->termNameMap($payload['categories'] ?? null),
            TermAssigner::TAG => $this->termNameMap($payload['tags'] ?? null),
        ];

        foreach (array_chunk($posts, self::BATCH) as $batch) {
            foreach ($batch as $item) {
                $line++;

                if (!is_array($item)) {
                    $fails[] = ['line' => $line, 'msg' => __('条目不是对象，跳过'), 'do' => __('已拒绝')];
                    continue;
                }

                $rejected = false;

                foreach ((array) ($item['media'] ?? []) as $mediaPath) {
                    if (str_contains((string) $mediaPath, '..')) {
                        $fails[] = ['line' => $line, 'msg' => __('媒体路径含 ../，拒绝写入'), 'do' => __('已拒绝')];
                        $rejected = true;
                        break;
                    }
                }

                if ($rejected) {
                    continue;
                }

                $this->importPost($item, $strategy, $user, $authorMap, $line, $fails, $termNames);
                $processed++;
            }
        }

        Audit::record('import.run', $file['name'] . ' / ' . $processed . ' 条', true, $request, 'user:' . $user->id);

        return $this->page->render($menu, [
            'picked' => [
                'name' => (string) $file['name'],
                'size_text' => Toolkit::sizeText((int) $file['size']),
            ],
            'import_result' => [
                'processed' => $processed,
                'total' => $total,
                'percent' => $total === 0 ? 0 : (int) round($processed / $total * 100),
                'fail_count' => count($fails),
                'fails' => $fails,
            ],
        ]);
    }

    /**
     * toXml() 的逆运算:把墨林导出 XML 还原成与 JSON 导入同形的数组,下游导入逻辑一行不改。
     *
     * 根元素不是 `molin-export`(或 XML 不良构)一律返回 null,交给导入流程按"不是墨林的导出包"处理。
     * `item` 的子元素名即字段名、文本即字符串值;空元素给空串。
     * 列表字段(见 LIST_FIELDS)里有 `<item>` 子元素时还原成字符串数组(空元素给空数组);
     * 除此之外的非标量嵌套(`meta`、每篇 `comments`)仍不处理 —— 与 toXml() 同一边界。
     * 五个 section 一律收集为数组,缺的给空数组。
     *
     * @return array<string, mixed>|null
     */
    private function fromXml(string $xml): ?array
    {
        if (trim($xml) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;

        if (!$loaded || $root === null || $root->nodeName !== 'molin-export') {
            return null;
        }

        $data = [
            'molin_export' => 1,
            'generated_at' => '',
            'site_name' => '',
        ];

        $sections = ['posts', 'categories', 'tags', 'comments', 'media'];

        foreach ($sections as $section) {
            $data[$section] = [];
        }

        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $name = $node->nodeName;

            if ($name === 'generated_at' || $name === 'site_name') {
                $data[$name] = $node->textContent;
                continue;
            }

            if (!in_array($name, $sections, true)) {
                continue;
            }

            $items = [];

            foreach ($node->childNodes as $item) {
                if (!$item instanceof \DOMElement || $item->nodeName !== 'item') {
                    continue;
                }

                $fields = [];

                foreach ($item->childNodes as $field) {
                    if (!$field instanceof \DOMElement) {
                        continue;
                    }

                    $entries = [];

                    foreach ($field->childNodes as $child) {
                        if ($child instanceof \DOMElement && $child->nodeName === 'item') {
                            $entries[] = $child->textContent;
                        }
                    }

                    // 有 <item> 子元素 = 列表;空的也认(靠字段名判定,否则分不出「空列表」与「空标量」)
                    if ($entries !== [] || in_array($field->nodeName, self::LIST_FIELDS, true)) {
                        $fields[$field->nodeName] = $entries;

                        continue;
                    }

                    $fields[$field->nodeName] = $field->textContent;
                }

                $items[] = $fields;
            }

            $data[$name] = $items;
        }

        return $data;
    }

    /**
     * 写入一条导入内容。同名按 slug 判策略:新建另起别名、覆盖写回、跳过不动、合并保原内容。
     *
     * @param array<string, mixed> $item
     * @param array<string, int> $authorMap 原站作者名 => 本站 user id
     * @param list<array{line:int, msg:string, do:string}> $fails
     * @param array<string, array<string, string>> $termNames 分类法 => (slug => 显示名),顶层段给的
     */
    private function importPost(array $item, string $strategy, User $user, array $authorMap, int $line, array &$fails, array $termNames): void
    {
        $type = in_array($item['type'] ?? 'post', ['post', 'page'], true) ? (string) $item['type'] : 'post';
        $title = trim((string) ($item['title'] ?? ''));
        $content = (string) ($item['content'] ?? '');

        if ($title === '' && $content === '') {
            $fails[] = ['line' => $line, 'msg' => __('标题与正文都空，跳过'), 'do' => __('已拒绝')];

            return;
        }

        $status = (string) ($item['status'] ?? 'draft');
        // 落库前一律过白名单(2026-10-17 修):导入链路此前直接 insert('posts'),绕过了 PostWriter 的
        // HtmlSanitizer —— 从旧站搬来的 <script>/onerror 会原样入库,而前台正文是 |raw 输出。
        // "管理员点的导入"不是免洗理由:旧站被人塞过什么,搬的人不知道。
        $content = HtmlSanitizer::clean($content);
        $status = in_array($status, self::SAFE_STATUSES, true) ? $status : 'draft';
        $slug = trim((string) ($item['slug'] ?? ''));
        $slug = $slug === '' ? $this->slugify($title, $type) : $this->slugify($slug, $type);
        $authorId = $this->resolveAuthor((string) ($item['author'] ?? ''), $authorMap, $user);
        $now = gmdate('Y-m-d H:i:s');

        $existing = $slug === '' ? null : $this->db->selectOne(
            'SELECT id FROM ' . $this->db->table('posts') . ' WHERE type = :type AND slug = :slug LIMIT 1',
            ['type' => $type, 'slug' => $slug]
        );

        if ($existing !== null && $strategy === 'skip') {
            return;
        }

        if ($existing !== null && $strategy === 'overwrite') {
            $this->db->update('posts', [
                'title' => $title,
                'content' => $content,
                'excerpt' => trim((string) ($item['excerpt'] ?? '')),
                'status' => $status,
                'author_id' => $authorId,
                'updated_at' => $now,
            ], 'id = :id', ['id' => (int) $existing['id']]);
            $this->assignTerms((int) $existing['id'], $item, $termNames);
            $this->recountTerms((int) $existing['id']);

            return;
        }

        if ($existing !== null && $strategy === 'merge') {
            // 合并语义文档未定:保守做法 = 保留原内容,只补分类与标签
            $this->assignTerms((int) $existing['id'], $item, $termNames);
            $this->recountTerms((int) $existing['id']);

            return;
        }

        // 新建;撞名时另起一个别名
        $excerpt = trim((string) ($item['excerpt'] ?? ''));

        $id = $this->db->insert('posts', [
            'type' => $type,
            'status' => $status,
            'author_id' => $authorId,
            'title' => $title,
            'slug' => $this->uniqueSlug($type, $slug),
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'content' => $content,
            'content_filtered' => null,
            'comment_status' => 'open',
            'published_at' => $status === 'publish' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assignTerms($id, $item, $termNames);
        $this->recountTerms($id);
    }

    /**
     * 的口径把 post 上的 categories / tags 落到关系表。
     * 数组里的值默认是 **slug**(`tech`);手写或旧文件可能给**显示名**(`技术`),
     * 故取值走「先 slug 后名字,都没有才新建」—— 见 termIdFor()。
     *
     * @param array<string, mixed> $item
     * @param array<string, array<string, string>> $termNames 分类法 => (slug => 显示名)
     */
    private function assignTerms(int $postId, array $item, array $termNames): void
    {
        $terms = new TermAssigner($this->db);
        $map = [TermAssigner::CATEGORY => 'categories', TermAssigner::TAG => 'tags'];

        foreach ($map as $taxonomy => $field) {
            $values = $item[$field] ?? null;

            if (!is_array($values)) {
                continue;
            }

            $names = $termNames[$taxonomy] ?? [];
            $ids = [];

            foreach ($values as $value) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $ids[] = $this->termIdFor($terms, $taxonomy, $value, $names);
                }
            }

            $terms->assign($postId, $taxonomy, array_values(array_unique($ids)));
        }
    }

    /**
     * 一个归类值 → term_taxonomy_id:先按 slug 找(`tech`),找不到再退回按名称找(`技术`,
     * 兼容手写 / 旧文件),两样都没有才新建 —— 免得把 slug 当成新名字又造一个重复词条。
     * 复用 TermAssigner 既有方法,不动 Term 侧。
     *
     * @param array<string, string> $names slug => 显示名(导出包顶层段给的真名),可空
     */
    private function termIdFor(TermAssigner $terms, string $taxonomy, string $value, array $names): int
    {
        $bySlug = $terms->findBySlug($taxonomy, $value);

        if ($bySlug !== null) {
            return $bySlug['term_taxonomy_id'];
        }

        $byName = $this->db->selectOne(
            'SELECT tt.term_taxonomy_id AS tt_id FROM ' . $this->db->table('terms') . ' t'
            . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_id = t.term_id'
            . ' WHERE t.name = :name AND tt.taxonomy = :tax LIMIT 1',
            ['name' => $value, 'tax' => $taxonomy]
        );

        if ($byName !== null) {
            return (int) $byName['tt_id'];
        }

        // ③ 真新建:有导出包给的真名就用「真名 + 原 slug」建,否则退回把值本身当名字(旧行为)。
        $name = $names[$value] ?? '';

        if ($name === '') {
            return $terms->findOrCreate($taxonomy, $value);
        }

        $termId = $this->db->insert('terms', ['name' => $name, 'slug' => $value]);

        return $terms->addTerm($taxonomy, $termId);
    }

    /**
     * 顶层 categories / tags 段 → slug => 显示名 字典。段非数组当空;项缺 name(或 name/slug 为空)跳过。
     *
     * @param mixed $section
     * @return array<string, string>
     */
    private function termNameMap(mixed $section): array
    {
        if (!is_array($section)) {
            return [];
        }

        $map = [];

        foreach ($section as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $slug = trim((string) ($entry['slug'] ?? ''));
            $name = trim((string) ($entry['name'] ?? ''));

            if ($slug === '' || $name === '') {
                continue;
            }

            $map[$slug] = $name;
        }

        return $map;
    }

    private function recountTerms(int $postId): void
    {
        $terms = new TermAssigner($this->db);

        foreach ([TermAssigner::CATEGORY, TermAssigner::TAG] as $taxonomy) {
            foreach ($terms->termsOf($postId, $taxonomy) as $term) {
                $terms->recount($term['term_taxonomy_id']);
            }
        }
    }

    /**
     * 作者映射:导出包里的作者名先按本站用户名匹配,匹配不上归当前管理员(既有规范④)。
     *
     * @param array<string, int> $authorMap
     */
    private function resolveAuthor(string $original, array $authorMap, User $user): int
    {
        $original = trim($original);

        return $original !== '' && isset($authorMap[$original]) ? $authorMap[$original] : $user->id;
    }

    /**
     * 本站用户名 => id(排序稳定,供作者映射下拉与导入匹配共用)。
     *
     * @return array<string, int>
     */
    private function authorMap(): array
    {
        $map = [];

        foreach ($this->db->select('SELECT id, username FROM ' . $this->db->table('users') . ' ORDER BY id') as $row) {
            $username = (string) $row['username'];

            if ($username !== '' && !isset($map[$username])) {
                $map[$username] = (int) $row['id'];
            }
        }

        return $map;
    }

    private function uniqueSlug(string $type, string $slug): string
    {
        $base = $slug === '' ? 'item' : $slug;

        for ($try = 1; $try <= 100; $try++) {
            $candidate = $try === 1 ? $base : $base . '-' . $try;
            $taken = $this->db->selectOne(
                'SELECT id FROM ' . $this->db->table('posts') . ' WHERE type = :type AND slug = :slug LIMIT 1',
                ['type' => $type, 'slug' => $candidate]
            );

            if ($taken === null) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(3));
    }

    private function slugify(string $value, string $type): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#\A[a-z0-9][a-z0-9-]*\z#', $value) === 1) {
            return $value;
        }

        $slug = strtolower($value);
        $slug = (string) preg_replace('#[^a-z0-9\p{Han}]+#u', '-', $slug);
        $slug = trim($slug, '-');

        return $slug === '' ? 'item-' . date('Ymd-His') : $slug;
    }
}
