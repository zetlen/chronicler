<?php
// Pure-logic checks for src/Slack/{Client,Proxy}.php (#99). Included by
// run.php. The live transport (wp_remote_post), REST wiring, and
// WordPress's case-insensitive route matching are verified at runtime in
// wp-env, not here.

use Chronicler\Slack\Client;
use Chronicler\Slack\Proxy;

// --- allowlist: exact and case-sensitive -------------------------------------
// WordPress matches route regexes case-insensitively, so garbage like
// SLACK/BADMETHOD reaches the handler; this in_array-strict gate is the
// only real filter (recorded on #99).

check('allowlist is exactly the six #99 methods', Proxy::ALLOWLIST === [
    'conversations.list',
    'conversations.history',
    'conversations.replies',
    'users.info',
    'emoji.list',
    'auth.test',
]);

foreach (Proxy::ALLOWLIST as $method) {
    check("$method with no args validates", Proxy::validate($method, [])['ok'] === true);
}

$rejected = [
    'BADMETHOD',              // what SLACK/BADMETHOD delivers to the handler
    'Conversations.History',  // mixed case
    'CONVERSATIONS.HISTORY',  // upper case
    'conversations..list',    // multi-dot, matches the [a-z.]+ route regex
    '.conversations.list',
    'conversations.list.',
    'conversations.list ',    // trailing space
    ' conversations.list',
    'chat.postMessage',       // real method, deliberately not proxied
    'users.list',             // real method, deliberately not proxied
    'admin.conversations.search',
    '',
];
foreach ($rejected as $method) {
    $result = Proxy::validate($method, []);
    check(
        "method '$method' is rejected",
        $result['ok'] === false && $result['code'] === 'chronicler_method_not_allowed'
    );
}

// --- per-method argument whitelists ------------------------------------------

$ok = Proxy::validate('conversations.history', [
    'channel' => 'C0123456789',
    'cursor' => 'dXNlcjpVMDYxTkZUVDI=',
    'limit' => 200,
    'oldest' => '1712345678.000100',
    'latest' => '1712349278.999999',
    'inclusive' => true,
]);
check('history accepts its full whitelist', $ok['ok'] === true, json_encode($ok));
check('validated args pass through', ($ok['args']['channel'] ?? null) === 'C0123456789');
check('inclusive normalizes to a form-encodable string', ($ok['args']['inclusive'] ?? null) === 'true');
check('inclusive false normalizes too', Proxy::validate('conversations.history', ['channel' => 'C1', 'inclusive' => false])['args']['inclusive'] === 'false');
check('no filters requested means none applied', $ok['filters'] === []);

$ok = Proxy::validate('conversations.replies', ['channel' => 'C1', 'ts' => '1712345678.000100']);
check('replies accepts ts', $ok['ok'] === true);
$ok = Proxy::validate('users.info', ['user' => 'U0123456789']);
check('users.info accepts user', $ok['ok'] === true);

// conversations.list: the full whitelist the channel picker sends — the
// Node app's exact arguments (lib/slack/fetchMessages.ts listChannels).
$ok = Proxy::validate('conversations.list', [
    'types' => 'public_channel,private_channel',
    'exclude_archived' => true,
    'limit' => 200,
    'cursor' => 'dXNlcjpVMDYxTkZUVDI=',
]);
check('conversations.list accepts its full whitelist', $ok['ok'] === true, json_encode($ok));
check('types passes through verbatim', ($ok['args']['types'] ?? null) === 'public_channel,private_channel');
check('exclude_archived normalizes to a form-encodable string', ($ok['args']['exclude_archived'] ?? null) === 'true');
check('exclude_archived false normalizes too', Proxy::validate('conversations.list', ['exclude_archived' => false])['args']['exclude_archived'] === 'false');
check('stringy exclude_archived is accepted', Proxy::validate('conversations.list', ['exclude_archived' => 'true'])['ok'] === true);
check('numeric exclude_archived is rejected', Proxy::validate('conversations.list', ['exclude_archived' => 1])['ok'] === false);

check('types vocabulary is the full Slack enum', Proxy::CONVERSATION_TYPES === ['public_channel', 'private_channel', 'mpim', 'im']);
foreach (Proxy::CONVERSATION_TYPES as $type) {
    check("types accepts '$type' alone", Proxy::validate('conversations.list', ['types' => $type])['ok'] === true);
}
check('types accepts every type at once', Proxy::validate('conversations.list', [
    'types' => 'public_channel,private_channel,mpim,im',
])['ok'] === true);
$badTypes = [
    'everything',                       // not in the vocabulary
    'public_channel,secret_channel',    // one bad token spoils the list
    'public_channel, private_channel',  // whitespace is not trimmed away
    'PUBLIC_CHANNEL',                   // case-sensitive
    'public_channel,',                  // trailing comma = empty token
    ',private_channel',                 // leading comma = empty token
    '',                                 // empty string
];
foreach ($badTypes as $types) {
    $result = Proxy::validate('conversations.list', ['types' => $types]);
    check(
        "types '$types' is rejected",
        $result['ok'] === false && $result['code'] === 'chronicler_bad_args',
        json_encode($result)
    );
}
check('array types is rejected', Proxy::validate('conversations.list', ['types' => ['public_channel']])['ok'] === false);
check('boolean types is rejected', Proxy::validate('conversations.list', ['types' => true])['ok'] === false);

