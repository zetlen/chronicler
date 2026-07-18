<?php
// Pure-logic checks for src/Settings/Screen.php (#98). Included by run.php.
// Menu registration, the form flow, and the live auth.test call are
// WordPress/network behavior, verified at runtime in wp-env, not here.

use Chronicler\Settings\Screen;

// bot_token() falls back to the option when no constant is defined.
$GLOBALS['chronicler_test_options'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['chronicler_test_options'][$name] ?? $default;
    }
}

// The #99 Slack client consumes exactly these names; renaming is a contract
// break, same as the routes table.
check('bot token option is chronicler_slack_bot_token', Screen::OPTION === 'chronicler_slack_bot_token');
check('override constant is CHRONICLER_SLACK_BOT_TOKEN', Screen::CONSTANT === 'CHRONICLER_SLACK_BOT_TOKEN');

// --- mask(): write-only redisplay -------------------------------------------

$masked = Screen::mask('xoxb-1234567890123-4567890123456-AbCdEfGhIjKlMnOpQrStw7Fq');
check('mask keeps the xoxb- prefix', str_starts_with($masked, 'xoxb-'), $masked);
check('mask reveals only the last 4 chars', str_ends_with($masked, "\u{2022}w7Fq"), $masked);
check(
    'mask bullets the middle',
    $masked === "xoxb-\u{2022}\u{2022}\u{2022}\u{2022}w7Fq",
    $masked
);
check('mask keeps the xoxp- prefix too', str_starts_with(Screen::mask('xoxp-11111111-abcdefgh'), 'xoxp-'));

// Never leak more than it shows: the mask must not contain the token body.
$token = 'xoxb-1234567890123-4567890123456-AbCdEfGhIjKlMnOpQrStw7Fq';
check('mask contains no long run of the token', strpos(Screen::mask($token), substr($token, 5, 10)) === false);

// Short or odd tokens degrade to bullets without revealing a tail.
check('short token shows no tail', Screen::mask('xoxb-abc') === "xoxb-\u{2022}\u{2022}\u{2022}\u{2022}");
check('tiny token is all bullets', Screen::mask('ab') === "\u{2022}\u{2022}\u{2022}\u{2022}");
check('empty token is all bullets', Screen::mask('') === "\u{2022}\u{2022}\u{2022}\u{2022}");
// A dash deep inside a prefixless token is not a type prefix.
$nodash = Screen::mask('0123456789-abcdefghij');
check('late dash is not treated as a prefix', !str_starts_with($nodash, '0123456789-'), $nodash);

// --- interpret_auth_test(): Slack response mapping ---------------------------

$ok = Screen::interpret_auth_test([
    'ok' => true,
    'url' => 'https://dargonkween.slack.com/',
    'team' => 'DARGON KWEEN',
    'user' => 'chronicler',
    'team_id' => 'T012AB3C4',
    'user_id' => 'U012AB3C4',
    'bot_id' => 'B012AB3C4',
]);
check('auth.test success is ok', $ok['ok'] === true);
check('auth.test success carries the team', $ok['team'] === 'DARGON KWEEN');
check('auth.test success carries the bot identity', $ok['user'] === 'chronicler');

$bad = Screen::interpret_auth_test(['ok' => false, 'error' => 'invalid_auth']);
check('auth.test failure is not ok', $bad['ok'] === false);
check("auth.test failure surfaces Slack's error code", $bad['error'] === 'invalid_auth');

check('missing error code degrades to unknown_error', Screen::interpret_auth_test(['ok' => false])['error'] === 'unknown_error');
check('non-JSON body is invalid_response', Screen::interpret_auth_test(null)['error'] === 'invalid_response');
check('scalar body is invalid_response', Screen::interpret_auth_test('warning: served HTML')['error'] === 'invalid_response');
check('ok:false wins over present team', Screen::interpret_auth_test(['ok' => 0, 'team' => 'x'])['ok'] === false);
$weird = Screen::interpret_auth_test(['ok' => true, 'team' => ['not' => 'a string']]);
check('non-string team degrades to empty', $weird['ok'] === true && $weird['team'] === '');

// --- bot_token(): constant-over-option precedence ----------------------------
// Order matters: the option branch must be checked BEFORE the constant is
// defined (constants cannot be undefined within a process).

check('bot_token is null with nothing configured', Screen::bot_token() === null);
$GLOBALS['chronicler_test_options'][Screen::OPTION] = 'xoxb-from-option';
check('bot_token reads the option', Screen::bot_token() === 'xoxb-from-option');
$GLOBALS['chronicler_test_options'][Screen::OPTION] = '';
check('empty option means null', Screen::bot_token() === null);
$GLOBALS['chronicler_test_options'][Screen::OPTION] = 'xoxb-from-option';

define('CHRONICLER_SLACK_BOT_TOKEN', 'xoxb-from-constant');
check('defined constant overrides the option', Screen::bot_token() === 'xoxb-from-constant');

// --- manifest_yaml(): the universal Slack app manifest, read from disk -------
$manifest = Screen::manifest_yaml();
check('manifest_yaml returns a non-empty string', is_string($manifest) && $manifest !== '');
check('manifest carries the display_information block', str_contains($manifest, 'display_information:'));
check('manifest carries the oauth_config scopes block', str_contains($manifest, 'oauth_config:'));
check('manifest names the Chronicler bot', str_contains($manifest, 'display_name: Chronicler'));
check('manifest requests channels:history', str_contains($manifest, 'channels:history'));
check('manifest header no longer embeds the numbered setup steps', !str_contains($manifest, 'Create New App'));

// --- setup_section_html(): the walkthrough + copyable manifest field --------
$section = Screen::setup_section_html();
check('setup section has the heading', str_contains($section, 'Set up your Slack app'));
check('setup section embeds the manifest YAML', str_contains($section, 'display_information:'));
check('setup section puts the manifest in a readonly textarea', str_contains($section, '<textarea') && str_contains($section, 'readonly'));
check('setup section has a copy button', str_contains($section, 'chronicler-copy-manifest'));
check('setup section wires the clipboard copy', str_contains($section, 'navigator.clipboard'));
check('setup section walks through From a manifest', str_contains($section, 'From a manifest'));
check('setup section tells the user to invite the bot', str_contains($section, '/invite @Chronicler'));
