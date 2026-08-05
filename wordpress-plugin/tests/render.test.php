<?php
// Render-level tests for sheets/render.php's GM-only gate. render.php leans on
// WordPress, so the handful of core/plugin functions it touches are stubbed
// here — enough to exercise chronicler_sheets_render_sheet() and assert on the
// produced HTML string. Included by run.php after schema.php is loaded.

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Registration hooks fire at include time; make them no-ops for the harness.
if (!function_exists('add_filter')) {
    function add_filter($hook, $cb, $priority = 10, $args = 1) { return true; }
}
if (!function_exists('add_action')) {
    function add_action($hook, $cb, $priority = 10, $args = 1) { return true; }
}

// Capabilities, driven by test globals so one render can be a GM and the next
// a player. edit_others_chr_characters is the game-master capability; edit_post
// is what an owning player also holds on their own sheet.
if (!function_exists('current_user_can')) {
    function current_user_can($cap, ...$args) {
        if ($cap === 'edit_others_chr_characters') {
            return !empty($GLOBALS['chr_test_is_gm']);
        }
        if ($cap === 'edit_post') {
            // Per-post overrides first (#183 needs "can edit their own PC
            // but not this NPC"); the blanket global keeps every older
            // suite's single-answer behavior.
            $id = isset($args[0]) ? (int) $args[0] : null;
            if ($id !== null && isset($GLOBALS['chr_test_can_edit_posts'][$id])) {
                return !empty($GLOBALS['chr_test_can_edit_posts'][$id]);
            }
            return !empty($GLOBALS['chr_test_can_edit']);
        }
        // Any other capability answers from a generic map (defaults to
        // false, preserving prior behavior) — caps.test.php drives the
        // media-scoping predicate through this.
        return !empty($GLOBALS['chr_test_user_caps'][$cap]);
    }
}

// the_content gating (#158): a singular main-loop context, plus core's
// password gate driven by a test global so one call can be locked and the
// next unlocked.
if (!function_exists('is_singular')) {
    function is_singular($post_types = '') { return true; }
}
if (!function_exists('in_the_loop')) {
    function in_the_loop() { return true; }
}
if (!function_exists('is_main_query')) {
    function is_main_query() { return true; }
}
if (!function_exists('get_the_ID')) {
    function get_the_ID() { return 7; }
}
if (!function_exists('post_password_required')) {
    function post_password_required($post = null) {
        return !empty($GLOBALS['chr_test_password_required']);
    }
}

