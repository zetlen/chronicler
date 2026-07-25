<?php
// Pure-logic checks for the inbound Slack surface (bot skeleton):
// Signature, Inbound::gate(), Bot\Commands, Deferred::strategy(). Route
// registration, raw-body capture, and the live endpoints are WP/network
// behavior — verified by the Settings self-check and
// scripts/slack-simulate.mjs, not here.

use Chronicler\Slack\Signature;

// --- Signature: Slack's documented example vector ---------------------------
// https://docs.slack.dev/authentication/verifying-requests-from-slack
$secret = '8f742231b10e8888abcd99yyyzzz85a5';
$ts = '1531420618';
$body = 'token=xyzz0WbapA4vBCDEFasx0q6G&team_id=T1DC2JH3J&team_domain=testteamnow&channel_id=G8PSS9T3V&channel_name=foobar&user_id=U2CERLKJA&user_name=roadrunner&command=%2Fwebhook-collect&text=&response_url=https%3A%2F%2Fhooks.slack.com%2Fcommands%2FT1DC2JH3J%2F397700885554%2F96rGlfmibIGlgcZRskXaIFfN&trigger_id=398738663015.47445629121.803a0bc887a14d10d2c447fce8b6703c';
$expected = 'v0=a2114d57b48eac39b9ad189dd8316235a7b4a8d21a10bd27519666489c69b503';

check('compute reproduces the documented Slack vector', Signature::compute($secret, $ts, $body) === $expected);
check('verify accepts the vector inside the window', Signature::verify($secret, $ts, $body, $expected, 1531420618 + 60));
check('verify accepts at the exact tolerance edge', Signature::verify($secret, $ts, $body, $expected, 1531420618 + Signature::TOLERANCE));

// Fail closed: every malformation is false, never an exception.
check('stale timestamp is rejected', !Signature::verify($secret, $ts, $body, $expected, 1531420618 + Signature::TOLERANCE + 1));
check('future skew beyond tolerance is rejected', !Signature::verify($secret, $ts, $body, $expected, 1531420618 - Signature::TOLERANCE - 1));
check('tampered body is rejected', !Signature::verify($secret, $ts, $body . '&evil=1', $expected, 1531420618));
check('tampered signature is rejected', !Signature::verify($secret, $ts, $body, substr($expected, 0, -1) . '0', 1531420618));
check('wrong secret is rejected', !Signature::verify('other-secret', $ts, $body, $expected, 1531420618));
check('missing timestamp header (null) is rejected', !Signature::verify($secret, null, $body, $expected, 1531420618));
check('non-numeric timestamp is rejected', !Signature::verify($secret, 'yesterday', $body, $expected, 1531420618));
check('missing signature header (null) is rejected', !Signature::verify($secret, $ts, $body, null, 1531420618));
check('signature without the v0= prefix is rejected', !Signature::verify($secret, $ts, $body, ltrim($expected, 'v0='), 1531420618));

// --- Bot\Commands: tokenizer + router ---------------------------------------

use Chronicler\Slack\Bot\Commands;

check('tokenize splits sub and rest', Commands::tokenize('roll cool') === ['roll', 'cool']);
check('tokenize lowercases the sub only', Commands::tokenize('ROLL Cool') === ['roll', 'Cool']);
check('tokenize collapses leading/inner whitespace', Commands::tokenize("  help   me  now") === ['help', 'me  now']);
check('tokenize of empty text is empty', Commands::tokenize('   ') === ['', '']);

check('escape handles Slack mrkdwn control chars', Commands::escape('a & b <c> d') === 'a &amp; b &lt;c&gt; d');

$help = Commands::dispatch(['text' => 'help']);
check('help is ephemeral', $help['response_type'] === 'ephemeral');
check('help names every advertised subcommand', !array_diff(
    array_map(static fn($s) => $s[0], Commands::SUBCOMMANDS),
    array_filter(array_map(
        static fn($m) => str_contains($help['text'], Commands::COMMAND . " {$m[0]}") ? $m[0] : null,
        Commands::SUBCOMMANDS
    ))
));
check('empty text is help too', Commands::dispatch(['text' => ''])['text'] === $help['text']);
check('missing text member is help too', Commands::dispatch([])['text'] === $help['text']);

$unknown = Commands::dispatch(['text' => 'dance <script>']);
check('unknown subcommand is ephemeral', $unknown['response_type'] === 'ephemeral');
check('unknown echoes the sub, escaped', str_contains($unknown['text'], '`dance`'));
check('unknown never reflects raw angle brackets', !str_contains(Commands::dispatch(['text' => '<bad>'])['text'], '<bad>'));
check('unknown points at help', str_contains($unknown['text'], Commands::COMMAND . ' help'));

// The manifest is a static YAML file Slack parses, so Commands::COMMAND
// cannot reach it — this is the seam where the two would silently drift
// apart (a renamed command that Slack still routes under the old name).
$manifest = \Chronicler\Settings\Screen::manifest_yaml();
check('manifest declares exactly the command the router answers to', str_contains($manifest, 'command: ' . Commands::COMMAND));
check('manifest usage_hint lists the advertised subcommands', str_contains($manifest, 'usage_hint:')
    && str_contains($manifest, Commands::SUBCOMMANDS[0][0]));

// --- Inbound::gate(): verify-before-everything, as pure data ----------------

use Chronicler\Slack\Inbound;

$sig = Signature::compute('s3cret', '1531420618', 'command=%2Fgame&text=help');
check('gate passes a verified request', Inbound::gate('s3cret', '1531420618', 'command=%2Fgame&text=help', $sig, 1531420618) === null);

