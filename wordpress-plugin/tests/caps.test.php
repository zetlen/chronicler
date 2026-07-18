<?php
// Sheets role/cap grants (#162): update-safe, additive reconciliation. Mirrors
// the npc-term ensure test (index.test.php) — drives grant/ensure against
// option + role stubs and asserts the version gate, idempotency, and
// additivity. Included by run.php AFTER src/Capabilities.php (grant references
// Capabilities::COMPOSE). This task covers the player + administrator grants;
// the gm role is asserted in the Task 3 additions below.

if (!defined('CHRONICLER_VERSION')) {
    define('CHRONICLER_VERSION', '162.0.0-test');
}

// Minimal WP_Role: a name + a capability map, with the additive add_cap the
// grant path uses. get_role/add_role back onto a per-test registry.
if (!class_exists('WP_Role')) {
    class WP_Role {
        public $name;
        public $capabilities;
        public function __construct($name, $capabilities = []) {
            $this->name = $name;
            $this->capabilities = $capabilities;
        }
        public function add_cap($cap, $grant = true) {
            $this->capabilities[$cap] = $grant;
        }
        public function has_cap($cap) {
            return !empty($this->capabilities[$cap]);
        }
    }
}
if (!function_exists('get_role')) {
    function get_role($slug) {
        return $GLOBALS['chr_test_roles'][$slug] ?? null;
    }
}
if (!function_exists('add_role')) {
    function add_role($slug, $label, $caps = []) {
        // WordPress: no-op returning null when the slug already exists.
        if (isset($GLOBALS['chr_test_roles'][$slug])) {
            return null;
        }
        return $GLOBALS['chr_test_roles'][$slug] = new WP_Role($slug, $caps);
    }
}

require __DIR__ . '/../sheets/caps.php';

// Clean option store + a registry with an administrator ready to receive caps.
function chronicler_caps_test_reset(): void {
    $GLOBALS['chronicler_test_options'] = [];
    $GLOBALS['chr_test_roles'] = [
        'administrator' => new WP_Role('administrator', ['manage_options' => true]),
    ];
}

// --- fresh install: grant creates the player role + admin caps ---------------
chronicler_caps_test_reset();
chronicler_sheets_grant_caps();

$player = get_role('player');
check('player role is created on grant', $player instanceof WP_Role);
check(
    'player edits its own sheets',
    $player && $player->has_cap('edit_chr_characters') && $player->has_cap('edit_published_chr_characters')
);
check('player CANNOT edit others\' characters', $player && !$player->has_cap('edit_others_chr_characters'));

$admin = get_role('administrator');
check(
    'administrator gains the full character cap set',
    $admin->has_cap('edit_others_chr_characters') && $admin->has_cap('delete_others_chr_characters')
);
check(
    'grant stamps the caps version',
    ($GLOBALS['chronicler_test_options']['chronicler_sheets_caps_version'] ?? null) === CHRONICLER_VERSION
);

// --- fresh install: the gm role is a full game runner, not a site admin -----
$gm = get_role('gm');
check('gm role is created on grant', $gm instanceof WP_Role);
check('gm can draft sessions (chronicler_compose)', $gm && $gm->has_cap(Chronicler\Capabilities::COMPOSE));
check('gm can access wp-admin (read)', $gm && $gm->has_cap('read'));
$gm_has_all_char_caps = $gm !== null;
foreach (CHRONICLER_CHARACTER_CAPS as $cap) {
    if (!$gm->has_cap($cap)) { $gm_has_all_char_caps = false; break; }
}
check('gm holds every character capability (incl. edit_others)', $gm_has_all_char_caps);
check('gm does NOT get chronicler_manage', $gm && !$gm->has_cap(Chronicler\Capabilities::MANAGE));
check('gm does NOT get chronicler_slack_read', $gm && !$gm->has_cap(Chronicler\Capabilities::SLACK_READ));
check('gm does NOT get manage_options', $gm && !$gm->has_cap('manage_options'));

// --- update path: stale version re-grants, additively ------------------------
chronicler_caps_test_reset();
// A pre-#162 install: player exists with old caps + a site admin's own tweak,
// and the version option lags.
$GLOBALS['chr_test_roles']['player'] = new WP_Role('player', [
    'read' => true,
    'edit_chr_characters' => true,
    'edit_published_chr_characters' => true,
    'my_custom_cap' => true, // a role-editor plugin tweak — must survive
]);
$GLOBALS['chronicler_test_options']['chronicler_sheets_caps_version'] = '4.0.0';

chronicler_sheets_ensure_caps();
check(
    'ensure re-stamps the caps version on an out-of-date install',
    ($GLOBALS['chronicler_test_options']['chronicler_sheets_caps_version'] ?? null) === CHRONICLER_VERSION
);
check(
    'ensure is additive: a site admin\'s custom cap survives a re-grant',
    get_role('player')->has_cap('my_custom_cap')
);
check('ensure creates the gm role on an existing install', get_role('gm') instanceof WP_Role);

// --- version gate: at the current version, ensure() does NOT re-grant ---------
unset($GLOBALS['chr_test_roles']['player']); // a re-grant would recreate it
chronicler_sheets_ensure_caps();
check('ensure at the current version is a no-op (gate holds)', get_role('player') === null);

// Leave the shared options global clean for any downstream test.
$GLOBALS['chronicler_test_options'] = [];