// Escaping/formatting: faithful enough that substring assertions hold.
if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_url')) {
    function esc_url($s) { return (string) $s; }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($s) { return (string) $s; }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($s) {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $s)), '-');
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v) { return json_encode($v); }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($a = -1) { return 'test-nonce'; }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'http://test.local/wp-json/' . $path; }
}
if (!function_exists('wp_is_block_theme')) {
    function wp_is_block_theme() { return false; }
}
if (!function_exists('wpautop')) {
    function wpautop($s) { return '<p>' . $s . '</p>'; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($s) { return (string) $s; }
}
if (!function_exists('checked')) {
    function checked($a, $b = true, $echo = true) { return $a == $b ? ' checked' : ''; }
}
if (!function_exists('selected')) {
    function selected($a, $b = true, $echo = true) { return $a == $b ? ' selected' : ''; }
}

// Post/author lookups used by the masthead (and #183's per-PC set labels).
if (!function_exists('get_the_title')) {
    function get_the_title($id = 0) {
        $key = is_object($id) ? (int) ($id->ID ?? 0) : (int) $id;
        return $GLOBALS['chr_test_titles'][$key] ?? 'Test Character';
    }
}
if (!function_exists('get_the_author_meta')) {
    function get_the_author_meta($field, $id = 0) { return 'Alice'; }
}
if (!function_exists('get_post_field')) {
    function get_post_field($field, $id = 0) { return $field === 'post_author' ? 1 : ''; }
}
if (!function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id($id = 0) { return 0; }
}
if (!function_exists('wp_get_attachment_image')) {
    function wp_get_attachment_image($id, $size = 'thumbnail') { return ''; }
}

// Plugin helpers that live in post-types.php (not loaded here): stub against
// the test template and value map.
if (!function_exists('chronicler_sheets_template_for_character')) {
    function chronicler_sheets_template_for_character($post_id) {
        return $GLOBALS['chr_test_template'];
    }
}
if (!function_exists('chronicler_sheets_get_value')) {
    function chronicler_sheets_get_value($post_id, array $property) {
        // Mirrors post-types.php: a derived property is never stored, so
        // recompute it fresh from the base values (#88) instead of reading
        // chr_test_values directly.
        if (isset($property['derived'])) {
            $template = $GLOBALS['chr_test_template'] ?? null;
            $computed = is_array($template) ? chronicler_sheets_compute_derived($template, $GLOBALS['chr_test_values'] ?? []) : [];
            return $computed[$property['id']] ?? chronicler_sheets_default_value($property);
        }
        return $GLOBALS['chr_test_values'][$property['id']] ?? chronicler_sheets_default_value($property);
    }
}
if (!function_exists('chronicler_sheets_get_detail')) {
    // Mirrors post-types.php: the character's chr_detail_<id> override (over
    // the shared harness meta map) beats the template's system default.
    function chronicler_sheets_get_detail($post_id, array $property) {
        $override = (string) ($GLOBALS['chr_test_post_meta'][$post_id]['chr_detail_' . $property['id']] ?? '');
        return $override !== '' ? $override : (string) ($property['detail'] ?? '');
    }
}
if (!function_exists('chronicler_sheets_is_npc')) {
    // Mirrors post-types.php's reader over the shared harness meta map
    // (the same one template-store.test.php's get_post_meta stub reads),
    // so suites drive NPC status the way production does: chr_npc = '1'.
    function chronicler_sheets_is_npc(int $post_id): bool {
        return ($GLOBALS['chr_test_post_meta'][$post_id]['chr_npc'] ?? '') === '1';
    }
}
// Opinions storage (#183), normally in post-types.php: PC enumeration from a
// test list, sets from a [character][pc] map (normalized like production),
// writes recorded for the save-path suites AND written back for re-reads.
if (!function_exists('chronicler_sheets_player_characters')) {
    function chronicler_sheets_player_characters(): array {
        return $GLOBALS['chr_test_pcs'] ?? [];
    }
}
if (!function_exists('chronicler_sheets_get_opinion')) {
    function chronicler_sheets_get_opinion(int $post_id, array $property, int $pc_id): array {
        return chronicler_sheets_normalize_opinion($property, $GLOBALS['chr_test_opinions'][$post_id][$pc_id] ?? null);
    }
}
if (!function_exists('chronicler_sheets_set_opinion')) {
    function chronicler_sheets_set_opinion(int $post_id, array $property, int $pc_id, array $set): void {
        $GLOBALS['chr_saved_opinions'][] = [$property['id'], $pc_id];
        $GLOBALS['chr_test_opinions'][$post_id][$pc_id] = chronicler_sheets_normalize_opinion($property, $set);
    }
}

require __DIR__ . '/../sheets/render.php';

// A sheet with a public "Player Notes" section and a GM-only "GM Notes"
// section — the shape from issue #63.
$gm_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test',
    'version' => 1,
    'properties' => [
        ['id' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 0, 'max' => 5],
        ['id' => 'player_notes', 'label' => 'Player Notes', 'type' => 'longtext', 'always_show' => true],
        ['id' => 'gm_notes', 'label' => 'GM Notes', 'type' => 'longtext', 'gm_only' => true, 'always_show' => true],
    ],
    'layout' => [
        ['section' => 'Stats', 'properties' => ['str']],
        ['section' => 'Player Notes', 'properties' => ['player_notes']],
        ['section' => 'GM Notes', 'properties' => ['gm_notes']],
    ],
]));
check('gm-only render fixture parses', is_array($gm_template));

$GLOBALS['chr_test_template'] = $gm_template;
$GLOBALS['chr_test_values'] = [
    'player_notes' => 'PLAYER_SECRET',
    'gm_notes' => 'GM_SECRET',
];

// --- Non-GM viewer (player / logged-out): GM Notes must be wholly absent ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$public = chronicler_sheets_render_sheet(7);

check('non-GM: GM Notes value omitted from output', strpos($public, 'GM_SECRET') === false);
check('non-GM: GM Notes property markup omitted', strpos($public, 'data-prop="gm_notes"') === false);
check('non-GM: emptied GM Notes section header omitted', strpos($public, 'GM Notes') === false);
check('non-GM: Player Notes still rendered', strpos($public, 'PLAYER_SECRET') !== false);
check('non-GM: Player Notes header still rendered', strpos($public, '<h2>Player Notes</h2>') !== false);

// --- Owning player editing their own sheet still gets no GM Notes ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true;
$owner = chronicler_sheets_render_sheet(7);
check('owning player: GM Notes value omitted', strpos($owner, 'GM_SECRET') === false);
check('owning player: Player Notes still rendered', strpos($owner, 'PLAYER_SECRET') !== false);