$no_secret = Inbound::gate(null, '1531420618', 'body', $sig, 1531420618);
check('gate with no secret is 503', $no_secret !== null && $no_secret[0] === 503);
check('gate 503 names the config problem', $no_secret[1] === 'chronicler_no_signing_secret');

$forged = Inbound::gate('s3cret', '1531420618', 'body-that-was-tampered', $sig, 1531420618);
check('gate with a bad signature is 401', $forged !== null && $forged[0] === 401);
check('gate 401 code is chronicler_bad_signature', $forged[1] === 'chronicler_bad_signature');
check('gate with a stale timestamp is 401', Inbound::gate('s3cret', '1531420618', 'command=%2Fchronicler&text=help', $sig, 1531420618 + 9000)[0] === 401);
check('gate with missing headers is 401, not an exception', Inbound::gate('s3cret', null, 'body', null, 1531420618)[0] === 401);

// --- Deferred::strategy(): SAPI probe ----------------------------------------

use Chronicler\Slack\Deferred;

// The CLI harness has no FastCGI, so the probe must degrade to 'flush'
// (under PHP-FPM on the host it reports 'fastcgi'); the contract is that
// it always names one of the two.
check('Deferred strategy is a known value', in_array(Deferred::strategy(), ['fastcgi', 'flush'], true));
check('Deferred strategy under CLI is flush', Deferred::strategy() === 'flush');

// --- Bot\Link::reply(): the pure link-instructions builder ------------------

use Chronicler\Slack\Bot\Link;

// Link::handle() leans on the sheet helpers render.test.php already stubs
// (chronicler_sheets_player_characters over $chr_test_pcs, get_the_title over
// $chr_test_titles, get_post_meta over $chr_test_post_meta) — reuse them
// rather than shadow them, since function_exists guards make the first
// definition the only one. admin_url() is the one addition.
if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') {
        return 'https://blog.test/wp-admin/' . ltrim((string) $path, '/');
    }
}
// A slash command runs with NO logged-in user, so WordPress's own
// get_edit_post_link() returns null there (it capability-checks the CURRENT
// user before handing back a URL). Stubbed to null so the suite reproduces
// the request context Slack actually gives us: handle() must build the sheet
// URL itself, or the reply ships an empty link — which it did, once.
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id = 0, $context = 'display') {
        return null;
    }
}
$chr_link_saved = [$GLOBALS['chr_test_pcs'] ?? null, $GLOBALS['chr_test_titles'] ?? null];
$GLOBALS['chr_test_pcs'] = [11, 22];
$GLOBALS['chr_test_titles'] = [11 => 'Alec', 22 => 'Brannagh the Bold'];

$hit = Link::reply('U0123ABCDEF', 'alec', ['name' => 'Alec', 'editUrl' => 'https://blog.test/wp-admin/post.php?post=11&action=edit'], []);
check('link reply is ephemeral', $hit['response_type'] === 'ephemeral');
check('link reply carries the caller Slack id', str_contains($hit['text'], 'U0123ABCDEF'));
check('link reply carries the sheet edit url', str_contains($hit['text'], 'post=11&action=edit'));
check('link reply names the character', str_contains($hit['text'], 'Alec'));
check('link reply says where to paste', str_contains($hit['text'], 'Slack member'));

$miss = Link::reply('U0123ABCDEF', 'zoltan', null, ['Alec', 'Brannagh the Bold']);
check('unresolved name is ephemeral', $miss['response_type'] === 'ephemeral');
check('unresolved name is reported', str_contains($miss['text'], 'zoltan'));
check('unresolved name lists the roster', str_contains($miss['text'], 'Brannagh the Bold'));
check('unresolved name never leaks an edit url', !str_contains($miss['text'], 'action=edit'));

$bare = Link::reply('U0123ABCDEF', null, null, ['Alec']);
check('no argument still reports your id', str_contains($bare['text'], 'U0123ABCDEF'));
check('no argument tells you the syntax', str_contains($bare['text'], 'link <'));

// The caller's Slack id comes from the verified payload, never from text.
$dispatched = Commands::dispatch(['text' => 'link', 'user_id' => 'UFROMPAYLOAD']);
check('dispatch threads user_id into the link reply', str_contains($dispatched['text'], 'UFROMPAYLOAD'));
check('link with no user_id degrades safely', is_string(Commands::dispatch(['text' => 'link'])['text']));

// --- Bot\Link::handle(): the sheet URL survives the anonymous request -------
// The deep link IS the feature, and it is built in the one context where
// get_edit_post_link() refuses to build one (no current user). Assert the
// whole round trip, not just reply()'s formatting of a URL handed to it.
$resolved = Commands::dispatch(['text' => 'link Alec', 'user_id' => 'USIMULATE']);
check('handle resolves a roster name to the sheet url', str_contains($resolved['text'], 'post=11&action=edit'));
check('handle never emits an empty link target', !str_contains($resolved['text'], '<|'));
check('handle matches case-insensitively end to end', Commands::dispatch(['text' => 'link ALEC', 'user_id' => 'USIMULATE'])['text'] === $resolved['text']);
check('handle reports the payload id alongside the url', str_contains($resolved['text'], 'USIMULATE'));
check('an unmatched name still lists the live roster', str_contains(
    Commands::dispatch(['text' => 'link zoltan', 'user_id' => 'USIMULATE'])['text'],
    'Brannagh the Bold'
));

// The roster/title globals are shared harness state; hand them back as found.
[$GLOBALS['chr_test_pcs'], $GLOBALS['chr_test_titles']] = $chr_link_saved;
