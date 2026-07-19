<?php
// Transcript blocks module: renders the chronicler/* blocks published by
// the app. Loaded by chronicler.php.

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/message-render.php';

/**
 * Block schema this plugin's code understands. Reported over XML-RPC so the
 * Chronicler publish flow knows whether to send block grammar or fall back
 * to plain HTML with embedded styles. Must match BLOCKS_VERSION in
 * lib/wordpress/blockGrammar.ts (a Chronicler test enforces the pairing).
 *
 * v2: the transcript block carries its own baseCss — styles are per-post
 * data, and there is deliberately no site-global stylesheet: a published
 * transcript is a finished artifact whose appearance never changes out
 * from under it.
 * v3: structured editable message attributes — the message bubble is now
 * composed server-side from discrete fields (authorName, bodyHtml, images,
 * etc.) instead of a single opaque html blob, so Gutenberg can offer
 * field-level editing while chronicler_render_message() keeps the same
 * markup byte-for-byte (enforced by the cross-language parity fixtures).
 */
const CHRONICLER_BLOCKS_VERSION = 4;

/**
 * The block-editor screens that host transcripts (#164): ordinary posts —
 * where every transcript is published — plus pages, where one can be
 * pasted, plus wp_block (the reusable-block/pattern editor is an ordinary
 * post editor for that CPT, and a transcript saved into one must stay
 * editable there). The widget/site editors and unrelated CPTs skip the
 * editor assets; sites hosting transcripts elsewhere can widen the list via
 * the filter. Front-end render never depends on this — the block
 * registrations are unconditional.
 */
function chronicler_editor_post_types(): array {
    return (array) apply_filters('chronicler_editor_post_types', ['post', 'page', 'wp_block']);
}

/** Whether the current admin screen is a block editor for a transcript-hosting post type. */
function chronicler_is_transcript_editor_screen(): bool {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    return $screen !== null
        && in_array($screen->post_type, chronicler_editor_post_types(), true);
}

// The editor renders blocks from the same attributes PHP does, so it needs
// only the block definitions — no stylesheet enqueue anywhere.
add_action('enqueue_block_editor_assets', function () {
    if (!chronicler_is_transcript_editor_screen()) {
        return;
    }
    wp_enqueue_script(
        'chronicler-blocks',
        plugins_url('editor.js', __FILE__),
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-rich-text'],
        CHRONICLER_VERSION,
        true
    );
});

function chronicler_note($text) {
    return $text === '' ? '' : '<div class="slk-thread__note">' . esc_html($text) . '</div>';
}