// --- GM viewer: GM Notes present ---
$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$gm_view = chronicler_sheets_render_sheet(7);
check('GM: GM Notes value present', strpos($gm_view, 'GM_SECRET') !== false);
check('GM: GM Notes property markup present', strpos($gm_view, 'data-prop="gm_notes"') !== false);
check('GM: GM Notes section header present', strpos($gm_view, '<h2>GM Notes</h2>') !== false);
check('GM: Player Notes still rendered', strpos($gm_view, 'PLAYER_SECRET') !== false);

// --- always_show: Player Notes / GM Notes still render when EMPTY (issue #67).
// The audience gate (GM-only) still wins for a non-GM viewer regardless of
// always_show — "always show" only overrides the emptiness check. ---
$GLOBALS['chr_test_values'] = []; // both notes fall back to their '' default

$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$empty_public = chronicler_sheets_render_sheet(7);
check('non-GM: empty Player Notes (always_show) still renders', strpos($empty_public, 'data-prop="player_notes"') !== false);
check('non-GM: empty Player Notes header still renders', strpos($empty_public, '<h2>Player Notes</h2>') !== false);
check('non-GM: empty GM Notes stays hidden — audience gate wins over always_show', strpos($empty_public, 'data-prop="gm_notes"') === false);
check('non-GM: empty GM Notes header stays hidden', strpos($empty_public, '<h2>GM Notes</h2>') === false);

$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$empty_gm = chronicler_sheets_render_sheet(7);
check('GM: empty Player Notes (always_show) still renders', strpos($empty_gm, 'data-prop="player_notes"') !== false);
check('GM: empty GM Notes (always_show) still renders', strpos($empty_gm, 'data-prop="gm_notes"') !== false);
check('GM: empty GM Notes header still renders', strpos($empty_gm, '<h2>GM Notes</h2>') !== false);

// --- owner_only gate: the inverse audience of gm_only. Private Notes must
// reach the owning player (can_edit) and the GM, and never fellow players
// or the public. ---
$oo_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test',
    'version' => 1,
    'properties' => [
        ['id' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 0, 'max' => 5],
        ['id' => 'private_notes', 'label' => 'Private Notes', 'type' => 'longtext', 'owner_only' => true, 'always_show' => true],
    ],
    'layout' => [
        ['section' => 'Stats', 'properties' => ['str']],
        ['section' => 'Private Notes', 'properties' => ['private_notes']],
    ],
]));
check('owner-only render fixture parses', is_array($oo_template));

$GLOBALS['chr_test_template'] = $oo_template;
$GLOBALS['chr_test_values'] = ['private_notes' => 'OWNER_SECRET'];

// Public / fellow player: no edit cap — wholly absent.
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$oo_public = chronicler_sheets_render_sheet(7);
check('stranger: owner-only value omitted from output', strpos($oo_public, 'OWNER_SECRET') === false);
check('stranger: owner-only property markup omitted', strpos($oo_public, 'data-prop="private_notes"') === false);
check('stranger: emptied owner-only section header omitted', strpos($oo_public, 'Private Notes') === false);
check('stranger: public Stats section still rendered', strpos($oo_public, '<h2>Stats</h2>') !== false);

// Owning player: the audience INCLUDES them — the inversion of gm_only.
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true;
$oo_owner = chronicler_sheets_render_sheet(7);
check('owning player: owner-only value present', strpos($oo_owner, 'OWNER_SECRET') !== false);
check('owning player: owner-only property markup present', strpos($oo_owner, 'data-prop="private_notes"') !== false);
check('owning player: owner-only section header present', strpos($oo_owner, '<h2>Private Notes</h2>') !== false);

// GM viewer: present too (a GM can edit every character).
$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$oo_gm = chronicler_sheets_render_sheet(7);
check('GM: owner-only value present', strpos($oo_gm, 'OWNER_SECRET') !== false);
check('GM: owner-only property markup present', strpos($oo_gm, 'data-prop="private_notes"') !== false);

// Empty + always_show: renders as a prompt for the owner, and the audience
// gate still wins over always_show for a stranger (same rule as gm_only).
$GLOBALS['chr_test_values'] = [];

$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$oo_empty_public = chronicler_sheets_render_sheet(7);
check('stranger: empty owner-only (always_show) stays hidden — audience gate wins', strpos($oo_empty_public, 'data-prop="private_notes"') === false);

$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true;
$oo_empty_owner = chronicler_sheets_render_sheet(7);
check('owning player: empty owner-only (always_show) still renders', strpos($oo_empty_owner, 'data-prop="private_notes"') !== false);
check('owning player: empty owner-only header still renders', strpos($oo_empty_owner, '<h2>Private Notes</h2>') !== false);

