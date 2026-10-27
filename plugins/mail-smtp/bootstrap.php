<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

use Molin\Comment\CommentRepository;
use Molin\Database\Database;
use Molin\Logger\Log;
use Molin\Option\Options;
use Molin\Plugins\MailSmtp\Mailer;
use Molin\Plugins\MailSmtp\Settings;
use Molin\User\User;

/*
 * SMTP 发信(既有规范mail.send 能力)。
 *
 * 找回密码内核只签令牌、不发信(既有规范②),签发后发 `user/lostpassword/after`(user, link)——
 * 这个监听把它寄成真邮件。
 *
 * **2026-10-17:只在"真的发得出去"时才挂钩子。** 此前是无条件挂、回调里发现没配置再静默 return;
 * 结果是内核用 `has_action()` 判断"这台站能不能发信"时永远为真,找回密码页就会对着一张没配好的站
 * 说"邮件已发出" —— 那正是这轮要根除的那句假话。现在没配置就一个钩子都不挂,页面自然改说
 * "本站还没有启用邮件发送"。配置形状见插件目录 config.example.php(放 storage/.secrets/mail.php)。
 */

$settings = Settings::load();
$mailer = $settings === null ? null : Mailer::fromSettings($settings);

if ($mailer !== null) {
    add_action('user/lostpassword/after', static function (User $user, string $link) use ($mailer): void {
        try {
            $mailer->sendReset($user, $link);
            mail_smtp_clear_failure();
        } catch (Throwable $e) {
            // 发信失败不能拖垮找回密码的页面;把实情留给日志(收件人地址会脱敏)
            Log::channel('plugin')->error('mail_smtp.send_failed', [
                'to' => $user->email,
                'error' => $e->getMessage(),
            ]);
            // 留一句给后台设置页看:配了但发不出去,最不该只躺在日志里
            mail_smtp_remember_failure($e);
        }
    }, 10, 2);

    /*
     * 新评论通知(既有规范⑨:入库后通知归插件)。评论一提交就触发,把后台 admin_email
     * (站长邮箱)接上 —— 此前它只存不用。发信失败也不拖垮评论提交页,实情进日志。
     * 入参只有 id 与 post_id,其余靠库补读。
     */
    add_action('comment/submit/after', static function (int $id, int $postId) use ($mailer): void {
        $config = defined('MOLIN_ROOT') ? (require MOLIN_ROOT . '/config.php') : null;

        if (!is_array($config) || ($config['database'] ?? null) === null) {
            return;
        }

        $db = new Database((array) $config['database']);
        $adminEmail = (string) (new Options($db))->get('admin_email', '');

        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            $comment = (new CommentRepository($db))->find($id);

            if ($comment === null) {
                return;
            }

            $post = $db->selectOne(
                'SELECT title FROM ' . $db->table('posts') . ' WHERE id = :id',
                ['id' => $postId]
            );

            $mailer->sendNewComment(
                $adminEmail,
                $post === null ? '#' . $postId : (string) $post['title'],
                (string) ($comment['author_name'] ?? ''),
                trim(strip_tags((string) ($comment['content'] ?? ''))),
                (string) ($comment['status'] ?? 'pending')
            );
            mail_smtp_clear_failure();
        } catch (Throwable $e) {
            Log::channel('plugin')->error('mail_smtp.new_comment_failed', [
                'comment' => $id,
                'error' => $e->getMessage(),
            ]);
            mail_smtp_remember_failure($e);
        }
    }, 10, 2);
}
/**
 * 把"最近一次发信失败"记进插件配置,后台设置页会显示它(2026-10-17)。
 * 配好了但发不出去(密码错、端口封了)最不该只躺在日志里 —— 管理员在那一页就能看见。
 * 记不下就算了:留痕失败不该影响发信这条路本身的容错。
 */
if (!function_exists('mail_smtp_remember_failure')) {
    function mail_smtp_remember_failure(Throwable $e): void
    {
        try {
            Molin\Plugin\Settings::for('mail-smtp')->set(
                'last_failed',
                gmdate('Y-m-d H:i:s') . ' UTC · ' . mb_substr($e->getMessage(), 0, 200)
            );
        } catch (Throwable) {
            // 无插件上下文(CLI 之类)或配置存储没起来:忽略
        }
    }
}

/**
 * 发出去一封就把那句失败清掉(页面只该显示"最近一次"的实况)。
 */
if (!function_exists('mail_smtp_clear_failure')) {
    function mail_smtp_clear_failure(): void
    {
        try {
            Molin\Plugin\Settings::for('mail-smtp')->set('last_failed', '');
        } catch (Throwable) {
        }
    }
}
