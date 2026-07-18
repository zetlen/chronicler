<?php
// GM-only gate on the two non-render surfaces: the public REST sheet endpoint
// (rest.php) and the wp-admin Stat Block box (admin.php). Reuses the WordPress
// stubs and helpers defined by render.test.php — run.php loads that first — and
// adds only what these two surfaces additionally touch. Asserts on the endpoint
// return value and the box's echoed HTML.

// --- extra stubs (render.test.php already defined the rest) ---
if (!function_exists('get_post')) {
    function get_post($id = 0) {
        $p = new stdClass();
        $p->ID = (int) $id;
        $p->post_type = 'chr_character';
        $p->post_status = $GLOBALS['chr_test_post_status'] ?? 'publish';
        return $p;
    }
}
if (!class_exists('WP_Post')) {
    class WP_Post {
        public $ID = 7;
        public $post_type = 'chr_character';
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess {
        private $params;
        public function __construct(array $params = []) { $this->params = $params; }
        public function offsetExists(mixed $offset): bool { return isset($this->params[$offset]); }
        public function offsetGet(mixed $offset): mixed { return $this->params[$offset] ?? null; }
        public function offsetSet(mixed $offset, mixed $value): void { $this->params[$offset] = $value; }
        public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
        public function get_param($key) { return $this->params[$key] ?? null; }
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) { return ''; }
}
if (!function_exists('wp_editor')) {
    // The real editor renders a textarea carrying the value; the stub keeps just
    // enough for value-presence assertions.
    function wp_editor($content, $editor_id, $settings = []) {
        echo '<textarea name="' . htmlspecialchars((string) ($settings['textarea_name'] ?? $editor_id), ENT_QUOTES) . '">'
            . htmlspecialchars((string) $content, ENT_QUOTES) . '</textarea>';
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key = '', $single = false) { return ''; }
}
// Save-path stubs (chronicler_sheets_save_stat_block).
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return true; }
}
if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision($id) { return false; }
}
if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave($id) { return false; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return $v; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : $s; }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($s) { return is_string($s) ? trim($s) : $s; }
}
// Records which property ids reached persistence (so a test can prove a forged
// present-flag for a GM-only field never gets written) AND writes back to the
// value map (so a later read — e.g. the rule engine — sees the new value).
if (!function_exists('chronicler_sheets_set_value')) {
    function chronicler_sheets_set_value($post_id, array $property, $value) {
        $GLOBALS['chr_saved'][] = $property['id'];
        $GLOBALS['chr_test_values'][$property['id']] = $value;
    }
}

require __DIR__ . '/../sheets/rest.php';
require __DIR__ . '/../sheets/admin.php';

// Shared fixture: a public "Player Notes" section, a GM-only "GM Notes"
// section (the issue #63 shape), and an owner-only "Private Notes" section
// (live, so the write path can be exercised end-to-end).
$GLOBALS['chr_test_template'] = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test',
    'version' => 1,
    'properties' => [
        ['id' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 0, 'max' => 5],
        ['id' => 'player_notes', 'label' => 'Player Notes', 'type' => 'longtext'],
        ['id' => 'gm_notes', 'label' => 'GM Notes', 'type' => 'longtext', 'gm_only' => true],
        ['id' => 'private_notes', 'label' => 'Private Notes', 'type' => 'longtext', 'owner_only' => true, 'live' => true],
    ],
    'layout' => [
        ['section' => 'Stats', 'properties' => ['str']],
        ['section' => 'Player Notes', 'properties' => ['player_notes']],
        ['section' => 'GM Notes', 'properties' => ['gm_notes']],
        ['section' => 'Private Notes', 'properties' => ['private_notes']],
    ],
]));
$GLOBALS['chr_test_values'] = ['player_notes' => 'PLAYER_SECRET', 'gm_notes' => 'GM_SECRET', 'private_notes' => 'OWNER_SECRET'];
check('surfaces fixture parses', is_array($GLOBALS['chr_test_template']));

$prop_ids = function (array $body): array {
    return array_map(function ($p) { return $p['id']; }, $body['properties']);
};
$layout_ids = function (array $body): array {
    $ids = [];
    foreach ($body['layout'] as $section) {
        foreach ($section['properties'] as $pid) {
            $ids[] = $pid;
        }
    }
    return $ids;
};
$has_value = function (array $body, $needle): bool {
    foreach ($body['properties'] as $p) {
        if (($p['value'] ?? null) === $needle) {
            return true;
        }
    }
    return false;
};

