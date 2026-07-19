<?php

namespace Chronicler\Editor;

use Chronicler\Capabilities;
use Chronicler\Store\Sessions;

/**
 * Editor-native session generation (#102): the post editor, not a REST
 * draft route, is where transcript posts are born now.
 *
 * Four cooperating pieces, all wired here:
 *
 * 1. The chronicler/session-placeholder block — a no-build apiVersion-3
 *    static block (generate/placeholder/block.json + index.js). Its edit UI
 *    picks a stored Session and generates the chronicler/transcript block
 *    tree in place (client-side, via generate/session-blocks.js — the pure
 *    mapping whose output lib/wordpress/generateBlocks.test.ts pins against
 *    lib/wordpress/blockGrammar.ts). Before creating blocks it mirrors every
 *    Slack image the stored messages reference into the media library
 *    (generate/mirror.js), parents the attachments to the post, and rewrites
 *    the capability-gated mirror-route srcs to local uploads URLs — a
 *    published post must never point readers at chronicler/v1/image. save()
 *    is null: generated output is never persisted by the placeholder itself.
 *
 * 2. The chronicler/session-log block pattern: placeholder + a layout
 *    skeleton (summary paragraph, More cut) mirroring what the Node publish
 *    flow used to assemble. Deliberately registered WITHOUT 'blockTypes'
 *    (in particular not core/post-content), so it stays a plain inserter
 *    pattern and never triggers the new-post pattern starter modal.
 *
 * 3. Deep-link seeding: post-new.php?post_type=post&chronicler_session=<id>
 *    &_wpnonce=<nonce> pre-fills the new post via the core default_content /
 *    default_title filters. The markup MUST come from these filters — core
 *    esc_html()s the ?content= request param, which would destroy block
 *    grammar. Seeding requires a valid nonce (action chronicler_draft_session
 *    — Admin\Page hands the session editor a ready-made URL template in
 *    chroniclerBoot.draftSessionUrlTemplate), the chronicler_compose
 *    capability, and an existing Session; anything else returns the incoming
 *    value untouched. Seeded content suppresses the pattern starter modal,
 *    which is exactly right for this flow.
 *
 * 4. The "Chronicler" document sidebar (generate/sidebar.js):
 *    PluginDocumentSettingPanel (wp.editor global) with the tag/featured-
 *    image actions, shown only when the post carries a generated wrapper or
 *    a placeholder.
 *
 * The static methods are pure (no WordPress calls) and unit-tested in
 * tests/generation.test.php.
 */
final class Generation
{
    public const BLOCK = 'chronicler/session-placeholder';
    public const PATTERN = 'chronicler/session-log';
    /** Nonce action for the deep-link seeding contract (see class docblock). */
    public const NONCE_ACTION = 'chronicler_draft_session';

    /** Pinned dialogueCss copy (generate/base-css.js). */
    public const BASE_CSS_HANDLE = 'chronicler-transcript-base-css';
    /** Pure session→blocks mapping (generate/session-blocks.js). */
    public const LIB_HANDLE = 'chronicler-session-blocks';
    /** Shared image mirror/parent REST plumbing (generate/mirror.js). */
    public const MIRROR_HANDLE = 'chronicler-session-mirror';
    /** Placeholder block editor script (named by block.json editorScript). */
    public const PLACEHOLDER_HANDLE = 'chronicler-session-placeholder';
    /** Document sidebar panel script. */
    public const SIDEBAR_HANDLE = 'chronicler-session-sidebar';

    public function register(): void
    {
        add_action('init', [$this, 'setup']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueueSidebar']);
        add_filter('default_content', [$this, 'defaultContent'], 10, 2);
        add_filter('default_title', [$this, 'defaultTitle'], 10, 2);
    }

    /** Scripts first (block.json names the placeholder handle), then the
     *  block type and the pattern. */
    public function setup(): void
    {
        $this->registerAssets();
        register_block_type(plugin_dir_path(CHRONICLER_PLUGIN_FILE) . 'generate/placeholder');
        register_block_pattern(self::PATTERN, self::patternDefinition());
    }

    private function registerAssets(): void
    {
        $url = static fn (string $file): string => plugins_url('generate/' . $file, CHRONICLER_PLUGIN_FILE);
        wp_register_script(self::BASE_CSS_HANDLE, $url('base-css.js'), [], CHRONICLER_VERSION, true);
        // The mapping module itself is pure; depending on the base-css asset
        // just guarantees both globals exist wherever the lib is loaded.
        wp_register_script(self::LIB_HANDLE, $url('session-blocks.js'), [self::BASE_CSS_HANDLE], CHRONICLER_VERSION, true);
        // Mirror/parent REST plumbing shared by the placeholder's Generate
        // flow and the sidebar's featured-image action.
        wp_register_script(self::MIRROR_HANDLE, $url('mirror.js'), ['wp-api-fetch'], CHRONICLER_VERSION, true);
        wp_register_script(
            self::PLACEHOLDER_HANDLE,
            $url('placeholder/index.js'),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-api-fetch', self::LIB_HANDLE, self::MIRROR_HANDLE],
            CHRONICLER_VERSION,
            true
        );
        // The no-sessions empty state links to the session editor page.
        wp_localize_script(self::PLACEHOLDER_HANDLE, 'chroniclerGenerateBoot', [
            'chroniclerAdminUrl' => admin_url('admin.php?page=' . \Chronicler\Admin\Page::SLUG),
        ]);
        wp_register_script(
            self::SIDEBAR_HANDLE,
            $url('sidebar.js'),
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', self::LIB_HANDLE, self::MIRROR_HANDLE],
            CHRONICLER_VERSION,
            true
        );
    }

    /** The sidebar registers a wp.plugins plugin, not a block: it must be
     *  enqueued explicitly — but only where generation can land (#164).
     *  Sessions generate into ordinary posts (the pattern's postTypes, the
     *  deep link's post_type gate), so other CPTs' editors and the
     *  widget/site editors skip the script; the panel UI already self-hides. */
    public function enqueueSidebar(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->post_type !== 'post') {
            return;
        }
        wp_enqueue_script(self::SIDEBAR_HANDLE);
    }