// --- Unfilled non-always_show fields are hidden, and placeholder-only gear
// rows are dropped from list tables (issue #67). ---
$unfilled_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test',
    'version' => 1,
    'properties' => [
        ['id' => 'occupation', 'label' => 'Occupation', 'type' => 'text'],
        ['id' => 'nickname', 'label' => 'Nickname', 'type' => 'text'],
        ['id' => 'gear', 'label' => 'Gear', 'type' => 'list', 'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'qty', 'label' => 'Qty', 'type' => 'number', 'min' => 0],
        ]],
    ],
    'layout' => [
        ['section' => 'Identity', 'properties' => ['occupation', 'nickname']],
        ['section' => 'Gear', 'properties' => ['gear']],
    ],
]));
check('unfilled-fields fixture parses', is_array($unfilled_template));

$GLOBALS['chr_test_template'] = $unfilled_template;
$GLOBALS['chr_test_values'] = [
    'occupation' => '',
    'nickname' => '[nickname]',
    'gear' => [
        ['name' => '[quantum backpack object 1]'],
        ['name' => 'Rusty cutlass', 'qty' => 1],
        ['name' => '[quantum backpack object 2]'],
    ],
];
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$mixed = chronicler_sheets_render_sheet(9);

check('empty text field is omitted', strpos($mixed, 'data-prop="occupation"') === false);
check('bracket-placeholder text field is omitted', strpos($mixed, 'data-prop="nickname"') === false);
check('Identity section header omitted when every field is unfilled', strpos($mixed, '<h2>Identity</h2>') === false);
check('placeholder gear row 1 text is omitted', strpos($mixed, 'quantum backpack object 1') === false);
check('placeholder gear row 2 text is omitted', strpos($mixed, 'quantum backpack object 2') === false);
check('real gear row still renders', strpos($mixed, 'Rusty cutlass') !== false);
check('Gear section header still renders (a real entry survives)', strpos($mixed, '<h2>Gear</h2>') !== false);

// --- List-table cells beyond the row-header column carry a data-label
// attribute, so mobile CSS can stack the table and print each field's label
// beside its value (issue #64). The row-header column (the entry's name)
// stays label-free — it's already the visually prominent line. ---
check('non-header list cell carries data-label with the field\'s own label', strpos($mixed, 'data-label="Qty">1</td>') !== false);
check('row-header cell (entry name) has no data-label', strpos($mixed, 'data-label="Name"') === false);

// --- Those same cells also carry their field's ID (data-field), so CSS can
// key styling on schema structure rather than the display label (issue #69's
// have/advanced marks) — labels are freely reworded in wp-admin, ids aren't. ---
check('non-header list cell carries data-field with the field\'s id', strpos($mixed, 'data-field="qty"') !== false);
check('row-header cell (entry name) has no data-field', strpos($mixed, 'data-field="name"') === false);

// The real consumer: an Improvements-shaped list with "advanced" and "has"
// toggle fields must stamp both ids on their cells.
$improvements_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test',
    'version' => 1,
    'properties' => [
        ['id' => 'improvements', 'label' => 'Improvements', 'type' => 'list', 'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'advanced', 'label' => 'Advanced?', 'type' => 'toggle'],
            ['id' => 'has', 'label' => 'Has it', 'type' => 'toggle'],
        ]],
    ],
    'layout' => [
        ['section' => 'Improvements', 'properties' => ['improvements']],
    ],
]));
check('improvements fixture parses', is_array($improvements_template));
$GLOBALS['chr_test_template'] = $improvements_template;
$GLOBALS['chr_test_values'] = [
    'improvements' => [
        ['name' => 'Get +1 Cool, max+3', 'advanced' => false, 'has' => true],
        ['name' => 'Retire this hunter to safety', 'advanced' => true, 'has' => false],
    ],
];
$improvements = chronicler_sheets_render_sheet(11);
check('checked "has" cell carries data-field="has"', strpos($improvements, '<td data-field="has" data-label="Has it">&#10003;</td>') !== false);
check('checked "advanced" cell carries data-field="advanced"', strpos($improvements, '<td data-field="advanced" data-label="Advanced?">&#10003;</td>') !== false);
check('unchecked toggle cell still carries its data-field', strpos($improvements, '<td data-field="advanced" data-label="Advanced?"></td>') !== false);

// Restore the unfilled-fields fixture for the assertions below.
$GLOBALS['chr_test_template'] = $unfilled_template;
$GLOBALS['chr_test_values'] = [
    'occupation' => '',
    'nickname' => '[nickname]',
];