$req = new WP_REST_Request(['id' => 7]);

// --- REST GET: non-GM (logged-out / player) ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$rest_public = chronicler_sheets_rest_get_sheet($req);
check('REST non-GM: gm_only omitted from properties', !in_array('gm_notes', $prop_ids($rest_public), true));
check('REST non-GM: gm_only value never serialized', !$has_value($rest_public, 'GM_SECRET'));
check('REST non-GM: gm_only id stripped from layout', !in_array('gm_notes', $layout_ids($rest_public), true));
check('REST non-GM: emptied layout section dropped', count($rest_public['layout']) === 2);
check('REST non-GM: public property retained', in_array('player_notes', $prop_ids($rest_public), true));
check('REST non-GM: public layout id retained', in_array('player_notes', $layout_ids($rest_public), true));
check('REST stranger: owner_only omitted from properties', !in_array('private_notes', $prop_ids($rest_public), true));
check('REST stranger: owner_only value never serialized', !$has_value($rest_public, 'OWNER_SECRET'));
check('REST stranger: owner_only id stripped from layout', !in_array('private_notes', $layout_ids($rest_public), true));

// --- REST GET: owning player (can edit own sheet, still not a GM) ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true;
$rest_owner = chronicler_sheets_rest_get_sheet($req);
check('REST owning player: gm_only omitted from properties', !in_array('gm_notes', $prop_ids($rest_owner), true));
check('REST owning player: gm_only value withheld', !$has_value($rest_owner, 'GM_SECRET'));
check('REST owning player: gm_only id stripped from layout', !in_array('gm_notes', $layout_ids($rest_owner), true));
check('REST owning player: canEdit still true', $rest_owner['canEdit'] === true);
// The inverse audience: the owner keeps their owner-only property whole.
check('REST owning player: owner_only retained in properties', in_array('private_notes', $prop_ids($rest_owner), true));
check('REST owning player: owner_only value served', $has_value($rest_owner, 'OWNER_SECRET'));
check('REST owning player: owner_only id retained in layout', in_array('private_notes', $layout_ids($rest_owner), true));

// --- REST GET: GM sees the whole sheet ---
$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$rest_gm = chronicler_sheets_rest_get_sheet($req);
check('REST GM: gm_only present in properties', in_array('gm_notes', $prop_ids($rest_gm), true));
check('REST GM: gm_only value present', $has_value($rest_gm, 'GM_SECRET'));
check('REST GM: gm_only id retained in layout', in_array('gm_notes', $layout_ids($rest_gm), true));
check('REST GM: owner_only present in properties', in_array('private_notes', $prop_ids($rest_gm), true));
check('REST GM: full layout returned', count($rest_gm['layout']) === 4);

// --- wp-admin Stat Block box ---
$post = new WP_Post();
$post->ID = 7;

$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true; // owning player editing their own sheet
ob_start();
chronicler_sheets_render_stat_block_box($post);
$admin_player = ob_get_clean();
check('admin non-GM: GM Notes value not rendered', strpos($admin_player, 'GM_SECRET') === false);
check('admin non-GM: GM Notes present-flag omitted', strpos($admin_player, 'chr_stat_present[gm_notes]') === false);
check('admin non-GM: emptied GM Notes section omitted', strpos($admin_player, 'GM Notes') === false);
check('admin non-GM: Player Notes still rendered', strpos($admin_player, 'PLAYER_SECRET') !== false);
check('admin non-GM: Player Notes present-flag rendered', strpos($admin_player, 'chr_stat_present[player_notes]') !== false);
// The owner-only field is the owner's own business: rendered for them in full.
check('admin non-GM owner: owner-only value rendered', strpos($admin_player, 'OWNER_SECRET') !== false);
check('admin non-GM owner: owner-only present-flag rendered', strpos($admin_player, 'chr_stat_present[private_notes]') !== false);

// --- "Insert Image" button (issue #68): a top-level longtext field (edited
// with wp_editor, context 'top') gets the media-upload button wired to its
// own editor id; row-context longtext (inside a list entry) never does,
// since TinyMCE can't init inside cloned <template> rows anyway. ---
check('admin: Insert Image button rendered for a top-level longtext field', strpos($admin_player, 'chr-insert-image') !== false);
check('admin: Insert Image button targets that field\'s editor id', strpos($admin_player, 'data-editor-id="chr_stat_player_notes_"') !== false);