// All four transcript blocks are Block API v3 (#164), like the newer
// character-index and session-placeholder blocks: v3 declares the editor may
// render them inside its iframed canvas, WordPress's direction of travel.
// They render server-side from attributes, so nothing else changes.
add_action('init', function () {
    register_block_type('chronicler/transcript', [
        'api_version' => 3,
        'attributes' => [
            'scheme' => ['type' => 'string', 'default' => 'light'],
            'density' => ['type' => 'string', 'default' => 'comfortable'],
            'fontSize' => ['type' => 'string', 'default' => ''],
            'baseCss' => ['type' => 'string', 'default' => ''],
            'customCss' => ['type' => 'string', 'default' => ''],
            // Inert provenance stamped by editor-native generation (#102):
            // which Session produced this wrapper and when. Registered so
            // Gutenberg re-saves keep them; the render callback ignores both.
            'sessionId' => ['type' => 'number', 'default' => 0],
            'generatedAt' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attributes, $content) {
            $classes = 'slack-log';
            if (($attributes['scheme'] ?? '') === 'dark') {
                $classes .= ' slk-dark';
            }
            // OOC hiding (#90) is PLUGIN-versioned, deliberately outside the
            // per-post baked stylesheet below (which is frozen at generation
            // time): printing it here reaches every already-published
            // transcript. Hidden is the default in every medium — reading,
            // no-JS, and print (unconditionally) — and display:none keeps
            // hidden messages out of the accessibility tree, so no
            // aria-hidden bookkeeping is needed. Only transcript.js's
            // "Show OOC messages" checkbox adds slk-ooc-shown; revealing
            // works by NOT hiding (:not), so each message returns to its
            // own natural display (flex bubbles, block system lines).
            static $ooc_css_printed = false;
            $ooc_style = '';
            if (!$ooc_css_printed) {
                $ooc_css_printed = true;
                $ooc_style = '<style id="chronicler-ooc-css">'
                    . '.slack-log:not(.slk-ooc-shown) .slk-msg--ooc{display:none;}'
                    . '.slack-log .slk-ooc-toggle{display:flex;justify-content:flex-end;align-items:center;'
                    . 'gap:6px;font-size:12.5px;color:var(--slk-muted);padding:0 8px 6px;'
                    . 'user-select:none;cursor:pointer;}'
                    . '.slack-log .slk-ooc-toggle input{accent-color:var(--slk-accent);margin:0;cursor:pointer;}'
                    . '.slack-log .slk-ooc-toggle:hover{color:var(--slk-fg);}'
                    // The "(N hidden)"/"(N shown)" count transcript-core.js
                    // maintains (#185) — quieter than the label text.
                    . '.slack-log .slk-ooc-count{opacity:.7;font-variant-numeric:tabular-nums;}'
                    . '@media print{'
                    . '.slack-log .slk-msg--ooc{display:none !important;}'
                    . '.slack-log .slk-ooc-toggle{display:none !important;}'
                    . '}'
                    . '</style>';
            }
            if (($attributes['density'] ?? '') === 'compact') {
                $classes .= ' slk-density-compact';
            }
            $style_attr = '';
            $font = chronicler_transcript_font_size($attributes['fontSize'] ?? '');
            if ($font !== '') {
                $style_attr = ' style="--slk-font-size:' . esc_attr($font) . '"';
            }
            // Both stylesheets render at view time (not stored as markup),
            // so they survive even for publishers without unfiltered_html.
            // chronicler_transcript_css guards the emitted <style> element:
            // stored CSS cannot smuggle markup past it (#159).
            $css = chronicler_transcript_css($attributes['baseCss'] ?? '', $attributes['customCss'] ?? '');
            $style = '';
            if ($css !== '') {
                // Each distinct stylesheet prints once per request: an
                // archive page showing several same-era transcripts gets
                // one copy, not one per post. (Transcripts from different
                // style eras still each print theirs; on a shared page the
                // later one wins the cascade — inherent to unscoped CSS.)
                static $printed = [];
                $key = md5($css);
                if (!isset($printed[$key])) {
                    $printed[$key] = true;
                    $style = '<style>' . $css . '</style>';
                }
            }
            return $ooc_style . $style . '<div class="' . esc_attr($classes) . '"' . $style_attr . '>' . $content . '</div>';
        },
    ]);

    register_block_type('chronicler/thread', [
        'api_version' => 3,
        'attributes' => [
            'context' => ['type' => 'boolean', 'default' => false],
            'contextNote' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attributes, $content) {
            $class = 'slk-thread' . (!empty($attributes['context']) ? ' slk-thread--context' : '');
            return '<div class="' . esc_attr($class) . '">'
                . chronicler_note($attributes['contextNote'] ?? '')
                . $content
                . '</div>';
        },
    ]);

    register_block_type('chronicler/replies', [
        'api_version' => 3,
        'attributes' => [
            'beforeNote' => ['type' => 'string', 'default' => ''],
            'afterNote' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attributes, $content) {
            return '<div class="slk-thread__replies">'
                . chronicler_note($attributes['beforeNote'] ?? '')
                . $content
                . chronicler_note($attributes['afterNote'] ?? '')
                . '</div>';
        },
    ]);

    // v3: structured, editable attributes; `html` remains as the v2 form and
    // the system-message path. className must be registered explicitly —
    // render callbacks only receive registered attributes.
    register_block_type('chronicler/message', [
        'api_version' => 3,
        'attributes' => [
            'html' => ['type' => 'string', 'default' => ''],
            'rootClass' => ['type' => 'string', 'default' => ''],
            'anchorId' => ['type' => 'string', 'default' => ''],
            'authorName' => ['type' => 'string', 'default' => ''],
            'authorColor' => ['type' => 'string', 'default' => ''],
            'authorColorDark' => ['type' => 'string', 'default' => ''],
            'avatarHtml' => ['type' => 'string', 'default' => ''],
            'headHtml' => ['type' => 'string', 'default' => ''],
            'bodyHtml' => ['type' => 'string', 'default' => ''],
            'images' => ['type' => 'array', 'default' => []],
            'extrasHtml' => ['type' => 'string', 'default' => ''],
            'reactionsHtml' => ['type' => 'string', 'default' => ''],
            'className' => ['type' => 'string', 'default' => ''],
            'variants' => ['type' => 'array', 'default' => []],
            'realName' => ['type' => 'string', 'default' => ''],
        ],
        'render_callback' => function ($attributes) {
            return chronicler_render_message($attributes);
        },
    ]);
});

/*
 * Transcript front-end behavior: the copy-link affordance (progressive
 * enhancement — the anchor works without JS) and the #90 OOC toggle (the
 * inverse: OOC messages are server-hidden; only this JS can reveal them).
 * All logic lives in transcript-core.js; the two attach calls are printed
 * INLINE with a version-busted core URL. A separate entry file used to
 * import the core relatively — an unversioned URL browsers cache across
 * plugin updates, and a stale core with a missing export kills the whole
 * module graph (observed while building #90). Inlining the entry leaves
 * exactly one fetch, always in lockstep with CHRONICLER_VERSION. Printed
 * only on pages that actually carry a transcript block; a plain module tag
 * stays version-proof (wp_enqueue_script_module needs WP >= 6.5).
 */
function chronicler_transcript_print_module(): void {
    if (!function_exists('has_block') || !has_block('chronicler/transcript')) {
        return;
    }
    $core = plugins_url('transcript-core.js', __FILE__) . '?ver=' . CHRONICLER_VERSION;
    echo '<script type="module">'
        . 'import{attachTranscriptInteractions,attachOocToggle}from"' . esc_url($core) . '";'
        . 'document.querySelectorAll(".slack-log").forEach((root)=>{'
        . 'attachTranscriptInteractions(root);attachOocToggle(root);'
        . '});'
        . '</script>';
}
add_action('wp_footer', 'chronicler_transcript_print_module');

