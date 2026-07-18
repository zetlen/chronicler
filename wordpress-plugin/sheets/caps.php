<?php
// Sheets-module roles and capabilities (#162): the character-sheet cap family,
// the player and gm roles, and the update-safe grant that keeps them current.
//
// Split out of post-types.php so the grant logic is loadable in isolation — the
// dependency-free PHP test harness can't require post-types.php (it redeclares
// helpers the render/surfaces tests stub). "Approach A" from the design: the
// sheets module owns its own caps, mirroring how src/Capabilities.php owns the
// plugin-core tiers; the gm role reaches across for exactly one cap (session
// drafting) via Capabilities::COMPOSE.

if (!defined('ABSPATH')) {
    exit;
}

// Full per-author character-sheet capability set. Core maps "edit this post"
// onto these via map_meta_cap (sheets/post-types.php); administrators and the
// gm role hold the whole set, the player role only the own-sheet subset.
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

/**
 * The gm role's capabilities (#162): every character-sheet capability, session
 * drafting (chronicler_compose — the same cap that gates Chronicler → Sessions,
 * src/Admin/Page.php), and wp-admin access (read). Deliberately NO site-config
 * caps (chronicler_manage / chronicler_slack_read) and no core site-admin
 * capability: a GM runs the game, not the site.
 */
function chronicler_sheets_gm_role_caps(): array {
    $caps = [
        'read' => true,
        \Chronicler\Capabilities::COMPOSE => true,
        // Same media-modal trap as the player role (#163): a GM building
        // NPC sheets hits the identical dead library without upload_files.
        'upload_files' => true,
    ];
    foreach (CHRONICLER_CHARACTER_CAPS as $cap) {
        $caps[$cap] = true;
    }
    return $caps;
}

/**
 * Grant the character caps to administrator and (re)build the player role. Task
 * 3 adds the gm role to the $roles map. Idempotent and ADDITIVE: add_role()
 * no-ops when the slug exists, and an existing role only ever gains caps
 * (add_cap), never loses them — so a site that tuned these roles with a
 * role-editor plugin keeps its changes. Stamps the version so
 * chronicler_sheets_ensure_caps() can tell a re-grant has run for this release.
 * Mirrors Chronicler\Capabilities::grant().
 */
function chronicler_sheets_grant_caps(): void {
    $admin = get_role('administrator');
    if ($admin) {
        foreach (CHRONICLER_CHARACTER_CAPS as $cap) {
            $admin->add_cap($cap);
        }
    }

    $roles = [
        'player' => [
            'label' => 'Player',
            'caps' => [
                'read' => true,
                'edit_chr_characters' => true,
                'edit_published_chr_characters' => true,
                // Portrait + Bio "Insert Image": the character edit screen
                // enqueues the wp.media modal (sheets/admin.php), but core's
                // attachment AJAX (query_attachments, upload_attachment)
                // hard-fails without upload_files — the modal opened empty
                // and uploads were refused (#163).
                'upload_files' => true,
            ],
        ],
        'gm' => [
            'label' => 'Game Master',
            'caps' => chronicler_sheets_gm_role_caps(),
        ],
    ];
    foreach ($roles as $slug => $role) {
        $existing = get_role($slug);
        if ($existing === null) {
            add_role($slug, $role['label'], $role['caps']);
        } else {
            foreach ($role['caps'] as $cap => $grant) {
                $existing->add_cap($cap, $grant);
            }
        }
    }

    update_option('chronicler_sheets_caps_version', CHRONICLER_VERSION);
}

/**
 * Update-safe re-grant, hooked on init. Activation hooks DON'T fire on plugin
 * updates, so an existing install would never receive a role or a cap added in
 * a newer version without this. One autoloaded option read per request;
 * re-grants only when the version changes, and grant is idempotent. Mirrors
 * Chronicler\Capabilities::ensure() and chronicler_sheets_ensure_npc_term().
 */
function chronicler_sheets_ensure_caps(): void {
    if (get_option('chronicler_sheets_caps_version') !== CHRONICLER_VERSION) {
        chronicler_sheets_grant_caps();
    }
}

// --- media-library scoping for the upload_files grant (#163, #173 review) ----

/**
 * Whether the current user gets the own-uploads-only media view: their only
 * claim to the library is the plugin's own-sheet grant (a player). GMs
 * (edit_others_chr_characters) and anyone with broader core editing keep the
 * full library, and users without the plugin's caps are never touched.
 */
function chronicler_sheets_media_scoped(): bool {
    return current_user_can('edit_chr_characters')
        && !current_user_can('edit_others_chr_characters')
        && !current_user_can('edit_others_posts');
}

/**
 * Core's query_attachments AJAX — the wp.media modal's library — checks only
 * upload_files and applies NO author scoping, so the #163 grant would let a
 * player page through every attachment sitewide (other players' uploads,
 * GM-only imagery, Slack mirrors from channels gated behind
 * chronicler_slack_read). Scope players to their own uploads; uploading for
 * their sheet keeps working.
 */
function chronicler_sheets_scope_attachment_query(array $query): array {
    if (chronicler_sheets_media_scoped()) {
        $query['author'] = get_current_user_id();
    }
    return $query;
}

/**
 * The list-mode Media screen (upload.php) is a plain admin main query, not
 * the AJAX route — apply the same scoping there.
 */
function chronicler_sheets_scope_media_screen($query): void {
    if (!is_admin() || !$query->is_main_query() || ($GLOBALS['pagenow'] ?? '') !== 'upload.php') {
        return;
    }
    if (chronicler_sheets_media_scoped()) {
        $query->set('author', get_current_user_id());
    }
}

// Not during uninstall: uninstall.php requires this file for the cap/role
// name constants only. Registering the init re-grant there would be dead at
// best (init has fired) and destructive at worst — were init ever re-fired,
// the callback would fatal on the undefined CHRONICLER_VERSION (chronicler.php
// isn't loaded) or resurrect the roles uninstall just removed (#173 review).
if (!defined('WP_UNINSTALL_PLUGIN')) {
    add_action('init', 'chronicler_sheets_ensure_caps');
    add_filter('ajax_query_attachments_args', 'chronicler_sheets_scope_attachment_query');
    add_action('pre_get_posts', 'chronicler_sheets_scope_media_screen');
}
