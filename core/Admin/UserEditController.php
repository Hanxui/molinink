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
use Molin\Logger\Audit;
use Molin\Router\Router;
use Molin\Security\Csrf;
use Molin\Security\Password;
use Molin\User\Auth;
use Molin\User\Authorizer;
use Molin\User\Capabilities;
use Molin\User\Roles;
use Molin\User\User;
use Molin\User\UserRepository;

/**
 * 用户新建与编辑屏(《106-后台用户新建与编辑实现规格》)。
 *
 * 两条路:`users/new`(新建态)与 `users/{id}/edit`(编辑态),一屏两态。
 *
 * 权限键取保守档:《106》§11-8 指出「改昵称/邮箱」该要的 `user.write` 不在
 * 《32》§3 的 19 项词表里(与《105》§11-1 同一处词表冲突,待作者裁),
 * 于是进页与保存整体先挂在 `user.create`(仅管理员),改角色再单查 `user.role.change` ——
 * 收紧容易放宽难,裁定后只动这一处常量。
 *
 * 已知未接(均记入待办清单,不在本屏自造):
 * - 「发送欢迎邮件」只读值不投递 —— 投递依赖还没落地的邮件插件与 `mail.send` 能力(《106》§11-11)。
 * - `.from` 来源标签:内置能力写「管理员单独授予」、插件注册的能力写「插件授予」——
 *   具体授予者/插件名没有字段可依(《106》§11-5),先照这个口径降级。
 */
final class UserEditController
{
    /** 进页与保存的权限键(保守档;《106》§11-8 待裁) */
    private const CAP_ACCESS = 'user.create';

    /** 改角色另查的权限键(《32》§3:仅管理员) */
    private const CAP_ROLE = 'user.role.change';

