<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\User;

use Molin\Media\MediaRepository;
use Molin\Media\Store;
use Molin\Option\Options;

/**
 * 头像解析(《31》第 7 节)。头像源是唯一开关,取值四档:
 *
 *   `upload`  用自己上传的头像(usermeta.avatar 存附件 id);没传过直接落本地字母,不往别的源退;
 *   `gravatar` Gravatar 外链,按邮箱哈希;
 *   `custom`   用模板 avatar_source_url(占位符 {hash} = md5(strtolower(trim(email)))),模板空则落本地字母;
 *   `local`    本地用户名首字母 SVG,data URI,零外部请求。
 *
 * 头像源取用户级 usermeta.avatar_source,未设置回退站点 option avatar_source(旧数据兼容);
 * custom 的模板同样用户级优先、站点兜底。旧 usermeta.gravatar(值为 '0' 表示当年主动关过)
 * 仍读取以向后兼容,直接落本地字母。
 *
 * 默认档是 `local`(本地首字母,data URI,零外部请求):Gravatar 在国内常年连不上或要等
 * 好几秒,而头像是每页都要出的图,挂在外部域名上等于给首屏加一个不可控的等待。
 * 想用 Gravatar 的站自己把头像源改成 gravatar 即可,不受默认值影响。
 *
 * 主题与后台同走 `avatar(email, size)`(`core/helpers.php`),一个邮箱地址就能出图。
 */
final class Avatar
{
    private static ?self $instance = null;

    /** @var array<string, array{user:?User, meta:array<string,string>}> 邮箱 => 已解析的用户与 meta;同屏一个邮箱只查一次 */
    private array $memo = [];

    public function __construct(
        private readonly ?UserRepository $users = null,
        private readonly ?MediaRepository $media = null,
        private readonly ?Store $store = null,
        private readonly ?Options $options = null
    ) {
    }

    public static function boot(self $avatar): void
    {
        self::$instance = $avatar;
    }

    /**
     * 没 boot 过(孤立渲染、测试手工装配)按降级实例走:头像源或本地 SVG,不碰数据库。
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function url(string $email, int $size = 48): string
    {
        $email = trim($email);
        $size = max(1, min(512, $size));

        $meta = $email === '' ? [] : $this->metaOf($email);

        // 只有头像源是 `upload` 才用自己上传的那张;没传过就落本地字母,不再往别的源退
        if ($this->avatarSource($meta) === 'upload') {
            $custom = $this->customUrl($email, $size);

            return $custom ?? $this->local($this->letterSource($email));
        }

        // 旧的按用户开关已从界面移除:'0' = 当年主动关掉外部头像,尊重它,直接落本地字母
        if (($meta['gravatar'] ?? '') !== '0') {
            // 头像源:自定义源优先于 Gravatar;源本身解析为空(选了 local、或模板没填)就让位给本地字母
            $source = $this->sourceUrl($email, $size);

            if ($source !== null && $source !== '') {
                return $source;
            }
        }

        return $this->local($this->letterSource($email));
    }

    /**
     * 自定义头像的地址;没设过、或那条附件不是图片就 null(资料页预览用)。
     */
    public function customUrl(string $email, int $size = 48): ?string
    {
        $email = trim($email);
        $size = max(1, min(512, $size));

        if ($email === '' || $this->store === null) {
            return null;
        }

        $meta = $this->metaOf($email);
        $record = isset($meta['avatar']) && $meta['avatar'] !== ''
            ? $this->media?->find((int) $meta['avatar'])
            : null;

        if ($record === null || !str_starts_with((string) $record['mime_type'], 'image/')) {
            return null;
        }

        return $this->fileUrl($record, $size, $this->store);
    }

    /**
     * 第②档「头像源」的地址:空邮箱给 null。
     *
     * `gravatar` → Gravatar 外链;`custom` → 用模板替换 {hash}(模板空则 '' 让位给本地字母);
     * `local` → '' 直接落本地字母 SVG,不走外部请求。返回 '' 即"本档不产图"。
     * `upload` 在 url() 里先分岔走完,到不了这一档。
     */
    private function sourceUrl(string $email, int $size): ?string
    {
        if ($email === '') {
            return null;
        }

        $meta = $this->metaOf($email);
        $source = $this->avatarSource($meta);

        if ($source === 'local') {
            return '';
        }

        $hash = md5(strtolower($email));

        if ($source === 'custom') {
            $template = trim((string) ($meta['avatar_source_url'] ?? ''));

            if ($template === '') {
                $template = trim((string) ($this->options?->get('avatar_source_url', '') ?? ''));
            }

            return $template === '' ? '' : str_replace('{hash}', $hash, $template);
        }

        return 'https://www.gravatar.com/avatar/' . $hash . '?s=' . $size . '&d=mp';
    }

    /**
     * 头像源:用户级 usermeta.avatar_source 优先,未设置回退站点 option(旧数据兼容);
     * 值不在合法集合里(脏数据)按 local 处理。
     *
     * @param array<string, string> $meta
     */
    private function avatarSource(array $meta): string
    {
        $source = (string) ($meta['avatar_source'] ?? '');

        if ($source === '') {
            $source = (string) ($this->options?->get('avatar_source', 'local') ?? 'local');
        }

        return in_array($source, ['gravatar', 'custom', 'local', 'upload'], true) ? $source : 'local';
    }

    /**
     * @param array<string, mixed> $record
     */
    private function fileUrl(array $record, int $size, Store $store): string
    {
        $best = null;

        foreach ((array) $record['sizes'] as $variant) {
            if (!is_array($variant) || !isset($variant['file'], $variant['width'])) {
                continue;
            }

            $width = (int) $variant['width'];

            if ($width >= $size && ($best === null || $width < (int) $best['width'])) {
                $best = $variant;
            }
        }

        return $store->url((string) ($best['file'] ?? $record['file_path']));
    }

    /**
     * 第③档的取字来源:用户名 → 昵称 → 邮箱,都没有给 '?'。
     */
    private function letterSource(string $email): string
    {
        $user = $email === '' ? null : $this->userOf($email);
        $candidate = $user === null ? '' : $user->username;

        if ($candidate === '') {
            $candidate = $user === null ? '' : $user->display_name;
        }

        if ($candidate === '') {
            $candidate = $email;
        }

        return $candidate === '' ? '?' : $candidate;
    }

    private function local(string $source): string
    {
        $letter = mb_strtoupper(mb_substr($source, 0, 1, 'UTF-8'), 'UTF-8');
        $letter = htmlspecialchars($letter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96">'
            . '<rect width="96" height="96" fill="#DEDCD6"/>'
            . '<text x="48" y="52" text-anchor="middle" dominant-baseline="middle"'
            . ' font-family="Georgia,serif" font-size="40" fill="#1B1C1E">' . $letter . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * @return array<string, string>
     */
    private function metaOf(string $email): array
    {
        return $this->resolve($email)['meta'];
    }

    private function userOf(string $email): ?User
    {
        return $this->resolve($email)['user'];
    }

    /**
     * @return array{user:?User, meta:array<string,string>}
     */
    private function resolve(string $email): array
    {
        if (array_key_exists($email, $this->memo)) {
            return $this->memo[$email];
        }

        $user = $this->users?->findByEmail($email);
        $meta = $user === null ? [] : ($this->users?->meta($user->id) ?? []);

        return $this->memo[$email] = ['user' => $user, 'meta' => $meta];
    }
}