$GLOBALS['chr_test_is_gm'] = true;
ob_start();
chronicler_sheets_render_stat_block_box($post);
$admin_gm = ob_get_clean();
check('admin GM: GM Notes value rendered', strpos($admin_gm, 'GM_SECRET') !== false);
check('admin GM: GM Notes present-flag rendered', strpos($admin_gm, 'chr_stat_present[gm_notes]') !== false);
check('admin GM: Insert Image button also rendered for GM Notes (top-level longtext)', strpos($admin_gm, 'data-editor-id="chr_stat_gm_notes_"') !== false);
check('admin GM: owner-only field rendered too', strpos($admin_gm, 'chr_stat_present[private_notes]') !== false);

// --- REST update (write surface): the GM-only gate has to cover writes too,
// not just reads. Issue #63 gated the read/display surfaces; the write path
// (chronicler_sheets_rest_update_property) checked only `live`, so a non-GM
// owning player could poke a GM-only property and read its value back off the
// response. A non-GM must be refused *as GM-only*, before any value is touched. ---
$upd_req = function (string $prop) {
    return new WP_REST_Request(['id' => 7, 'prop' => $prop, 'op' => 'set', 'value' => 'x']);
};

$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true; // owning player, still not a GM
$upd_forbidden = chronicler_sheets_rest_update_property($upd_req('gm_notes'));
check('REST update non-GM: GM-only property write is refused', is_wp_error($upd_forbidden));
check(
    'REST update non-GM: refused as GM-only (not via the incidental not-live path)',
    is_wp_error($upd_forbidden) && $upd_forbidden->code === 'chronicler_forbidden'
);

$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$upd_gm = chronicler_sheets_rest_update_property($upd_req('gm_notes'));
check(
    'REST update GM: passes the GM-only gate',
    is_wp_error($upd_gm) && $upd_gm->code !== 'chronicler_forbidden'
);

// --- REST update: owner_only needs no write-path gate of its own. The
// route's permission_callback (edit_post on THIS character) IS the audience
// — the author and game masters pass it, fellow players and the public
// never reach the callback. A live owner-only property round-trips. ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true; // owning player
$upd_owner = chronicler_sheets_rest_update_property($upd_req('private_notes'));
check('REST update owner: live owner-only write succeeds', is_array($upd_owner) && ($upd_owner['prop'] ?? null) === 'private_notes');
check('REST update owner: written value echoed back', is_array($upd_owner) && ($upd_owner['value'] ?? null) === 'x');

// Defense-in-depth: the handler itself must also refuse an owner-only write
// for a caller outside the audience, even though the route's
// permission_callback already blocks them — if that callback is ever
// loosened, or the handler gains another caller, a no-op write would
// otherwise reflect the private value back: the same read primitive the
// gm_only backstop above closes.
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$upd_outsider = chronicler_sheets_rest_update_property($upd_req('private_notes'));
check(
    'REST update outsider: owner-only write refused by the handler itself',
    is_wp_error($upd_outsider) && $upd_outsider->code === 'chronicler_forbidden'
);

// Pin the wiring the audience claim rests on: the properties route's
// permission_callback IS the owner_only audience (edit_post on THIS
// character). A refactor that swaps or widens the callback must fail here,
// not ship silently.
if (!function_exists('register_rest_route')) {
    function register_rest_route($ns, $route, $args = []) {
        $GLOBALS['chr_registered_routes'][$route] = $args;
        return true;
    }
}
$GLOBALS['chr_registered_routes'] = [];
chronicler_sheets_register_routes();
$chr_prop_route = $GLOBALS['chr_registered_routes']['/characters/(?P<id>\d+)/properties/(?P<prop>[a-z][a-z0-9_]*)'] ?? null;
check('properties route registered', is_array($chr_prop_route));
check(
    'properties route permission is edit_post on the character',
    is_array($chr_prop_route) && ($chr_prop_route['permission_callback'] ?? null) === 'chronicler_sheets_rest_can_edit'
);

// --- REST GET: the public (__return_true) endpoint must still respect
// post_status. get_post() returns a post regardless of status, so without an
// explicit check a draft/pending/private/trashed sheet leaks to anonymous
// callers. Only someone who can edit an unpublished character may read it. ---
$GLOBALS['chr_test_post_status'] = 'draft';
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false; // anonymous / unprivileged
check('REST GET: an unpublished sheet is 404 for the public', is_wp_error(chronicler_sheets_rest_get_sheet($req)));

