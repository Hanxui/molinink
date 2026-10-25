<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Logger;

use Molin\Http\Request;

/**
 * 审计日志:谁在何时对什么做了什么、结果如何(《26》第 7 节)。
 *
 * 走 audit 通道,IP 与 UA 按《26》第 6 节保留全值(该通道不过脱敏处理器),
 * 且按《26》第 5.3 节单独存放、不随常规日志清理。
 */
final class Audit
{
    /**
     * 必须审计的动作:登录(成败)、权限变更、内容删除、插件启停、设置修改、密钥操作、导出备份。
     */
    public static function record(
        string $action,
        string $target,
        bool $allowed = true,
        ?Request $request = null,
        string $actor = 'system'
    ): void {
        $context = [
            'action' => $action,
            'actor'  => $actor,
            'target' => $target,
            'result' => $allowed ? 'ok' : 'denied',
            'ip'     => $request?->ip() ?? '',
            'ua'     => $request?->userAgent() ?? '',
        ];

        // 《26》第 5.2 节:拒绝的操属于"可疑但已处理",记 WARNING
        if ($allowed) {
            Log::channel('audit')->info($action, $context);
        } else {
            Log::channel('audit')->warning($action, $context);
        }
    }
}
