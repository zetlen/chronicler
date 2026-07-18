<?php

namespace Chronicler\Slack;

use Chronicler\Settings\Screen;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The stateless allowlisted Slack proxy behind POST chronicler/v1/slack/
 * {method} (#99). The #96 session editor pulls Slack pages one at a time
 * through here; the plugin holds no fetch state — no cursors, no locks, no
 * server-side waiting.
 *
 * SECURITY: the exact, case-sensitive ALLOWLIST check in validate() is the
 * ONLY method gate. WordPress matches route regexes case-INsensitively, so
 * `SLACK/BADMETHOD` and `slack/conversations..list` both reach this handler
 * — the `[a-z.]+` route pattern filters nothing (verified in testing,
 * recorded on issue #99). Never widen the allowlist by pattern.
 *
 * Request contract (all responses are JSON):
 * - Body: a JSON object of Slack arguments (form-encoded also accepted as
 *   a curl convenience). Every argument must appear in the method's
 *   whitelist below — unknown args, and any attempt to smuggle `token`,
 *   are a 400 (`chronicler_bad_args`).
 * - `filters` is a RESERVED body member (never forwarded to Slack): an
 *   object of named response filters applied server-side to Slack's reply.
 *   Each filter is itself allowlisted per method. The vocabulary — grown
 *   only when a real consumer needs more (candidate: drop_subtypes):
 *
 *     trim_ts_range: {oldest?: ts, latest?: ts}   (at least one bound)
 *       conversations.history only. Drops entries of the response's
 *       `messages` array whose `ts` lies outside [oldest, latest]
 *       (inclusive). Messages without a string `ts` are kept (the filter
 *       only trims what it can measure); everything else in the body
 *       passes through verbatim.
 *
 * Response contract:
 * - success            -> 200, Slack's JSON body verbatim (post-filters)
 * - bad method/args    -> 400 {code: chronicler_method_not_allowed |
 *                              chronicler_bad_args | chronicler_bad_filter,
 *                              message, data: {status: 400}}
 * - no token           -> 409 {code: chronicler_no_token, message, ...}
 * - Slack rate limit   -> 429 {code: chronicler_rate_limited,
 *                              retry_after: <seconds>} — the browser waits
 *                              retry_after and retries; the server never does
 * - Slack/transport    -> 502 {code: chronicler_slack_error, message,
 *   error                     data: {status: 502, slack_error: <code>}}
 */
class Proxy
{
    /**
     * The exact Slack Web API methods this proxy will call — matched
     * case-sensitively with in_array strict (see the class docblock for
     * why nothing upstream narrows this).
     */
    public const ALLOWLIST = [
        'conversations.list',
        'conversations.history',
        'conversations.replies',
        'users.info',
        'emoji.list',
        'auth.test',
    ];

    public const MAX_LIMIT = 200;

    /** Slack timestamp: unix seconds, optionally dot-fraction ("1712345678.123456"). */
    private const TS_PATTERN = '/^\d{1,16}(\.\d{1,6})?$/';

    /**
     * The conversation types conversations.list may request — Slack's full
     * `types` vocabulary. The Node app asks for public+private channels
     * (lib/slack/fetchMessages.ts listChannels); the whitelist carries the
     * whole enum so the proxy never needs another bump for DMs.
     */
    public const CONVERSATION_TYPES = ['public_channel', 'private_channel', 'mpim', 'im'];

    /**
     * Per-method argument whitelists. Validation vocabulary (checkArg):
     * channel/cursor/user = non-empty string; limit = integer 1..MAX_LIMIT;
     * oldest/latest/ts = TS_PATTERN string (bare ints accepted and
     * stringified); inclusive/exclude_archived = boolean (or "true"/"false");
     * types = comma-list drawn exactly from CONVERSATION_TYPES.
     */
    private const METHOD_ARGS = [
        'conversations.list' => ['cursor', 'limit', 'types', 'exclude_archived'],
        'conversations.history' => ['channel', 'cursor', 'limit', 'oldest', 'latest', 'inclusive'],
        'conversations.replies' => ['channel', 'ts', 'cursor', 'limit', 'oldest', 'latest', 'inclusive'],
        'users.info' => ['user'],
        'emoji.list' => [],
        'auth.test' => [],
    ];

    /** Which response filters each method supports (absent = none). */
    private const METHOD_FILTERS = [
        'conversations.history' => ['trim_ts_range'],
    ];

    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /** The one REST entry point; Routes::slackProxy delegates here. */
    public function handle(WP_REST_Request $request)
    {
        // The URL param specifically: get_param('method') would let a JSON
        // body member shadow the path segment the caller thinks they hit.
        $method = (string) ($request->get_url_params()['method'] ?? '');
        $body = $request->get_json_params() ?? $request->get_body_params();

        $validated = self::validate($method, is_array($body) ? $body : []);
        if ($validated['ok'] !== true) {
            return new WP_Error($validated['code'], $validated['message'], ['status' => 400]);
        }

        if (Screen::bot_token() === null) {
            return new WP_Error(
                'chronicler_no_token',
                'No Slack bot token is configured. Save one on the Chronicler → Settings screen.',
                ['status' => 409]
            );
        }

        try {
            $result = $this->client->call($method, $validated['args']);
        } catch (RateLimited $e) {
            // The #96 editor's contract: it reads retry_after, waits it out
            // client-side, and retries. No server-side sleeping, ever.
            return new WP_REST_Response(
                ['code' => 'chronicler_rate_limited', 'retry_after' => $e->retryAfter],
                429
            );
        } catch (ApiError $e) {
            return new WP_Error(
                'chronicler_slack_error',
                $e->getMessage(),
                ['status' => 502, 'slack_error' => $e->slackError]
            );
        }

        foreach ($validated['filters'] as $name => $spec) {
            $result = self::applyFilter($name, $spec, $result);
        }
        return rest_ensure_response($result);
    }

    /* ------------------------------------------------------------------ *
     * Pure validation core — no WordPress calls; unit-tested in
     * tests/run.php.
     * ------------------------------------------------------------------ */

    /**
     * Validate a request against the allowlist, the method's argument
     * whitelist, and the filter vocabulary. Returns
     * ['ok' => true, 'args' => <normalized args>, 'filters' => <normalized>]
     * or ['ok' => false, 'code' => ..., 'message' => ...] (always a 400).
     */
    public static function validate(string $method, array $body): array
    {
        if (!in_array($method, self::ALLOWLIST, true)) {
            return self::problem(
                'chronicler_method_not_allowed',
                "Slack method '$method' is not proxied. Allowed methods: " . implode(', ', self::ALLOWLIST) . '.'
            );
        }
        if ($body !== [] && array_is_list($body)) {
            return self::problem('chronicler_bad_args', 'The request body must be a JSON object of Slack arguments.');
        }

        $filters = [];
        if (array_key_exists('filters', $body)) {
            $result = self::validateFilters($method, $body['filters']);
            if (($result['ok'] ?? false) !== true) {
                return $result;
            }
            $filters = $result['filters'];
            unset($body['filters']);
        }

        $allowed = self::METHOD_ARGS[$method];
        $args = [];
        foreach ($body as $name => $value) {
            if (!in_array((string) $name, $allowed, true)) {
                return self::problem(
                    'chronicler_bad_args',
                    "Argument '$name' is not accepted for $method. "
                        . ($allowed === [] ? 'It takes no arguments.' : 'Allowed arguments: ' . implode(', ', $allowed) . '.')
                );
            }
            $checked = self::checkArg((string) $name, $value);
            if ($checked['ok'] !== true) {
                return self::problem('chronicler_bad_args', $checked['message']);
            }
            $args[$name] = $checked['value'];
        }
        return ['ok' => true, 'args' => $args, 'filters' => $filters];
    }

    /** One argument against the shared vocabulary; normalizes for transport. */
    private static function checkArg(string $name, mixed $value): array
    {
        switch ($name) {
            case 'channel':
            case 'cursor':
            case 'user':
                if (is_string($value) && $value !== '') {
                    return ['ok' => true, 'value' => $value];
                }
                return ['ok' => false, 'message' => "Argument '$name' must be a non-empty string."];
            case 'limit':
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    $limit = (int) $value;
                    if ($limit >= 1 && $limit <= self::MAX_LIMIT) {
                        return ['ok' => true, 'value' => $limit];
                    }
                }
                return ['ok' => false, 'message' => "Argument 'limit' must be an integer between 1 and " . self::MAX_LIMIT . '.'];
            case 'oldest':
            case 'latest':
            case 'ts':
                $ts = self::asTs($value);
                if ($ts !== null) {
                    return ['ok' => true, 'value' => $ts];
                }
                return ['ok' => false, 'message' => "Argument '$name' must be a Slack timestamp like \"1712345678.123456\"."];
            case 'inclusive':
            case 'exclude_archived':
                if (is_bool($value)) {
                    return ['ok' => true, 'value' => $value ? 'true' : 'false'];
                }
                if ($value === 'true' || $value === 'false') {
                    return ['ok' => true, 'value' => $value];
                }
                return ['ok' => false, 'message' => "Argument '$name' must be a boolean."];
            case 'types':
                // A comma-list drawn exactly from CONVERSATION_TYPES — no
                // blanks, no whitespace, no case variance. Fail closed on
                // anything else: the value is forwarded to Slack verbatim.
                if (is_string($value) && $value !== '') {
                    foreach (explode(',', $value) as $type) {
                        if (!in_array($type, self::CONVERSATION_TYPES, true)) {
                            return ['ok' => false, 'message' =>
                                "Argument 'types' must be a comma-separated list of: "
                                . implode(', ', self::CONVERSATION_TYPES) . '.'];
                        }
                    }
                    return ['ok' => true, 'value' => $value];
                }
                return ['ok' => false, 'message' => "Argument 'types' must be a non-empty string."];
        }
        // Unreachable while METHOD_ARGS only names vocabulary args; fail
        // closed if the tables ever drift.
        return ['ok' => false, 'message' => "Argument '$name' has no validator."];
    }

    /** Normalize a candidate Slack timestamp, or null when invalid. */
    private static function asTs(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            $value = (string) $value;
        }
        if (is_string($value) && preg_match(self::TS_PATTERN, $value) === 1) {
            return $value;
        }
        return null;
    }

    /**
     * The `filters` body member against the vocabulary in the class
     * docblock. Returns ['ok' => true, 'filters' => <normalized>] or a
     * problem array.
     */
    public static function validateFilters(string $method, mixed $filters): array
    {
        if (!is_array($filters) || ($filters !== [] && array_is_list($filters))) {
            return self::problem('chronicler_bad_filter', "The 'filters' member must be an object of named response filters.");
        }
        $supported = self::METHOD_FILTERS[$method] ?? [];
        $normalized = [];
        foreach ($filters as $name => $spec) {
            if (!in_array((string) $name, $supported, true)) {
                return self::problem(
                    'chronicler_bad_filter',
                    "Response filter '$name' is not supported for $method."
                        . ($supported === [] ? '' : ' Supported: ' . implode(', ', $supported) . '.')
                );
            }
            // Only trim_ts_range exists today; shape-check its spec.
            if (!is_array($spec) || $spec === [] || array_is_list($spec)) {
                return self::problem('chronicler_bad_filter', "Filter 'trim_ts_range' takes {oldest?, latest?} with at least one bound.");
            }
            $range = [];
            foreach ($spec as $key => $value) {
                if (!in_array((string) $key, ['oldest', 'latest'], true)) {
                    return self::problem('chronicler_bad_filter', "Filter 'trim_ts_range' does not take '$key'; only oldest and latest.");
                }
                $ts = self::asTs($value);
                if ($ts === null) {
                    return self::problem('chronicler_bad_filter', "Filter 'trim_ts_range' bound '$key' must be a Slack timestamp.");
                }
                $range[$key] = $ts;
            }
            $normalized[$name] = $range;
        }
        return ['ok' => true, 'filters' => $normalized];
    }

    /** Apply one validated filter to a successful Slack response body. */
    public static function applyFilter(string $name, array $spec, array $body): array
    {
        // Only trim_ts_range exists; a switch grows with the vocabulary.
        if ($name !== 'trim_ts_range' || !isset($body['messages']) || !is_array($body['messages'])) {
            return $body;
        }
        $oldest = isset($spec['oldest']) ? self::tsKey($spec['oldest']) : null;
        $latest = isset($spec['latest']) ? self::tsKey($spec['latest']) : null;
        $body['messages'] = array_values(array_filter(
            $body['messages'],
            static function ($message) use ($oldest, $latest): bool {
                $ts = is_array($message) ? ($message['ts'] ?? null) : null;
                if (!is_string($ts) || $ts === '') {
                    return true; // only trim what we can measure
                }
                $key = self::tsKey($ts);
                return ($oldest === null || $key >= $oldest) && ($latest === null || $key <= $latest);
            }
        ));
        return $body;
    }

    /**
     * Sortable fixed-width form of a Slack ts, so range comparison is a
     * plain string compare — floats would lose precision on Slack's
     * 16-significant-digit timestamps, and naive string compare misorders
     * across second-count widths ("999.5" vs "1000.1").
     */
    private static function tsKey(string $ts): string
    {
        $dot = strpos($ts, '.');
        $seconds = $dot === false ? $ts : substr($ts, 0, $dot);
        $fraction = $dot === false ? '' : substr($ts, $dot + 1);
        return str_pad($seconds, 20, '0', STR_PAD_LEFT) . '.' . str_pad($fraction, 9, '0', STR_PAD_RIGHT);
    }

    private static function problem(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
