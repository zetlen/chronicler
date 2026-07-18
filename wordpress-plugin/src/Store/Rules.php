<?php

namespace Chronicler\Store;

/**
 * Rule storage (#101): a non-public custom post type, `chronicler_rule`.
 *
 * A Rule is today's regex-rule config (RegexRule in lib/transform/rules.ts,
 * minus the per-channel `enabled` flag — LibraryRule) plus a `description`.
 * Rules are small and get a native wp-admin CRUD screen (#109 — the list
 * table, trash, and capabilities a CPT buys; Rules\AdminPage supplies the
 * metabox editor, save validation, and list columns) — the sheets subsystem
 * (sheets/post-types.php) is the precedent. Only PUBLISHED rules are served:
 * all()/get() filter by status, so drafts and trashed rules drop out of the
 * session editor's rule menu without extra bookkeeping.
 *
 * The config itself lives in ONE meta row as JSON (`chronicler_rule`), not
 * in post_content: regex patterns routinely contain `<@U123>`-style angle
 * brackets that kses would chew on save for non-unfiltered_html users.
 * post_title is a derived, display-only label regenerated on every write.
 * `chronicler_library_id` meta carries the Node app's rule id (a UUID) so
 * import (#101) is idempotent — re-import updates by library id instead of
 * duplicating.
 */
final class Rules
{
    public const POST_TYPE = 'chronicler_rule';
    public const META_RULE = 'chronicler_rule';
    public const META_LIBRARY_ID = 'chronicler_library_id';

    /** Stored/served config fields and their defaults. Pure data. */
    public const DEFAULTS = [
        'pattern' => '',
        'flags' => 'i',
        'mode' => 'hide',
        'className' => '',
        'tagNames' => '',
        'description' => '',
    ];

    public static function register(): void
    {
        add_action('init', [self::class, 'registerPostType']);
    }