$GLOBALS['chr_test_values']['gear'] = [
    ['name' => '[quantum backpack object 1]'],
    ['name' => '[quantum backpack object 2]'],
];
$all_placeholder = chronicler_sheets_render_sheet(9);
check('Gear section is dropped entirely when every entry is a placeholder', strpos($all_placeholder, '<h2>Gear</h2>') === false);
check('no placeholder text leaks once the section is dropped', strpos($all_placeholder, 'quantum backpack') === false);

$GLOBALS['chr_test_values']['occupation'] = 'Fisherman';
$GLOBALS['chr_test_values']['nickname'] = 'Big Marty';
$filled = chronicler_sheets_render_sheet(9);
check('a filled text field renders normally', strpos($filled, 'data-prop="occupation"') !== false);
check('Identity header returns once a field is filled', strpos($filled, '<h2>Identity</h2>') !== false);

// --- Unfilled masthead trait lines are hidden too (issue #67: "Identity
// fields render as empty label rows when unset" — the label: value lines in
// the header card). always_show overrides here the same as in the body. ---
$masthead_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test',
    'version' => 1,
    'properties' => [
        ['id' => 'playbook', 'label' => 'Playbook', 'type' => 'text'],
        ['id' => 'occupation', 'label' => 'Occupation', 'type' => 'text'],
        ['id' => 'age', 'label' => 'Age', 'type' => 'text'],
        ['id' => 'motto', 'label' => 'Motto', 'type' => 'text', 'always_show' => true],
        ['id' => 'grit', 'label' => 'Grit', 'type' => 'number', 'min' => 0, 'max' => 5],
    ],
    'layout' => [
        ['section' => 'Identity', 'properties' => ['playbook', 'occupation', 'age', 'motto'], 'masthead' => true],
        ['section' => 'Stats', 'properties' => ['grit']],
    ],
]));
check('masthead render fixture parses', is_array($masthead_template));

$GLOBALS['chr_test_template'] = $masthead_template;
$GLOBALS['chr_test_values'] = [
    'playbook' => 'The Chosen',
    'occupation' => '',
    'age' => '[age]',
    // motto left unset — always_show must keep its line anyway
];
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$masthead = chronicler_sheets_render_sheet(11);

check('masthead: filled trait renders', strpos($masthead, 'The Chosen') !== false);
check('masthead: empty trait line is omitted', strpos($masthead, 'Occupation:') === false);
check('masthead: bracket-placeholder trait line is omitted', strpos($masthead, 'Age:') === false && strpos($masthead, '[age]') === false);
check('masthead: empty always_show trait still renders', strpos($masthead, 'Motto:') !== false);

// When the first (primary-slot) trait is unfilled, the next filled trait
// takes the prominent slot instead of an empty line.
$GLOBALS['chr_test_values'] = [
    'playbook' => '[playbook]',
    'occupation' => 'Fisherman',
    'age' => '',
];
$promoted = chronicler_sheets_render_sheet(11);
check('masthead: unfilled primary trait is omitted', strpos($promoted, '[playbook]') === false);
check('masthead: next filled trait is promoted to the primary slot', strpos($promoted, 'chr-masthead__trait--primary">Fisherman<') !== false);

// All traits unfilled (none always_show): the traits container disappears.
$bare_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test',
    'version' => 1,
    'properties' => [
        ['id' => 'playbook', 'label' => 'Playbook', 'type' => 'text'],
        ['id' => 'occupation', 'label' => 'Occupation', 'type' => 'text'],
        ['id' => 'grit', 'label' => 'Grit', 'type' => 'number', 'min' => 0, 'max' => 5],
    ],
    'layout' => [
        ['section' => 'Identity', 'properties' => ['playbook', 'occupation'], 'masthead' => true],
        ['section' => 'Stats', 'properties' => ['grit']],
    ],
]));
check('bare masthead fixture parses', is_array($bare_template));
$GLOBALS['chr_test_template'] = $bare_template;
$GLOBALS['chr_test_values'] = ['playbook' => '', 'occupation' => '  '];
$bare = chronicler_sheets_render_sheet(11);
check('masthead: traits container omitted when every trait is unfilled', strpos($bare, 'chr-masthead__traits') === false);

// --- when-gated list cells (expression form, 2026-07-13) ---
$chr_when_prop = [
    'id' => 'gear', 'label' => 'Gear', 'type' => 'list',
    'fields' => [
        ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
        ['id' => 'is_weapon', 'label' => 'Weapon?', 'type' => 'toggle'],
        ['id' => 'notes', 'label' => 'Notes', 'type' => 'text', 'when' => 'is_weapon and name != ""'],
    ],
];
$chr_when_table = chronicler_sheets_render_list_table($chr_when_prop, [
    ['name' => 'Shotgun', 'is_weapon' => true, 'notes' => 'loud'],
    ['name' => 'Rope', 'is_weapon' => false, 'notes' => 'long'],
]);
check('when expression holds: gated cell renders', strpos($chr_when_table, 'loud') !== false);
check('when expression fails: gated cell blanks (value stays stored)', strpos($chr_when_table, 'long') === false);