// Unknown args are rejected — including args valid for OTHER methods, and
// any attempt to smuggle a token past the server-side credential.
$cases = [
    ['users.info', ['user' => 'U1', 'channel' => 'C1'], 'channel is not a users.info arg'],
    ['conversations.list', ['channel' => 'C1'], 'channel is not a conversations.list arg'],
    ['conversations.history', ['channel' => 'C1', 'ts' => '1.0'], 'ts is not a history arg'],
    ['conversations.history', ['channel' => 'C1', 'types' => 'public_channel'], 'types is not a history arg'],
    ['conversations.history', ['channel' => 'C1', 'exclude_archived' => true], 'exclude_archived is not a history arg'],
    ['auth.test', ['limit' => 1], 'auth.test takes no args'],
    ['emoji.list', ['anything' => 'x'], 'emoji.list takes no args'],
    ['conversations.history', ['channel' => 'C1', 'token' => 'xoxb-evil'], 'client-supplied token'],
    ['auth.test', ['token' => 'xoxb-evil'], 'client-supplied token on auth.test'],
    ['conversations.list', ['method' => 'chat.postMessage'], 'method smuggled as a body arg'],
];
foreach ($cases as [$method, $args, $why]) {
    $result = Proxy::validate($method, $args);
    check("unknown arg rejected: $why", $result['ok'] === false && $result['code'] === 'chronicler_bad_args');
}

// Argument value validation.
check('limit over 200 is rejected', Proxy::validate('conversations.list', ['limit' => 201])['ok'] === false);
check('limit 0 is rejected', Proxy::validate('conversations.list', ['limit' => 0])['ok'] === false);
check('negative limit is rejected', Proxy::validate('conversations.list', ['limit' => -5])['ok'] === false);
check('boolean limit is rejected', Proxy::validate('conversations.list', ['limit' => true])['ok'] === false);
$ok = Proxy::validate('conversations.list', ['limit' => '50']);
check('digit-string limit normalizes to int', $ok['ok'] === true && $ok['args']['limit'] === 50);
check('empty channel is rejected', Proxy::validate('conversations.history', ['channel' => ''])['ok'] === false);
check('array channel is rejected', Proxy::validate('conversations.history', ['channel' => ['C1']])['ok'] === false);
check('empty user is rejected', Proxy::validate('users.info', ['user' => ''])['ok'] === false);
check('non-ts oldest is rejected', Proxy::validate('conversations.history', ['channel' => 'C1', 'oldest' => 'yesterday'])['ok'] === false);
check('double-dot ts is rejected', Proxy::validate('conversations.history', ['channel' => 'C1', 'oldest' => '12.34.56'])['ok'] === false);
check('negative ts is rejected', Proxy::validate('conversations.history', ['channel' => 'C1', 'oldest' => '-5'])['ok'] === false);
$ok = Proxy::validate('conversations.history', ['channel' => 'C1', 'oldest' => 1712345678]);
check('bare integer ts is stringified', $ok['ok'] === true && $ok['args']['oldest'] === '1712345678');
check('float ts is rejected (precision)', Proxy::validate('conversations.history', ['channel' => 'C1', 'oldest' => 1712345678.000100])['ok'] === false);
check('stringy inclusive is accepted', Proxy::validate('conversations.history', ['channel' => 'C1', 'inclusive' => 'true'])['ok'] === true);
check('numeric inclusive is rejected', Proxy::validate('conversations.history', ['channel' => 'C1', 'inclusive' => 1])['ok'] === false);

// A JSON list body is not an args object.
$result = Proxy::validate('conversations.history', ['C1', 'C2']);
check('list body is rejected', $result['ok'] === false && $result['code'] === 'chronicler_bad_args');

// --- response filters: vocabulary and shape ----------------------------------

$ok = Proxy::validate('conversations.history', [
    'channel' => 'C1',
    'filters' => ['trim_ts_range' => ['oldest' => '100.000000', 'latest' => '200.000000']],
]);
check('trim_ts_range validates on history', $ok['ok'] === true, json_encode($ok));
check('filters never reach the Slack args', !isset($ok['args']['filters']));
check('filter bounds are normalized', $ok['filters']['trim_ts_range'] === ['oldest' => '100.000000', 'latest' => '200.000000']);

check('one-bound trim validates', Proxy::validate('conversations.history', [
    'channel' => 'C1', 'filters' => ['trim_ts_range' => ['latest' => '200']],
])['ok'] === true);
check('integer bound is stringified', Proxy::validate('conversations.history', [
    'channel' => 'C1', 'filters' => ['trim_ts_range' => ['oldest' => 100]],
])['filters']['trim_ts_range']['oldest'] === '100');