    public static function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            // A full label set — anything omitted falls back to core's
            // "Post" strings in the admin UI (see sheets/post-types.php).
            // "Transcription Rules" (#134): these exist to shape session
            // transcripts — the name says so everywhere an author sees it.
            // (The chronicler/v1 /rules REST routes keep their name: that's
            // the API contract, not author-facing copy.)
            'labels' => [
                'name' => 'Transcription Rules',
                'singular_name' => 'Transcription Rule',
                'add_new' => 'Add Transcription Rule',
                'add_new_item' => 'Add Transcription Rule',
                'edit_item' => 'Edit Transcription Rule',
                'new_item' => 'New Transcription Rule',
                'search_items' => 'Search Transcription Rules',
                'not_found' => 'No transcription rules found.',
                'not_found_in_trash' => 'No transcription rules found in Trash.',
                'all_items' => 'Transcription Rules',
                'menu_name' => 'Transcription Rules',
                'item_published' => 'Transcription rule published. It is now available in the session editor.',
                'item_updated' => 'Transcription rule updated.',
                'item_reverted_to_draft' => 'Transcription rule reverted to draft. Drafts do not appear in the session editor.',
            ],
            'public' => false,
            'show_ui' => true, // The #109 admin screen.
            // Submenu of the Chronicler top-level menu (Admin\Page). Core's
            // _add_post_type_submenus() adds the entry on admin_menu.
            'show_in_menu' => \Chronicler\Admin\Page::SLUG,
            'show_in_admin_bar' => false,
            // No title (derived — Rules\AdminPage regenerates it on save),
            // no content editor: the Rule metabox is the whole edit form.
            // `false` is deliberate; an empty array would re-add core's
            // title+editor defaults (see register_post_type()).
            'supports' => false,
            // Every rule action maps onto the manage capability the REST
            // rule-write routes require (see adminCapabilities()).
            'capability_type' => [self::POST_TYPE, self::POST_TYPE . 's'],
            'map_meta_cap' => false,
            'capabilities' => self::adminCapabilities(),
        ]);
    }

    /**
     * Every CPT capability collapses to Capabilities::MANAGE, the tier the
     * chronicler/v1 rule WRITE routes require (#159) — Rules are shared site
     * configuration, so editing them is a manage action, not a compose one
     * (compose holders still LIST rules over REST to attach them). Sheets'
     * per-post cap family (CHRONICLER_CHARACTER_CAPS) exists for
     * own-vs-others sheet editing; Rules have no ownership, so one flat
     * capability is the consistent mapping. The unlisted keys fall back to
     * `{edit_…}_chronicler_rule(s)` caps (capability_type above) that are
     * granted to no one — never to plain post caps. Pure.
     */
    public static function adminCapabilities(): array
    {
        return array_fill_keys([
            // Meta capabilities (map_meta_cap false returns these verbatim).
            'edit_post', 'read_post', 'delete_post',
            // Primitive capabilities the admin screens check directly.
            'edit_posts', 'edit_others_posts', 'publish_posts',
            'read_private_posts', 'create_posts', 'delete_posts',
        ], \Chronicler\Capabilities::MANAGE);
    }

    /** Every Rule, oldest first (stable ids make stable ordering). */
    public static function all(): array
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $out = [];
        foreach ($posts as $post) {
            $rule = self::fromPost($post->ID);
            if ($rule !== null) {
                $out[] = $rule;
            }
        }
        return $out;
    }

    public static function get(int $id): ?array
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'publish') {
            return null;
        }
        return self::fromPost($id);
    }

    /** Create a Rule; $libraryId records the Node app's rule id for import. */
    public static function create(array $data, ?string $libraryId = null): ?array
    {
        $config = self::normalize($data);
        $id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => self::adminTitle($config),
        ], true);
        if (is_wp_error($id)) {
            return null;
        }
        update_post_meta($id, self::META_RULE, wp_slash(wp_json_encode($config)));
        if ($libraryId !== null && $libraryId !== '') {
            update_post_meta($id, self::META_LIBRARY_ID, $libraryId);
        }
        return self::get((int) $id);
    }

    /** Merge a partial update over the stored config (absent keys keep). */
    public static function update(int $id, array $patch): ?array
    {
        $existing = self::get($id);
        if ($existing === null) {
            return null;
        }
        $config = self::normalize($patch, self::configOf($existing));
        update_post_meta($id, self::META_RULE, wp_slash(wp_json_encode($config)));
        wp_update_post([
            'ID' => $id,
            'post_title' => self::adminTitle($config),
        ]);
        return self::get($id);
    }

    /**
     * Hard delete — the session editor's REST DELETE is immediate. The #109
     * admin list table keeps WordPress's native trash flow instead (trashed
     * rules leave publish status, so they drop out of all()/get()).
     */
    public static function delete(int $id): bool
    {
        if (self::get($id) === null) {
            return false;
        }
        return (bool) wp_delete_post($id, true);
    }

    /** WP post id for a Node-app library rule id, or null. */
    public static function findByLibraryId(string $libraryId): ?int
    {
        if ($libraryId === '') {
            return null;
        }
        $found = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'meta_key' => self::META_LIBRARY_ID,
            'meta_value' => $libraryId,
            'numberposts' => 1,
            'fields' => 'ids',
        ]);
        return $found ? (int) $found[0] : null;
    }

    private static function fromPost(int $id): ?array
    {
        $json = get_post_meta($id, self::META_RULE, true);
        $config = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($config)) {
            // A rule post without readable config is corrupt; hide it.
            return null;
        }
        $libraryId = get_post_meta($id, self::META_LIBRARY_ID, true);
        return ['id' => $id]
            + self::normalize($config)
            + ['libraryId' => is_string($libraryId) && $libraryId !== '' ? $libraryId : null];
    }

    /* ------------------------------------------------------------------ *
     * Pure helpers (exercised by tests/store.test.php)
     * ------------------------------------------------------------------ */

    /**
     * Coerce untrusted input to the config shape, merging over $base (the
     * DEFAULTS for creates, the stored config for updates). Pure. Unknown
     * fields — including the Node app's per-channel `enabled` and its `id` —
     * are dropped; an out-of-vocabulary mode falls back to $base's.
     */
    public static function normalize(array $input, array $base = self::DEFAULTS): array
    {
        $base = array_merge(self::DEFAULTS, array_intersect_key($base, self::DEFAULTS));
        $out = $base;
        foreach (['pattern', 'flags', 'className', 'tagNames', 'description'] as $key) {
            if (array_key_exists($key, $input) && is_string($input[$key])) {
                $out[$key] = $input[$key];
            }
        }
        if (in_array($input['mode'] ?? null, \Chronicler\Rest\Schemas::RULE_MODES, true)) {
            $out['mode'] = $input['mode'];
        }
        return $out;
    }

    /**
     * Display-only wp-admin label, regenerated on every write: the
     * description when present, else "mode: pattern". Pure.
     */
    public static function adminTitle(array $config): string
    {
        $description = trim((string) ($config['description'] ?? ''));
        if ($description !== '') {
            return mb_substr($description, 0, 80);
        }
        return mb_substr(($config['mode'] ?? 'hide') . ': ' . ($config['pattern'] ?? ''), 0, 80);
    }

    /** The config fields of a served Rule (drops id/libraryId). Pure. */
    public static function configOf(array $rule): array
    {
        return array_intersect_key($rule, self::DEFAULTS);
    }
}
