<?php

namespace Chronicler\Slack;

use Chronicler\Settings\Screen;

/**
 * Thin Slack Web API client (#99): one outbound HTTPS call per invocation,
 * bearer-authenticated with the bot token from Settings\Screen (which the
 * browser never sees — Ground rule 3). Deliberately stateless: no caching,
 * no locks, no retries, and above all NO SLEEPING — a Slack 429 becomes a
 * RateLimited exception carrying retry_after, and the browser owns the wait
 * (the #96 session editor's fetch-loop contract).
 *
 * Failure surface:
 * - HTTP 429            -> RateLimited (retry_after from the Retry-After
 *                          header, DEFAULT_RETRY_AFTER when unparseable)
 * - transport failure   -> ApiError('http_error')
 * - non-JSON body       -> ApiError('invalid_response')
 * - `ok: false` body    -> ApiError(<Slack's error code>)
 * - no token configured -> ApiError('missing_token') — callers that can
 *                          say something friendlier (the proxy's 409)
 *                          check Screen::bot_token() first.
 */
class Client
{
    public const BASE_URL = 'https://slack.com/api/';
    public const DEFAULT_RETRY_AFTER = 30;
    private const TIMEOUT = 15;

    /**
     * Call one Slack Web API method and return its decoded JSON body
     * verbatim (`ok: true` guaranteed — anything else throws, see the
     * class docblock).
     *
     * Encoding: scalar-only args go form-encoded, which every read method
     * accepts; args with any structured value (arrays — e.g. blocks, if a
     * write method ever joins the allowlist) switch the request to JSON,
     * which Slack only honors for methods that document it.
     */
    public function call(string $method, array $args): array
    {
        $token = Screen::bot_token();
        if ($token === null) {
            throw new ApiError('missing_token', 'No Slack bot token is configured.');
        }

        $structured = array_filter($args, static fn($value) => !is_scalar($value));
        $response = wp_remote_post(self::BASE_URL . $method, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => $structured === []
                    ? 'application/x-www-form-urlencoded'
                    : 'application/json; charset=utf-8',
            ],
            'body' => $structured === [] ? $args : wp_json_encode($args),
        ]);

        if (is_wp_error($response)) {
            throw new ApiError('http_error', 'Slack request failed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 429) {
            throw new RateLimited(
                self::retryAfterSeconds(wp_remote_retrieve_header($response, 'retry-after'))
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            throw new ApiError('invalid_response', "Slack returned a non-JSON response (HTTP $status).");
        }
        if (empty($body['ok'])) {
            $error = $body['error'] ?? null;
            $code = is_string($error) && $error !== '' ? $error : 'unknown_error';
            throw new ApiError($code, "Slack returned an error: $code");
        }
        return $body;
    }

    /**
     * Parse a Retry-After header value into whole seconds. Slack sends a
     * bare integer; anything else (absent, empty, HTTP-date, zero,
     * negative) degrades to DEFAULT_RETRY_AFTER. wp_remote_retrieve_header
     * may hand back an array when the header repeats — first value wins.
     * Pure; unit-tested in tests/run.php.
     */
    public static function retryAfterSeconds(mixed $header): int
    {
        if (is_array($header)) {
            $header = reset($header);
        }
        if (is_int($header) && $header > 0) {
            return $header;
        }
        if (is_string($header) && preg_match('/^\s*(\d+)\s*$/', $header, $m) === 1) {
            $seconds = (int) $m[1];
            if ($seconds > 0) {
                return $seconds;
            }
        }
        return self::DEFAULT_RETRY_AFTER;
    }
}
