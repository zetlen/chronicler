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
add_action('init', 'chronicler_sheets_ensure_caps');
