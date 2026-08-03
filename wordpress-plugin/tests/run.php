<?php
// Dependency-free test runner: `php tests/run.php` (see npm run test:php).
// schema.php is pure except for WP_Error, stubbed here for standalone runs.

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = null) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code() {
            return $this->code;
        }
        public function get_error_message() {
            return $this->message;
        }
        public function get_error_data() {
            return $this->data;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('wp_kses_post')) {
    // Minimal stand-in reproducing the kses behavior these tests rely on:
    // disallowed element markup (script/style/iframe/…) is stripped while
    // its text content survives, and allowed post markup passes untouched.
    // Attribute-level filtering is WordPress's own and is NOT modeled here —
    // the tests assert Chronicler routes the right fields through the
    // filter, not kses's internals (verified at runtime in wp-env).
    function wp_kses_post($content) {
        $allowed = '(?:a|abbr|b|blockquote|br|cite|code|del|div|em|figcaption|figure|h[1-6]|hr|i|img|ins|li|ol|p|pre|q|s|span|strong|sub|sup|table|tbody|td|tfoot|th|thead|tr|ul)';
        return preg_replace('#</?(?!' . $allowed . '[\s>/])[a-zA-Z][^>]*>#i', '', (string) $content);
    }
}

$GLOBALS['chronicler_test_failures'] = 0;
$GLOBALS['chronicler_test_count'] = 0;

function check(string $desc, bool $ok, string $detail = ''): void {
    $GLOBALS['chronicler_test_count']++;
    if (!$ok) {
        $GLOBALS['chronicler_test_failures']++;
        fwrite(STDERR, "FAIL: $desc" . ($detail !== '' ? " — $detail" : '') . "\n");
    }
}

define('CHRONICLER_TESTS', true);

// The derived-formula engine (#88) rides the plugin's Composer vendor tree;
// scripts/test-php.mjs provisions it via the composer image when absent.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

require __DIR__ . '/../message-render.php';
require __DIR__ . '/message-render.test.php';

require __DIR__ . '/../sheets/schema.php';
require __DIR__ . '/../sheets/formulas.php';
require __DIR__ . '/../sheets/dice.php';
require __DIR__ . '/../sheets/names.php';
require __DIR__ . '/schema.test.php';
require __DIR__ . '/formulas.test.php';
require __DIR__ . '/dice.test.php';
require __DIR__ . '/schema-drift.test.php';
// template-store.test.php defines the shared get_post_meta/update_post_meta/
// wp_slash/wp_unslash/WP_Post stubs — it MUST load before render/surfaces so
// its registry-backed versions win those suites' function_exists guards.
require __DIR__ . '/template-store.test.php';
require __DIR__ . '/render.test.php';
require __DIR__ . '/index.test.php';
require __DIR__ . '/surfaces.test.php';
// After surfaces: sheets-viewer.test.php drives sheets/rest.php, which
// surfaces.test.php requires (along with the get_post / WP_REST_Request stubs
// it needs).
require __DIR__ . '/sheets-viewer.test.php';
require __DIR__ . '/preflight.test.php';
require __DIR__ . '/names.test.php';

require __DIR__ . '/../src/Capabilities.php';
require __DIR__ . '/../src/Sanitize.php';
require __DIR__ . '/../src/Store/Settings.php';
require __DIR__ . '/../src/Store/Sessions.php';
require __DIR__ . '/../src/Store/Rules.php';
require __DIR__ . '/../src/Store/Schema.php';
require __DIR__ . '/../src/Rest/Schemas.php';
require __DIR__ . '/../src/Rest/Import.php';
require __DIR__ . '/../src/Rest/Routes.php';
require __DIR__ . '/../src/Rules/AdminPage.php';
require __DIR__ . '/routes.test.php';
require __DIR__ . '/store.test.php';
require __DIR__ . '/sanitize.test.php';
require __DIR__ . '/rules-admin.test.php';

require __DIR__ . '/../src/Settings/Screen.php';
require __DIR__ . '/settings.test.php';

require __DIR__ . '/../src/Slack/Signature.php';
require __DIR__ . '/../src/Slack/Bot/Commands.php';
require __DIR__ . '/../src/Slack/Bot/Link.php';
require __DIR__ . '/../src/Slack/Bot/BlockKit.php';
require __DIR__ . '/../src/Slack/Bot/My.php';
require __DIR__ . '/../src/Slack/Bot/Roll.php';
require __DIR__ . '/../src/Slack/Inbound.php';
require __DIR__ . '/../src/Slack/Deferred.php';
require __DIR__ . '/slack-inbound.test.php';
require __DIR__ . '/slack-my.test.php';
require __DIR__ . '/slack-roll.test.php';

require __DIR__ . '/../src/Slack/ApiError.php';
require __DIR__ . '/../src/Slack/RateLimited.php';
require __DIR__ . '/../src/Slack/Client.php';
require __DIR__ . '/../src/Slack/Proxy.php';
require __DIR__ . '/slack.test.php';
require __DIR__ . '/../src/Media/Mirror.php';
require __DIR__ . '/mirror.test.php';

require __DIR__ . '/../src/Editor/Generation.php';
require __DIR__ . '/generation.test.php';

require __DIR__ . '/caps.test.php';
// After caps: uninstall.test.php reuses its WP_Role stub (remove_cap) and the
// role registry, and drives uninstall.php with WP_UNINSTALL_PLUGIN defined.
require __DIR__ . '/uninstall.test.php';
// After uninstall (which defines WP_UNINSTALL_PLUGIN): login.test.php calls
// the sheets/login.php functions directly, so the skipped hook block is fine.
require __DIR__ . '/login.test.php';

$n = $GLOBALS['chronicler_test_count'];
$f = $GLOBALS['chronicler_test_failures'];
echo ($f === 0 ? "OK" : "FAILED") . " — $n checks, $f failures\n";
exit($f === 0 ? 0 : 1);
