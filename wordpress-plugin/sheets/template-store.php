<?php
// Template-config storage (#163): a chr_template post's YAML/JSON source lives
// in the chr_template_config meta row, NOT in post_content.
//
// The same move Rules made earlier (src/Store/Rules.php, #101): template
// formulas carry `>=`-style angle brackets that content_save_pre →
// wp_filter_post_kses mangles on save for users without unfiltered_html —
// every multisite admin. Validation runs on the raw POST *before* kses, so
// the save silently "succeeded" while the stored copy lost its `>=`; the
// mangled source then failed parsing on read, and because the active
// template is global, EVERY sheet on the site degraded to "No sheet
// template is configured yet." (Kimi #157 F9 — verified worse than first
// stated: it bricks all sheets, not just the one template.)
//
// Split out of post-types.php so the harness can load it in isolation — the
// same reason caps.php split out (the render/surfaces tests stub the
// post-types.php helpers, so requiring it there fatals).

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

/** The meta key holding a chr_template post's YAML/JSON source. */
const CHRONICLER_TEMPLATE_META = 'chr_template_config';

/**
 * A template post's source: the meta row when it agrees with the post, else
 * post_content, re-homed into meta on the spot.
 *
 * The fallback doubles as the migration: a pre-#163 post still carrying its
 * config in post_content is read from there once and re-homed into meta,
 * ending the kses exposure without a version-stamped upgrade pass.
 * get_post_meta's '' unset-sentinel makes "never migrated" distinguishable
 * from a stored config, and an empty source can never be a real one — the
 * configurator rejects unparseable input, so '' always means "look at
 * post_content".
 *
 * A non-empty post_content that DISAGREES with a non-empty meta row also
 * wins and re-homes (#173 review): the save path always clears post_content
 * (sheets/admin.php), so a differing copy can only have been written by an
 * older plugin version after a rollback — it is the newer edit, and letting
 * meta shadow it would silently revert that edit forever on re-upgrade.
 * (The other direction — rolling back and never re-saving — keeps sheets
 * dark until the site returns to >= 4.16, because pre-#163 code only reads
 * post_content, which the newer save cleared; the data itself stays safe in
 * meta.)
 */
function chronicler_sheets_template_source(WP_Post $post): string {
    $meta = get_post_meta($post->ID, CHRONICLER_TEMPLATE_META, true);
    $meta = is_string($meta) ? $meta : '';
    $legacy = is_string($post->post_content) ? $post->post_content : '';
    if ($meta !== '' && ($legacy === '' || $legacy === $meta)) {
        return $meta;
    }
    if ($legacy !== '') {
        update_post_meta($post->ID, CHRONICLER_TEMPLATE_META, wp_slash($legacy));
    }
    return $legacy;
}

/**
 * Persist template source (the configurator save in sheets/admin.php). The
 * buffer is wp_slash'd because update_post_meta unslashes — source must
 * round-trip byte-for-byte or formulas corrupt. The caller also clears the
 * post's post_content so meta stays the single source of truth.
 *
 * Returns whether the stored copy reads back byte-identical. The read-back
 * is the point (#173 review): update_post_meta's raw return can't tell a
 * failed write from "this exact value is already stored" (a normal unchanged
 * re-save), so the caller must not report success — or activate the post —
 * on a false return.
 */
function chronicler_sheets_save_template_source(int $post_id, string $source): bool {
    update_post_meta($post_id, CHRONICLER_TEMPLATE_META, wp_slash($source));
    return get_post_meta($post_id, CHRONICLER_TEMPLATE_META, true) === $source;
}
