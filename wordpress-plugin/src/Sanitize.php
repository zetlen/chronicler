<?php

namespace Chronicler;

/**
 * Write-boundary sanitization for stored HTML and CSS (#159).
 *
 * The chronicler/message block attributes carry pre-rendered HTML fragments
 * (avatarHtml, bodyHtml, …) that chronicler_render_message() echoes verbatim
 * at view time, and the transcript block carries stylesheet text that
 * blocks.php prints inside a <style> element. KSES-on-save never sees either:
 * Gutenberg's serializer unicode-escapes the angle brackets inside
 * block-delimiter JSON, so stored post_content contains no literal tags for
 * wp_filter_post_kses to filter, and Sessions (a custom table) bypass kses
 * entirely. The REST routes are therefore the trust boundary: every write
 * that can reach those sinks is filtered here, via the sanitize callbacks
 * Rest\Schemas attaches (see tree()).
 *
 * css() is pure; html() delegates to wp_kses_post (stubbed minimally in
 * tests/run.php for the standalone runner).
 */
final class Sanitize
{
    /**
     * The stored-fragment members that reach the render path unescaped:
     * chronicler/message's five composed-HTML attributes plus the opaque v2
     * `html` attribute (also the system-message path). Everything else a
     * message carries (authorName, anchorId, colors, image src/alt/…) is
     * escaped or validated by the renderer itself.
     */
    public const HTML_FIELDS = [
        'html',
        'avatarHtml',
        'headHtml',
        'bodyHtml',
        'extrasHtml',
        'reactionsHtml',
    ];

    /**
     * Stylesheet text safe to print inside a <style> element: CSS has no
     * legitimate use for '<', so every occurrence is removed — a stored
     * `</style><script>` payload cannot close the element. Pure; mirrors
     * sanitizeCss() in lib/transform/sanitize.ts, which the admin preview
     * applies to the same stored value.
     */
    public static function css(string $css): string
    {
        return str_replace('<', '', $css);
    }

    /** One stored HTML fragment through WordPress's post-content filter. */
    public static function html(string $html): string
    {
        return wp_kses_post($html);
    }

    /**
     * Recursively sanitize a decoded JSON tree: members named after a stored
     * HTML fragment go through html(), members named customCss through
     * css(), everything else passes untouched (plain-text fields like
     * override names are escaped at render and must round-trip verbatim).
     * Handles every write shape that can reach the render sinks — message
     * arrays, editorState (additionalProperties stays open), channel
     * defaults, import presets — so it is the one sanitize callback the
     * REST args need. Non-arrays pass through for the schema validator to
     * reject.
     */
    public static function tree($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::tree($item);
            } elseif (is_string($item)) {
                if ($key === 'customCss') {
                    $value[$key] = self::css($item);
                } elseif (in_array($key, self::HTML_FIELDS, true)) {
                    $value[$key] = self::html($item);
                }
            }
        }
        return $value;
    }
}
