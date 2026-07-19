<?php
// Character-index tests (#66): partition, section/flat rendering rules, and
// the tag-archive orderby prepend. sheets/index.php leans on WordPress like
// render.php does, so the handful of extra functions it touches are stubbed
// here (render.test.php, included earlier by run.php, already stubbed the
// escaping/title/thumbnail set — and chronicler_sheets_is_npc, which reads
// the shared chr_test_post_meta map). Included by run.php after
// render.test.php.

// Index query, driven by test globals. Args are recorded so suites can
// assert query SCOPE (uninstall.test.php: post_type/status/meta_key), not
// just that results were consumed — a stub that swallows its args can't
// catch a widened or typo'd query (#173 review).
if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        $GLOBALS['chr_test_get_posts_calls'][] = $args;
        return $GLOBALS['chr_test_index_ids'] ?? [];
    }
}
if (!function_exists('update_meta_cache')) {
    function update_meta_cache($type, $ids) { return []; }
}
if (!function_exists('get_permalink')) {
    function get_permalink($id = 0) { return 'http://test.local/?chr=' . $id; }
}
if (!function_exists('register_block_type')) {
    function register_block_type($name, $args = []) { return true; }
}
if (!function_exists('post_type_archive_title')) {
    function post_type_archive_title($prefix = '', $display = true) { return 'Characters'; }
}
// Options share settings.test.php's backing global (that file's own guarded
// stubs skip when these load first — run.php includes this file earlier).
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['chronicler_test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['chronicler_test_options'][$name] = $value;
        return true;
    }
}
require __DIR__ . '/../sheets/index.php';

/** NPC status is the chr_npc flag (#176); seed it the way production reads it. */
function chr_test_seed_npcs(array $ids): void {
    $GLOBALS['chr_test_post_meta'] = [];
    foreach ($ids as $id) {
        $GLOBALS['chr_test_post_meta'][$id]['chr_npc'] = '1';
    }
}

// --- partition ---------------------------------------------------------------

[$pcs, $npcs] = chronicler_sheets_partition_characters(
    [1, 2, 3, 4],
    fn ($id) => $id % 2 === 0
);
check('partition keeps PCs in input order', $pcs === [1, 3]);
check('partition keeps NPCs in input order', $npcs === [2, 4]);

[$pcs, $npcs] = chronicler_sheets_partition_characters([], fn ($id) => true);
check('partition of nothing is two empty groups', $pcs === [] && $npcs === []);

// --- index rendering: both kinds present → two headed sections ---------------

$GLOBALS['chr_test_index_ids'] = [10, 20, 30, 40];
chr_test_seed_npcs([20, 40]);
$html = chronicler_sheets_render_index();

check('index: PC section heading rendered', strpos($html, '>Player Characters<') !== false);
check('index: NPC section heading rendered', strpos($html, '>NPCs<') !== false);
check('index: every character card rendered', substr_count($html, 'chr-index__card') === 4);
check(
    'index: PCs render before NPCs',
    strpos($html, 'data-character="10"') < strpos($html, 'data-character="20"')
        && strpos($html, 'data-character="30"') < strpos($html, 'data-character="20"')
);
check(
    'index: NPC order preserved from the query (menu_order)',
    strpos($html, 'data-character="20"') < strpos($html, 'data-character="40"')
);
check('index: cards link to the character', strpos($html, 'http://test.local/?chr=10') !== false);
$npc_section = substr($html, strpos($html, 'chr-index__group--npcs'));
check('index: NPC cards live in the NPC section', strpos($npc_section, 'data-character="20"') !== false);
check('index: PC cards do not leak into the NPC section', strpos($npc_section, 'data-character="10"') === false);

// --- index rendering: one kind only → flat grid, no headings ------------------

chr_test_seed_npcs([]);
$flat = chronicler_sheets_render_index();
check('index: no NPCs → no group headings', strpos($flat, '>Player Characters<') === false && strpos($flat, '>NPCs<') === false);
check('index: no NPCs → all cards still render', substr_count($flat, 'chr-index__card') === 4);

chr_test_seed_npcs([10, 20, 30, 40]);
$all_npc = chronicler_sheets_render_index();
check('index: all NPCs → flat grid too', strpos($all_npc, '>NPCs<') === false);

$GLOBALS['chr_test_index_ids'] = [];
check('index: no characters → empty-state message', strpos(chronicler_sheets_render_index(), 'chr-index__empty') !== false);

// Leave the shared meta map clean for surfaces.test.php downstream.
chr_test_seed_npcs([]);

// --- tag-archive orderby: characters first, original order intact -------------

check(
    'orderby: character pin prepended to an existing clause',
    chronicler_sheets_character_first_orderby('wp_posts.post_date DESC', 'wp_posts')
        === "(wp_posts.post_type = 'chr_character') DESC, wp_posts.post_date DESC"
);
check(
    'orderby: character pin stands alone when the clause is empty',
    chronicler_sheets_character_first_orderby('', 'wp_posts')
        === "(wp_posts.post_type = 'chr_character') DESC"
);
