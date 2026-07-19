<?php
// The /characters index (#66): plugin-rendered so PCs and NPCs read as two
// groups on any theme, plus the tag-archive integration. Delivery follows
// the single-sheet pattern in render.php — a registered block template on
// block themes, a template_include fallback on classic themes — so both
// paths share one renderer.
//
// The PC/NPC split is the chr_npc flag (#176 — the NPC checkbox on the
// character editor; chronicler_sheets_is_npc lives in post-types.php with
// the other meta helpers): flagged characters group under "NPCs",
// everything else is a player character. Unflagged installs render the
// flat grid they had before, so the feature is opt-in per site. Order
// within each group is menu_order (the "Order" field), then title.
//
// Through 4.17 the split was the `npc` post tag (#66). The tag carries no
// meaning anymore — recheck existing NPCs by hand — but it remains ordinary
// user content (it still surfaces characters in tag archives).

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pure: split ordered ids into [pcs, npcs], preserving order within each
 * group. $is_npc is injectable for the harness.
 */
function chronicler_sheets_partition_characters(array $ids, callable $is_npc): array {
    $pcs = [];
    $npcs = [];
    foreach ($ids as $id) {
        if ($is_npc($id)) {
            $npcs[] = $id;
        } else {
            $pcs[] = $id;
        }
    }
    return [$pcs, $npcs];
}

/**
 * The index body. Group headings appear only when BOTH kinds exist — a
 * one-kind campaign (or a site that never tags) keeps the plain grid.
 */
function chronicler_sheets_render_index(): string {
    $ids = get_posts([
        'post_type' => 'chr_character',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        'fields' => 'ids',
    ]);
    // fields=>'ids' skips core's cache priming; one warm-up query here beats
    // a chr_npc meta read per character in the partition below.
    if ($ids !== []) {
        update_meta_cache('post', $ids);
    }
    [$pcs, $npcs] = chronicler_sheets_partition_characters($ids, 'chronicler_sheets_is_npc');

    $html = '<div class="chr-index">';
    if ($ids === []) {
        $html .= '<p class="chr-index__empty">No characters yet.</p>';
    } elseif ($pcs !== [] && $npcs !== []) {
        $html .= chronicler_sheets_render_index_group('Player Characters', 'pcs', $pcs);
        $html .= chronicler_sheets_render_index_group('NPCs', 'npcs', $npcs);
    } else {
        $html .= chronicler_sheets_render_index_grid($ids);
    }
    return $html . '</div>';
}

function chronicler_sheets_render_index_group(string $title, string $slug, array $ids): string {
    return '<section class="chr-index__group chr-index__group--' . esc_attr($slug) . '">'
        . '<h2 class="chr-index__heading">' . esc_html($title) . '</h2>'
        . chronicler_sheets_render_index_grid($ids)
        . '</section>';
}

function chronicler_sheets_render_index_grid(array $ids): string {
    $html = '<ul class="chr-index__grid">';
    foreach ($ids as $id) {
        $html .= chronicler_sheets_render_index_card((int) $id);
    }
    return $html . '</ul>';
}

/** One card: portrait (or an initial), name, tagline — the whole card links. */
function chronicler_sheets_render_index_card(int $post_id): string {
    $name = get_the_title($post_id);
    $thumb_id = get_post_thumbnail_id($post_id);
    // Raw excerpt for the same reason as the masthead: an empty tagline must
    // stay empty rather than auto-generating from the intro content.
    $tagline = trim((string) get_post_field('post_excerpt', $post_id));

    $html = '<li class="chr-index__card" data-character="' . (int) $post_id . '">';
    $html .= '<a class="chr-index__link" href="' . esc_url(get_permalink($post_id)) . '">';
    if ($thumb_id) {
        $html .= '<span class="chr-index__portrait">' . wp_get_attachment_image($thumb_id, 'medium') . '</span>';
    } else {
        $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
        $html .= '<span class="chr-index__portrait chr-index__portrait--empty" aria-hidden="true">'
            . esc_html($initial) . '</span>';
    }
    $html .= '<span class="chr-index__name">' . esc_html($name) . '</span>';
    if ($tagline !== '') {
        $html .= '<span class="chr-index__tagline">' . esc_html($tagline) . '</span>';
    }
    return $html . '</a></li>';
}

/* ------------------------------------------------------------------ *
 * Delivery: block template (block themes) / template_include (classic)
 * ------------------------------------------------------------------ */

/**
 * A server-rendered block carrying the index, so the block-theme archive
 * template below can host it. Not in the inserter — it exists for the
 * template (and for anyone hand-writing its comment into a page).
 */
function chronicler_sheets_register_index_block(): void {
    register_block_type('chronicler/character-index', [
        'api_version' => 3,
        'render_callback' => static fn () => chronicler_sheets_render_index(),
        'supports' => ['html' => false, 'inserter' => false],
    ]);
}
add_action('init', 'chronicler_sheets_register_index_block');

/**
 * Block themes: the archive template, same shape as the single-sheet
 * template in render.php (and it loses to a theme's own archive template
 * the same way).
 */
function chronicler_sheets_register_archive_template(): void {
    if (!function_exists('register_block_template') || !wp_is_block_theme()) {
        return;
    }
    register_block_template('chronicler//archive-chr_character', [
        'title' => 'Character Index',
        'description' => 'The character index: player characters, then NPCs.',
        'content' => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->'
            . '<!-- wp:query-title {"type":"archive","align":"wide"} /-->'
            . '<!-- wp:chronicler/character-index /-->'
            . '<!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
    ]);
}
add_action('init', 'chronicler_sheets_register_archive_template');

/**
 * Classic themes (e.g. Kadence): take over the archive with the plugin's
 * template — unless the theme ships its own archive-chr_character.php,
 * which wins.
 */
function chronicler_sheets_archive_template(string $template): string {
    if (!is_post_type_archive('chr_character') || wp_is_block_theme()) {
        return $template;
    }
    if (basename($template) === 'archive-chr_character.php') {
        return $template;
    }
    return plugin_dir_path(CHRONICLER_PLUGIN_FILE) . 'sheets/archive-template.php';
}
add_filter('template_include', 'chronicler_sheets_archive_template');

/* ------------------------------------------------------------------ *
 * Tag archives: characters ride along, pinned to the top
 * ------------------------------------------------------------------ */

/** Tag archives include character pages alongside posts. */
function chronicler_sheets_tag_archive_types(WP_Query $query): void {
    if (is_admin() || !$query->is_main_query() || !$query->is_tag()) {
        return;
    }
    $types = (array) ($query->get('post_type') ?: ['post']);
    if (!in_array('chr_character', $types, true)) {
        $types[] = 'chr_character';
    }
    $query->set('post_type', $types);
}
add_action('pre_get_posts', 'chronicler_sheets_tag_archive_types');

/** Pure: the ORDER BY prefix that floats characters above the posts. */
function chronicler_sheets_character_first_orderby(string $orderby, string $posts_table): string {
    $first = "({$posts_table}.post_type = 'chr_character') DESC";
    return $orderby === '' ? $first : $first . ', ' . $orderby;
}

function chronicler_sheets_tag_archive_orderby(string $orderby, WP_Query $query): string {
    if (is_admin() || !$query->is_main_query() || !$query->is_tag()) {
        return $orderby;
    }
    global $wpdb;
    return chronicler_sheets_character_first_orderby($orderby, $wpdb->posts);
}
add_filter('posts_orderby', 'chronicler_sheets_tag_archive_orderby', 10, 2);
