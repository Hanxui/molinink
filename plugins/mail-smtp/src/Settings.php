<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Plugins\MailSmtp;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * SMTP 配置的读取与校验。
 *
 * 配置活在 storage/.secrets/mail.php —— 与库里凭据同一保管区:不入仓库、不进安装包、
 * 插件目录被更新覆盖也不丢。模板见插件目录 config.example.php。
 * 缺文件、语法坏、字段不齐 —— 一律回 null,调用方静默跳过,把事故挡在页面之外。
 */
final class Settings
{
    public const ENCRYPTIONS = ['ssl', 'starttls', 'none'];

    /**
     * 连接与单次读写共用的超时(秒)。不设就跟着 php.ini 的 default_socket_timeout 走,
     * 常见 60 秒 —— 评论提交、找回密码页面会陪着一起等。
     */
    private const TIMEOUT = 5.0;

    /** 允许写进配置文件的键(白名单) */
    private const WRITABLE = ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $username,
        public readonly string $password,
        public readonly string $fromAddress,
        public readonly string $fromName
    ) {
    }

    /**
     * 配置路径。测试可用 MOLIN_MAIL_CONFIG 覆盖,与 MOLIN_LOG_ROOT 一族同法。
     */
    public static function defaultPath(): string
    {
        $override = getenv('MOLIN_MAIL_CONFIG');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $root = defined('MOLIN_ROOT') ? MOLIN_ROOT : dirname(__DIR__, 4);

        return $root . '/storage/.secrets/mail.php';
    }

    public static function load(?string $path = null): ?self
    {
        $path ??= self::defaultPath();

        if (!is_file($path)) {
            return null;
        }

        // 配置文件是人工填的:语法错、半截内容都可能。缓冲输出再吞异常,
        // 别让一个手滑把找回密码页变成白屏或混进杂字。
        ob_start();

        try {
            $data = include $path;
        } catch (\Throwable) {
            $data = null;
        } finally {
            ob_end_clean();
        }

        return is_array($data) ? self::fromArray($data) : null;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $host = trim((string) ($data['host'] ?? ''));
        $from = trim((string) ($data['from_address'] ?? ''));
        $encryption = (string) ($data['encryption'] ?? 'ssl');
        $username = (string) ($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $port = (int) ($data['port'] ?? 0);

        if ($host === '' || $from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            return null;
        }

        if ($port === 0) {
            $port = $encryption === 'ssl' ? 465 : 587;
        }

        if ($port < 1 || $port > 65535) {
            return null;
        }

        // 一半的认证信息比没有更危险:用户名与密码要么都给,要么都不给(内网中继常见)
        if (($username === '') !== ($password === '')) {
            return null;
        }

        return new self($host, $port, $encryption, $username, $password, $from, trim((string) ($data['from_name'] ?? '')));
    }

    /**
     * 把配置写回文件(后台设置页用,2026-10-17)。
     *
     * 三个要点:
     * ① **键白名单**——表单里多出来的东西一律不写,别把一次提交变成任意内容落盘;
     * ② **var_export**——值里带引号/换行也只是字符串,不会变成代码(注入在这里断掉);
     * ③ **先写临时文件再 rename**——半截文件最要命:写一半断电,找回密码那条路就白屏了。
     * 落盘权限 0600(与其它 secrets 同级),目录 0700。
     *
     * @param array<string, mixed> $values
     */
    public static function write(array $values, ?string $path = null): bool
    {
        $path ??= self::defaultPath();
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            return false;
        }

        $clean = [];

        foreach (self::WRITABLE as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $clean[$key] = $key === 'port' ? (int) $values[$key] : (string) $values[$key];
        }

        $code = "<?php\n\n"
            . "/**\n * 墨林 - Molinink\n * @author Kira\n * @link   https://www.molinink.com\n */\n\n"
            . 'declare(strict_types=1);' . "\n\n"
            . '// 由后台「SMTP 发信」设置页写入(' . gmdate('Y-m-d H:i:s') . " UTC);手改同样有效,\n"
            . "// 格式见插件目录 config.example.php。改完不用重启,下一次请求就会生效。\n"
            . 'return ' . var_export($clean, true) . ";\n";

        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $code, LOCK_EX) === false) {
            return false;
        }

        @chmod($tmp, 0o600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        @chmod($path, 0o600);

        return true;
    }

    /**
     * 建一条 SMTP 传输。走 Dsn + 官方工厂:隐式 TLS / STARTTLS 的差别与
     * 凭据里的特殊字符(rawurlencode 转义、工厂侧解码)都不用我们猜。
     */
    public function transport(): TransportInterface
    {
        $scheme = $this->encryption === 'ssl' ? 'smtps' : 'smtp';

        $auth = $this->username === ''
            ? ''
            : rawurlencode($this->username) . ':' . rawurlencode($this->password) . '@';

        $transport = (new EsmtpTransportFactory())->create(
            Dsn::fromString($scheme . '://' . $auth . $this->host . ':' . $this->port)
        );

        if ($transport instanceof EsmtpTransport) {
            // 'none' 用于内网中继:连 STARTTLS 的自动升级也关掉
            if ($this->encryption === 'none') {
                $transport->setAutoTls(false);
            }

            // symfony/mailer 7.2 的超时挂在 SocketStream 上,EsmtpTransport 本身没有 setTimeout
            $stream = $transport->getStream();

            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT);
            }
        }

        return $transport;
    }
}
