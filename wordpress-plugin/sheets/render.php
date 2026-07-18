<?php
// Front-end sheet: schema-driven markup, same for every viewer; sheet.js
// activates controls for viewers who can edit. Rendering hangs off
// the_content (not template_include) so any theme, classic or block,
// frames the sheet like a normal post.

if (!defined('ABSPATH')) {
    exit;
}

function chronicler_sheets_the_content(string $content): string {
    if (!is_singular('chr_character') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    // Password-protected character (#158): core hands the password form in as
    // $content — pass it through untouched. Rebuilding the sheet from meta
    // here would disclose every stat around the form.
    if (post_password_required()) {
        return $content;
    }
    // The post content (rich-text subhead) becomes the masthead's tagline.
    return chronicler_sheets_render_sheet(get_the_ID(), $content);
}
add_filter('the_content', 'chronicler_sheets_the_content');

/**
 * Obsidian-Portal-style header: portrait left; name, plain-text tagline
 * (the post excerpt), and the masthead-flagged sections' properties right —
 * the first trait prominent (the playbook slot), the rest label: value.
 */
function chronicler_sheets_render_masthead(int $post_id, array $trait_props = []): string {
    $html = '<header class="chr-masthead">';
    // The author IS the owning player (that's the permission model), so the
    // attribution the theme byline used to muddle reads plainly here.
    $player = get_the_author_meta('display_name', (int) get_post_field('post_author', $post_id));
    if ($player !== '') {
        $html .= '<span class="chr-masthead__player">Played by ' . esc_html($player) . '</span>';
    }
    $thumb_id = get_post_thumbnail_id($post_id);
    if ($thumb_id) {
        // wp_get_attachment_image, not get_the_post_thumbnail: the latter runs
        // the post_thumbnail_html filter we blank below to keep the theme from
        // printing the portrait a second time above the sheet.
        $html .= '<div class="chr-masthead__portrait">'
            . wp_get_attachment_image($thumb_id, 'medium')
            . '</div>';
    }
    $html .= '<div class="chr-masthead__id">';
    // The page's real H1: the theme's own post-title block is blanked below.
    $html .= '<h1 class="chr-masthead__name">' . esc_html(get_the_title($post_id)) . '</h1>';
    // Raw excerpt, not get_the_excerpt(): an empty tagline must stay empty
    // rather than auto-generating from the intro content.
    $tagline = (string) get_post_field('post_excerpt', $post_id);
    if (trim($tagline) !== '') {
        $html .= '<p class="chr-masthead__tagline">' . esc_html($tagline) . '</p>';
    }
    if ($trait_props !== []) {
        $html .= '<div class="chr-masthead__traits">';
        $first = true;
        foreach ($trait_props as $property) {
            $display = esc_html(chronicler_sheets_display_value($property, chronicler_sheets_get_value($post_id, $property)));
            if ($first) {
                $html .= '<p class="chr-masthead__trait chr-masthead__trait--primary">' . $display . '</p>';
                $first = false;
            } else {
                $html .= '<p class="chr-masthead__trait"><span class="chr-masthead__trait-label">'
                    . esc_html($property['label']) . ':</span> ' . $display . '</p>';
            }
        }
        $html .= '</div>';
    }
    $html .= '</div></header>';
    return $html;
}

/**
 * Character singles get their own block template: theme header, the sheet
 * (which carries the real H1 masthead), theme footer — none of the single-
 * post header pattern (title slot, "Written by ... in ..." byline, featured
 * image). Plugin-registered templates lose to a theme's own
 * single-chr_character template, and register_block_template needs WP 6.7 —
 * in both fallback cases the suppression filters below still apply.
 */
function chronicler_sheets_register_template(): void {
    // Classic themes (e.g. Kadence) have no header/footer template PARTS, so
    // this template would render a chrome-less page with "template part has
    // been deleted" warnings. They keep their own single.php; the sheet CSS
    // hides their .entry-header on character singles instead.
    if (!function_exists('register_block_template') || !wp_is_block_theme()) {
        return;
    }
    register_block_template('chronicler//single-chr_character', [
        'title' => 'Character Sheet',
        'description' => 'Full-width character page: masthead and stat sections, no post header.',
        'post_types' => ['chr_character'],
        'content' => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
            . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->'
            . '<!-- wp:post-content {"layout":{"type":"constrained"}} /-->'
            . '<!-- /wp:group -->'
            . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
    ]);
}
add_action('init', 'chronicler_sheets_register_template');

/**
 * Fallback when the plugin template doesn't apply: blank the theme's
 * post-title block on character singles — but only for the queried
 * character itself, so titles inside any other query loop on the page
 * survive. (Block themes only; classic themes printing the_title()
 * directly will show the name twice, which is cosmetic.)
 */
function chronicler_sheets_suppress_theme_title(string $block_content, array $block, $instance): string {
    if (!is_singular('chr_character')) {
        return $block_content;
    }
    $post_id = $instance->context['postId'] ?? null;
    return $post_id === null || $post_id === get_queried_object_id() ? '' : $block_content;
}
add_filter('render_block_core/post-title', 'chronicler_sheets_suppress_theme_title', 10, 3);

/**
 * Character pages draw the portrait inside the masthead; blank the theme's
 * own featured-image rendering so it doesn't appear twice.
 */
function chronicler_sheets_suppress_theme_thumbnail(string $html, int $post_id): string {
    return is_singular('chr_character') && get_post_type($post_id) === 'chr_character' ? '' : $html;
}
add_filter('post_thumbnail_html', 'chronicler_sheets_suppress_theme_thumbnail', 10, 2);

function chronicler_sheets_render_sheet(int $post_id, string $intro = ''): string {
    $template = chronicler_sheets_template_for_character($post_id);
    if ($template === null) {
        return $intro . '<p>No sheet template is configured yet.</p>';
    }
    $can_edit = current_user_can('edit_post', $post_id);
    // GM-only properties are gated on editing characters you don't own — the
    // game master capability. This deliberately excludes the owning player
    // (who can edit their own sheet) and the public, and the gate drops the
    // markup rather than hiding it, so the content never reaches their page.
    $is_gm = current_user_can('edit_others_chr_characters');

    $boot = [
        'restUrl' => esc_url_raw(rest_url('chronicler/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'canEdit' => $can_edit,
        'characterId' => $post_id,
    ];

    // Masthead sections feed the header card; the rest render as body sections.
    // Audience-gated properties (gm_only, owner_only) are filtered out here for
    // viewers outside their audience, and unfilled properties (blank, or a
    // leftover "[placeholder]") are dropped too unless
    // the schema flags them always_show — Player Notes, GM Notes, etc. That
    // covers masthead traits as well: an unset identity field would otherwise
    // render as a bare "Label:" line, and skipping it here lets the next
    // filled trait take the prominent first slot. A section left with nothing
    // to show is dropped so no empty header remains.
    $trait_props = [];
    $body_sections = [];
    foreach (chronicler_sheets_layout_sections($template) as $section) {
        $visible = [];
        foreach ($section['properties'] as $pid) {
            $property = $template['properties'][$pid];
            if (!$is_gm && chronicler_sheets_is_gm_only($property)) {
                continue;
            }
            // Owner-only properties render solely for viewers who can edit
            // this character — its author and game masters. The gate drops
            // the markup rather than hiding it, so fellow players and the
            // public never receive the content.
            if (!$can_edit && chronicler_sheets_is_owner_only($property)) {
                continue;
            }
            if (!chronicler_sheets_is_always_show($property)
                && chronicler_sheets_is_unfilled($property, chronicler_sheets_get_value($post_id, $property))) {
                continue;
            }
            $visible[] = $pid;
        }
        if ($visible === []) {
            continue;
        }
        if (!empty($section['masthead'])) {
            foreach ($visible as $pid) {
                $trait_props[] = $template['properties'][$pid];
            }
        } else {
            $section['properties'] = $visible;
            $body_sections[] = $section;
        }
    }

    // alignwide: block themes grant their sanctioned wide measure to this
    // class — the sheet escapes blog-post column width the theme's own way.
    // Classic themes implement alignwide with NEGATIVE margins (overflowing
    // the viewport), so there the sheet just fills the theme's container.
    $wide = wp_is_block_theme() ? ' alignwide' : '';
    $html = '<article class="chr-sheet' . $wide . '" data-chronicler-sheet>';
    $html .= '<script type="application/json" id="chronicler-sheet-boot">' . wp_json_encode($boot) . '</script>';
    $html .= '<div class="chr-sheet__error" hidden></div>';
    $html .= chronicler_sheets_render_masthead($post_id, $trait_props);
    // The unstructured rich block (post content) sits between header and stats.
    if (trim($intro) !== '') {
        $html .= '<div class="chr-sheet__intro">' . $intro . '</div>';
    }
    foreach ($body_sections as $section) {
        // Named hooks for site CSS: .chr-section--ratings, .chr-prop--charm.
        // The schema stays styling-agnostic; themes target names they know.
        $html .= '<section class="chr-section chr-section--' . esc_attr(sanitize_title($section['section'])) . '">'
            . '<h2>' . esc_html($section['section']) . '</h2>';
        foreach ($section['properties'] as $pid) {
            $property = $template['properties'][$pid];
            $value = chronicler_sheets_get_value($post_id, $property);
            $editable = $can_edit && chronicler_sheets_is_live($property);
            // "Moves" header over a lone "Moves" label is noise — hide the
            // label when the section repeats it and holds nothing else.
            $show_label = count($section['properties']) > 1 || $property['label'] !== $section['section'];
            $detail = chronicler_sheets_get_detail($post_id, $property);
            $html .= chronicler_sheets_render_property($property, $value, $editable, $show_label, $detail);
        }
        $html .= '</section>';
    }
    $html .= '</article>';
    return $html;
}

function chronicler_sheets_render_property(array $property, $value, bool $editable, bool $show_label = true, string $detail = ''): string {
    // Longtext skips the display badge: it would just repeat the content
    // beside itself (as raw markup, once rich text is involved).
    $badge = $property['type'] === 'longtext'
        ? ''
        : '<span class="chr-prop__display">' . esc_html(chronicler_sheets_display_value($property, $value)) . '</span>';
    // Detail survives independently of the badge — the static path clears
    // the badge for scalar types, but what a rating does must still show.
    // Commas separate items ("·" still honored for older values); each gets
    // its own span so site CSS can choose inline-with-separators (the
    // default) or one per line.
    $detail_html = '';
    if ($detail !== '') {
        $detail_html = '<span class="chr-prop__detail">';
        foreach (array_filter(array_map('trim', preg_split('/[,·]/u', $detail))) as $item) {
            $detail_html .= '<span class="chr-prop__detail-item">' . esc_html($item) . '</span>';
        }
        $detail_html .= '</span>';
    }
    $html = '<div class="chr-prop chr-prop--' . esc_attr($property['id']) . '" data-prop="' . esc_attr($property['id']) . '" data-type="' . esc_attr($property['type']) . '">';
    if ($show_label) {
        $html .= '<span class="chr-prop__label">' . esc_html($property['label']) . '</span>';
    }

    if (!$editable) {
        // Static markup already IS the value for most types; the summary
        // badge only earns its keep where the markup is boxes or a list.
        if (!in_array($property['type'], ['track', 'checklist'], true)) {
            $badge = '';
        }
        $html .= '<span class="chr-prop__static">' . chronicler_sheets_render_static($property, $value) . '</span>';
        return $html . $detail_html . $badge . '</div>';
    }

    switch ($property['type']) {
        case 'track':
            $html .= '<span class="chr-track">';
            for ($i = 0; $i < $property['length']; $i++) {
                $marked = $i < $value ? 'true' : 'false';
                $html .= '<button type="button" class="chr-track__box" data-index="' . $i . '" aria-pressed="' . $marked . '"></button>';
            }
            $html .= '</span>';
            break;
        case 'number':
        case 'counter':
            $html .= '<button type="button" class="chr-step" data-step="-1">&minus;</button>'
                . '<span class="chr-prop__value">' . (int) $value . '</span>'
                . '<button type="button" class="chr-step" data-step="1">+</button>';
            break;
        case 'toggle':
            $html .= '<input type="checkbox" class="chr-toggle"' . checked((bool) $value, true, false) . '>';
            break;
        case 'select':
            $html .= '<select class="chr-select">';
            foreach ($property['options'] as $opt) {
                $html .= '<option value="' . esc_attr($opt['id']) . '"' . selected($value, $opt['id'], false) . '>'
                    . esc_html($opt['label']) . '</option>';
            }
            $html .= '</select>';
            break;
        case 'checklist':
            $html .= '<ul class="chr-checklist">';
            foreach ($property['options'] as $opt) {
                $on = in_array($opt['id'], (array) $value, true);
                $html .= '<li><label><input type="checkbox" class="chr-check" value="' . esc_attr($opt['id']) . '"'
                    . checked($on, true, false) . '> ' . esc_html($opt['label']) . '</label></li>';
            }
            $html .= '</ul>';
            break;
        case 'longtext':
            $html .= '<textarea class="chr-longtext" rows="4">' . esc_textarea((string) $value) . '</textarea>';
            break;
        default: // text
            $html .= '<input type="text" class="chr-text" value="' . esc_attr((string) $value) . '">';
    }

    return $html . $detail_html . $badge . '</div>';
}

/** Non-interactive value markup for properties the viewer cannot edit here. */
function chronicler_sheets_render_static(array $property, $value): string {
    switch ($property['type']) {
        case 'track':
            $out = '';
            for ($i = 0; $i < $property['length']; $i++) {
                $out .= '<span class="chr-track__box" aria-hidden="true" data-marked="' . ($i < $value ? '1' : '0') . '"></span>';
            }
            return $out;
        case 'checklist':
            $out = '<ul class="chr-checklist">';
            foreach ($property['options'] as $opt) {
                $on = in_array($opt['id'], (array) $value, true);
                $out .= '<li data-checked="' . ($on ? '1' : '0') . '">' . esc_html($opt['label']) . '</li>';
            }
            return $out . '</ul>';
        case 'list':
            // Placeholder-only rows (e.g. "[quantum backpack object 1]") never
            // reach the table — chronicler_sheets_is_unfilled() already hides
            // the whole property when this leaves nothing real to show.
            return chronicler_sheets_render_list_table($property, chronicler_sheets_filter_placeholder_entries($property, (array) $value));
        case 'longtext':
            // Top-level longtext is authored with the rich-text editor in
            // wp-admin (and wp_kses_post-sanitized on save); render the
            // stored markup rather than escaping it into visible tags.
            // wpautop also paragraph-breaks plain-text legacy values.
            return wpautop(wp_kses_post((string) $value));
        default:
            // number/counter/toggle/select/text read fine as their display string.
            return esc_html(chronicler_sheets_display_value($property, $value));
    }
}

/** The list type's front-end table. First field is the entry's display name. */
function chronicler_sheets_render_list_table(array $property, array $entries): string {
    if ($entries === []) {
        return '<em class="chr-list--empty">None yet.</em>';
    }
    $out = '<table class="chr-list"><thead><tr>';
    foreach ($property['fields'] as $field) {
        // Toggle columns are quiet: a checkmark (or nothing) explains itself,
        // so the label goes to screen readers only.
        $out .= $field['type'] === 'toggle'
            ? '<th><span class="screen-reader-text">' . esc_html($field['label']) . '</span></th>'
            : '<th>' . esc_html($field['label']) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($entries as $entry) {
        // Toggle fields mark their row (data-has="1") so site CSS can style
        // entries by state — e.g. fade the moves a character lacks.
        $row_attrs = '';
        foreach ($property['fields'] as $field) {
            if ($field['type'] === 'toggle') {
                $on = (bool) ($entry[$field['id']] ?? false);
                $row_attrs .= ' data-' . esc_attr($field['id']) . '="' . ($on ? '1' : '0') . '"';
            }
        }
        $out .= '<tr' . $row_attrs . '>';
        foreach ($property['fields'] as $i => $field) {
            $value = $entry[$field['id']] ?? chronicler_sheets_default_value($field);
            if (!chronicler_sheets_when_holds($property, $field, $entry)) {
                // Gated off: the value stays stored but doesn't display.
                $cell = '';
            } elseif ($field['type'] === 'toggle') {
                $cell = $value ? '&#10003;' : '';
            } elseif ($field['type'] === 'select') {
                $cell = esc_html(chronicler_sheets_display_value($field, $value));
            } elseif ($field['type'] === 'longtext') {
                $cell = nl2br(esc_html((string) $value));
            } else {
                $cell = esc_html((string) $value);
            }
            // Non-header cells carry their field's label so a narrow viewport
            // can stack the table and print "Label: value" per row (issue #64),
            // and the field's ID so CSS can key styling on schema structure
            // (e.g. Improvements' have/advanced marks, issue #69) rather than
            // on display text that wp-admin can reword at any time.
            $out .= $i === 0
                ? '<th scope="row">' . $cell . '</th>'
                : '<td data-field="' . esc_attr($field['id']) . '" data-label="' . esc_attr($field['label']) . '">' . $cell . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

function chronicler_sheets_enqueue_assets(): void {
    // Singles (the sheet) and the /characters archive (the #66 index) share
    // one stylesheet.
    if (!is_singular('chr_character') && !is_post_type_archive('chr_character')) {
        return;
    }
    wp_enqueue_style(
        'chronicler-sheet',
        plugins_url('sheets/sheet.css', CHRONICLER_PLUGIN_FILE),
        [],
        CHRONICLER_VERSION
    );
}
add_action('wp_enqueue_scripts', 'chronicler_sheets_enqueue_assets');

/*
 * The editor script is an ES module. Printed directly (not enqueued):
 * wp_enqueue_script_module needs WP >= 6.5, and a plain module tag is
 * version-proof with identical behavior.
 */
function chronicler_sheets_print_module(): void {
    if (!is_singular('chr_character')) {
        return;
    }
    $src = plugins_url('sheets/sheet.js', CHRONICLER_PLUGIN_FILE) . '?ver=' . CHRONICLER_VERSION;
    echo '<script type="module" src="' . esc_url($src) . '"></script>';
}
add_action('wp_footer', 'chronicler_sheets_print_module');
