<?php
// wordpress-plugin/message-render.php
// Pure rendering for chronicler/message v3 attributes — no WordPress
// dependencies, so tests/run.php can exercise it standalone. Escaping
// mirrors lib/transform/shared.ts (escapeHtml/escapeAttr) exactly; using
// WP's esc_html here would double-escape differently and break the
// cross-language parity fixtures.

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

function chronicler_msg_esc_html($t) {
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string)$t);
}

function chronicler_msg_esc_attr($t) {
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], (string)$t);
}

function chronicler_msg_valid_hex($c) {
    return is_string($c) && preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $c) ? $c : '';
}

const CHRONICLER_MESSAGE_VARIANTS = ['ooc', 'important'];

/** The slk-msg--<v> classes for a message's variants, in vocabulary order,
 *  unknown entries dropped. Mirrors variantClasses() in lib/transform/variants.ts. */
function chronicler_variant_classes($variants) {
    if (!is_array($variants)) {
        return [];
    }
    $out = [];
    foreach (CHRONICLER_MESSAGE_VARIANTS as $v) {
        if (in_array($v, $variants, true)) {
            $out[] = 'slk-msg--' . $v;
        }
    }
    return $out;
}

/**
 * Render a chronicler/message block. v3 attributes compose the bubble
 * exactly like composeBubble() in lib/transform/shared.ts (the parity
 * fixtures enforce byte equality); anything else falls back to echoing the
 * opaque v2 `html` attribute (also the system-message path). Opaque chrome
 * attributes are echoed as-is — NOT because kses-on-save covers them (it
 * cannot: the block serializer unicode-escapes angle brackets inside the
 * block-delimiter JSON, so stored post_content holds no literal tags for
 * wp_filter_post_kses to see). Their trust boundary is write time: every
 * chronicler/v1 write runs them through Chronicler\Sanitize (#159), and the
 * admin preview DOMPurifies the same fragments client-side.
 */
function chronicler_render_message($attributes) {
    $a = is_array($attributes) ? $attributes : [];
    $is_v3 = ($a['authorName'] ?? '') !== '' || ($a['bodyHtml'] ?? '') !== '';
    if (!$is_v3) {
        return (string)($a['html'] ?? '');
    }

    $root = ($a['rootClass'] ?? '') !== '' ? (string)$a['rootClass'] : 'slk-msg slk-msg--text';
    foreach (chronicler_variant_classes($a['variants'] ?? []) as $variant_class) {
        $root .= ' ' . $variant_class;
    }
    if (($a['className'] ?? '') !== '') {
        $root .= ' ' . (string)$a['className'];
    }

    $style = '';
    $color = chronicler_msg_valid_hex($a['authorColor'] ?? '');
    if ($color !== '') {
        $dark = chronicler_msg_valid_hex($a['authorColorDark'] ?? '');
        $style = ' style="--slk-id:' . chronicler_msg_esc_attr($color)
            . ';--slk-id-dark:' . chronicler_msg_esc_attr($dark !== '' ? $dark : $color) . '"';
    }

    $figures = '';
    if (!empty($a['images']) && is_array($a['images'])) {
        $items = '';
        foreach ($a['images'] as $im) {
            if (!is_array($im) || !isset($im['src']) || !is_string($im['src']) || $im['src'] === '') {
                continue;
            }
            $alt = isset($im['alt']) && is_string($im['alt']) ? $im['alt'] : '';
            $caption = isset($im['caption']) && is_string($im['caption']) ? $im['caption'] : $alt;
            $items .= '<figure class="slk-image"><img src="' . chronicler_msg_esc_attr($im['src'])
                . '" alt="' . chronicler_msg_esc_attr($alt)
                . '" loading="lazy"><figcaption>' . chronicler_msg_esc_html($caption)
                . '</figcaption></figure>';
        }
        if ($items !== '') {
            $figures = '<div class="slk-images">' . $items . '</div>';
        }
    }

    $anchor = '';
    if (isset($a['anchorId']) && is_string($a['anchorId']) && $a['anchorId'] !== '') {
        $anchor = ' id="' . chronicler_msg_esc_attr($a['anchorId']) . '"';
    }

    $author = (string)($a['authorName'] ?? '');
    $variants = is_array($a['variants'] ?? null) ? $a['variants'] : [];
    if (in_array('ooc', $variants, true) && ($a['realName'] ?? '') !== '') {
        $author = (string)$a['realName'];
    }

    return '<div class="' . chronicler_msg_esc_attr($root) . '"' . $anchor . $style . '>' . "\n"
        . '  ' . (string)($a['avatarHtml'] ?? '') . "\n"
        . '  <div class="slk-msg__main">' . "\n"
        . '    <div class="slk-msg__head"><span class="slk-msg__author">'
        . chronicler_msg_esc_html($author)
        . '</span>' . (string)($a['headHtml'] ?? '') . '</div>' . "\n"
        . '    <div class="slk-msg__body">' . (string)($a['bodyHtml'] ?? '') . $figures
        . (string)($a['extrasHtml'] ?? '') . '</div>' . "\n"
        . '    ' . (string)($a['reactionsHtml'] ?? '') . "\n"
        . '  </div>' . "\n"
        . '</div>';
}

/**
 * The transcript block's combined stylesheet, guarded against markup
 * breakout (#159): the value lands between <style> and </style>, and CSS
 * has no legitimate use for '<', so Chronicler\Sanitize::css() removes
 * every occurrence — a stored `</style><script>` payload cannot close the
 * element. Pure (Sanitize::css is WordPress-free).
 */
function chronicler_transcript_css($baseCss, $customCss) {
    return \Chronicler\Sanitize::css(trim((string)$baseCss . "\n" . (string)$customCss));
}

/**
 * Validate the transcript block's fontSize attribute. Strict whitelist —
 * the value lands in a style attribute, so nothing else may pass. Values
 * are 15px / 17px / 19px / 21px; '' means "defer to the stylesheet" (17px
 * since plugin 3.8.0).
 */
function chronicler_transcript_font_size($value) {
    return in_array($value, ['15px', '17px', '19px', '21px'], true) ? $value : '';
}