// --- Password-protected characters (#158): when core's password gate applies,
// the_content receives the password form as $content and must return it
// untouched — rebuilding the sheet from meta would disclose the masthead and
// every stat around the form. ---
$GLOBALS['chr_test_template'] = $gm_template;
$GLOBALS['chr_test_values'] = ['str' => 3, 'player_notes' => 'PLAYER_SECRET', 'gm_notes' => 'GM_SECRET'];
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;

$GLOBALS['chr_test_password_required'] = true;
$sheet_locked = chronicler_sheets_the_content('PASSWORD_FORM');
check('password-protected: content (the password form) passes through unmodified', $sheet_locked === 'PASSWORD_FORM');
check('password-protected: no sheet markup renders', strpos($sheet_locked, 'data-chronicler-sheet') === false);
check('password-protected: no masthead renders', strpos($sheet_locked, 'chr-masthead') === false);
check('password-protected: no stat value leaks', strpos($sheet_locked, 'PLAYER_SECRET') === false);

$GLOBALS['chr_test_password_required'] = false;
$sheet_unlocked = chronicler_sheets_the_content('INTRO');
check('password satisfied: the sheet renders again', strpos($sheet_unlocked, 'data-chronicler-sheet') !== false);
check('password satisfied: the content rides along as the intro', strpos($sheet_unlocked, 'INTRO') !== false);
unset($GLOBALS['chr_test_password_required']);

// --- NPC pages (#176): stats are kept, not displayed. Viewers who can't
// edit the character get a lore page — portrait, name, tagline, intro —
// with the whole stat block (masthead traits AND body sections) withheld,
// and "Played by" gone for every viewer. Editors keep the full sheet plus
// a note explaining why their page differs from what visitors see. ---
$GLOBALS['chr_test_template'] = $masthead_template;
$GLOBALS['chr_test_values'] = ['playbook' => 'The Chosen', 'grit' => 3];

// Control: an ordinary PC still credits its player and gets no NPC class.
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$pc_page = chronicler_sheets_render_sheet(11, 'INTRO');
check('PC: "Played by" renders', strpos($pc_page, 'Played by Alice') !== false);
check('PC: no chr-sheet--npc class', strpos($pc_page, 'chr-sheet--npc') === false);
check('PC: no NPC editor note', strpos($pc_page, 'chr-sheet__npc-note') === false);

$GLOBALS['chr_test_post_meta'][11]['chr_npc'] = '1';
$npc_public = chronicler_sheets_render_sheet(11, 'INTRO');
check('NPC public: "Played by" omitted', strpos($npc_public, 'Played by') === false);
check('NPC public: masthead traits withheld', strpos($npc_public, 'The Chosen') === false);
check(
    'NPC public: body sections withheld entirely',
    strpos($npc_public, '<h2>Stats</h2>') === false && strpos($npc_public, 'data-prop=') === false
);
check('NPC public: masthead still renders', strpos($npc_public, 'chr-masthead__name') !== false);
check('NPC public: intro still renders', strpos($npc_public, 'INTRO') !== false);
check('NPC public: page carries the chr-sheet--npc class', strpos($npc_public, 'chr-sheet--npc') !== false);
check('NPC public: no editor note for non-editors', strpos($npc_public, 'chr-sheet__npc-note') === false);

$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$npc_editor = chronicler_sheets_render_sheet(11, 'INTRO');
check(
    'NPC editor: the full stat block renders',
    strpos($npc_editor, '<h2>Stats</h2>') !== false && strpos($npc_editor, 'The Chosen') !== false
);
check('NPC editor: the note explains the visitor view', strpos($npc_editor, 'chr-sheet__npc-note') !== false);
check('NPC editor: "Played by" still omitted', strpos($npc_editor, 'Played by') === false);
unset($GLOBALS['chr_test_post_meta'][11]);

