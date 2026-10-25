<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Security\Csrf;
use Molin\User\Auth;

/**
 * 后台控制器共用的两处守卫:表单令牌校验与 403 页。
 * 使用方必须持有 protected View $view。
 */
trait AdminGuards
{
    protected function tokenOk(Request $request): bool
    {
        $session = Auth::session();

        return $session !== null && Csrf::check($session, $request->input(Csrf::FIELD));
    }

    /**
     * @param list<array{title:string, path:string, active:bool}> $menu
     * @param list<string> $capabilities
     */
    protected function forbidden(array $menu, array $capabilities, string $doing): Response
    {
        return $this->view->render('forbidden.twig', [
            'page_title' => $doing,
            'capability' => implode(' / ', $capabilities),
        ], $menu, 403);
    }
}