    private const NOTICES = [
        'saved' => '已保存。',
        'created' => '用户已创建。',
    ];

    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly Authorizer $can,
        private readonly Router $router
    ) {
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    public function handle(Request $request, string $path, User $manager, array $menu): Response
    {
        if (!$this->can->can($manager, self::CAP_ACCESS)) {
            return $this->view->render('forbidden.twig', [
                'page_title' => __('用户'),
                'capability' => self::CAP_ACCESS,
            ], $menu, 403);
        }

        if ($path === 'users/new') {
            if ($request->isPost()) {
                return $this->create($request, $manager, $menu);
            }

            return $this->render($request, $menu, null, [], '');
        }

        if (preg_match('#\Ausers/(\d+)/edit\z#', $path, $match) === 1) {
            $target = $this->users->find((int) $match[1]);

            if ($target === null) {
                throw new NotFoundException('这个用户不存在:' . $path);
            }

            if ($request->isPost()) {
                return $this->save($request, $manager, $target, $menu);
            }

            return $this->render($request, $menu, $target, [], '');
        }

        throw new NotFoundException('后台没有这个页面:' . $path);
    }

    /**
     * 新建(《106》§11-1 的七处缺口按最保守推定,已在待办清单登记待追认)。
     *
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function create(Request $request, User $manager, array $menu): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return $this->render($request, $menu, null, [], __(WebAuth::STALE), 403);
        }

        $overrides = [
            'username' => trim($request->input('username')),
            'display_name' => trim($request->input('display_name')),
            'email' => trim($request->input('email')),
            'role' => $request->input('role'),
        ];

        $error = $this->displayNameError($overrides['display_name']);

        if ($error === '') {
            try {
                $created = $this->users->create(
                    $overrides['username'],
                    $overrides['email'],
                    $request->input('new_password'),
                    $overrides['role'],
                    $overrides['display_name']
                );
            } catch (ValidationException $e) {
                return $this->render($request, $menu, null, $overrides, $this->firstError($e));
            }

            Audit::record('user.create', 'user:' . $created->id, true, $request, 'user:' . $manager->id);

            return Response::redirect($this->router->path('admin', ['path' => 'users/' . $created->id . '/edit']) . '?done=created');
        }

        return $this->render($request, $menu, null, $overrides, $error);
    }

    /**
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function save(Request $request, User $manager, User $target, array $menu): Response
    {
        $session = Auth::session();

        if ($session === null || !Csrf::check($session, $request->input(Csrf::FIELD))) {
            return $this->render($request, $menu, $target, [], __(WebAuth::STALE), 403);
        }

        $displayName = trim($request->input('display_name'));
        $email = trim($request->input('email'));
        $role = $request->input('role');
        $newPassword = $request->input('new_password');
        $overrides = [
            'display_name' => $displayName,
            'email' => $email,
            'role' => $role,
        ];

        $error = $this->displayNameError($displayName);
        $roleChange = $role !== '' && $role !== $target->role;

        if ($error === '' && $roleChange && !$this->can->can($manager, self::CAP_ROLE)) {
            $error = __('没有变更角色的权限。');
        }

        if ($error === '' && $roleChange && !Roles::exists($role)) {
            $error = __('没有这个角色。');
        }

        // 密码三项预检放在写入之前,免得资料已存、密码却卡在半路(与《84》§6 同口径)
        if ($error === '' && $newPassword !== '') {
            $strength = Password::checkStrength($newPassword);
            $error = $strength === [] ? '' : __($strength[0]);
        }

        if ($error !== '') {
            return $this->render($request, $menu, $target, $overrides, $error);
        }

        try {
            $this->users->updateProfile($target->id, ['display_name' => $displayName, 'email' => $email], []);
        } catch (ValidationException $e) {
            return $this->render($request, $menu, $target, $overrides, $this->firstError($e));
        }

        Audit::record('user.update', 'user:' . $target->id, true, $request, 'user:' . $manager->id);

        if ($roleChange) {
            $this->users->changeRole($target->id, $role);
            Audit::record('user.role.change', 'user:' . $target->id, true, $request, 'user:' . $manager->id);
        }

        if ($newPassword !== '') {
            $this->users->changePassword($target->id, $newPassword);
            Audit::record('user.password.change', 'user:' . $target->id, true, $request, 'user:' . $manager->id);
        }

        return Response::redirect($this->router->path('admin', ['path' => 'users/' . $target->id . '/edit']) . '?done=saved');
    }

    /**
     * @param array<string, string> $overrides 出错回显:表单里填过什么就还显示什么
     * @param list<array{title:string, path:string, active:bool, badge?:string}> $menu
     */
    private function render(Request $request, array $menu, ?User $target, array $overrides, string $error, int $status = 200): Response
    {
        $mode = $target === null ? 'new' : 'edit';
        $values = $target === null
            ? ['username' => '', 'display_name' => '', 'email' => '', 'role' => Roles::SUBSCRIBER]
            : ['username' => $target->username, 'display_name' => $target->display_name, 'email' => $target->email, 'role' => $target->role];

        foreach ($overrides as $key => $value) {
            $values[$key] = $value;
        }

        $savePath = $mode === 'new' ? 'users/new' : 'users/' . $target->id . '/edit';
        $lastSaved = null;

        if ($target !== null) {
            $times = $this->users->timestamps($target->id);
            $stamp = strtotime((string) ($times['updated_at'] ?? '') . ' UTC');

            if ($stamp !== false) {
                $lastSaved = [
                    'date' => date('Y-m-d', $stamp),
                    'time' => date('H:i', $stamp),
                ];
            }
        }

        return $this->view->render('user-edit.twig', [
            'page_kind' => 'user-edit',
            'page_title' => $mode === 'new' ? __('新建用户') : __('编辑用户'),
            'mode' => $mode,
            'values' => $values,
            'roles' => $this->roleOptions($values['role']),
            'caps' => $mode === 'new' ? [] : $this->capRows($target),
            'meta_line' => $mode === 'new' ? null : $this->metaLine($target),
            'last_saved' => $lastSaved,
            'save_url' => $this->router->path('admin', ['path' => $savePath]),
            'cancel_url' => $this->router->path('admin', ['path' => 'users']),
            'notice' => __(self::NOTICES[$request->input('done')] ?? ''),
            'error' => $error,
        ], $menu, $status);
    }

    /**
     * 角色下拉:五内置角色的次序即《32》§3 的次序(管理员 / 编辑 / 作者 / 贡献者 / 订阅者)。
     *
     * @return list<array{v:string, t:string, sel:bool}>
     */
    private function roleOptions(string $current): array
    {
        $out = [];

        foreach (Roles::all() as $role) {
            $out[] = [
                'v' => $role,
                't' => __(Roles::label($role)),
                'sel' => $role === $current,
            ];
        }

        return $out;
    }

    /**
     * 额外能力只读表(《106》§10):只列不属于当前角色能力集的那部分。
     * 行序按存储序(grant() 落库时排过序,不在这里重排)。
     *
     * @return list<array{k:string, why:string, from:string, lock:bool}>
     */
    private function capRows(User $target): array
    {
        $extras = array_values(array_diff($target->extraCapabilities, Roles::capabilitiesOf($target->role)));
        $out = [];

        foreach ($extras as $capability) {
            $builtIn = in_array($capability, Capabilities::builtIn(), true);
            $out[] = [
                'k' => $capability,
                'why' => __(Capabilities::label($capability)),
                'from' => $builtIn ? __('管理员单独授予') : __('插件授予'),
                'lock' => !$builtIn,
            ];
        }

        return $out;
    }

    /**
     * 「最后活跃 2026-09-26 · 8 篇文章」(《106》§5.2)。
     *
     * @return array{date:string, posts:int}
     */
    private function metaLine(User $target): array
    {
        $times = $this->users->timestamps($target->id);
        $stamp = strtotime((string) ($times['updated_at'] ?? $times['registered_at'] ?? '') . ' UTC');

        return [
            'date' => $stamp === false ? '' : date('Y-m-d', $stamp),
            'posts' => $this->users->postCount($target->id),
        ];
    }

    private function displayNameError(string $displayName): string
    {
        if ($displayName === '' || mb_strlen($displayName) > 60) {
            return __('昵称要有,且不超过 60 个字符');
        }

        return '';
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
