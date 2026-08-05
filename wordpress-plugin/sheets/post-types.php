<?php
// Character/template post types and resolution helpers. Roles and caps
// (player, gm, the character-cap set) live in sheets/caps.php (#162).

if (!defined('ABSPATH')) {
    exit;
}

function chronicler_sheets_register_types(): void {
    register_post_type('chr_character', [
        // A full label set — anything omitted falls back to core's "Post"
        // strings ("Add Post", "Edit Post") in the admin UI.
        'labels' => [
            'name' => 'Characters',
            'singular_name' => 'Character',
            'add_new' => 'Add Character',
            'add_new_item' => 'Add Character',
            'edit_item' => 'Edit Character',
            'new_item' => 'New Character',
            'view_item' => 'View Character',
            'view_items' => 'View Characters',
            'search_items' => 'Search Characters',
            'not_found' => 'No characters found.',
            'not_found_in_trash' => 'No characters found in Trash.',
            'all_items' => 'All Characters',
            'menu_name' => 'Characters',
            'item_published' => 'Character published.',
            'item_updated' => 'Character updated.',
        ],
        'public' => true,
        'has_archive' => true,
        'rewrite' => ['slug' => 'characters'],
        'menu_icon' => 'dashicons-id',
        // post_tag powers both #66 features: the `npc` tag groups the index,
        // and tagged characters surface in tag archives. page-attributes is
        // the "Order" field — menu_order drives in-group index order.
        'taxonomies' => ['post_tag'],
        'supports' => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'page-attributes'],
        // Core maps "edit this post" to edit_chr_characters for the author and
        // edit_others_chr_characters for everyone else — own-sheet-only editing
        // with no custom ACL.
        'capability_type' => ['chr_character', 'chr_characters'],
        'map_meta_cap' => true,
        'capabilities' => ['create_posts' => 'create_chr_characters'],
        'show_in_rest' => false,
    ]);

    // Templates are managed exclusively by the configurator page.
    register_post_type('chr_template', [
        'public' => false,
        'show_ui' => false,
        'supports' => ['title', 'editor'],
    ]);

    chronicler_sheets_register_property_meta();
}
add_action('init', 'chronicler_sheets_register_types');

/** Declare chr_prop_<id> meta (types only; all access goes through our endpoints). */
function chronicler_sheets_register_property_meta(): void {
    $template = chronicler_sheets_active_template();
    if ($template === null) {
        return;
    }
    $wpTypes = [
        'number' => 'integer', 'track' => 'integer', 'counter' => 'integer',
        'toggle' => 'boolean', 'checklist' => 'array', 'list' => 'array',
        'select' => 'string', 'text' => 'string', 'longtext' => 'string',
        'dice' => 'string',
    ];
    foreach ($template['properties'] as $id => $property) {
        // Opinions (#183) never store under the property's own key — each
        // set lives in a per-PC row (chronicler_sheets_opinion_meta_key),
        // and those keys are dynamic (one per player character), so there
        // is nothing meaningful to declare here.
        if ($property['type'] === 'opinions') {
            continue;
        }
        register_post_meta('chr_character', 'chr_prop_' . $id, [
            'single' => true,
            'type' => $wpTypes[$property['type']],
            'show_in_rest' => false,
            'auth_callback' => '__return_false',
        ]);
        if (isset($property['detail'])) {
            register_post_meta('chr_character', 'chr_detail_' . $id, [
                'single' => true,
                'type' => 'string',
                'show_in_rest' => false,
                'auth_callback' => '__return_false',
            ]);
        }
    }
}

/**
 * Effective annotation for what a property does: the character's override
 * (playbooks amend what ratings roll), else the template's system default.
 */
function chronicler_sheets_get_detail(int $post_id, array $property): string {
    $override = (string) get_post_meta($post_id, 'chr_detail_' . $property['id'], true);
    return $override !== '' ? $override : (string) ($property['detail'] ?? '');
}

/**
 * Whether a character is a non-player character (#176). A real flag — the
 * chr_npc meta, set by the NPC checkbox in the character editor — not the
 * old `npc` post tag: rendering conditions on NPC status (stats withheld
 * from non-editors, no "Played by", no Active box), and a free-form tag is
 * too easy to typo or delete for gates like that. The tag carries no
 * meaning anymore; it remains ordinary user content (tag archives).
 */
function chronicler_sheets_is_npc(int $post_id): bool {
    return get_post_meta($post_id, 'chr_npc', true) === '1';
}

