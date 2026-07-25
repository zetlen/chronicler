<?php

namespace Chronicler\Slack;

/**
 * Ack-then-work escape hatch (spec §2): emit a complete response inside
 * Slack's 3-second window, keep working in the same process. NO CALLER in
 * the skeleton — every current handler answers synchronously, and the
 * Settings self-check measures whether that ever stops being true. When a
 * handler measurably breaches ~2s, wrap it: Deferred::respond($ack, $work)
 * where $work posts its real answer to the payload's response_url.
 *
 * Strategy: under PHP-FPM (DreamHost shared) fastcgi_finish_request()
 * flushes the response and frees the connection while $work continues —
 * the process still occupies an FPM worker, so keep $work under ~10s.
 * Elsewhere, degrade to flushing output buffers with the connection held
 * open; ignore_user_abort keeps $work alive if Slack hangs up first.
 * The spec's cron leg (work too big for either) lands with its first real
 * caller: callables don't serialize, so that contract needs a concrete
 * payload to design against.
 */
final class Deferred
{
    /** Which flush strategy this SAPI offers. */
    public static function strategy(): string
    {
        return function_exists('fastcgi_finish_request') ? 'fastcgi' : 'flush';
    }

    /**
     * Emit $ack as the complete HTTP response, run $work, exit. Callers
     * use this INSTEAD of returning from a REST handler — nothing runs
     * afterward except registered shutdown hooks.
     */
    public static function respond(array $ack, callable $work): void
    {
        ignore_user_abort(true);
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode($ack);
        if (self::strategy() === 'fastcgi') {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
        $work();
        exit;
    }
}
