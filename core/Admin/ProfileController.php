<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Exception\NotFoundException;
use Molin\Exception\ValidationException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Language\Translator;
use Molin\Logger\Audit;
use Molin\Media\Remover;
use Molin\Media\Upload;
use Molin\Media\Uploader;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Security\Password;
use Molin\User\Auth;
use Molin\User\Avatar;
use Molin\User\Roles;
use Molin\User\SessionStore;
use Molin\User\User;
use Molin\User\UserRepository;
use Throwable;

/**
 * 个人资料屏。
 *
 * 这页只改登录者自己的事:昵称、邮箱、头像、简介、界面语言、密码。
 * 不得出现改角色 / 改他人资料的入口 —— 那些是「用户」列表页的活。
 * 进页需 `read`,写操作带 CSRF 并记审计。
 */
final class ProfileController
{
    private const NOTICES = [
        'saved' => '资料已保存。',
        'avatar' => '头像已更新。',
    ];

    /** 头像来源的合法值:本地首字母(默认) / 自上传 / Gravatar / 自定义模板 */
    private const AVATAR_SOURCES = ['local', 'upload', 'gravatar', 'custom'];

    /**
     * `?err=` 的码 → 人话。上传失败一律带回执 ——
     * 失败静默跳回等于什么都没告诉人,作者实测过这一笔。
     */
    /**
     * 上传失败的人话(既有规范头像三档)。码由 uploadAvatar 带回来,文案集中在这一处。
     * 写字面量而不是常量表 —— 语言包提取按源码里的字面量扫,常量表它看不见。
     */
    private static function avatarError(string $code): string
    {
        return match ($code) {
            'nofile' => __('没有收到头像文件，重新选一张再传。'),
            'toolarge' => __('这张图太大了，超过本站允许的上限，压缩或缩小后再传。'),
            'type' => __('这个文件不能当头像（只收 JPG、PNG、GIF、WebP 图片），头像没有改。'),
            'failed' => __('头像上传没有完成，请再试一次。'),
            default => '',
        };
    }

    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly Avatar $avatar,
        private readonly Uploader $uploader,
        private readonly Remover $remover,
        private readonly Translator $translator,
        private readonly Options $options,
        private readonly SessionStore $sessions,
        private readonly Router $router
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if ($path === 'profile/avatar') {
            return $this->uploadAvatar($request, $user, $menu);
        }

        if ($path !== 'profile') {
            throw new NotFoundException('后台没有这个页面:' . $path);
        }

        if ($request->isPost()) {
            return $this->save($request, $user, $menu);
        }