$GLOBALS['chr_test_can_edit'] = true; // someone who can edit the character
check('REST GET: an unpublished sheet is readable by an editor', is_array(chronicler_sheets_rest_get_sheet($req)));
unset($GLOBALS['chr_test_post_status']);

// --- REST GET: a published but password-protected sheet (#158) gets the same
// treatment. post_password_required() reads core's password cookie, which REST
// callers won't normally carry — so the public gets the same existence-hiding
// 404 as an unpublished sheet, and an editor (owner/GM) still reads it. ---
$GLOBALS['chr_test_password_required'] = true;
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false; // anonymous / unprivileged
$rest_locked = chronicler_sheets_rest_get_sheet($req);
check('REST GET: a password-protected sheet is 404 for the public', is_wp_error($rest_locked));
check(
    'REST GET: the password 404 matches the unpublished shape (existence stays hidden)',
    is_wp_error($rest_locked) && $rest_locked->code === 'chronicler_not_found'
        && ($rest_locked->data['status'] ?? null) === 404
);

$GLOBALS['chr_test_can_edit'] = true; // someone who can edit the character
check('REST GET: a password-protected sheet is readable by an editor', is_array(chronicler_sheets_rest_get_sheet($req)));
unset($GLOBALS['chr_test_password_required']);

// --- wp-admin Stat Block save: present-flags are client-supplied and the box
// hides GM-only fields from non-GMs, but the save path re-checked nothing — a
// forged chr_stat_present[gm_notes] could write a GM-only field. Enforce the
// same audience gate on save. ---
$_POST['chronicler_stat_block_nonce'] = 'ok';
$_POST['chr_stat_present'] = ['player_notes' => '1', 'gm_notes' => '1', 'private_notes' => '1'];
$_POST['chr_stat'] = ['player_notes' => 'legit', 'gm_notes' => 'FORGED', 'private_notes' => 'mine'];

$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true; // owning player, not a GM
$GLOBALS['chr_saved'] = [];
chronicler_sheets_save_stat_block(7);
check('admin save non-GM: the player field is written', in_array('player_notes', $GLOBALS['chr_saved'], true));
check('admin save non-GM: a forged GM-only field is NOT written', !in_array('gm_notes', $GLOBALS['chr_saved'], true));
// owner_only needs no save-path gate: the save handler already requires
// edit_post, so everyone who reaches it is inside the audience.
check('admin save non-GM owner: the owner-only field IS written', in_array('private_notes', $GLOBALS['chr_saved'], true));

$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_saved'] = [];
chronicler_sheets_save_stat_block(7);
check('admin save GM: the GM-only field is written', in_array('gm_notes', $GLOBALS['chr_saved'], true));

unset($_POST['chronicler_stat_block_nonce'], $_POST['chr_stat_present'], $_POST['chr_stat']);

// --- rules removed (2026-07-13): the REST write's derived echo is formula-only.
// The fixture is a D&D-style ability modifier — a pure function of current
// values, which is what `derived` is for. (MOTW's unstable/doomed are
// event-driven — stabilizing clears Unstable regardless of Harm — so they
// stay manual toggles and never appear here as derived.) ---
$GLOBALS['chr_test_template'] = chronicler_sheets_parse_template(json_encode([
    'system' => 'D&D', 'version' => 1,
    'properties' => [
        ['id' => 'strength', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 20, 'live' => true],
        ['id' => 'strength_mod', 'label' => 'Strength Modifier', 'type' => 'number', 'derived' => 'floor((strength - 10) / 2)'],
    ],
]));
check('derived-echo fixture parses', is_array($GLOBALS['chr_test_template']));
$GLOBALS['chr_test_values'] = ['strength' => 10];
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true;
$chr_echo_resp = chronicler_sheets_rest_update_property(new WP_REST_Request(['id' => 7, 'prop' => 'strength', 'op' => 'set', 'value' => 14]));
check('REST write succeeds without a rules engine', is_array($chr_echo_resp));
check(
    'REST write echoes recomputed derived values',
    is_array($chr_echo_resp) && isset($chr_echo_resp['derived'])
        && count($chr_echo_resp['derived']) === 1
        && $chr_echo_resp['derived'][0]['prop'] === 'strength_mod' && $chr_echo_resp['derived'][0]['value'] === 2
);
