<?php
// Template-config storage (#163): chr_template source lives in the
// chr_template_config meta, with legacy post_content migrated on first read.
// Loaded by run.php BEFORE render/surfaces so these stubs win their guards —
// the defaults ('' meta, recorded writes) are exactly what those suites
// already assume.

if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key = '', $single = false) {
        return $GLOBALS['chr_test_post_meta'][$id][$key] ?? '';
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $value) {
        // chr_test_post_meta_fail simulates a write that doesn't land (DB
        // error, a metadata filter short-circuit) — default off, so the
        // suites that share this stub see the old always-succeeds behavior.
        if (!empty($GLOBALS['chr_test_post_meta_fail'])) {
            return false;
        }
        $GLOBALS['chr_test_post_meta_writes'][] = [$id, $key, $value];
        $GLOBALS['chr_test_post_meta'][$id][$key] = is_string($value) ? wp_unslash($value) : $value;
        return true;
    }
}
if (!function_exists('wp_slash')) {
    function wp_slash($value) {
        return is_string($value) ? addslashes($value) : $value;
    }
}
// wp_unslash is first defined by surfaces.test.php (guarded); the stub here
// must exist before update_post_meta's first call.
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!class_exists('WP_Post')) {
    class WP_Post {
        public $ID = 0;
        public $post_type = 'chr_character';
        public $post_content = '';
    }
}

require __DIR__ . '/../sheets/template-store.php';

function chronicler_template_store_reset(): void {
    $GLOBALS['chr_test_post_meta'] = [];
    $GLOBALS['chr_test_post_meta_writes'] = [];
}

function chronicler_template_post(int $id, string $content): WP_Post {
    $post = new WP_Post();
    $post->ID = $id;
    $post->post_type = 'chr_template';
    $post->post_content = $content;
    return $post;
}

// --- meta present: the meta row wins over cleared/identical post_content -----
chronicler_template_store_reset();
$GLOBALS['chr_test_post_meta'][42][CHRONICLER_TEMPLATE_META] = 'system: FromMeta';
check(
    'source: meta wins when post_content is cleared (the post-save state)',
    chronicler_sheets_template_source(chronicler_template_post(42, '')) === 'system: FromMeta'
);
check(
    'source: meta wins when post_content still equals it (migrated, unsaved)',
    chronicler_sheets_template_source(chronicler_template_post(42, 'system: FromMeta')) === 'system: FromMeta'
);
check('source: a meta hit triggers no migration write', $GLOBALS['chr_test_post_meta_writes'] === []);

// --- rollback recovery (#173 review): a non-empty post_content that DISAGREES
// with meta can only have been written by a pre-#163 plugin after a rollback
// (the save path always clears post_content) — it is the newer edit, wins,
// and re-homes, instead of being silently shadowed by stale meta forever.
chronicler_template_store_reset();
$GLOBALS['chr_test_post_meta'][46][CHRONICLER_TEMPLATE_META] = 'system: Stale';
$rollback_edit = 'system: FixedDuringRollback';
check(
    'source: a differing post_content (rollback-era save) wins over stale meta',
    chronicler_sheets_template_source(chronicler_template_post(46, $rollback_edit)) === $rollback_edit
);
check(
    'source: the rollback-era edit is re-homed into meta',
    ($GLOBALS['chr_test_post_meta'][46][CHRONICLER_TEMPLATE_META] ?? null) === $rollback_edit
);

// --- legacy post: read from post_content, re-homed into meta on the spot -----
chronicler_template_store_reset();
// The kses-mangle shape PLUS a literal backslash and quotes: addslashes is
// only non-identity on those bytes, so without them the slash-safety checks
// below would pass even if the migration dropped its wp_slash (#173 review).
$legacy = 'system: MOTW' . "\n" . 'formulas: harm_rating >= 3 && dir == "C:\\loot"';
$source = chronicler_sheets_template_source(chronicler_template_post(43, $legacy));
check('source: legacy post_content is the fallback', $source === $legacy);
check(
    'source: the legacy read backfills the meta row',
    ($GLOBALS['chr_test_post_meta'][43][CHRONICLER_TEMPLATE_META] ?? null) === $legacy
);
check(
    'source: the backfill is slash-safe (stored byte-for-byte)',
    count($GLOBALS['chr_test_post_meta_writes']) === 1
        && wp_unslash($GLOBALS['chr_test_post_meta_writes'][0][2]) === $legacy
);
// A second read now takes the meta path — no further writes.
$again = chronicler_sheets_template_source(chronicler_template_post(43, $legacy));
check('source: the migrated post reads from meta thereafter', $again === $legacy && count($GLOBALS['chr_test_post_meta_writes']) === 1);

// --- empty everything: no source, no write ------------------------------------
chronicler_template_store_reset();
check(
    'source: an empty post yields empty source',
    chronicler_sheets_template_source(chronicler_template_post(44, '')) === ''
);
check('source: nothing to migrate means nothing written', $GLOBALS['chr_test_post_meta_writes'] === []);

// --- save: wp_slash'd so the raw buffer round-trips through update_post_meta --
chronicler_template_store_reset();
$raw = 'system: "Quoted"\nwhen: harm["current"] >= 3';
check('save: reports success on a verified write', chronicler_sheets_save_template_source(45, $raw) === true);
check(
    'save: stored meta is the exact input buffer',
    ($GLOBALS['chr_test_post_meta'][45][CHRONICLER_TEMPLATE_META] ?? null) === $raw
);
check(
    'save: the write was slashed against update_post_meta\'s unslash',
    wp_unslash($GLOBALS['chr_test_post_meta_writes'][0][2]) === $raw
);

// --- save failure: the read-back guard (#173 review) --------------------------
// A write that doesn't land must return false — sheets/admin.php's
// "Template saved." notice and the active-template activation hang on it.
chronicler_template_store_reset();
$GLOBALS['chr_test_post_meta_fail'] = true;
check(
    'save: reports failure when the meta write does not land',
    chronicler_sheets_save_template_source(47, 'system: Unstored') === false
);
$GLOBALS['chr_test_post_meta_fail'] = false;
check('save: the failed write stored nothing', ($GLOBALS['chr_test_post_meta'][47][CHRONICLER_TEMPLATE_META] ?? null) === null);

chronicler_template_store_reset();
