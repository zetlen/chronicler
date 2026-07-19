<?php
/**
 * Plugin Name: Chronicler
 * Description: Chronicles Slack RPG sessions — a wp-admin session editor, transcripts as Gutenberg blocks, and schema-driven character sheets.
 * Version: 4.20.0
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: chronicler
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CHRONICLER_PLUGIN_FILE', __FILE__);
// Derived from the Version: header above — the plugin's ONLY version literal
// (bump it with `npm run bump patch|minor`). Used for asset cache-busting
// and the Capabilities re-grant option.
define('CHRONICLER_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

// Composer autoloader for Chronicler\ classes. Built zips vendor it in
// (npm run build:plugin runs `composer install`); a live-mounted source tree
// (wp-env) may not have it, so fall back to a minimal PSR-4 loader matching
// composer.json's Chronicler\ => src/ mapping.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'Chronicler\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Chronicler\\'))) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        }
    });
}

require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/sheets/formulas.php';
require_once __DIR__ . '/sheets/schema.php';
require_once __DIR__ . '/sheets/names.php';
// Template-config storage (#163): meta-backed source + legacy migration,
// used by post-types.php's readers and admin.php's configurator save.
require_once __DIR__ . '/sheets/template-store.php';
require_once __DIR__ . '/sheets/caps.php';
require_once __DIR__ . '/sheets/post-types.php';
require_once __DIR__ . '/sheets/admin.php';
require_once __DIR__ . '/sheets/rest.php';
require_once __DIR__ . '/sheets/render.php';
require_once __DIR__ . '/sheets/index.php';
require_once __DIR__ . '/admin/page.php';

(new Chronicler\Rest\Routes())->register();
Chronicler\Store\Rules::register();
// The wp-admin CRUD screen for Rules (#109): metabox editor, save
// validation shared with the REST layer, list-table columns.
(new Chronicler\Rules\AdminPage())->register();
// After admin/page.php: the Settings submenu hangs off Admin\Page's menu.
(new Chronicler\Settings\Screen())->register();
// Editor-native generation (#102): the session-placeholder block, the
// chronicler/session-log pattern, deep-link seeding, and the document
// sidebar. After admin/page.php: the seeding nonce it verifies is minted
// into Admin\Page's chroniclerBoot.
(new Chronicler\Editor\Generation())->register();
// Image mirror (#103): cron eviction callback + update-safe daily schedule.
Chronicler\Media\Mirror::register();
register_deactivation_hook(__FILE__, [Chronicler\Media\Mirror::class, 'unschedule']);
// Deactivation also clears the plugin's rewrite-rule residue (#164): left
// behind, the chr_character rules (/characters, characters/%) would shadow
// future content wanting those slugs until permalinks were re-saved.
// Deleting the option beats flush_rewrite_rules() here (uninstall.php's
// reasoning): this hook runs after init, chr_character is still registered,
// and a flush regenerates IMMEDIATELY — rebuilding the very rules it's
// meant to drop. Deletion regenerates clean rules lazily on the next
// request, when the deactivated plugin no longer registers its CPT. On a
// network-wide deactivation every site carries the residue, so every site's
// option goes (#174 review).
register_deactivation_hook(__FILE__, static function ($network_wide): void {
    if ($network_wide && is_multisite()) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
            switch_to_blog((int) $site_id);
            delete_option('rewrite_rules');
            restore_current_blog();
        }
        return;
    }
    delete_option('rewrite_rules');
});

// A data-loss warning on the plugin's own Plugins-screen row (#174): the
// closest reachable spot to the Delete action. Best-effort by nature — once
// the plugin is deactivated (the state Delete requires) this code no longer
// runs, so Settings\Screen::render() and readme.txt's FAQ carry the durable
// copies of the same warning.
add_filter('plugin_row_meta', static function (array $meta, string $file): array {
    if ($file === plugin_basename(__FILE__)) {
        $meta[] = '<span style="color:#996800">Deleting this plugin removes its sessions and character sheets; published transcripts are kept.</span>';
    }
    return $meta;
}, 10, 2);

register_activation_hook(__FILE__, 'chronicler_sheets_activate');
register_activation_hook(__FILE__, [Chronicler\Capabilities::class, 'grant']);
register_activation_hook(__FILE__, [Chronicler\Store\Schema::class, 'ensure']);
// Activation hooks don't fire on plugin UPDATES; re-grant when the version
// changes so a zip upload over an existing install still gets new caps.
add_action('init', [Chronicler\Capabilities::class, 'ensure']);
// Same update-safety for the storage schema (#101): the schema-version
// option lags after a zip upload over an existing install, and ensure()
// replays the missing upgrade steps.
add_action('init', [Chronicler\Store\Schema::class, 'ensure']);
