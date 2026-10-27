<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin\Transfer;

use Molin\Admin\WebAuth;
use Molin\Content\TermAssigner;
use Molin\Database\Database;
use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\User;
use XMLWriter;

/**
 * 导出侧:把 posts / categories / tags / comments 收成通用结构(`molin_export: 1`),
 * JSON 或 XML 落盘到 storage/exports/,签发一次性限时下载令牌;另管导出件的下载与删除。
 * 序列化结果即对外格式 —— toJson() / toXml() 一个字符都不许动,改了旧程序就读不回来。
 */
final class Exporter
{
    public function __construct(
        private readonly Database $db,
        private readonly Options $options,
        private readonly Router $router,
        private readonly ExportStore $store,
        private readonly DownloadTokens $tokens,
        private readonly TransferPage $page
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function export(Request $request, User $user, array $menu): Response
    {
        if (!Toolkit::csrfOk($request)) {
            return $this->page->render($menu, ['error' => __(WebAuth::STALE)], 403);
        }

        $scope = in_array($request->input('scope'), ['post', 'page'], true) ? $request->input('scope') : 'all';
        $format = $request->input('format') === 'xml' ? 'xml' : 'json';
        $withComments = $request->input('comments') === '1';
        // 含媒体本轮只登记引用、不打包(tar.gz 打包规格未定:既有规范未列此项;既有规范只点了「含媒体打包 tar.gz」,未给包内清单),故不改变落盘格式
        $withMedia = $request->input('media') === 'with';

        $data = $this->collect($scope, $withComments, $withMedia);
        $stamp = gmdate('YmdHis');
        $name = 'export-molin-' . $stamp . '.' . $format;
        $body = $format === 'xml' ? $this->toXml($data) : $this->toJson($data);
        $dir = $this->store->exportDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($dir . '/' . $name, $body);
        $this->store->pruneExports();
        Audit::record('export.create', $name, true, $request, 'user:' . $user->id);

        return $this->page->render($menu, ['export_result' => [
            'filename' => $name,
            'size_text' => Toolkit::sizeText(strlen($body)),
            'download_url' => $this->router->path('admin', ['path' => 'import-export/download/' . $this->tokens->issueToken($name)]),
        ]]);
    }

    public function download(Request $request, User $user, string $token): Response
    {
        $name = $this->tokens->consumeToken($token);
        $file = $name === null ? null : $this->store->exportDir() . '/' . $name;

        if ($name === null || $file === null || !is_file($file)) {
            throw new NotFoundException('下载链接已过期或用过了，回去重新生成一个。');
        }

        Audit::record('export.download', $name, true, $request, 'user:' . $user->id);

        return Response::download($file, $name);
    }

    /**
     * 删掉一份导出文件。名字只认 EXPORT_FILENAME,并且解析出来的真实路径必须落在导出目录里 ——
     * 不拿请求参数直接拼路径(那是路径穿越)。
     */
    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function deleteExport(Request $request, User $user, array $menu): Response
    {
        if (!Toolkit::csrfOk($request)) {
            return $this->page->render($menu, ['error' => __(WebAuth::STALE)], 403);
        }

        $name = trim($request->input('name'));
        $path = $name !== '' ? $this->store->exportPath($name) : null;

        if ($path === null) {
            return $this->page->render($menu, ['error' => __('找不到这份导出文件，可能已经被自动清理了。')], 404);
        }

        @unlink($path);
        $this->store->pruneExports();
        Audit::record('export.delete', $name, true, $request, 'user:' . $user->id);

        return Response::redirect($this->router->path('admin', ['path' => 'import-export']) . '?done=deleted');
    }

    /**
     * 收导出结构:文章 / 页面、分类、标签、评论(可选)、媒体引用(可选)。
     *
     * @return array<string, mixed>
     */
    private function collect(string $scope, bool $withComments, bool $withMedia): array
    {
        $types = $scope === 'post' ? ['post'] : ($scope === 'page' ? ['page'] : ['post', 'page']);
        $marks = [];
        $params = [];

        foreach ($types as $index => $type) {
            $marks[] = ':t' . $index;
            $params['t' . $index] = $type;
        }

        $rows = $this->db->select(
            'SELECT id, type, status, author_id, title, slug, excerpt, content, published_at, created_at, updated_at'
            . ' FROM ' . $this->db->table('posts')
            . ' WHERE deleted_at IS NULL AND type IN (' . implode(', ', $marks) . ') ORDER BY id',
            $params
        );

        // 每篇的归类一次取回(既有规范的 post 条目挂的是 slug):posts ↔ term_relationships
        // ↔ term_taxonomy ↔ terms,按 post_id 在内存里分组。逐篇各查一次 = 几千次查询,导不起。
        $links = $this->db->select(
            'SELECT r.post_id AS post_id, tt.taxonomy AS taxonomy, t.slug AS slug'
            . ' FROM ' . $this->db->table('term_relationships') . ' r'
            . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = r.term_taxonomy_id'
            . ' JOIN ' . $this->db->table('terms') . ' t ON t.term_id = tt.term_id'
            . ' WHERE tt.taxonomy IN (:category, :tag)'
            . ' ORDER BY r.post_id, r.order, t.name',
            ['category' => TermAssigner::CATEGORY, 'tag' => TermAssigner::TAG]
        );

        $linksByPost = [];

        foreach ($links as $link) {
            $linksByPost[(int) $link['post_id']][(string) $link['taxonomy']][] = (string) $link['slug'];
        }

        $posts = [];

        foreach ($rows as $row) {
            $postId = (int) $row['id'];

            $posts[] = [
                'id' => $postId,
                'type' => (string) $row['type'],
                'status' => (string) $row['status'],
                'author' => (string) $row['author_id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'excerpt' => (string) $row['excerpt'],
                'content' => (string) $row['content'],
                'published_at' => (string) ($row['published_at'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
                // 没有归类给空数组,不给 null、不省键 —— 导入侧才能一视同仁
                'categories' => $linksByPost[$postId][TermAssigner::CATEGORY] ?? [],
                'tags' => $linksByPost[$postId][TermAssigner::TAG] ?? [],
            ];
        }

        $termRows = $this->db->select(
            'SELECT t.name AS name, t.slug AS slug, tt.taxonomy AS taxonomy'
            . ' FROM ' . $this->db->table('terms') . ' t'
            . ' JOIN ' . $this->db->table('term_taxonomy') . ' tt ON tt.term_id = t.term_id'
            . ' ORDER BY t.name'
        );
        $categories = [];
        $tags = [];

        foreach ($termRows as $row) {
            $entry = ['name' => (string) $row['name'], 'slug' => (string) $row['slug']];

            if ((string) $row['taxonomy'] === TermAssigner::TAG) {
                $tags[] = $entry;
            } else {
                $categories[] = $entry;
            }
        }

        $comments = [];

        if ($withComments) {
            foreach ($this->db->select(
                'SELECT id, post_id, author_name, author_email, content, status, created_at'
                . ' FROM ' . $this->db->table('comments') . ' ORDER BY id'
            ) as $row) {
                $comments[] = [
                    'id' => (int) $row['id'],
                    'post_id' => (int) $row['post_id'],
                    'author_name' => (string) $row['author_name'],
                    'author_email' => (string) $row['author_email'],
                    'content' => (string) $row['content'],
                    'status' => (string) $row['status'],
                    'created_at' => (string) $row['created_at'],
                ];
            }
        }

        $media = [];

        if ($withMedia) {
            foreach ($this->db->select('SELECT id, file_name, file_path FROM ' . $this->db->table('media') . ' ORDER BY id') as $row) {
                $media[] = [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['file_name'],
                    'path' => (string) $row['file_path'],
                ];
            }
        }

        return [
            'molin_export' => 1,
            'generated_at' => gmdate('c'),
            'site_name' => (string) $this->options->get('site_name', '墨林'),
            'posts' => $posts,
            'categories' => $categories,
            'tags' => $tags,
            'comments' => $comments,
            'media' => $media,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toJson(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * XML 参考 WXR 思路的极简版:一层条目 + 字段子节点,便于旧程序读。
     * 标量列表(如每篇的 categories / tags)写法为 `<字段名><item>值</item>…</字段名>`,
     * 与 section 内条目复用同一个 `<item>` 记号;空列表落成 `<字段名/>`。嵌套对象不在这里写。
     *
     * @param array<string, mixed> $data
     */
    private function toXml(array $data): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('molin-export');
        $writer->writeAttribute('version', '1');
        $writer->writeElement('generated_at', (string) $data['generated_at']);
        $writer->writeElement('site_name', (string) $data['site_name']);

        foreach (['posts', 'categories', 'tags', 'comments', 'media'] as $section) {
            $writer->startElement($section);

            foreach ((array) ($data[$section] ?? []) as $item) {
                $writer->startElement('item');

                foreach ((array) $item as $key => $value) {
                    if (is_array($value)) {
                        $writer->startElement((string) $key);

                        foreach ($value as $entry) {
                            if (is_scalar($entry) || $entry === null) {
                                $writer->writeElement('item', (string) $entry);
                            }
                        }

                        $writer->endElement();

                        continue;
                    }

                    if (is_scalar($value) || $value === null) {
                        $writer->writeElement((string) $key, (string) $value);
                    }
                }

                $writer->endElement();
            }

            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }
}