/**
 * Every published player character's id, in the index's order (menu_order,
 * then title) — the PCs an opinions property (#183) renders one set for.
 * Request-memoized: the sheet render, REST serializer, and Stat Block box
 * may each ask during one request.
 */
function chronicler_sheets_player_characters(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $ids = get_posts([
        'post_type' => 'chr_character',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        'fields' => 'ids',
    ]);
    if ($ids !== []) {
        // fields=>'ids' skips cache priming; warm once so the chr_npc reads
        // below (and the per-PC opinion reads that follow) don't each query.
        update_meta_cache('post', $ids);
    }
    return $cached = array_values(array_filter(array_map('intval', $ids), function ($id) {
        return !chronicler_sheets_is_npc($id);
    }));
}

/**
 * Meta key for one PC's opinion set on this character (#183). The double
 * separator keeps property ids (which may themselves contain underscores)
 * from colliding with the pc suffix.
 */
function chronicler_sheets_opinion_meta_key(string $prop_id, int $pc_id): string {
    return 'chr_prop_' . $prop_id . '__pc_' . $pc_id;
}

/** One PC's stored opinion set, normalized ({rating, notes}); defaults when unset. */
function chronicler_sheets_get_opinion(int $post_id, array $property, int $pc_id): array {
    $raw = get_post_meta($post_id, chronicler_sheets_opinion_meta_key($property['id'], $pc_id), true);
    return chronicler_sheets_normalize_opinion($property, $raw);
}

/**
 * Store one PC's opinion set whole. One meta row per set, so two players
 * writing opinions on the same NPC mid-session never clobber each other.
 */
function chronicler_sheets_set_opinion(int $post_id, array $property, int $pc_id, array $set): void {
    update_post_meta(
        $post_id,
        chronicler_sheets_opinion_meta_key($property['id'], $pc_id),
        chronicler_sheets_normalize_opinion($property, $set)
    );
}

function chronicler_sheets_activate(): void {
    chronicler_sheets_register_types();
    // Roles + caps (player/gm + the character caps) — the same update-safe
    // grant that runs on init, called directly here so a fresh activation
    // seeds them immediately. Defined in sheets/caps.php (#162).
    chronicler_sheets_grant_caps();
    flush_rewrite_rules();
}

/**
 * The parsed active template, request-cached. Null when unset or invalid.
 * The memo is primed at init (chronicler_sheets_register_property_meta), so a
 * caller that changed the active template mid-request — the configurator save
 * — passes $reset to see its own write (#173 review).
 */
function chronicler_sheets_active_template(bool $reset = false): ?array {
    static $cached = false;
    static $template = null;
    if ($reset) {
        $cached = false;
    }
    if ($cached) {
        return $template;
    }
    $cached = true;
    $post_id = (int) get_option('chronicler_active_template', 0);
    $post = $post_id ? get_post($post_id) : null;
    if (!$post || $post->post_type !== 'chr_template') {
        return $template = null;
    }
    // Meta-backed source with legacy post_content migration (#163, see
    // sheets/template-store.php).
    $parsed = chronicler_sheets_parse_template(chronicler_sheets_template_source($post), true);
    if (is_wp_error($parsed)) {
        // Every sheet degrades to "no template configured" below; without
        // this line the reason would vanish with it.
        error_log("Chronicler: active template $post_id failed to parse: " . $parsed->get_error_message());
    }
    return $template = is_wp_error($parsed) ? null : $parsed;
}

function chronicler_sheets_template_for_character(int $post_id): ?array {
    $template_id = (int) get_post_meta($post_id, 'chr_template_id', true);
    if ($template_id) {
        $post = get_post($template_id);
        if ($post && $post->post_type === 'chr_template') {
            $parsed = chronicler_sheets_parse_template(chronicler_sheets_template_source($post), true);
            if (!is_wp_error($parsed)) {
                return $parsed;
            }
            // Falling back to the active template reinterprets the character's
            // stored values under a different template's property definitions
            // — including its audience flags — so say why, loudly.
            error_log("Chronicler: template $template_id for character $post_id failed to parse (" . $parsed->get_error_message() . '); falling back to the active template.');
        }
    }
    return chronicler_sheets_active_template();
}

/**
 * Request-cached derived values for a character (compute-on-read, #88):
 * base values feed the formula engine once; every read of a derived
 * property answers from this. set_value() resets the entry so a write
 * mid-request recomputes.
 */
