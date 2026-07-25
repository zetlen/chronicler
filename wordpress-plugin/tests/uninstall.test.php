<?php
// Uninstall cleanup (#163, hardened per the #173 review): drives uninstall.php
// against recording stubs and asserts every artifact family the plugin writes
// is removed — including trashed posts, per-user cap grants, and rewrite
// rules — while publish-attached mirrors and foreign data survive. The
// get_posts stub records its args so the queries' scope is asserted, not just
// their results. Loaded by run.php AFTER caps.test.php (the WP_Role stub with
// remove_cap, the role registry, and CHRONICLER_CHARACTER_CAPS all come from
// there) and after run.php's own src-class requires — uninstall.php's
// require_once calls are no-ops here, exactly like an uninstall on a live
// site where WordPress is loaded but the plugin is not. is_multisite is
// undefined in the harness, so uninstall.php takes its single-site path.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', true);
}

if (!function_exists('delete_option')) {
    function delete_option($name) {
        $GLOBALS['chr_test_deleted_options'][] = $name;
        unset($GLOBALS['chronicler_test_options'][$name]);
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($name) {
        $GLOBALS['chr_test_deleted_transients'][] = $name;
        return true;
    }
}
if (!function_exists('delete_metadata')) {
    function delete_metadata($type, $id, $key, $value = '', $delete_all = false) {
        $GLOBALS['chr_test_deleted_metadata'][] = [$type, $id, $key, $value, $delete_all];
        return true;
    }
}
if (!function_exists('remove_role')) {
    function remove_role($slug) {
        unset($GLOBALS['chr_test_roles'][$slug]);
        return true;
    }
}
if (!function_exists('wp_delete_post')) {
    function wp_delete_post($id = 0, $force = false) {
        $GLOBALS['chr_test_deleted_posts'][] = [$id, $force];
        return true;
    }
}
if (!function_exists('wp_delete_attachment')) {
    function wp_delete_attachment($id = 0, $force = false) {
        $GLOBALS['chr_test_deleted_attachments'][] = [$id, $force];
        return true;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook) {
        $GLOBALS['chr_test_cleared_crons'][] = $hook;
        return true;
    }
}
if (!function_exists('get_post_stati')) {
    // The core-registered statuses (names, keyed by name) — trash and
    // auto-draft included, which 'any' would silently exclude.
    function get_post_stati($args = [], $output = 'names') {
        return [
            'publish' => 'publish', 'future' => 'future', 'draft' => 'draft',
            'pending' => 'pending', 'private' => 'private',
            'trash' => 'trash', 'auto-draft' => 'auto-draft', 'inherit' => 'inherit',
        ];
    }
}
if (!function_exists('delete_post_meta_by_key')) {
    function delete_post_meta_by_key($key) {
        $GLOBALS['chr_test_deleted_meta_keys'][] = $key;
        return true;
    }
}
// Per-user capability holders (the wp user add-cap bot-account flow).
if (!class_exists('Chronicler_Test_User')) {
    class Chronicler_Test_User {
        public $caps;
        public function __construct(array $caps) { $this->caps = $caps; }
        public function remove_cap($cap) { unset($this->caps[$cap]); }
    }
}
if (!function_exists('get_users')) {
    function get_users($args = []) {
        $want = $args['capability__in'] ?? [];
        return array_values(array_filter(
            $GLOBALS['chr_test_users'] ?? [],
            function ($user) use ($want) {
                foreach ($want as $cap) {
                    if (!empty($user->caps[$cap])) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }
}

// A site mid-life: every Chronicler artifact present, plus foreign data that
// must survive.
$GLOBALS['chronicler_test_options'] = [
    'chronicler_caps_version' => '4.15.0',
    'chronicler_sheets_caps_version' => '4.15.0',
    'chronicler_schema_version' => 1,
    'chronicler_active_template' => 9,
    'chronicler_sheets_npc_seeded' => '1',
    // Deliberately NOT token-shaped: the GitHub-sync preflight (scripts/
    // sync-github.mjs) fails the build on xoxb-* markers outside its
    // known-fixture allowlist.
    'chronicler_slack_bot_token' => 'test-bot-token',
    'chronicler_settings_keys' => ['navView', 'default-scheme'],
    'chronicler_setting_navView' => 'grid',
    'chronicler_setting_default-scheme' => 'dark',
    'chronicler_channel_defaults' => ['C1' => ['scheme' => 'light']],
    'siteurl' => 'http://test.local', // NOT ours — must survive
];

$admin = new WP_Role('administrator', ['manage_options' => true]);
foreach (array_merge(CHRONICLER_CHARACTER_CAPS, Chronicler\Capabilities::ALL) as $cap) {
    $admin->add_cap($cap);
}
$GLOBALS['chr_test_roles'] = [
    'administrator' => $admin,
    'player' => new WP_Role('player', ['read' => true]),
    'gm' => new WP_Role('gm', ['read' => true]),
    'editor' => new WP_Role('editor', ['read' => true]), // NOT ours — must survive
];

// A documented bot account with delegated caps (docs/rest-api.md) plus a
// foreign user whose own caps must survive.
$GLOBALS['chr_test_users'] = [
    'bot' => new Chronicler_Test_User(['chronicler_compose' => true, 'chronicler_slack_read' => true]),
    'author' => new Chronicler_Test_User(['edit_posts' => true]), // NOT ours — must survive
];

// get_posts (index.test.php's stub) answers both uninstall queries with this;
// the recorded args below let us assert the queries' SCOPE too.
$GLOBALS['chr_test_index_ids'] = [101, 202];
$GLOBALS['chr_test_get_posts_calls'] = [];

$GLOBALS['chr_test_deleted_options'] = [];
$GLOBALS['chr_test_deleted_transients'] = [];
$GLOBALS['chr_test_deleted_metadata'] = [];
$GLOBALS['chr_test_deleted_posts'] = [];
$GLOBALS['chr_test_deleted_attachments'] = [];
$GLOBALS['chr_test_cleared_crons'] = [];
$GLOBALS['chr_test_deleted_meta_keys'] = [];
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $queries = [];
    public function query($sql) {
        $this->queries[] = $sql;
        return 1;
    }
};

require __DIR__ . '/../uninstall.php';

// --- 1. the sessions table ---------------------------------------------------
check(
    'uninstall drops the sessions table',
    $GLOBALS['wpdb']->queries === ['DROP TABLE IF EXISTS wp_chronicler_sessions']
);

// --- 2. options ---------------------------------------------------------------
$expected_options = [
    'chronicler_setting_navView',
    'chronicler_setting_default-scheme',
    'chronicler_settings_keys',
    'chronicler_channel_defaults',
    'chronicler_slack_bot_token',
    'chronicler_caps_version',
    'chronicler_schema_version',
    'chronicler_sheets_caps_version',
    'chronicler_active_template',
    'chronicler_sheets_npc_seeded',
    // Not chronicler-prefixed but plugin-written state: chr_character's
    // public rewrites live here; deletion regenerates clean rules (#173).
    'rewrite_rules',
];
sort($expected_options);
$deleted = $GLOBALS['chr_test_deleted_options'];
sort($deleted);
check('uninstall deletes exactly the plugin options', $deleted === $expected_options);
check('uninstall leaves foreign options alone', isset($GLOBALS['chronicler_test_options']['siteurl']));

// --- 3. roles and caps ---------------------------------------------------------
check('uninstall removes the player role', !isset($GLOBALS['chr_test_roles']['player']));
check('uninstall removes the gm role', !isset($GLOBALS['chr_test_roles']['gm']));
check('uninstall leaves foreign roles alone', isset($GLOBALS['chr_test_roles']['editor']));
$admin_after = get_role('administrator');
$admin_caps_left = true;
foreach (array_merge(CHRONICLER_CHARACTER_CAPS, Chronicler\Capabilities::ALL) as $cap) {
    if ($admin_after->has_cap($cap)) {
        $admin_caps_left = false;
        break;
    }
}
check('uninstall strips every Chronicler cap from administrator', $admin_caps_left);
check('uninstall leaves administrator\'s own caps alone', $admin_after->has_cap('manage_options'));
check(
    'uninstall strips documented per-user cap grants (bot accounts, #173)',
    $GLOBALS['chr_test_users']['bot']->caps === []
);
check(
    'uninstall leaves foreign per-user caps alone',
    ($GLOBALS['chr_test_users']['author']->caps['edit_posts'] ?? false) === true
);

// --- 4/5. posts and mirrored attachments, force deleted ------------------------
check(
    'uninstall force-deletes the plugin\'s posts',
    $GLOBALS['chr_test_deleted_posts'] === [[101, true], [202, true]]
);
check(
    'uninstall force-deletes unused mirrored attachments (files included)',
    $GLOBALS['chr_test_deleted_attachments'] === [[101, true], [202, true]]
);

// The queries' SCOPE (#173 review): the recording get_posts stub proves the
// deletes above were fed by correctly-bounded queries — a widened post_type,
// a typo'd meta_key, or a status list that misses trash would pass the
// count-based checks while deleting the wrong things on a real site.
$posts_query = $GLOBALS['chr_test_get_posts_calls'][0] ?? [];
check(
    'uninstall queries exactly the plugin post types',
    ($posts_query['post_type'] ?? null) === ['chr_character', 'chr_template', 'chronicler_rule']
);
check(
    'uninstall reaches trashed and auto-draft posts (not \'any\')',
    is_array($posts_query['post_status'] ?? null)
        && in_array('trash', $posts_query['post_status'], true)
        && in_array('auto-draft', $posts_query['post_status'], true)
);
$attachments_query = $GLOBALS['chr_test_get_posts_calls'][1] ?? [];
check(
    'uninstall matches attachments by the Mirror meta key',
    ($attachments_query['meta_key'] ?? null) === 'chronicler_mirror_key'
);
check(
    'uninstall spares publish-attached mirrors (unattached only, #173)',
    ($attachments_query['post_parent'] ?? null) === 0
);
check(
    'uninstall reaches MEDIA_TRASH-trashed mirrors',
    is_array($attachments_query['post_status'] ?? null)
        && in_array('trash', $attachments_query['post_status'], true)
);
check(
    'uninstall scrubs all mirror marker meta from the kept attachments (#177)',
    $GLOBALS['chr_test_deleted_meta_keys'] === [
        'chronicler_slack_user_id',
        'chronicler_mirror_key',
        'chronicler_mirror_source',
        'chronicler_mirror_sha256',
    ]
);
check(
    'uninstall scrubs the chronicler_slack_user_id POST meta (sheet links)',
    in_array('chronicler_slack_user_id', $GLOBALS['chr_test_deleted_meta_keys'], true)
);

// --- 6. the Slack-id user meta, every user --------------------------------------
check(
    'uninstall deletes the chronicler_slack_user_id user meta site-wide',
    $GLOBALS['chr_test_deleted_metadata'] === [['user', 0, 'chronicler_slack_user_id', '', true]]
);

// --- 7/8. cron event + cached Slack directory ------------------------------------
check('uninstall clears the mirror eviction cron', $GLOBALS['chr_test_cleared_crons'] === ['chronicler_mirror_evict']);
check('uninstall deletes the Slack directory transient', $GLOBALS['chr_test_deleted_transients'] === ['chronicler_slack_user_directory']);

// (The `npc` term is deliberately left alone — see uninstall.php. No
// wp_delete_term stub exists here, so calling it would fatal this suite.)

// Leave the shared globals clean for any downstream test.
$GLOBALS['chronicler_test_options'] = [];
$GLOBALS['chr_test_index_ids'] = [];
$GLOBALS['chr_test_get_posts_calls'] = [];
unset($GLOBALS['wpdb'], $GLOBALS['chr_test_users']);
