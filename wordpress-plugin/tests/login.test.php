<?php
// Player login landing (GitHub #1): default-target normalization, the
// player-shaped gate, and the login_redirect shell. Included by run.php LAST,
// so the shared guarded stubs are already in place: add_filter (render),
// get_posts / get_permalink (index), admin_url (slack-inbound). WP_User,
// user_can and add_query_arg are the additions here.

if (!class_exists('WP_User')) {
    class WP_User {
        public $ID;
        public function __construct($id = 0) { $this->ID = (int) $id; }
    }
}
if (!function_exists('user_can')) {
    function user_can($user, $cap, ...$args) {
        return !empty($GLOBALS['chr_test_login_user_caps'][$cap]);
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value, $url) {
        return $url . (strpos($url, '?') === false ? '?' : '&') . $key . '=' . $value;
    }
}

require __DIR__ . '/../sheets/login.php';

// --- default-target normalization --------------------------------------------
$admin_root = 'https://blog.test/wp-admin/';
check('empty requested target is default-shaped',
    chronicler_sheets_is_default_login_target('', $admin_root));
check('the dashboard root is default-shaped',
    chronicler_sheets_is_default_login_target('https://blog.test/wp-admin/', $admin_root));
check('trailing slash is irrelevant',
    chronicler_sheets_is_default_login_target('https://blog.test/wp-admin', $admin_root));
check('the dashboard index.php form is default-shaped',
    chronicler_sheets_is_default_login_target('https://blog.test/wp-admin/index.php', $admin_root));
check('a specific admin screen is a deep link',
    !chronicler_sheets_is_default_login_target('https://blog.test/wp-admin/profile.php', $admin_root));
check('an off-site URL is not default-shaped',
    !chronicler_sheets_is_default_login_target('https://evil.test/wp-admin/', $admin_root));

// --- the login_redirect shell -------------------------------------------------
// admin_url() answers 'https://blog.test/wp-admin/' (slack-inbound stub), so
// that string is this suite's "default destination".
function chronicler_login_test_reset(array $caps, array $sheet_ids = [], array $meta = []): WP_User {
    $GLOBALS['chr_test_login_user_caps'] = $caps;
    $GLOBALS['chr_test_get_posts_calls'] = [];
    $GLOBALS['chr_test_index_ids'] = $sheet_ids;
    $GLOBALS['chr_test_post_meta'] = $meta;
    $GLOBALS['chr_test_post_meta_writes'] = [];
    return new WP_User(5);
}
$dashboard = 'https://blog.test/wp-admin/';
$player_caps = ['edit_chr_characters' => true];

chronicler_login_test_reset($player_caps);
check('a failed login (WP_Error) passes through',
    chronicler_sheets_login_redirect($dashboard, '', new WP_Error('denied')) === $dashboard);

$gm = chronicler_login_test_reset(['edit_chr_characters' => true, 'edit_others_chr_characters' => true]);
check('a GM keeps the default destination',
    chronicler_sheets_login_redirect($dashboard, '', $gm) === $dashboard);

$editor = chronicler_login_test_reset(['edit_chr_characters' => true, 'edit_others_posts' => true]);
check('a broader core editor keeps the default destination',
    chronicler_sheets_login_redirect($dashboard, '', $editor) === $dashboard);

$user = chronicler_login_test_reset($player_caps, [11]);
check('a deep link is honored for a player',
    chronicler_sheets_login_redirect('https://blog.test/wp-admin/profile.php',
        'https://blog.test/wp-admin/profile.php', $user) === 'https://blog.test/wp-admin/profile.php');
check('a deep link never queries for sheets', $GLOBALS['chr_test_get_posts_calls'] === []);

$user = chronicler_login_test_reset($player_caps);
check('a player with no sheet falls through to the default',
    chronicler_sheets_login_redirect($dashboard, '', $user) === $dashboard);

$user = chronicler_login_test_reset($player_caps, [11]);
$result = chronicler_sheets_login_redirect($dashboard, '', $user);
check('a player lands on their sheet permalink with the chr_welcome arg',
    $result === 'http://test.local/?chr=11&chr_welcome=1', "got: $result");
check('the landing goes through the active-character authority (#17): the heal writes the flag',
    in_array([11, 'chr_active', '1'], $GLOBALS['chr_test_post_meta_writes'], true));

// The active flag, not recency, picks the destination (#17).
$user = chronicler_login_test_reset($player_caps, [22, 11], [11 => ['chr_active' => '1']]);
$result = chronicler_sheets_login_redirect($dashboard, '', $user);
check('the flagged sheet wins over a newer one',
    $result === 'http://test.local/?chr=11&chr_welcome=1', "got: $result");

$user = chronicler_login_test_reset($player_caps, [11], [11 => ['chr_active' => '0']]);
check('a player the GM opted out (all sheets tombstoned) keeps the default landing',
    chronicler_sheets_login_redirect($dashboard, '', $user) === $dashboard);

// --- arrival banner (sheets/render.php) ---------------------------------------
$banner = chronicler_sheets_render_welcome_banner(true, true);
check('welcome banner renders for an editor arriving from login',
    strpos($banner, 'chr-sheet__welcome') !== false);
check('banner is a status region for screen readers',
    strpos($banner, 'role="status"') !== false);
check('no banner without the chr_welcome arg',
    chronicler_sheets_render_welcome_banner(false, true) === '');
check('no banner for a viewer who cannot edit (pasted URL)',
    chronicler_sheets_render_welcome_banner(true, false) === '');

// Leave the shared globals clean for any downstream suite.
$GLOBALS['chr_test_login_user_caps'] = [];
$GLOBALS['chr_test_get_posts_calls'] = [];
$GLOBALS['chr_test_index_ids'] = [];
