<?php
/**
 * Uninstall cleanup (#163): remove the data Chronicler wrote, so a site that
 * deletes the plugin is left as it was found. Plugin-review teams flag a
 * plugin that leaves its data behind.
 *
 * WordPress runs this file directly, WITHOUT loading the plugin — so the
 * definitions whose names are cleaned up are require_once'd below. They are
 * class/const files that register nothing when required here: sheets/caps.php
 * is the one file with a file-scope hook registration, and it guards that on
 * !defined('WP_UNINSTALL_PLUGIN') for exactly this reason (#173 review).
 * The harness (tests/uninstall.test.php) has them loaded already, making
 * the requires no-ops there.
 *
 * The inventory, per site:
 *  - {$prefix}chronicler_sessions table            (Store\Sessions)
 *  - options: the version stamps (Capabilities::GRANTED_OPTION, Schema::OPTION,
 *    chronicler_sheets_caps_version), chronicler_active_template,
 *    chronicler_sheets_npc_seeded, the bot token (Settings\Screen::OPTION),
 *    and the whole chronicler_setting_* family + its index and the
 *    channel-defaults map (Store\Settings)
 *  - roles: player, gm                              (sheets/caps.php)
 *  - capabilities granted to administrator          (CHRONICLER_CHARACTER_CAPS
 *    + Capabilities::ALL)
 *  - capabilities delegated to individual users     (docs/rest-api.md's
 *    wp user add-cap bot-account flow)
 *  - posts of the plugin's CPTs (chr_character, chr_template,
 *    chronicler_rule) in EVERY status incl. trash + all their meta
 *  - unused mirrored Slack attachments + files      (Media\Mirror; see below
 *    for the ones published content still uses)
 *  - the mirror eviction cron event                 (Media\Mirror::CRON_HOOK)
 *  - the Slack user-directory transient             (sheets/admin.php)
 *  - the rewrite_rules option                       (chr_character registered
 *    public rewrites; deleting the option regenerates clean rules next request)
 * And once, network-wide (usermeta is a shared table):
 *  - the chronicler_slack_user_id user meta         (every user)
 *
 * Deliberately NOT touched:
 *  - the `npc` post_tag term (sheets/index.php): tags are shared with the
 *    site's regular posts, so deleting the term could untag content that
 *    isn't ours; an orphaned tag term is inert.
 *  - attachments users uploaded themselves (character portraits, Bio images
 *    — no Mirror meta): deleting a user's Media Library assets is not ours
 *    to do; post deletion re-parents them and they remain ordinary media.
 *  - mirrored Slack images that a PUBLISHED post still uses (post_parent set
 *    by the publish flow, Media\Mirror): transcripts survive uninstall, and
 *    deleting their images would 404 every inline <img> in content the site
 *    keeps. Only the chronicler_mirror_key marker meta is scrubbed.
 *
 * Multisite: WordPress runs this file ONCE, in the deleting site's context —
 * there is no per-site invocation — so the per-site cleanup below loops
 * get_sites()/switch_to_blog() itself (#173 review).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Capabilities.php';
require_once __DIR__ . '/src/Store/Sessions.php';
require_once __DIR__ . '/src/Store/Schema.php';
require_once __DIR__ . '/src/Store/Settings.php';
require_once __DIR__ . '/src/Store/Rules.php';
require_once __DIR__ . '/src/Settings/Screen.php';
require_once __DIR__ . '/src/Media/Mirror.php';
require_once __DIR__ . '/sheets/caps.php';

/** Everything blog-scoped, so the multisite loop below can run it per site. */
function chronicler_uninstall_site(): void
{
    global $wpdb;

    // 1. The sessions table.
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . Chronicler\Store\Sessions::TABLE);

    // 2. Options. The settings family first — each written key is recorded in
    //    the index option (Store\Settings) — then the named options.
    $setting_keys = get_option(Chronicler\Store\Settings::KEYS_OPTION, []);
    if (is_array($setting_keys)) {
        foreach ($setting_keys as $setting_key) {
            if (is_string($setting_key)) {
                delete_option(Chronicler\Store\Settings::OPTION_PREFIX . $setting_key);
            }
        }
    }
    foreach (
        [
            Chronicler\Store\Settings::KEYS_OPTION,
            Chronicler\Store\Settings::CHANNEL_DEFAULTS_OPTION,
            Chronicler\Settings\Screen::OPTION,
            Chronicler\Capabilities::GRANTED_OPTION,
            Chronicler\Store\Schema::OPTION,
            'chronicler_sheets_caps_version',
            'chronicler_active_template',
            'chronicler_sheets_npc_seeded',
        ] as $option
    ) {
        delete_option($option);
    }

    // 3. Roles and granted capabilities. Administrator only ever GAINS our
    //    caps (the grants are additive), so stripping exactly those caps
    //    restores it. Users assigned the removed roles are left as WordPress
    //    leaves any user of a deleted role — conventional plugin practice.
    $all_caps = array_merge(CHRONICLER_CHARACTER_CAPS, Chronicler\Capabilities::ALL);
    $admin = get_role('administrator');
    if ($admin) {
        foreach ($all_caps as $cap) {
            $admin->remove_cap($cap);
        }
    }
    remove_role('player');
    remove_role('gm');

    //    Caps delegated to individual users too (docs/rest-api.md prescribes
    //    `wp user add-cap <bot> chronicler_compose` for bot accounts); those
    //    live in per-user wp_capabilities meta that role objects don't cover.
    //    Runs after the role strips so role-derived holders no longer match.
    foreach (get_users(['capability__in' => $all_caps, 'fields' => 'all']) as $user) {
        foreach ($all_caps as $cap) {
            $user->remove_cap($cap);
        }
    }

    // 4. The plugin's posts (characters, the template, rules) and their meta.
    //    Explicit status list: 'any' resolves to statuses NOT flagged
    //    exclude_from_search, silently skipping trash and auto-draft — the
    //    exact rows an uninstall must not leave behind (#173 review).
    foreach (
        get_posts([
            'post_type' => ['chr_character', 'chr_template', Chronicler\Store\Rules::POST_TYPE],
            'post_status' => array_keys(get_post_stati()),
            'numberposts' => -1,
            'fields' => 'ids',
        ]) as $post_id
    ) {
        wp_delete_post((int) $post_id, true);
    }

    // 5. Unused mirrored Slack attachments — force delete removes files and
    //    meta. post_parent 0 confines this to mirrors nothing published ever
    //    used (Media\Mirror parents a mirror when a publish consumes it, and
    //    step 4's deletions re-parent their children to 0) — a mirror inside
    //    a surviving transcript is that post's imagery now and stays. All
    //    statuses, so MEDIA_TRASH-trashed mirrors don't slip through.
    foreach (
        get_posts([
            'post_type' => 'attachment',
            'post_status' => array_keys(get_post_stati()),
            'post_parent' => 0,
            'meta_key' => Chronicler\Media\Mirror::META_KEY,
            'numberposts' => -1,
            'fields' => 'ids',
        ]) as $attachment_id
    ) {
        wp_delete_attachment((int) $attachment_id, true);
    }
    //    The marker meta on the kept (publish-attached) mirrors is still
    //    plugin data — scrub the key everywhere; the attachments remain.
    delete_post_meta_by_key(Chronicler\Media\Mirror::META_KEY);

    // 6. The mirror eviction cron (deactivation unschedules it too, but an
    //    uninstall can arrive with the event still pending).
    wp_clear_scheduled_hook(Chronicler\Media\Mirror::CRON_HOOK);

    // 7. The cached Slack user directory.
    delete_transient('chronicler_slack_user_directory');

    // 8. The CPT rewrite rules (chr_character registers a public 'characters'
    //    slug + archive). Deleting the option beats flush_rewrite_rules()
    //    here: the CPTs are unregistered in this request, so a flush would
    //    bake their ABSENCE from a half-true state; deletion regenerates
    //    clean rules lazily on the next ordinary request.
    delete_option('rewrite_rules');
}

// function_exists: always true on a live uninstall (WordPress is loaded);
// only the dependency-free test harness lacks it and takes the single-site
// path. get_sites' default LIMIT is 100 — number 0 means every site.
if (function_exists('is_multisite') && is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $chronicler_site_id) {
        switch_to_blog((int) $chronicler_site_id);
        chronicler_uninstall_site();
        restore_current_blog();
    }
} else {
    chronicler_uninstall_site();
}

// The Slack-id mapping on every user account — usermeta is one network-wide
// table, so once covers all sites.
delete_metadata('user', 0, 'chronicler_slack_user_id', '', true);
