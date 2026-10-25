<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugins\MailSmtp;

use Molin\Http\Response;
use Molin\Plugin\AdminPageContext;
use Molin\Plugin\AdminPageController;

/**
 * 「SMTP 发信」的后台设置页(《12》§13/§14)。
 *
 * 在此之前,配 SMTP 只能上服务器手写 storage/.secrets/mail.php —— 对不懂技术的站长就是一道墙。
 * 这一页把它变成:填、存、试发一封。密码框**永远不回显**(只告诉你有没存过),留空 = 不改动。
 *
 * 会话 / 页面能力 / CSRF 三道闸由内核先过(见 AdminController::servePluginPage),这里不重复查。
 * 配置仍落在 storage/.secrets/mail.php:不进仓库、不进安装包、不入数据库 —— 与其它密钥同一处。
 */
final class SettingsPage implements AdminPageController
{
    /** 表单里的动作名:save 保存、test 试发 */
    private const ACTION = 'do';

    /** 加密方式 => 人话(下拉里的显示名) */
    private const ENCRYPTION_LABELS = [
        'ssl' => 'SSL(常见 465)',
        'starttls' => 'STARTTLS(常见 587)',
        'none' => '不加密(内网中继才用)',
    ];

    public function handle(AdminPageContext $context): array|Response
    {
        $path = Settings::defaultPath();

        if ($context->isPost()) {
            return $this->submit($context, $path);
        }

        return $this->view($context, Settings::load($path));
    }

    /**
     * 表单提交:存配置,或拿**已保存的**配置试发一封。
     */
    /**
     * @return array<string, mixed>|Response
     */
    private function submit(AdminPageContext $context, string $path): array|Response
    {
        $action = trim($context->request->input(self::ACTION, 'save'));

        if ($action === 'test') {
            return $this->test($context, $path);
        }

        $current = Settings::load($path);
        $values = [
            'host' => $context->request->input('host'),
            'port' => $context->request->input('port'),
            'encryption' => $context->request->input('encryption'),
            'username' => $context->request->input('username'),
            'from_address' => $context->request->input('from_address'),
            'from_name' => $context->request->input('from_name'),
        ];

        // 密码框留空 = 不改动:回读现有的(否则每改一次别的字段,密码就被抹掉一次)
        $typed = $context->request->input('password');
        $values['password'] = $typed !== '' ? $typed : ($current->password ?? '');

        // 用户名与密码要么都给、要么都不给(内网中继常见):只填一半时把人话讲清楚,
        // 而不是丢一句"保存失败"让他猜
        if (($values['username'] === '') !== ($values['password'] === '')) {
            return $this->view($context, $current, __('用户名与密码要么都填,要么都留空(内网中继可以不用认证)。', 'mail-smtp'));
        }

        $settings = Settings::fromArray($values);

        if ($settings === null) {
            return $this->view(
                $context,
                $current,
                __('这几项还不对:服务器地址不能空;发件地址要是个邮箱;端口 1–65535;加密方式从下拉里选。', 'mail-smtp')
            );
        }

        if (!Settings::write($values, $path)) {
            return $this->view($context, $current, __('写不进配置文件:请确认 storage/.secrets 目录可写(权限 0700、属主是运行网站的用户)。', 'mail-smtp'));
        }

        // PRG:存完重定向,刷新不会重复提交
        return Response::redirect($context->url . '?done=saved');
    }

    /**
     * 试发:用**已经存下来**的配置发一封 —— 与真发信同一条传输,发得出去就说明那条路是通的。
     */
    /**
     * @return array<string, mixed>|Response
     */
    private function test(AdminPageContext $context, string $path): array|Response
    {
        $settings = Settings::load($path);

        if ($settings === null) {
            return $this->view($context, null, __('先保存配置,再试发。', 'mail-smtp'));
        }

        // 收件地址:优先站长邮箱(后台「设置」里的那个),没有就发给发件地址自己
        $to = trim((string) $context->options->get('admin_email', ''));

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $to = $settings->fromAddress;
        }

        try {
            $mailer = Mailer::fromSettings($settings);

            if ($mailer === null) {
                return $this->view($context, $settings, __('配置读得出来但建不起连接,检查一下加密方式与端口是否对得上。', 'mail-smtp'));
            }

            $mailer->sendTest($to);
        } catch (\Throwable $e) {
            $why = $this->reason($e);
            // 与发信那条同一个键:后台这一页就能看见"最近一次失败",不用去翻日志
            $context->settings->set('last_failed', gmdate('Y-m-d H:i:s') . ' UTC · ' . $why);

            return Response::redirect($context->url . '?done=test-failed&why=' . rawurlencode($why));
        }

        $context->settings->set('last_failed', '');

        return Response::redirect($context->url . '?done=test-ok&to=' . rawurlencode($to));
    }

    /**
     * 失败原因收成一句人能看懂的话:超时 / 连不上 / 认证被拒 最常见。
     * **不把异常里的连接串原样铺出来**(里面可能有账号),只给判断依据。
     */
    private function reason(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false
                => __('连服务器超时:地址或端口不对,或机房挡了出站 25/465/587。', 'mail-smtp'),
            stripos($message, 'authentication') !== false || stripos($message, '535') !== false
                => __('登录被拒:账号或密码不对(很多邮箱要用「授权码」而不是登录密码)。', 'mail-smtp'),
            stripos($message, 'certificate') !== false || stripos($message, 'ssl') !== false
                => __('TLS 握手失败:加密方式和端口可能配反了(SSL 配 465、STARTTLS 配 587)。', 'mail-smtp'),
            default => __('发信失败:') . mb_substr($message, 0, 200),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function view(AdminPageContext $context, ?Settings $settings, string $error = ''): array
    {
        $done = trim($context->request->input('done'));

        return [
            'page_url' => $context->url,
            'configured' => $settings !== null,
            'config_path' => Settings::defaultPath(),
            'host' => $settings->host ?? '',
            'port' => $settings === null ? '' : (string) $settings->port,
            'encryption' => $settings->encryption ?? 'ssl',
            'username' => $settings->username ?? '',
            'from_address' => $settings->fromAddress ?? '',
            'from_name' => $settings->fromName ?? '',
            'has_password' => ($settings->password ?? '') !== '',
            'encryptions' => self::ENCRYPTION_LABELS,
            'admin_email' => (string) $context->options->get('admin_email', ''),
            'error' => $error,
            'last_failed' => trim($context->settings->get('last_failed')),
            'notice' => $this->notice($done, $context),
        ];
    }

    private function notice(string $done, AdminPageContext $context): string
    {
        return match ($done) {
            'saved' => __('已保存。下一次请求起,找回密码就能真发信了(配好之后建议点一下「发一封试试」)。', 'mail-smtp'),
            'test-ok' => __('测试邮件已发出,收件地址:%s。去收件箱(和垃圾箱)看看。', 'mail-smtp', $context->request->input('to')),
            'test-failed' => __('没发出去 —— ') . $context->request->input('why'),
            default => '',
        };
    }
}
