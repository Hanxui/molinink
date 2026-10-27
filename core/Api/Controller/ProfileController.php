<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Api\Controller;

use Molin\Api\ApiSupport;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Logger\Audit;
use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Search\Searcher;
use Molin\User\Avatar;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 站点与当前用户的端点(《25》§3.2、§3.3):健康检查、站点信息、搜索、我的资料。
 */
final class ProfileController extends ApiSupport
{
    public function __construct(
        Options $options,
        Router $router,
        private readonly UserRepository $users,
        private readonly Searcher $searcher,
        private readonly MediaRepository $media,
        private readonly Store $store
    ) {
        parent::__construct($options, $router);
    }

    public function health(): Response
    {
        return $this->ok(['data' => ['status' => 'ok', 'time' => gmdate('c')]]);
    }

    public function site(): Response
    {
        return $this->ok(['data' => [
            'name' => (string) $this->options->get('site_name', ''),
            'description' => (string) $this->options->get('site_description', ''),
            'url' => (string) $this->options->get('site_url', ''),
        ]]);
    }

    public function me(?User $user): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        return $this->ok(['data' => $this->profile($user)]);
    }

    /**
     * 当前用户的自述资料。`site_name` / `avatar_url` 是 App 资料页直接要用的字段
     * (《40》第 3 节),所以在 `/me` 与登录响应里都带上。
     *
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        // 头像优先读 usermeta.avatar(存的是 media.id,《21》§5);拿到并确认为图片才用,
        // 否则回退到旧的 email 派生逻辑(默认/ Gravatar)。
        $avatarUrl = null;
        $avatarMeta = (string) ($this->users->meta($user->id)['avatar'] ?? '');

        if ($avatarMeta !== '') {
            $media = $this->media->find((int) $avatarMeta);

            if ($media !== null && str_starts_with((string) $media['mime_type'], 'image/')) {
                $avatarUrl = $this->absoluteUrl($this->store->url((string) $media['file_path']));
            }
        }

        if ($avatarUrl === null) {
            $avatarUrl = $this->absoluteUrl(Avatar::instance()->url($user->email, 96));
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'capabilities' => $user->capabilities(),
            'avatar_url' => $avatarUrl,
            'site_name' => (string) $this->options->get('site_name', ''),
            'site_url' => (string) $this->options->get('site_url', ''),
        ];
    }

    public function search(Request $request): Response
    {
        $keyword = trim((string) $request->query('q', ''));
        $result = $this->searcher->search($keyword, $request->queryInt('page', 1));

        $page = (int) $result['page'];
        $pages = (int) $result['pages'];

        return $this->ok([
            'data' => array_map(static fn (array $item): array => [
                'id' => $item['id'],
                'title' => $item['title'],
                'slug' => $item['slug'],
                'excerpt' => $item['excerpt'],
                'published_at' => $item['published_at'],
                'comments' => $item['comments'],
            ], $result['items']),
            'meta' => [
                'page' => $page,
                'per_page' => Searcher::PER_PAGE,
                'total' => (int) $result['total'],
                'total_pages' => $pages,
            ],
            'links' => $this->links('search', $keyword === '' ? [] : ['q' => $keyword], $page, $pages),
        ]);
    }

    /**
     * `PUT /api/v1/me` —— 改自己的资料。当前只开放头像:`avatar_url` 是刚上传的媒体地址,
     * 这里把它还原成 media.id 记进 `usermeta.avatar`(《21》§5 的口径:存 id 不存 URL)。
     */
    public function updateProfile(Request $request, ?User $user, bool $viaSession): Response
    {
        if ($user === null) {
            return $this->fail('unauthorized', '未认证', 401);
        }

        if (($guard = $this->guardSession($request, $viaSession)) !== null) {
            return $guard;
        }

        $body = $request->json();

        if (!array_key_exists('avatar_url', $body)) {
            return $this->fail('validation_failed', '没有可更新的字段(当前支持 avatar_url)', 422, [
                'avatar_url' => ['必填'],
            ]);
        }

        $reference = trim((string) $body['avatar_url']);
        $record = $reference === '' ? null : $this->media->findByPath($reference);

        if ($record === null) {
            return $this->fail('validation_failed', '头像附件不存在或已删除,请先上传', 422, [
                'avatar_url' => ['找不到对应附件'],
            ]);
        }

        if (!str_starts_with((string) $record['mime_type'], 'image/')) {
            return $this->fail('validation_failed', '头像必须是图片', 422, ['avatar_url' => ['不是图片']]);
        }

        $this->users->setMeta($user->id, 'avatar', (string) $record['id']);
        Audit::record('user.profile', 'user:' . $user->id . ' avatar:' . $record['id'], true, $request, 'user:' . $user->id);

        $fresh = $this->users->find($user->id) ?? $user;

        return $this->ok(['data' => $this->profile($fresh)]);
    }
}
