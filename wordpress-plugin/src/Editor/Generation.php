<?php

namespace Chronicler\Editor;

use Chronicler\Capabilities;
use Chronicler\Rest\Routes;
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
 * 2. The draft-template block patterns (#12): each templates() entry is a
 *    placeholder + a layout arrangement of plain core blocks, registered as
 *    chronicler/<slug> under the "Chronicler" pattern category. session-log
 *    mirrors what the Node publish flow used to assemble (summary paragraph,
 *    More cut); adventure-log adds recap/loot/next-time sections around the
 *    transcript. All are deliberately registered WITHOUT 'blockTypes' (in
 *    particular not core/post-content), so they stay plain inserter patterns
 *    and never trigger the new-post pattern starter modal.
 *
 * 3. Deep-link seeding: post-new.php?post_type=post&chronicler_session=<id>
 *    &_wpnonce=<nonce> pre-fills the new post via the core default_content /
 *    default_title filters. The markup MUST come from these filters — core
 *    esc_html()s the ?content= request param, which would destroy block
 *    grammar. Seeding requires a valid nonce (action chronicler_draft_session
 *    — Admin\Page hands the session editor a ready-made URL template in
 *    chroniclerBoot.draftSessionUrlTemplate), the chronicler_compose
 *    capability, and an existing Session; anything else returns the incoming
 *    value untouched. An optional &chronicler_template=<slug> picks which
 *    templates() entry seeds (the session editor's picker sets it); anything
 *    unrecognized falls back to DEFAULT_TEMPLATE. Seeded content suppresses
 *    the pattern starter modal, which is exactly right for this flow.
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
    /** Pattern category all draft templates register under (#12). */
    public const CATEGORY = 'chronicler';
    /** templates() slug seeded when the deep link names no template. */
    public const DEFAULT_TEMPLATE = 'session-log';
    /** Nonce action for the deep-link seeding contract (see class docblock). */
    public const NONCE_ACTION = 'chronicler_draft_session';

    /** Pinned dialogueCss copy (generate/base-css.js). */
    public const BASE_CSS_HANDLE = 'chronicler-transcript-base-css';
    /** Pure session→blocks mapping (generate/session-blocks.js). */
    public const LIB_HANDLE = 'chronicler-session-blocks';
    /** Bundled transcription engine (components/generate/engine.ts): computes
     *  a session's message attributes from its stored raw + rules at generate
     *  time, exposed as window.chroniclerSessionEngine (#3). */
    public const ENGINE_HANDLE = 'chronicler-session-engine';
    /** Shared image mirror/parent REST plumbing (generate/mirror.js). */
    public const MIRROR_HANDLE = 'chronicler-session-mirror';
    /** Shared wp.data tag-staging (generate/session-tags.js): find-or-create
     *  post_tag terms + editPost({tags}), used by both the Generate flow's
     *  auto-apply and the sidebar's "Apply tags from session" button. */
    public const TAGS_HANDLE = 'chronicler-session-tags';
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
     *  block type, the pattern category, and the draft-template patterns. */
    public function setup(): void
    {
        $this->registerAssets();
        register_block_type(plugin_dir_path(CHRONICLER_PLUGIN_FILE) . 'generate/placeholder');
        register_block_pattern_category(self::CATEGORY, ['label' => 'Chronicler']);
        foreach (self::templates() as $slug => $template) {
            register_block_pattern('chronicler/' . $slug, $template['pattern']);
        }
    }

    private function registerAssets(): void
    {
        $url = static fn (string $file): string => plugins_url('generate/' . $file, CHRONICLER_PLUGIN_FILE);
        wp_register_script(self::BASE_CSS_HANDLE, $url('base-css.js'), [], CHRONICLER_VERSION, true);
        // The mapping module itself is pure; depending on the base-css asset
        // just guarantees both globals exist wherever the lib is loaded.
        wp_register_script(self::LIB_HANDLE, $url('session-blocks.js'), [self::BASE_CSS_HANDLE], CHRONICLER_VERSION, true);
        // The bundled engine lives in the built dist dir (esbuild output), not
        // the hand-written generate/ scripts; it self-registers
        // window.chroniclerSessionEngine on load.
        $distUrl = static fn (string $file): string => plugins_url('admin/dist/' . $file, CHRONICLER_PLUGIN_FILE);
        wp_register_script(self::ENGINE_HANDLE, $distUrl('chronicler-session-engine.js'), [], CHRONICLER_VERSION, true);
        // Mirror/parent REST plumbing shared by the placeholder's Generate
        // flow and the sidebar's featured-image action.
        wp_register_script(self::MIRROR_HANDLE, $url('mirror.js'), ['wp-api-fetch'], CHRONICLER_VERSION, true);
        // Tag staging touches only the editor store, so it depends on wp-data.
        wp_register_script(self::TAGS_HANDLE, $url('session-tags.js'), ['wp-data'], CHRONICLER_VERSION, true);
        wp_register_script(
            self::PLACEHOLDER_HANDLE,
            $url('placeholder/index.js'),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-api-fetch', self::LIB_HANDLE, self::ENGINE_HANDLE, self::MIRROR_HANDLE, self::TAGS_HANDLE],
            CHRONICLER_VERSION,
            true
        );
        // chroniclerAdminUrl: the no-sessions empty state links to the session
        // editor page. apiBase: the engine derives the image-proxy base from it
        // (read lazily at Generate time, so localize order doesn't matter).
        wp_localize_script(self::PLACEHOLDER_HANDLE, 'chroniclerGenerateBoot', [
            'chroniclerAdminUrl' => admin_url('admin.php?page=' . \Chronicler\Admin\Page::SLUG),
            'apiBase' => esc_url_raw(rest_url(Routes::API_NAMESPACE)),
        ]);
        wp_register_script(
            self::SIDEBAR_HANDLE,
            $url('sidebar.js'),
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', self::LIB_HANDLE, self::ENGINE_HANDLE, self::MIRROR_HANDLE, self::TAGS_HANDLE],
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
        return self::seededContent($sessionId, self::requestedTemplateSlug($_GET));
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

    /** An empty paragraph block carrying an editor placeholder hint. Pure. */
    private static function placeholderParagraph(string $hint): string
    {
        $attrs = json_encode(['placeholder' => $hint], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return "<!-- wp:paragraph {$attrs} -->\n<p></p>\n<!-- /wp:paragraph -->";
    }

    /** An h2 heading block. $html is trusted literal markup text. Pure. */
    private static function heading(string $html): string
    {
        return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$html}</h2>\n<!-- /wp:heading -->";
    }

    /** The above-the-fold opening every template shares: a summary
     *  standfirst slot and the More cut. Pure. */
    private static function standfirst(): array
    {
        return [
            self::placeholderParagraph('Session summary — a short standfirst shown above the fold.'),
            "<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->",
        ];
    }

    /** session-log content: standfirst + an unseeded placeholder — the same
     *  reading order the Node publish flow assembled. Pure. */
    public static function patternContent(): string
    {
        return implode("\n\n", [...self::standfirst(), self::placeholderBlock()]);
    }

    /** adventure-log content (#12): the standfirst, then recap / transcript /
     *  loot / next-time sections. All core blocks except the placeholder, so
     *  a GM can freely cut whatever a given week doesn't need. Pure. */
    public static function adventureLogContent(): string
    {
        return implode("\n\n", [
            ...self::standfirst(),
            self::heading('Previously'),
            self::placeholderParagraph('Where things stood — a quick recap for readers joining mid-campaign.'),
            self::heading('The Session'),
            self::placeholderBlock(),
            self::heading('Loot &amp; Rewards'),
            self::placeholderParagraph('Treasure, XP, boons, and favors earned this session.'),
            self::heading('Next Time'),
            self::placeholderParagraph('Cliffhangers, plans, and where the party goes from here.'),
        ]);
    }

    /**
     * The draft templates (#12), keyed by slug: 'label' names the entry in
     * the session editor's picker (array order = picker order, first entry =
     * the picker's default); 'pattern' is the register_block_pattern() args.
     * Pure data; tests pin that every pattern's postTypes is exactly ['post']
     * and that no 'blockTypes' key exists (a core/post-content blockTypes
     * entry would put the pattern in the new-post starter modal).
     */
    public static function templates(): array
    {
        return [
            'adventure-log' => [
                'label' => 'Adventure log',
                'pattern' => [
                    'title' => 'Chronicler adventure log',
                    'description' => 'A full adventure-log write-up: a summary, a recap, the session transcript, and loot and next-time sections.',
                    'categories' => [self::CATEGORY, 'text'],
                    'postTypes' => ['post'],
                    'content' => self::adventureLogContent(),
                ],
            ],
            'session-log' => [
                'label' => 'Session log (minimal)',
                'pattern' => [
                    'title' => 'Chronicler session log',
                    'description' => 'A session-log post: a summary, a Read More break, and a transcript placeholder.',
                    'categories' => [self::CATEGORY, 'text'],
                    'postTypes' => ['post'],
                    'content' => self::patternContent(),
                ],
            ],
        ];
    }

    /**
     * The chronicler_template query arg when it names a known template,
     * DEFAULT_TEMPLATE otherwise — an unrecognized slug degrades the layout
     * choice, never the seeding. Pure.
     *
     * @param array<mixed> $get the request query args ($_GET)
     */
    public static function requestedTemplateSlug(array $get): string
    {
        $raw = $get['chronicler_template'] ?? null;
        if (is_string($raw) && array_key_exists($raw, self::templates())) {
            return $raw;
        }
        return self::DEFAULT_TEMPLATE;
    }

    /** Seeded deep-link content: the template's pattern content with the
     *  Session's id swapped into its placeholder block. Pure. */
    public static function seededContent(int $sessionId, string $template = self::DEFAULT_TEMPLATE): string
    {
        $templates = self::templates();
        $content = $templates[$template]['pattern']['content'] ?? self::patternContent();
        return str_replace(self::placeholderBlock(), self::placeholderBlock($sessionId), $content);
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
