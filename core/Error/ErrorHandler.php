<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Error;

use ErrorException;
use Molin\Cli\ExitCode;
use Molin\Exception\AuthException;
use Molin\Exception\HttpMappable;
use Molin\Exception\RateLimitException;
use Molin\Http\Response;
use Molin\Logger\Log;
use Molin\Support\ErrorId;
use Molin\Support\Performance\RequestSamples;
use Throwable;

/**
 * 未捕获错误的正门(《26》第 8 节):用户看到友好页,开发者拿到线索,攻击者什么都拿不到。
 *
 * 环境判断只认 APP_DEBUG(《26》第 3 节),不许有第二套开关。
 */
final class ErrorHandler
{
    /** 《26》第 9 节:单请求超过 2000ms 记 WARNING */
    private const SLOW_REQUEST_MS = 2000.0;

    private const FATAL_LEVELS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private static ?float $startedAt = null;

    /** @var (callable(Throwable): Response)|null 404 交回主题渲染,由入口注入 */
    private static $notFoundRenderer = null;

    private static bool $registered = false;

    /** system/shutdown 只能播一次:请求收尾可能被 register_shutdown_function 与显式调用各碰一次 */
    private static bool $shutdownFired = false;

    public static function boot(?callable $notFoundRenderer = null): void
    {
        self::$startedAt = microtime(true);
        self::setNotFoundRenderer($notFoundRenderer);

        if (self::$registered) {
            return;
        }

        self::$registered = true;

        set_exception_handler(static function (Throwable $e): void {
            self::handle($e);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            // 开发模式把 notice/warning 升成异常,当场暴露;生产模式只记不炸 ——
            // 为一条通知把整页变 500,代价远大于收益
            if (APP_DEBUG) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }

            Log::channel('error')->warning($message, ['file' => $file, 'line' => $line]);

            return true;
        });

        register_shutdown_function(static function (): void {
            self::shutdown();
        });
    }

    /**
     * 丢掉注入项,测试用。
     */
    public static function reset(): void
    {
        self::$startedAt = null;
        self::$notFoundRenderer = null;
        self::$shutdownFired = false;
    }

    /**
     * 单独开这个口子:404 交回主题的注入点要可测,而测它不该真去改全进程的异常处理器。
     */
    public static function setNotFoundRenderer(?callable $notFoundRenderer): void
    {
        self::$notFoundRenderer = $notFoundRenderer;
    }

    public static function handle(Throwable $e): void
    {
        $errorId = ErrorId::generate();
        self::log($e, $errorId);

        if (self::isCli()) {
            fwrite(STDERR, self::plainText($e, $errorId) . PHP_EOL);

            exit(ExitCode::fromException($e));
        }

        if (!headers_sent()) {
            self::responseFor($e, $errorId)->send();
        }
    }

    public static function responseFor(Throwable $e, string $errorId, ?bool $debug = null): Response
    {
        $status = $e instanceof HttpMappable ? $e->statusCode() : 500;

        // 默认只看 APP_DEBUG(《26》第 3 节);显式传值只为让两套行为都能被测到
        if ($debug ?? APP_DEBUG) {
            return (new DebugRenderer())->render($e);
        }

        if ($status === 404 && self::$notFoundRenderer !== null) {
            return (self::$notFoundRenderer)($e)->withStatus(404);
        }

        return (new PlainRenderer())->render($errorId, $status, self::friendlyText($status));
    }

    public static function log(Throwable $e, string $errorId): void
    {
        $status = $e instanceof HttpMappable ? $e->statusCode() : 500;
        $context = [
            'err_id' => $errorId,
            'file'   => $e->getFile(),
            'line'   => $e->getLine(),
            'code'   => $e->getCode(),
            'trace'  => $e->getTraceAsString(),
        ];

        try {
            // 《26》第 5.1 节:认证、权限、可疑行为走 security 通道
            if ($e instanceof AuthException || $e instanceof RateLimitException) {
                $logger = Log::channel('security');
            } else {
                $logger = Log::channel('error');
            }

            if ($status >= 500) {
                $logger->error($e->getMessage(), $context);
            } else {
                $logger->warning($e->getMessage(), $context);
            }
        } catch (Throwable) {
            // 日志自己写不了(目录不可写等)不能把错误页也拖没,否则用户只剩白屏
        }
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    public static function plainText(Throwable $e, string $errorId, ?bool $debug = null): string
    {
        if ($debug ?? APP_DEBUG) {
            return $e::class . ': ' . $e->getMessage() . "\n"
                . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        }

        return self::friendlyText($e instanceof HttpMappable ? $e->statusCode() : 500)
            . '(错误编号 ' . $errorId . ')';
    }

    /**
     * 生产可见文案。不含路径、SQL、PHP 版本、异常原文(《26》第 4 节)。
     */
    private static function friendlyText(int $status): string
    {
        return match ($status) {
            401 => '请先登录。',
            403 => '你没有权限进行这个操作。',
            404 => '这一页不存在。',
            422 => '提交的内容没有通过校验。',
            429 => '请求太频繁,请稍后再试。',
            503 => '站点正在维护,稍后恢复。',
            default => '站点暂时没能完成这个请求。',
        };
    }

    /**
     * 请求收尾的统一位置:`register_shutdown_function` 里调的就是这里。
     *
     * 播 `system/shutdown`(《11》§1.1 两段式生命周期的另一半):放在 finally 里,
     * 致命错误、慢请求记账、采样任何一步出岔子都必须走到 —— 插件靠它收自己的账(临时文件、
     * 缓冲、统计),漏播一次就是一次泄漏。只播一次:重复调用直接返回。
     */
    public static function shutdown(): void
    {
        if (self::$shutdownFired) {
            return;
        }

        self::$shutdownFired = true;

        try {
            self::finishRequest();
        } finally {
            self::fireShutdownHook();
        }
    }

    private static function finishRequest(): void
    {
        $fatal = error_get_last();

        if ($fatal !== null && in_array($fatal['type'], self::FATAL_LEVELS, true)) {
            self::handle(new ErrorException(
                $fatal['message'],
                0,
                $fatal['type'],
                $fatal['file'],
                $fatal['line']
            ));
        }

        if (self::$startedAt === null || self::isCli()) {
            return;
        }

        $elapsed = (microtime(true) - self::$startedAt) * 1000;

        if ($elapsed > self::SLOW_REQUEST_MS) {
            Log::channel('app')->warning('slow_request', ['ms' => round($elapsed, 1)]);
        }

        // 《57》§2 的按页采样:与慢请求同源。全站唯一的请求计时器就在这里收尾,
        // 把本次请求的真实耗时交给采样器归档(首页按页面缓存命中 / 未命中分桶,后台归一类),
        // 健康屏「性能预算」组据此出读数 —— 不是打开健康屏时才现测一次。
        (new RequestSamples())->recordFromGlobals(self::$startedAt, $elapsed);
    }

    private static function fireShutdownHook(): void
    {
        try {
            do_action('system/shutdown');
        } catch (Throwable $e) {
            // 收尾阶段已经没有人接异常了:内核回调在这里抛,记一笔就走,别让日志反过来把请求带走
            try {
                Log::channel('error')->error('system/shutdown 监听抛异常:' . $e->getMessage(), [
                    'exception' => $e::class,
                ]);
            } catch (Throwable) {
                // 连日志都写不了:请求已经在收尾,不再往下追
            }
        }
    }
}
