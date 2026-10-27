<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugins\MailSmtp;

use Molin\User\User;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * 把"重置密码"这件事拼成一封信寄出去。
 *
 * 传输件从外面注入:生产走 Settings::transport(),测试塞假的进来看信写得对不对。
 * 发送本身不做兜底 —— 失败怎么处理由接线方(bootstrap)决定。
 */
final class Mailer
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Settings $settings
    ) {
    }

    public static function fromSettings(?Settings $settings): ?self
    {
        return $settings === null ? null : new self($settings->transport(), $settings);
    }

    /**
     * 插件的通用出口：别家插件要发自己写的信，不必再复制一份传输件。
     */
    public function sendEmail(Email $email, ?Envelope $envelope = null): void
    {
        (new SymfonyMailer($this->transport))->send($email, $envelope);
    }

    /**
     * 站点默认发件人。没填名称时只给地址，与上面几封信同一口径。
     */
    public function defaultFrom(): Address
    {
        return $this->settings->fromName !== ''
            ? new Address($this->settings->fromAddress, $this->settings->fromName)
            : new Address($this->settings->fromAddress);
    }

    /**
     * 重置密码邮件。正文里「1 小时」与内核 PasswordReset::TTL 同源(既有规范①)。
     */
    public function sendReset(User $user, string $link): void
    {
        $site = $this->settings->fromName !== '' ? $this->settings->fromName : '本站';

        $from = $this->settings->fromName !== ''
            ? new Address($this->settings->fromAddress, $this->settings->fromName)
            : new Address($this->settings->fromAddress);

        $email = (new Email())
            ->from($from)
            ->to($user->email)
            ->subject('重设你的登录密码')
            ->text(
                "有人在「{$site}」上为这个邮箱申请了重设登录密码。\n\n"
                . "点下面的链接,打开后设置新密码(1 小时内有效,只能用一次):\n"
                . $link . "\n\n"
                . '不是你申请的?不用理这封邮件,你的密码不会被改动。'
            );

        (new SymfonyMailer($this->transport))->send($email);
    }

    /**
     * 试发一封(后台设置页那颗「发一封试试」,2026-10-17)。
     *
     * 与真发信走**同一条传输**:能发出测试信,就说明找回密码那条路也通;
     * 发不出去就把异常原样抛给调用方 —— 页面要把真实原因给人看,不能吞。
     */
    public function sendTest(string $to): void
    {
        $site = $this->settings->fromName !== '' ? $this->settings->fromName : '本站';

        $from = $this->settings->fromName !== ''
            ? new Address($this->settings->fromAddress, $this->settings->fromName)
            : new Address($this->settings->fromAddress);

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject('「' . $site . '」的发信测试')
            ->text(
                "这封是「{$site}」后台「SMTP 发信」设置页发出的测试邮件。\n\n"
                . "收到它说明:SMTP 能连上、能登录、能投递 —— 找回密码那条路也就通了。\n"
                . '发件地址:' . $this->settings->fromAddress . "\n"
                . '服务器:' . $this->settings->host . ':' . $this->settings->port . '(' . $this->settings->encryption . ")\n"
            );

        (new SymfonyMailer($this->transport))->send($email);
    }

    /**
     * 新评论通知。后台的 admin_email(站长邮箱)此前只存不用,这里把它接上:
     * 每收到一条新评论就给站长去信,正文写明是否待审 —— 待审的要人去后台通审。
     * 调用方(bootstrap)负责判 SMTP 配置与取齐入参;发送失败由调用方记日志兜底。
     */
    public function sendNewComment(
        string $to,
        string $postTitle,
        string $authorName,
        string $content,
        string $status
    ): void {
        $site = $this->settings->fromName !== '' ? $this->settings->fromName : '本站';

        $from = $this->settings->fromName !== ''
            ? new Address($this->settings->fromAddress, $this->settings->fromName)
            : new Address($this->settings->fromAddress);

        $review = $status === 'pending'
            ? '这条评论正在等待你审核,去后台过一下即可放行。'
            : '这条评论已通过审核,可直接阅读。';

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject('「' . $site . '」有新评论: ' . ($authorName === '' ? '匿名' : $authorName))
            ->text(
                "文章《{$postTitle}》收到了一条新评论。\n\n"
                . '作者: ' . ($authorName === '' ? '匿名' : $authorName) . "\n\n"
                . "内容:\n{$content}\n\n"
                . $review
            );

        (new SymfonyMailer($this->transport))->send($email);
    }
}