// --- Opinions (#183): per-PC opinion sets on NPC pages — each a personal
// notebook. A set renders exactly for the viewer who can edit ITS player
// character (plus GMs, who see every set) — the one gate that pierces the
// NPC withhold; fellow players and the public get no markup at all. ---
$opinion_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test', 'version' => 1,
    'properties' => [
        ['id' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 0, 'max' => 5],
        ['id' => 'opinions', 'label' => 'Opinion', 'type' => 'opinions', 'length' => 6],
    ],
    'layout' => [
        ['section' => 'Stats', 'properties' => ['str']],
        ['section' => 'Opinions', 'properties' => ['opinions']],
    ],
]));
check('opinions render fixture parses', is_array($opinion_template));
$GLOBALS['chr_test_template'] = $opinion_template;
$GLOBALS['chr_test_values'] = ['str' => 3];
$GLOBALS['chr_test_pcs'] = [21, 22];
$GLOBALS['chr_test_titles'] = [21 => 'Alec', 22 => 'Sam'];
$GLOBALS['chr_test_opinions'] = [12 => [
    21 => ['rating' => 2, 'notes' => 'ALEC_OPINION_NOTES'],
    22 => ['rating' => 5, 'notes' => 'SAM_OPINION_NOTES'],
]];
$GLOBALS['chr_test_post_meta'][12]['chr_npc'] = '1';

// The public: no table membership, no opinions — and the NPC withhold still
// hides the stats, so the page stays a pure lore page.
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$GLOBALS['chr_test_user_caps'] = [];
$op_public = chronicler_sheets_render_sheet(12, 'INTRO');
check('opinions public: no set markup', strpos($op_public, 'chr-opinion') === false);
check('opinions public: no notes leak', strpos($op_public, 'ALEC_OPINION_NOTES') === false && strpos($op_public, 'SAM_OPINION_NOTES') === false);
check('opinions public: no Opinions section heading', strpos($op_public, '<h2>Opinions</h2>') === false);
check('opinions public: boot canOpine false', strpos($op_public, '"canOpine":false') !== false);

// A player (Alec's): their own set only — a personal notebook. The other
// player's set is dropped whole: no label, no markup, no values.
$GLOBALS['chr_test_user_caps'] = ['edit_chr_characters' => true];
$GLOBALS['chr_test_can_edit_posts'] = [21 => true, 22 => false];
$op_player = chronicler_sheets_render_sheet(12, 'INTRO');
check('opinions player: their own set renders, PC-labeled', strpos($op_player, 'Alec’s Opinion') !== false);
check('opinions player: the set is addressed by data-pc', strpos($op_player, 'data-pc="21"') !== false);
check(
    "opinions player: the other player's set is dropped whole",
    strpos($op_player, 'Sam’s Opinion') === false
        && strpos($op_player, 'data-pc="22"') === false
        && strpos($op_player, 'SAM_OPINION_NOTES') === false
);
check(
    'opinions player: their set is editable',
    substr_count($op_player, 'textarea class="chr-longtext chr-opinion__notes"') === 1
);
check('opinions player: stats stay withheld (the NPC gate holds)', strpos($op_player, '<h2>Stats</h2>') === false);
check('opinions player: boot canOpine true', strpos($op_player, '"canOpine":true') !== false);
check('opinions player: boot canEdit still false', strpos($op_player, '"canEdit":false') !== false);

// The GM: every player's set, every pen, plus the full stat block and the
// widened note.
$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
unset($GLOBALS['chr_test_can_edit_posts']);
$op_gm = chronicler_sheets_render_sheet(12, 'INTRO');
check('opinions GM: every set renders', strpos($op_gm, 'Alec’s Opinion') !== false && strpos($op_gm, 'Sam’s Opinion') !== false);
check('opinions GM: every set editable', substr_count($op_gm, 'textarea class="chr-longtext chr-opinion__notes"') === 2);
check('opinions GM: stats render too', strpos($op_gm, '<h2>Stats</h2>') !== false);
check('opinions GM: the NPC note explains the private notebooks', strpos($op_gm, 'opinion box') !== false);

// On a PC's page the property is simply absent — for every viewer.
unset($GLOBALS['chr_test_post_meta'][12]);
$op_on_pc = chronicler_sheets_render_sheet(12, 'INTRO');
check('opinions on a PC page: nothing renders, GM included', strpos($op_on_pc, 'chr-opinion') === false && strpos($op_on_pc, '<h2>Opinions</h2>') === false);
$GLOBALS['chr_test_post_meta'][12]['chr_npc'] = '1';

// A campaign with no PCs yet renders no empty Opinions section.
$GLOBALS['chr_test_pcs'] = [];
$op_no_pcs = chronicler_sheets_render_sheet(12, 'INTRO');
check('opinions with no PCs: section dropped', strpos($op_no_pcs, '<h2>Opinions</h2>') === false);

unset(
    $GLOBALS['chr_test_post_meta'][12],
    $GLOBALS['chr_test_pcs'],
    $GLOBALS['chr_test_titles'],
    $GLOBALS['chr_test_opinions'],
    $GLOBALS['chr_test_user_caps']
);