    /* ------------------------------------------------------------------ *
     * Deep-link seeding (default_content / default_title)
     * ------------------------------------------------------------------ */

    /**
     * default_content: the seeded placeholder + skeleton for an authorized
     * deep link, the incoming value otherwise. Always returns a string.
     *
     * @param mixed $content the filter's incoming default content
     * @param mixed $post    the auto-draft WP_Post
     */
    public function defaultContent($content, $post): string
    {
        $sessionId = $this->authorizedSeedSessionId($post);
        if ($sessionId === null) {
            return is_string($content) ? $content : '';
        }
        return self::seededContent($sessionId);
    }

    /**
     * default_title: "#channel — <date>" from the seeded Session; the
     * incoming value otherwise. Always returns a string.
     *
     * @param mixed $title the filter's incoming default title
     * @param mixed $post  the auto-draft WP_Post
     */
    public function defaultTitle($title, $post): string
    {
        $sessionId = $this->authorizedSeedSessionId($post);
        if ($sessionId === null) {
            return is_string($title) ? $title : '';
        }
        $session = Sessions::get($sessionId);
        return $session === null ? (is_string($title) ? $title : '') : self::titleFor($session);
    }

    /**
     * The Session id to seed, or null unless EVERY gate passes: post_type
     * `post`, a well-formed chronicler_session query arg, a valid
     * chronicler_draft_session nonce, the chronicler_compose capability,
     * and an existing Session. Deliberately NOT memoized: the checks are one
     * indexed SELECT plus constant-time guards, run twice per new-post
     * request (content + title) — cheaper than a cache that could go stale
     * if anything re-applies the filters.
     *
     * @param mixed $post the filter's post argument
     */
    private function authorizedSeedSessionId($post): ?int
    {
        if (!$post instanceof \WP_Post || $post->post_type !== 'post') {
            return null;
        }
        $sessionId = self::requestedSessionId($_GET);
        if ($sessionId === null) {
            return null;
        }
        $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))
            : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return null;
        }
        if (!current_user_can(Capabilities::COMPOSE)) {
            return null;
        }
        if (Sessions::get($sessionId) === null) {
            return null;
        }
        return $sessionId;
    }

    /* ------------------------------------------------------------------ *
     * Pure helpers (exercised by tests/generation.test.php)
     * ------------------------------------------------------------------ */

    /**
     * The chronicler_session query arg as a positive int, or null. Stricter
     * than absint: a mangled value ('5abc', '-3', arrays) never seeds. Pure.
     *
     * @param array<mixed> $get the request query args ($_GET)
     */
    public static function requestedSessionId(array $get): ?int
    {
        $raw = $get['chronicler_session'] ?? null;
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (!is_string($raw) || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    /** The placeholder block's serialized grammar, optionally pre-seeded. Pure. */
    public static function placeholderBlock(?int $sessionId = null): string
    {
        if ($sessionId === null) {
            return '<!-- wp:' . self::BLOCK . ' /-->';
        }
        return '<!-- wp:' . self::BLOCK . ' {"sessionId":' . $sessionId . '} /-->';
    }

    /**
     * The layout skeleton around a placeholder: a summary paragraph slot and
     * a More cut — the same reading order the Node publish flow assembled
     * (summary standfirst, More, transcript). Pure.
     */
    private static function skeleton(string $placeholder): string
    {
        return implode("\n\n", [
            "<!-- wp:paragraph {\"placeholder\":\"Session summary — a short standfirst shown above the fold.\"} -->\n<p></p>\n<!-- /wp:paragraph -->",
            "<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->",
            $placeholder,
        ]);
    }

    /** Pattern content: skeleton + an unseeded placeholder. Pure. */
    public static function patternContent(): string
    {
        return self::skeleton(self::placeholderBlock());
    }

    /** Seeded deep-link content: skeleton + the Session's placeholder. Pure. */
    public static function seededContent(int $sessionId): string
    {
        return self::skeleton(self::placeholderBlock($sessionId));
    }

    /**
     * register_block_pattern() args. Pure data; tests pin that postTypes is
     * exactly ['post'] and that no 'blockTypes' key exists (a
     * core/post-content blockTypes entry would put the pattern in the
     * new-post starter modal).
     */
    public static function patternDefinition(): array
    {
        return [
            'title' => 'Chronicler session log',
            'description' => 'A session-log post: a summary, a Read More break, and a transcript placeholder.',
            'categories' => ['text'],
            'postTypes' => ['post'],
            'content' => self::patternContent(),
        ];
    }

    /**
     * Seed title for a Session: "#channel — July 11, 2026". Falls back to
     * the channel id when the name is empty, to the raw start string when it
     * doesn't parse, and to "Chronicler session" when there's no channel at
     * all. Pure (strtotime/gmdate only).
     */
    public static function titleFor(array $session): string
    {
        $channel = trim((string) ($session['channel']['name'] ?? ''));
        if ($channel === '') {
            $channel = trim((string) ($session['channel']['id'] ?? ''));
        }
        $label = $channel === '' ? 'Chronicler session' : '#' . $channel;
        $start = (string) ($session['start'] ?? '');
        if ($start === '') {
            return $label;
        }
        $timestamp = strtotime($start);
        $date = $timestamp === false ? $start : gmdate('F j, Y', $timestamp);
        return $label . ' — ' . $date;
    }
}