function chronicler_sheets_derived_values(int $post_id, bool $reset = false): array {
    static $cache = [];
    if ($reset) {
        unset($cache[$post_id]);
        return [];
    }
    if (isset($cache[$post_id])) {
        return $cache[$post_id];
    }
    $template = chronicler_sheets_template_for_character($post_id);
    if ($template === null) {
        return $cache[$post_id] = [];
    }
    $base = [];
    foreach ($template['properties'] as $id => $property) {
        if (!isset($property['derived'])) {
            $base[$id] = chronicler_sheets_get_value($post_id, $property);
        }
    }
    return $cache[$post_id] = chronicler_sheets_compute_derived($template, $base);
}

/** Stored value normalized to the property's type; default when unset. */
function chronicler_sheets_get_value(int $post_id, array $property) {
    // Derived properties never touch storage — the value is a function of
    // the others, computed fresh (per request) on every read (#88).
    if (isset($property['derived'])) {
        $derived = chronicler_sheets_derived_values($post_id);
        return $derived[$property['id']] ?? chronicler_sheets_default_value($property);
    }
    // Opinions (#183) assemble from their per-PC rows: the full, UNFILTERED
    // pc id => set map. Audience filtering (the table gate, `private`) is the
    // render/REST surfaces' job, exactly like the other audience flags.
    if ($property['type'] === 'opinions') {
        $sets = [];
        foreach (chronicler_sheets_player_characters() as $pc_id) {
            $sets[$pc_id] = chronicler_sheets_get_opinion($post_id, $property, $pc_id);
        }
        return $sets;
    }
    $raw = get_post_meta($post_id, 'chr_prop_' . $property['id'], true);
    if ($raw === '' || $raw === false || $raw === null) {
        return chronicler_sheets_default_value($property);
    }
    switch ($property['type']) {
        case 'number':
        case 'track':
        case 'counter':
            return (int) $raw;
        case 'toggle':
            return (bool) $raw;
        case 'checklist':
        case 'list':
            return is_array($raw) ? array_values($raw) : [];
        default:
            return (string) $raw;
    }
}

function chronicler_sheets_set_value(int $post_id, array $property, $value): void {
    // Toggles store '1'/'0' so get_post_meta's '' -"unset"- sentinel stays
    // distinguishable from a stored false.
    if ($property['type'] === 'toggle') {
        $value = $value ? '1' : '0';
    }
    update_post_meta($post_id, 'chr_prop_' . $property['id'], $value);
    // A base value changed; derived values recompute on the next read (#88).
    chronicler_sheets_derived_values($post_id, true);
}

/**
 * The character a Slack user acts on: the character whose own sheet claims
 * that Slack id, else — for links made on the WP profile — the author's
 * active character per the sheets/active.php authority (#17). Null when the
 * Slack id is unmapped, the player is sheetless, or the GM opted them out.
 */
function chronicler_sheets_character_for_slack_id(string $slack_id): ?WP_Post {
    if ($slack_id === '') {
        return null;
    }
    // A character linked on its own sheet wins: it is the self-service path
    // (sheets/admin.php's Slack member box) and it names a character
    // directly, with no active-character guesswork. The user-meta chain
    // below stays as the fallback — it still serves installs linked before
    // the box existed, and staff/GMs who have no character of their own.
    $linked = get_posts([
        'post_type' => 'chr_character',
        'post_status' => 'publish',
        'meta_key' => 'chronicler_slack_user_id',
        'meta_value' => $slack_id,
        'numberposts' => 1,
    ]);
    if ($linked) {
        return $linked[0];
    }
    $users = get_users([
        'meta_key' => 'chronicler_slack_user_id',
        'meta_value' => $slack_id,
        'number' => 1,
        'fields' => 'ID',
    ]);
    if (!$users) {
        return null;
    }
    $active = chronicler_sheets_active_character_for((int) $users[0]);
    return $active === null ? null : (get_post($active) ?: null);
}

/**
 * On save: bind new characters to the active template, and keep chr_active
 * unique per author (setting one active clears their others).
 */
function chronicler_sheets_on_save_character(int $post_id, WP_Post $post): void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!get_post_meta($post_id, 'chr_template_id', true)) {
        $active = (int) get_option('chronicler_active_template', 0);
        if ($active) {
            update_post_meta($post_id, 'chr_template_id', $active);
        }
    }
    if (get_post_meta($post_id, 'chr_active', true) === '1') {
        $siblings = get_posts([
            'post_type' => 'chr_character',
            'post_status' => 'any',
            'author' => (int) $post->post_author,
            'exclude' => [$post_id],
            'meta_key' => 'chr_active',
            'meta_value' => '1',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);
        foreach ($siblings as $sibling) {
            delete_post_meta($sibling, 'chr_active');
        }
    }
}
add_action('save_post_chr_character', 'chronicler_sheets_on_save_character', 10, 2);
