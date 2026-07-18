<?php
// Character/template post types, the player role, and resolution helpers.

if (!defined('ABSPATH')) {
    exit;
}

const CHRONICLER_CHARACTER_CAPS = [
    'edit_chr_characters',
    'edit_others_chr_characters',
    'edit_published_chr_characters',
    'edit_private_chr_characters',
    'publish_chr_characters',
    'read_private_chr_characters',
    'delete_chr_characters',
    'delete_others_chr_characters',
    'delete_published_chr_characters',
    'delete_private_chr_characters',
    'create_chr_characters',
];

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
    ];
    foreach ($template['properties'] as $id => $property) {
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

function chronicler_sheets_activate(): void {
    chronicler_sheets_register_types();
    add_role('player', 'Player', [
        'read' => true,
        'edit_chr_characters' => true,
        'edit_published_chr_characters' => true,
    ]);
    $admin = get_role('administrator');
    if ($admin) {
        foreach (CHRONICLER_CHARACTER_CAPS as $cap) {
            $admin->add_cap($cap);
        }
    }
    // The npc grouping tag is seeded by chronicler_sheets_ensure_npc_term()
    // (sheets/index.php) on init — update-safe, unlike this activation hook.
    flush_rewrite_rules();
}

/** The parsed active template, request-cached. Null when unset or invalid. */
function chronicler_sheets_active_template(): ?array {
    static $cached = false;
    static $template = null;
    if ($cached) {
        return $template;
    }
    $cached = true;
    $post_id = (int) get_option('chronicler_active_template', 0);
    $post = $post_id ? get_post($post_id) : null;
    if (!$post || $post->post_type !== 'chr_template') {
        return $template = null;
    }
    $parsed = chronicler_sheets_parse_template($post->post_content, true);
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
            $parsed = chronicler_sheets_parse_template($post->post_content, true);
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
 * The character a Slack user acts on: their chr_active character, else their
 * most recent published one. Null when the Slack id is unmapped or sheetless.
 */
function chronicler_sheets_character_for_slack_id(string $slack_id): ?WP_Post {
    if ($slack_id === '') {
        return null;
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
    $base = [
        'post_type' => 'chr_character',
        'post_status' => 'publish',
        'author' => (int) $users[0],
        'numberposts' => 1,
    ];
    $active = get_posts($base + ['meta_key' => 'chr_active', 'meta_value' => '1']);
    if ($active) {
        return $active[0];
    }
    $any = get_posts($base);
    return $any ? $any[0] : null;
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
