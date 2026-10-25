<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Admin;

use Molin\Exception\NotFoundException;
use Molin\Http\Request;
use Molin\Http\Response;
use Molin\Option\Options;
use Molin\Router\Router;
use Molin\User\User;

/**
 * 站点健康屏(《111-后台站点健康实现规格》)。
 *
 * **2026-10-17 作者定:必须按了「开始检测」才跑,不能每次点进来就测完。** 于是分两种进入:
 *   · `GET /admin/health`        → 只读**上一次的结果**(没有就显示「还没检测过」的空态);
 *   · `GET /admin/health?run=1`  → 真跑 18 项,把结果与时间存成一份快照,再照常渲染。
 * 这是只读动作:不碰站点数据,只是把一次体检的结果记下来给下一次进页面用。
 *
 * 18 项检查的取数全在 Health 里(服务端算好,模板不做业务);页面文案逐字照 §7。
 */
final class HealthController
{
    /** 快照落在 options 里(autoload=0):只有这一屏要读 */
    private const SNAPSHOT = 'health_snapshot';

    public function __construct(
        private readonly View $view,
        private readonly Health $health,
        private readonly Router $router,
        // 未注入 = 不存快照(只造外壳、不测这一屏的老用例会这样);生产由 Bootstrap 传进来
        private readonly ?Options $options = null
    ) {
    }

    /**
     * @param list<array{title: string, path: string, active: bool, badge?: string}> $menu
     */
    public function handle(Request $request, string $path, User $user, array $menu): Response
    {
        if ($path !== 'health') {
            throw new NotFoundException('后台没有这个页面:' . $path);
        }

        $url = $this->router->path('admin', ['path' => 'health']);
        $fresh = $request->input('run') === '1';
        $at = '';
        $result = null;

        if ($fresh) {
            $at = date('Y-m-d H:i');
            $result = $this->health->payload($request->server('SERVER_SOFTWARE'));
            $this->options?->set(self::SNAPSHOT, (string) json_encode(
                ['at' => $at, 'result' => $result],
                JSON_UNESCAPED_UNICODE
            ));
        } else {
            ['at' => $at, 'result' => $result] = $this->snapshot();
        }

        $vars = [
            'page_kind' => 'health',
            'page_title' => __('站点健康'),
            'health_url' => $url,
            'run_url' => $url . '?run=1',
            'has_result' => $result !== null,
            'fresh' => $fresh && $result !== null,
        ];

        if ($result !== null) {
            // 结果里的 when 是"跑的那一刻"生成的;从快照读回时按快照时间重算,不然跨天会说错
            $vars += $result;
            $vars['when'] = __('上次检测：', 'molin') . $this->whenText($at);
        }

        return $this->view->render('health.twig', $vars, $menu);
    }

    /**
     * 读上次的快照。形状不对(手改坏了、旧版留下的)就当没有 —— 空态比半张假表安全。
     *
     * @return array{at: string, result: array<string, mixed>|null}
     */
    private function snapshot(): array
    {
        $raw = $this->options?->get(self::SNAPSHOT, '') ?? '';
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($decoded) || !is_array($decoded['result'] ?? null)) {
            return ['at' => '', 'result' => null];
        }

        $result = $decoded['result'];

        if (!is_array($result['groups'] ?? null) || !is_array($result['sum'] ?? null)) {
            return ['at' => '', 'result' => null];
        }

        return ['at' => (string) ($decoded['at'] ?? ''), 'result' => $result];
    }

    /** 「今天 14:05」或「2026-10-16 09:31」—— 隔天还说"今天"就是假话 */
    private function whenText(string $at): string
    {
        if ($at === '') {
            return '';
        }

        $stamp = strtotime($at);
        $today = strtotime(date('Y-m-d'));

        if ($stamp !== false && $today !== false && $stamp >= $today) {
            return __('今天 %s', 'molin', date('H:i', $stamp));
        }

        return $stamp === false ? $at : date('Y-m-d H:i', $stamp);
    }
}