// --- Active Effects (2026-08-04): applied state, not authored layout ---------
// A GM-applied effect renders under the masthead in the same fields
// /game effect prints, for every viewer — no secret effects — and a character
// carrying nothing renders nothing at all.
$fx_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Test', 'version' => 1,
    'properties' => [
        ['id' => 'rizz', 'label' => 'Rizz', 'type' => 'dice'],
        ['id' => 'grit', 'label' => 'Grit', 'type' => 'number', 'min' => 0, 'max' => 5],
    ],
    'rolls' => [['id' => 'nausea_check', 'label' => 'Nausea Check', 'dice' => '2d6']],
    'effects' => [
        ['id' => 'queasy', 'label' => 'Queasy', 'applies_to' => 'nausea_check', 'modifier' => -1, 'cap' => -3],
        ['id' => 'taunted', 'label' => 'Taunted', 'modifier' => "'rizz' in roll['uses'] ? -amount : 0"],
    ],
    'layout' => [['section' => 'Stats', 'properties' => ['grit']]],
]));
check('effects render fixture parses', is_array($fx_template), is_wp_error($fx_template) ? $fx_template->get_error_message() : '');
$GLOBALS['chr_test_template'] = $fx_template;
$GLOBALS['chr_test_values'] = ['grit' => 3];
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;

$no_effects = chronicler_sheets_render_sheet(31);
check('effects: a character carrying none renders nothing new', strpos($no_effects, 'chr-section--effects') === false);

$GLOBALS['chr_test_post_meta'][31][CHRONICLER_EFFECTS_META] = wp_json_encode([
    chronicler_sheets_effects_normalize(['effect' => 'queasy', 'amount' => 2, 'note' => 'he ate the whole thing']),
    chronicler_sheets_effects_normalize(['label' => 'Acne', 'modifier' => -2, 'target' => 'rizz']),
    chronicler_sheets_effects_normalize(['effect' => 'taunted']),
    chronicler_sheets_effects_normalize(['effect' => 'bogus']),
]);
$with_effects = chronicler_sheets_render_sheet(31);
check('effects: the row renders in the sheet\'s own section chrome',
    strpos($with_effects, '<section class="chr-section chr-section--effects"><h2>Active Effects</h2>') !== false);
check('effects: it sits directly under the masthead',
    strpos($with_effects, 'chr-section--effects') < strpos($with_effects, '<h2>Stats</h2>'));
check(
    'effects: a named instance shows its definition\'s label, contribution, amount and target',
    strpos($with_effects, '<span class="chr-effect__label">Queasy</span><span class="chr-effect__modifier">-1</span>'
        . '<span class="chr-effect__amount">×2</span><span class="chr-effect__target">on nausea_check</span>'
        . '<span class="chr-effect__note">he ate the whole thing</span>') !== false,
    $with_effects
);
check('effects: a one-off shows its own label and number',
    strpos($with_effects, '<span class="chr-effect__label">Acne</span><span class="chr-effect__modifier">-2</span>'
        . '<span class="chr-effect__target">on rizz</span>') !== false);
check('effects: an amount of one goes unsaid', strpos($with_effects, '×1') === false);
check('effects: a formula modifier prints as expr — its number depends on the roll',
    strpos($with_effects, '<span class="chr-effect__label">Taunted</span><span class="chr-effect__modifier">expr</span>') !== false);
check(
    'effects: an instance the system no longer declares is flagged under its id',
    strpos($with_effects, '<li class="chr-effect chr-effect--unknown" data-effect="bogus">'
        . '<span class="chr-effect__label">bogus</span>'
        . '<span class="chr-effect__note">no longer in this system — clear it?</span></li>') !== false
);
check('effects: an effect id is addressable by site CSS', strpos($with_effects, 'data-effect="queasy"') !== false);

// An NPC's effects go with the rest of its withheld stat block: to a visitor
// that page is lore, not a sheet. Its editor still sees them.
$GLOBALS['chr_test_post_meta'][31]['chr_npc'] = '1';
$npc_effects = chronicler_sheets_render_sheet(31);
check('effects: an NPC withholds them from visitors with the rest of the stats',
    strpos($npc_effects, 'chr-section--effects') === false);
$GLOBALS['chr_test_can_edit'] = true;
$GLOBALS['chr_test_is_gm'] = true;
check('effects: … and its editor still sees them',
    strpos(chronicler_sheets_render_sheet(31), 'chr-section--effects') !== false);
unset($GLOBALS['chr_test_post_meta'][31]);