$bad = [
    ['conversations.list', ['filters' => ['trim_ts_range' => ['oldest' => '1']]], 'trim on a method without filters'],
    ['conversations.history', ['filters' => ['drop_subtypes' => []]], 'unknown filter name'],
    ['conversations.history', ['filters' => ['trim_ts_range' => []]], 'empty filter spec'],
    ['conversations.history', ['filters' => ['trim_ts_range' => ['1', '2']]], 'list filter spec'],
    ['conversations.history', ['filters' => ['trim_ts_range' => ['before' => '1']]], 'unknown bound name'],
    ['conversations.history', ['filters' => ['trim_ts_range' => ['oldest' => 'abc']]], 'non-ts bound'],
    ['conversations.history', ['filters' => ['trim_ts_range']], 'filters as a list'],
    ['conversations.history', ['filters' => 'trim_ts_range'], 'filters as a string'],
];
foreach ($bad as [$method, $body, $why]) {
    $body += ['channel' => 'C1'];
    $result = Proxy::validate($method, $body);
    check(
        "bad filter rejected: $why",
        $result['ok'] === false && $result['code'] === 'chronicler_bad_filter',
        json_encode($result)
    );
}
check('empty filters object is a no-op', Proxy::validate('conversations.history', [
    'channel' => 'C1', 'filters' => [],
]) === ['ok' => true, 'args' => ['channel' => 'C1'], 'filters' => []]);

// --- trim_ts_range application -----------------------------------------------

$slackBody = [
    'ok' => true,
    'messages' => [
        ['ts' => '999.500000', 'text' => 'before'],
        ['ts' => '1000.100000', 'text' => 'at oldest'],
        ['ts' => '1500.000000', 'text' => 'inside'],
        ['ts' => '2000.900000', 'text' => 'at latest'],
        ['ts' => '2000.900001', 'text' => 'after'],
        ['text' => 'no ts — kept: the filter only trims what it can measure'],
    ],
    'has_more' => true,
    'response_metadata' => ['next_cursor' => 'abc'],
];
$trimmed = Proxy::applyFilter('trim_ts_range', ['oldest' => '1000.100000', 'latest' => '2000.900000'], $slackBody);
$kept = array_column($trimmed['messages'], 'text');
check(
    'trim keeps the inclusive range plus unmeasurable messages',
    $kept === ['at oldest', 'inside', 'at latest', 'no ts — kept: the filter only trims what it can measure'],
    json_encode($kept)
);
check('trim reindexes so messages stays a JSON list', array_is_list($trimmed['messages']));
check('trim passes the rest of the body verbatim', $trimmed['has_more'] === true && $trimmed['response_metadata'] === ['next_cursor' => 'abc']);

// Cross-width comparison: naive string compare would put "999.5" AFTER
// "1000.1"; float compare would lose Slack's 16 significant digits.
$wide = Proxy::applyFilter('trim_ts_range', ['oldest' => '1000'], $slackBody);
check('999.5 sorts below 1000 (no lexicographic trap)', !in_array('before', array_column($wide['messages'], 'text'), true));
$precise = Proxy::applyFilter(
    'trim_ts_range',
    ['latest' => '1712345678.123456'],
    ['ok' => true, 'messages' => [['ts' => '1712345678.123457', 'text' => 'one-microsecond late']]]
);
check('microsecond precision survives (no float trap)', $precise['messages'] === []);

$oneBound = Proxy::applyFilter('trim_ts_range', ['oldest' => '1500.000000'], $slackBody);
check('single lower bound trims only below', array_column($oneBound['messages'], 'text')[0] === 'inside');
check('body without messages passes through', Proxy::applyFilter('trim_ts_range', ['oldest' => '1'], ['ok' => true]) === ['ok' => true]);

// --- Retry-After parsing (Client::retryAfterSeconds) --------------------------

check('integer header parses', Client::retryAfterSeconds('12') === 12);
check('int-typed header parses', Client::retryAfterSeconds(12) === 12);
check('padded header parses', Client::retryAfterSeconds(' 45 ') === 45);
check('repeated header takes the first value', Client::retryAfterSeconds(['7', '9']) === 7);
check('missing header defaults to 30', Client::retryAfterSeconds('') === Client::DEFAULT_RETRY_AFTER);
check('null header defaults to 30', Client::retryAfterSeconds(null) === 30);
check('HTTP-date header defaults to 30', Client::retryAfterSeconds('Fri, 11 Jul 2026 12:00:00 GMT') === 30);
check('zero header defaults to 30 (never a hot loop)', Client::retryAfterSeconds('0') === 30);
check('negative header defaults to 30', Client::retryAfterSeconds('-1') === 30);
check('fractional header defaults to 30', Client::retryAfterSeconds('1.5') === 30);
check('default retry is 30s', Client::DEFAULT_RETRY_AFTER === 30);
