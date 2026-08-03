<?php
// The active-character authority (GitHub #17): chr_active is tri-state per
// sheet (absent = undecided, '1' = active, '0' = GM opt-out tombstone), and
// chronicler_sheets_active_character_for() resolves-and-heals lazily: the
// flagged sheet wins, else the newest undecided sheet is promoted in place,
// else null. Reuses the shared stubs run.php has already loaded: get_posts /
// update_meta_cache (index), get_post_meta / update_post_meta
// (template-store), chronicler_sheets_is_npc reading the chr_test_post_meta
// map (render).

require __DIR__ . '/../sheets/active.php';

/** Seed the author's published sheets (modified-DESC order) and their meta. */
function chr_active_test_reset(array $ids, array $meta = []): void {
    $GLOBALS['chr_test_index_ids'] = $ids;
    $GLOBALS['chr_test_get_posts_calls'] = [];
    $GLOBALS['chr_test_post_meta'] = $meta;
    $GLOBALS['chr_test_post_meta_writes'] = [];
}

// --- the flag wins ------------------------------------------------------------
chr_active_test_reset([20, 10], [10 => ['chr_active' => '1']]);
check('flagged sheet wins over a more recently modified sibling',
    chronicler_sheets_active_character_for(5) === 10);
check('a resolved flag heals nothing', $GLOBALS['chr_test_post_meta_writes'] === []);
$chr_active_query = $GLOBALS['chr_test_get_posts_calls'][0] ?? [];
check('the resolver asks for ALL the author\'s published sheets, newest modified first',
    ($chr_active_query['post_type'] ?? null) === 'chr_character'
    && ($chr_active_query['post_status'] ?? null) === 'publish'
    && ($chr_active_query['author'] ?? null) === 5
    && ($chr_active_query['orderby'] ?? null) === 'modified'
    && ($chr_active_query['order'] ?? null) === 'DESC'
    && ($chr_active_query['numberposts'] ?? null) === -1
    && ($chr_active_query['fields'] ?? null) === 'ids');

// --- lazy self-heal -----------------------------------------------------------
chr_active_test_reset([20, 10]);
check('no flag anywhere: the newest undecided sheet is promoted',
    chronicler_sheets_active_character_for(5) === 20);
check('the promotion is written to the sheet',
    in_array([20, 'chr_active', '1'], $GLOBALS['chr_test_post_meta_writes'], true));
check('healing is idempotent: the second read returns the same sheet without a second write',
    chronicler_sheets_active_character_for(5) === 20
    && count($GLOBALS['chr_test_post_meta_writes']) === 1);

chr_active_test_reset([30, 20, 10], [30 => ['chr_active' => '0']]);
check('heal skips a tombstoned sheet even when it is newest',
    chronicler_sheets_active_character_for(5) === 20);

chr_active_test_reset([40, 30], [40 => ['chr_npc' => '1']]);
check('heal skips an NPC', chronicler_sheets_active_character_for(5) === 30);

chr_active_test_reset([20, 10], [10 => ['chr_active' => '1', 'chr_npc' => '1']]);
check('a flagged sheet that became an NPC is ineligible — heal moves on',
    chronicler_sheets_active_character_for(5) === 20);

// --- the deliberate no-active state -------------------------------------------
chr_active_test_reset([20, 10], [20 => ['chr_active' => '0'], 10 => ['chr_active' => '0']]);
check('all eligible sheets tombstoned: the GM opted out — null',
    chronicler_sheets_active_character_for(5) === null);
check('an opt-out heals nothing', $GLOBALS['chr_test_post_meta_writes'] === []);

chr_active_test_reset([]);
check('no published sheets at all: null', chronicler_sheets_active_character_for(5) === null);

// Leave the shared globals clean for login.test.php downstream.
chr_active_test_reset([]);
