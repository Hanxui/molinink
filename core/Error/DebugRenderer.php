<?php

/**
 * 墨林 - Molinink
 * @author Kira
 * @link   https://www.molinink.com
 */

declare(strict_types=1);

namespace Molin\Error;

use Molin\Exception\HttpMappable;
use Molin\Http\Response;
use Throwable;

/**
 * 开发模式(APP_DEBUG=true)的完整堆栈页。
 * 生产路径永远不会走到这里。
 */
final class DebugRenderer
{
    public function render(Throwable $e): Response
    {
        $status = $e instanceof HttpMappable ? $e->statusCode() : 500;

        $html = '<!doctype html>'
            . '<html lang="zh-Hans"><head><meta charset="utf-8"><title>'
            . $this->esc($e::class)
            . '</title></head><body>'
            . '<h1>' . $this->esc($e::class) . '</h1>'
            . '<p>' . $this->esc($e->getMessage()) . '</p>'
            . '<p>码:' . $status . ' / ' . ($e instanceof HttpMappable ? $this->esc($e->errorCode()) : $e->getCode()) . '</p>'
            . '<pre>' . $this->esc($e->getFile() . ':' . $e->getLine()) . "\n\n"
            . $this->esc($e->getTraceAsString()) . '</pre>';

        $previous = $e->getPrevious();

        if ($previous !== null) {
            $html .= '<h2>由它引起</h2><pre>' . $this->esc($previous::class . ': ' . $previous->getMessage()) . '</pre>';
        }

        return Response::html($html . '</body></html>', $status >= 400 ? $status : 500);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