        return $this->render(
            $user,
            $menu,
            notice: self::NOTICES[$request->input('done')] ?? '',
            error: self::avatarError($request->input('err'))
        );
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function save(Request $request, User $user, array $menu): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return $this->render($user, $menu, [], __(WebAuth::STALE), 403);
        }

        $displayName = trim($request->input('display_name'));
        $email = trim($request->input('email'));
        $bio = trim($request->input('bio'));
        $locale = $request->input('locale');
        $avatarSource = $request->input('avatar_source');
        $avatarSourceUrl = trim($request->input('avatar_source_url'));

        if (!in_array($avatarSource, self::AVATAR_SOURCES, true)) {
            $avatarSource = 'local';
        }

        $overrides = [
            'display_name' => $displayName,
            'email' => $email,
            'bio' => $bio,
            'locale' => $locale,
            'avatar_source' => $avatarSource,
            'avatar_source_url' => $avatarSourceUrl,
        ];

        if (mb_strlen($bio) > 500) {
            return $this->render($user, $menu, $overrides, __('简介太长了，压缩到 500 字以内。'));
        }

        if ($locale !== Translator::AUTO && $locale !== Translator::SITE && !isset($this->translator->available()[$locale])) {
            return $this->render($user, $menu, $overrides, __('这个界面语言站上还没装。'));
        }

        // 改密三项要么全填、要么全空;预校验放在写入之前,免得资料已存、密码却卡在半路
        $current = $request->input('current_password');
        $new = $request->input('new_password');
        $confirm = $request->input('confirm_password');
        $passwordChange = $current !== '' || $new !== '' || $confirm !== '';
        $passwordError = '';

        if ($passwordChange) {
            $passwordError = match (true) {
                $current === '' || $new === '' || $confirm === '' => __('修改密码要把三项都填上。'),
                $new !== $confirm => __('两次输入的密码不一致。'),
                !Password::verify($current, $this->users->hashOf($user->id)) => __('当前密码不对。'),
                default => '',
            };

            if ($passwordError === '') {
                $strength = Password::checkStrength($new);
                $passwordError = $strength === [] ? '' : __($strength[0]);
            }
        }

        if ($passwordError !== '') {
            return $this->render($user, $menu, $overrides, $passwordError);
        }

        try {
            $this->users->updateProfile($user->id, ['display_name' => $displayName, 'email' => $email], [
                'bio' => $bio,
                'locale' => $locale,
            ]);
        } catch (ValidationException $e) {
            return $this->render($user, $menu, $overrides, $this->firstError($e));
        }

        // 头像来源是用户级设置(usermeta,与站点 option 同名);Avatar 读取时用户级优先、站点兜底
        $this->users->setMeta($user->id, 'avatar_source', $avatarSource);
        $this->users->setMeta($user->id, 'avatar_source_url', $avatarSourceUrl);

        Audit::record('user.profile.update', 'user:' . $user->id, true, $request, 'user:' . $user->id);

        if ($passwordChange) {
            $this->users->changePassword($user->id, $new);

            // 本设备留在原地:把本会话的版本号跟到新值;其他设备的旧会话仍因版本对不上被拒
            $fresh = $this->users->find($user->id);
            $session = Auth::session();

            if ($fresh !== null && $session !== null) {
                $session->data['session_version'] = $fresh->sessionVersion;
                $this->sessions->save($session);
            }

            Audit::record('user.password.change', 'user:' . $user->id, true, $request, 'user:' . $user->id);
        }

        return Response::redirect($this->router->path('admin', ['path' => 'profile']) . '?done=saved');
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function uploadAvatar(Request $request, User $user, array $menu): Response
    {
        $session = Auth::session();
        $token = $request->input(Csrf::FIELD);

        // 超 post_max_size 时 PHP 连 $_POST 一起丢,令牌"对不上"其实是文件太大;
        // 别拿"页面放得太久了"糊弄,按太大如实回话(本体有长度、却既没令牌也没文件 = 被丢弃的签名)
        if ($request->isPost() && $token === '' && $request->uploads('avatar') === []
            && (int) $request->server('CONTENT_LENGTH') > 0
        ) {
            return $this->avatarFailed('toolarge');
        }

        if (!$request->isPost() || $session === null || !Csrf::check($session, $token)) {
            return $this->render($user, $menu, [], __(WebAuth::STALE), 403);
        }

        $file = $request->uploads('avatar')[0] ?? null;

        // 令牌在、文件却不在(没选文件就交了):照样给回执,不许静默跳回
        if ($file === null) {
            return $this->avatarFailed('nofile');
        }

        $phpError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($phpError === UPLOAD_ERR_NO_FILE) {
            return $this->avatarFailed('nofile');
        }

        if ($phpError === UPLOAD_ERR_INI_SIZE || $phpError === UPLOAD_ERR_FORM_SIZE) {
            return $this->avatarFailed('toolarge');
        }

        if ($phpError !== UPLOAD_ERR_OK) {
            return $this->avatarFailed('failed');
        }

        try {
            $record = $this->uploader->upload(Upload::fromRequest($file), $user->id);
        } catch (ValidationException $e) {
            // 库里拒人的细话不进地址栏:只回一个码,译文在 avatarError() 一处收口
            return $this->avatarFailed(match ($e->rule()) {
                'too_large', 'pixel_limit' => 'toolarge',
                '' => 'failed',
                default => 'type',
            });
        } catch (Throwable) {
            return $this->avatarFailed('failed');
        }

        if ($record === [] || !isset($record['id'])) {
            return $this->avatarFailed('failed');
        }

        if (!str_starts_with((string) ($record['mime_type'] ?? ''), 'image/')) {
            // 走完媒体校验却不是图片:别把 pdf 挂成头像,刚落的附件也清掉
            $this->remover->destroy((int) $record['id'], $user->id);

            return $this->avatarFailed('type');
        }

        $this->users->setMeta($user->id, 'avatar', (string) $record['id']);
        // 传完立刻把来源切到"自己上传":不然来源停在 Gravatar 的人传了图也见不到(反直觉)
        $this->users->setMeta($user->id, 'avatar_source', 'upload');
        $this->users->touchProfile($user->id);
        Audit::record('user.avatar.update', 'media:' . (string) $record['id'], true, $request, 'user:' . $user->id);

        return Response::redirect($this->router->path('admin', ['path' => 'profile']) . '?done=avatar');
    }

    /**
     * 头像来源:用户级 meta 优先,未设置回退站点 option(老安装兼容);脏数据按 local。
     *
     * @param array<string, string> $meta
     */
    private function avatarSource(array $meta): string
    {
        $source = (string) ($meta['avatar_source'] ?? '');

        if ($source === '') {
            $source = (string) ($this->options->get('avatar_source', 'local') ?? 'local');
        }

        return in_array($source, self::AVATAR_SOURCES, true) ? $source : 'local';
    }

    /**
     * 自定义头像源的模板地址:同样用户级优先、站点兜底。
     *
     * @param array<string, string> $meta
     */
    private function avatarSourceUrl(array $meta): string
    {
        $template = trim((string) ($meta['avatar_source_url'] ?? ''));

        if ($template === '') {
            $template = trim((string) ($this->options->get('avatar_source_url', '') ?? ''));
        }

        return $template;
    }

    /**
     * 上传失败的回执一律走 PRG:地址上只带码,页面渲染时翻成人话。
     */
    private function avatarFailed(string $code): Response
    {
        return Response::redirect($this->router->path('admin', ['path' => 'profile']) . '?err=' . $code);
    }

    /**
     * @param array<string, string> $overrides 出错回显:表单里填过什么就还显示什么
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function render(User $user, array $menu, array $overrides = [], string $error = '', int $status = 200, string $notice = ''): Response
    {
        $meta = $this->users->meta($user->id);
        $times = $this->users->timestamps($user->id);
        $lastSaved = $times['updated_at'] ?? $times['registered_at'];
        $languages = $this->translator->available();
        // 没表过态(或存的写法已不认)一律落成「跟随站点」。以前这里落的是"站点当时那个具体语言",
        // 于是保存一次个人资料就把它钉死,站点语言从此对这个人不生效。
        $locale = (string) ($meta['locale'] ?? '');
        $known = $locale === Translator::AUTO
            || $locale === Translator::SITE
            || isset($languages[$locale]);

        if (!$known) {
            $locale = Translator::SITE;
        }

        $values = [
            'display_name' => $user->display_name,
            'email' => $user->email,
            'bio' => (string) ($meta['bio'] ?? ''),
            'locale' => $locale,
            'avatar_source' => $this->avatarSource($meta),
            'avatar_source_url' => $this->avatarSourceUrl($meta),
        ];

        foreach ($overrides as $key => $value) {
            $values[$key] = $value;
        }

        $stamp = $lastSaved === null ? false : strtotime($lastSaved . ' UTC');

        return $this->view->render('profile.twig', [
            'page_kind' => 'profile',
            'page_title' => __('个人资料'),
            'values' => $values + ['username' => $user->username],
            'languages' => $languages,
            'meta_line' => $this->metaLine($user),
            'last_saved_date' => $stamp === false ? '' : date('Y-m-d', $stamp),
            'last_saved_time' => $stamp === false ? '' : date('H:i', $stamp),
            'avatar_url' => $this->avatar->url($user->email, 56),
            'notice' => $notice,
            'error' => $error,
        ], $menu, $status);
    }

    /**
     * 「管理员 · 36 篇文章 · 加入于 2026 年 3 月」。
     */
    private function metaLine(User $user): string
    {
        $times = $this->users->timestamps($user->id);
        $parts = [
            __(Roles::label($user->role)),
            __('%s 篇文章', 'molin', format_number($this->users->postCount($user->id))),
        ];

        if ($times['registered_at'] !== null) {
            $parts[] = __('加入于 %s', 'molin', format_date($times['registered_at'] . ' UTC', 'month'));
        }

        return implode(' · ', $parts);
    }

    private function firstError(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            if ($messages !== []) {
                return __($messages[0]);
            }
        }

        return __($e->getMessage());
    }
}
