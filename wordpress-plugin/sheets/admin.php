<?php
// Admin UI: the sheet-template configurator, the character Active box, and
// the Slack-id user profile field. The configurator's textarea is a
// deliberately disposable editor — the versioned schema JSON is the contract.

if (!defined('ABSPATH')) {
    exit;
}

function chronicler_sheets_admin_menu(): void {
    // Under the Chronicler menu (#125) — Admin\Page registers the parent at
    // admin_menu priority 9, so 'chronicler' exists by the time this runs.
    // The returned hook suffix gates the editor-bundle enqueue below.
    $GLOBALS['chronicler_sheets_configurator_hook'] = add_submenu_page(
        'chronicler',
        __('Game System', 'chronicler'),
        __('Game System', 'chronicler'),
        'manage_options',
        'chronicler-sheet-template',
        'chronicler_sheets_render_configurator'
    );
}
add_action('admin_menu', 'chronicler_sheets_admin_menu');

/**
 * The schema-aware editor bundle (#149), mounted over the configurator
 * textarea by progressive enhancement: no bundle file (source checkout that
 * never ran build:admin) or no JS means the plain textarea keeps working —
 * it stays the form transport either way.
 */
function chronicler_sheets_configurator_assets(string $hook_suffix): void {
    // The registration-time capture is primary; the derived name is a net
    // under it, so a refactor that moves the menu registration degrades to
    // the still-correct core-derived hook instead of a silently absent
    // editor (the textarea fallback would mask the breakage).
    $expected = $GLOBALS['chronicler_sheets_configurator_hook']
        ?? (function_exists('get_plugin_page_hookname')
            ? get_plugin_page_hookname('chronicler-sheet-template', 'chronicler')
            : null);
    if ($expected === null || $hook_suffix !== $expected) {
        return;
    }
    $bundle = plugin_dir_path(CHRONICLER_PLUGIN_FILE) . 'admin/dist/chronicler-game-system.js';
    if (!file_exists($bundle)) {
        return;
    }
    wp_enqueue_script(
        'chronicler-game-system',
        plugins_url('admin/dist/chronicler-game-system.js', CHRONICLER_PLUGIN_FILE),
        [],
        CHRONICLER_VERSION,
        true
    );
    // Live validation asks the plugin to run the exact Save-time parse over
    // the unsaved buffer (rest.php's /template/preflight).
    wp_localize_script('chronicler-game-system', 'chroniclerGameSystemBoot', [
        'preflightUrl' => esc_url_raw(rest_url('chronicler/v1/template/preflight')),
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
}
add_action('admin_enqueue_scripts', 'chronicler_sheets_configurator_assets');

function chronicler_sheets_render_configurator(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'chronicler'));
    }

    $notice = '';
    $error = '';
    if (isset($_POST['chronicler_template_json'])) {
        check_admin_referer('chronicler_sheet_template');
        $json = wp_unslash($_POST['chronicler_template_json']);
        $parsed = chronicler_sheets_parse_template($json);
        if (is_wp_error($parsed)) {
            $error = $parsed->get_error_message();
        } else {
            $post_id = (int) get_option('chronicler_active_template', 0);
            // The source goes to the chr_template_config meta, never
            // post_content (#163 — kses mangles `>=` in formulas for users
            // without unfiltered_html; see sheets/template-store.php), and
            // post_content is cleared so meta is the one source of truth.
            $data = [
                'post_type' => 'chr_template',
                'post_status' => 'publish',
                'post_title' => $parsed['system'],
                'post_content' => '',
            ];
            // Same target guard as post-types.php's readers (#173 review): a
            // stale option (partial restore, imported IDs) must not let this
            // branch convert-and-blank a post the plugin doesn't own —
            // anything that isn't a chr_template gets a fresh post instead.
            $existing = $post_id ? get_post($post_id) : null;
            if ($existing && $existing->post_type === 'chr_template') {
                $data['ID'] = $post_id;
                $result = wp_update_post($data, true);
                if (is_wp_error($result)) {
                    $error = $result->get_error_message();
                } elseif (!chronicler_sheets_save_template_source($post_id, $json)) {
                    // The read-back failed: meta still holds the previous
                    // source, so sheets keep rendering the old template —
                    // say so instead of announcing a save that didn't land.
                    $error = __('WordPress could not store the template configuration.', 'chronicler');
                } else {
                    $notice = __('Template saved.', 'chronicler');
                }
            } else {
                $result = wp_insert_post($data, true);
                if (is_wp_error($result)) {
                    $error = $result->get_error_message();
                } elseif (!$result) {
                    $error = __('WordPress could not save the template.', 'chronicler');
                } elseif (!chronicler_sheets_save_template_source($result, $json)) {
                    // Never activate (or keep) a post whose config failed to
                    // store — an active template with an empty source would
                    // degrade every sheet on the site.
                    wp_delete_post($result, true);
                    $error = __('WordPress could not store the template configuration.', 'chronicler');
                } else {
                    // Meta is verified before the option makes the post live,
                    // so no request can resolve an active template whose
                    // source is still empty.
                    update_option('chronicler_active_template', $result);
                    $notice = __('Template saved.', 'chronicler');
                }
            }
        }
    }

    $post_id = (int) get_option('chronicler_active_template', 0);
    $post = $post_id ? get_post($post_id) : null;
    if ($post && $post->post_type !== 'chr_template') {
        // Same guard as post-types.php's readers — and doubly needed here
        // since #163: chronicler_sheets_template_source() migrates
        // post_content into meta as a side effect, which must never touch a
        // foreign post a stale option points at (#173 review).
        $post = null;
    }
    $json = isset($_POST['chronicler_template_json'])
        ? wp_unslash($_POST['chronicler_template_json'])
        : ($post ? chronicler_sheets_template_source($post) : '');
    // The static memo was primed at init (register_property_meta), i.e.
    // pre-save: after a successful save, re-read so the Layout preview shows
    // what was just stored, not the previous request state (#173 review).
    $template = chronicler_sheets_active_template($notice !== '');

    echo '<div class="wrap"><h1>' . esc_html__('Game System', 'chronicler') . '</h1>';
    if ($notice) {
        echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
    }
    if ($error) {
        echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
    }
    echo '<form method="post">';
    wp_nonce_field('chronicler_sheet_template');
    echo '<p>' . esc_html__('Describe the game system in YAML (or JSON). The editor suggests fields as you type, explains them on hover, and checks the template before you save. Press Ctrl-Space in the editor and help will pop up at your cursor. Invalid templates are rejected, never stored.', 'chronicler') . '</p>';
    echo '<textarea name="chronicler_template_json" rows="24" style="width:100%;font-family:monospace" spellcheck="false">'
        . esc_textarea($json) . '</textarea>';
    echo '<p><button class="button button-primary">' . esc_html__('Validate & Save', 'chronicler') . '</button></p>';
    echo '</form>';

    if ($template !== null) {
        echo '<h2>' . esc_html__('Layout preview', 'chronicler') . ' — ' . esc_html($template['system']) . '</h2>';
        foreach ($template['layout'] as $section) {
            echo '<h3>' . esc_html($section['section']) . '</h3><ul>';
            foreach ($section['properties'] as $pid) {
                $p = $template['properties'][$pid];
                echo '<li><code>' . esc_html($pid) . '</code> — ' . esc_html($p['label'])
                    . ' <em>(' . esc_html($p['type']) . ')</em></li>';
            }
            echo '</ul>';
        }
    }
    echo '</div>';
}

// --- Character "NPC" and "Active" meta boxes --------------------------------

function chronicler_sheets_add_meta_boxes(): void {
    add_meta_box('chronicler-npc', 'NPC', 'chronicler_sheets_render_npc_box', 'chr_character', 'side');
    add_meta_box('chronicler-active', 'Active Character', 'chronicler_sheets_render_active_box', 'chr_character', 'side');
    add_meta_box('chronicler-slack-member', 'Slack member', 'chronicler_sheets_render_slack_box', 'chr_character', 'side');
    // The tagline field under the title IS the excerpt; don't show it twice.
    remove_meta_box('postexcerpt', 'chr_character', 'normal');
}
add_action('add_meta_boxes_chr_character', 'chronicler_sheets_add_meta_boxes');

/** The chr_npc flag (#176) — see chronicler_sheets_is_npc() in post-types.php. */
function chronicler_sheets_render_npc_box(WP_Post $post): void {
    wp_nonce_field('chronicler_npc_box', 'chronicler_npc_nonce');
    echo '<label><input type="checkbox" name="chr_npc" value="1" '
        . checked(chronicler_sheets_is_npc($post->ID), true, false) . '> '
        . 'Non-player character</label>';
    echo '<p class="description">An NPC keeps its stats here, but its public page shows only the portrait, name, tagline, and intro — no stat block, no &ldquo;Played by&rdquo;.</p>';
}

/**
 * Saves at priority 7 — ahead of the Active box (9) — so a save that just
 * flagged a character NPC clears chr_active in the same request (the
 * Active handler below re-checks NPC status).
 */
function chronicler_sheets_save_npc_box(int $post_id): void {
    if (
        !isset($_POST['chronicler_npc_nonce'])
        || !wp_verify_nonce($_POST['chronicler_npc_nonce'], 'chronicler_npc_box')
        || !current_user_can('edit_post', $post_id)
    ) {
        return;
    }
    if (isset($_POST['chr_npc'])) {
        update_post_meta($post_id, 'chr_npc', '1');
    } else {
        delete_post_meta($post_id, 'chr_npc');
    }
}
add_action('save_post_chr_character', 'chronicler_sheets_save_npc_box', 7);

// --- Masthead fields: tagline under the title, short intro editor -----------

/**
 * The tagline is the post excerpt — plain text, edited right where it renders:
 * under the character's name. Core's edit_post() saves the `excerpt` field.
 */
function chronicler_sheets_tagline_field(WP_Post $post): void {
    if ($post->post_type !== 'chr_character') {
        return;
    }
    echo '<div class="chr-tagline-field">';
    echo '<label for="chr-tagline"><strong>Tagline</strong></label>';
    echo '<input type="text" id="chr-tagline" name="excerpt" class="widefat" value="' . esc_attr($post->post_excerpt) . '" placeholder="One evocative line under the character\'s name">';
    echo '<p class="description">Shown beside the portrait. The editor below renders between the header and the stat block — vibe, table-facing notes. Long-form history belongs in Bio, below the stat block.</p>';
    echo '</div>';
}
add_action('edit_form_after_title', 'chronicler_sheets_tagline_field');

/** A short table-facing name for logs; empty falls back to the character title. */
function chronicler_sheets_goesby_field(WP_Post $post): void {
    if ($post->post_type !== 'chr_character') {
        return;
    }
    wp_nonce_field('chronicler_goesby_box', 'chronicler_goesby_nonce');
    $value = get_post_meta($post->ID, 'chr_goes_by', true);
    echo '<div class="chr-goesby-field">';
    echo '<label for="chr-goes-by"><strong>Goes by</strong></label>';
    echo '<input type="text" id="chr-goes-by" name="chr_goes_by" class="widefat" value="'
        . esc_attr($value) . '" placeholder="Short table name — e.g. WOLFGANG for Wolfgang Glizzy">';
    echo '<p class="description">The name this character shows as in session logs. Empty uses the full title.</p>';
    echo '</div>';
}
add_action('edit_form_after_title', 'chronicler_sheets_goesby_field');

function chronicler_sheets_save_goesby(int $post_id): void {
    if (
        !isset($_POST['chronicler_goesby_nonce'])
        || !wp_verify_nonce($_POST['chronicler_goesby_nonce'], 'chronicler_goesby_box')
        || !current_user_can('edit_post', $post_id)
    ) {
        return;
    }
    if (isset($_POST['chr_goes_by'])) {
        update_post_meta($post_id, 'chr_goes_by', sanitize_text_field(wp_unslash($_POST['chr_goes_by'])));
    }
}
add_action('save_post_chr_character', 'chronicler_sheets_save_goesby', 9);

/** The masthead intro editor is for a short block, not the memoir. */
function chronicler_sheets_shrink_content_editor(array $settings, string $editor_id): array {
    if (
        $editor_id === 'content'
        && function_exists('get_current_screen')
        && get_current_screen()?->post_type === 'chr_character'
    ) {
        $settings['editor_height'] = 180;
    }
    return $settings;
}
add_filter('wp_editor_settings', 'chronicler_sheets_shrink_content_editor', 10, 2);

function chronicler_sheets_render_active_box(WP_Post $post): void {
    wp_nonce_field('chronicler_active_box', 'chronicler_active_nonce');
    // Disabled rather than hidden for an NPC (#176): the box vanishing when
    // the NPC checkbox goes on would read as a lost setting, and disabled
    // inputs post nothing, so the save handler below clears the flag. The
    // nonce still renders so that clear actually runs.
    if (chronicler_sheets_is_npc($post->ID)) {
        echo '<label><input type="checkbox" disabled> This player\'s active character</label>';
        echo '<p class="description">An NPC isn\'t played, so it can\'t be anyone\'s active character. Uncheck NPC (and save) to re-enable this.</p>';
        return;
    }
    $active = get_post_meta($post->ID, 'chr_active', true) === '1';
    echo '<label><input type="checkbox" name="chr_active" value="1" ' . checked($active, true, false) . '> '
        . 'This player\'s active character</label>';
}

function chronicler_sheets_save_active_box(int $post_id): void {
    if (
        !isset($_POST['chronicler_active_nonce'])
        || !wp_verify_nonce($_POST['chronicler_active_nonce'], 'chronicler_active_box')
        || !current_user_can('edit_post', $post_id)
    ) {
        return;
    }
    // NPCs are never an active character (#176). The NPC box saved at
    // priority 7, so a save that just checked NPC lands here with the flag
    // already set and drops chr_active even though the (pre-NPC) form still
    // posted it checked.
    if (chronicler_sheets_is_npc($post_id)) {
        delete_post_meta($post_id, 'chr_active');
        return;
    }
    if (isset($_POST['chr_active'])) {
        update_post_meta($post_id, 'chr_active', '1');
    } else {
        delete_post_meta($post_id, 'chr_active');
    }
}
add_action('save_post_chr_character', 'chronicler_sheets_save_active_box', 9);

/**
 * The Slack member box: which Slack account drives this character. This is
 * the ONE self-service surface for identity linking — a player reaches it
 * only for their own sheet, because chr_character's map_meta_cap wiring
 * limits editing to the author (plus edit_others_chr_characters for GMs).
 * That access IS the authorization; the bot only tells a player their id
 * and links them here (Bot\Commands::link()).
 *
 * Everyone gets the plain id field, so the flow matches what the bot hands
 * out (paste this string). Anyone who can edit OTHER people's characters
 * also gets the workspace picker, which is a GM/admin convenience — and
 * deliberately not offered to players, for whom browsing the roster of
 * Slack accounts to attach is not a thing to encourage.
 */
function chronicler_sheets_render_slack_box(WP_Post $post): void {
    wp_nonce_field('chronicler_slack_box', 'chronicler_slack_nonce');
    $value = (string) get_post_meta($post->ID, 'chronicler_slack_user_id', true);
    $directory = current_user_can('edit_others_chr_characters')
        ? chronicler_sheets_slack_user_directory()
        : null;

    if (is_array($directory)) {
        if ($value !== '' && !isset($directory[$value])) {
            $directory[$value] = $value;
        }
        asort($directory, SORT_FLAG_CASE | SORT_STRING);
        echo '<select name="chronicler_slack_user_id" class="widefat">';
        echo '<option value="">— not linked —</option>';
        foreach ($directory as $id => $name) {
            echo '<option value="' . esc_attr($id) . '" ' . selected($value, $id, false) . '>'
                . esc_html($name) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input type="text" name="chronicler_slack_user_id" class="widefat" value="'
            . esc_attr($value) . '" placeholder="U0123ABCDEF" spellcheck="false">';
    }

    echo '<p class="description">In Slack, run <code>/game link '
        . esc_html(chronicler_sheets_display_name_for(
            (string) get_post_meta($post->ID, 'chr_goes_by', true),
            get_the_title($post)
        ))
        . '</code> — the bot replies with the id to paste here. Saving it lets that Slack account use this character\'s stats and dice.</p>';
}

/**
 * One Slack id may drive only one character: a second claim would make
 * chronicler_sheets_character_for_slack_id() arbitrary. Returns the
 * conflicting character id, or null when the id is free.
 */
function chronicler_sheets_slack_id_conflict(string $slack_id, int $post_id): ?int {
    if ($slack_id === '') {
        return null;
    }
    $others = get_posts([
        'post_type' => 'chr_character',
        'post_status' => 'any',
        'meta_key' => 'chronicler_slack_user_id',
        'meta_value' => $slack_id,
        'exclude' => [$post_id],
        'numberposts' => 1,
        'fields' => 'ids',
    ]);
    return $others ? (int) $others[0] : null;
}

/**
 * Saves at priority 9, like the Active box. Refuses rather than steals when
 * the id is already claimed: silently moving a link would let one player
 * capture another's Slack commands, and the admin notice names the sheet to
 * clear first.
 */
function chronicler_sheets_save_slack_box(int $post_id): void {
    if (
        !isset($_POST['chronicler_slack_nonce'])
        || !wp_verify_nonce($_POST['chronicler_slack_nonce'], 'chronicler_slack_box')
        || !current_user_can('edit_post', $post_id)
    ) {
        return;
    }
    $slack_id = sanitize_text_field(wp_unslash($_POST['chronicler_slack_user_id'] ?? ''));
    if ($slack_id === '') {
        delete_post_meta($post_id, 'chronicler_slack_user_id');
        return;
    }
    $conflict = chronicler_sheets_slack_id_conflict($slack_id, $post_id);
    if ($conflict !== null) {
        set_transient('chronicler_slack_link_conflict_' . get_current_user_id(), $conflict, 60);
        return;
    }
    update_post_meta($post_id, 'chronicler_slack_user_id', $slack_id);
}
add_action('save_post_chr_character', 'chronicler_sheets_save_slack_box', 9);

/** Surfaces the refusal above on the next sheet load. */
function chronicler_sheets_slack_conflict_notice(): void {
    $key = 'chronicler_slack_link_conflict_' . get_current_user_id();
    $conflict = get_transient($key);
    if (!$conflict) {
        return;
    }
    delete_transient($key);
    echo '<div class="notice notice-error"><p>That Slack member is already linked to <strong>'
        . esc_html(get_the_title((int) $conflict))
        . '</strong>, so this sheet was left unlinked. Clear it there first, then save this one.</p></div>';
}
add_action('admin_notices', 'chronicler_sheets_slack_conflict_notice');

// --- Slack id on the user profile -------------------------------------------

/**
 * [slackId => display name] for the whole workspace, from a cached users.list.
 * Returns null when no token is configured or Slack is unreachable, so callers
 * degrade to a free-text id input. Cached an hour — new members are rare.
 */
function chronicler_sheets_slack_user_directory(): ?array {
    $cached = get_transient('chronicler_slack_user_directory');
    if (is_array($cached)) {
        return $cached;
    }
    $members = [];
    $cursor = '';
    try {
        do {
            $args = ['limit' => 200];
            if ($cursor !== '') {
                $args['cursor'] = $cursor;
            }
            $body = (new \Chronicler\Slack\Client())->call('users.list', $args);
            foreach (($body['members'] ?? []) as $member) {
                $members[] = $member;
            }
            $cursor = $body['response_metadata']['next_cursor'] ?? '';
        } while (is_string($cursor) && $cursor !== '');
    } catch (\Throwable $e) {
        return null;
    }
    $directory = chronicler_sheets_parse_slack_users($members);
    set_transient('chronicler_slack_user_directory', $directory, HOUR_IN_SECONDS);
    return $directory;
}

function chronicler_sheets_profile_field(WP_User $user): void {
    if (!current_user_can('manage_options')) {
        return; // Players cannot reassign their own mapping.
    }
    $value = get_user_meta($user->ID, 'chronicler_slack_user_id', true);
    $directory = chronicler_sheets_slack_user_directory();
    echo '<h2>Chronicler</h2><table class="form-table"><tr>';
    echo '<th><label for="chronicler_slack_user_id">Slack member</label></th><td>';
    if (is_array($directory)) {
        // Keep a saved id selectable even if it's missing from the fetched list.
        if ($value !== '' && !isset($directory[$value])) {
            $directory[$value] = $value;
        }
        asort($directory, SORT_FLAG_CASE | SORT_STRING);
        echo '<select id="chronicler_slack_user_id" name="chronicler_slack_user_id" class="regular-text">';
        echo '<option value="">— none —</option>';
        foreach ($directory as $id => $name) {
            echo '<option value="' . esc_attr($id) . '" ' . selected($value, $id, false) . '>'
                . esc_html($name) . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input type="text" id="chronicler_slack_user_id" name="chronicler_slack_user_id" value="'
            . esc_attr($value) . '" class="regular-text" placeholder="U0123ABCDEF">';
    }
    echo '<p class="description">Links this account to a Slack member.</p></td>';
    echo '</tr></table>';
}
add_action('show_user_profile', 'chronicler_sheets_profile_field');
add_action('edit_user_profile', 'chronicler_sheets_profile_field');

function chronicler_sheets_save_profile_field(int $user_id): void {
    if (!current_user_can('manage_options') || !isset($_POST['chronicler_slack_user_id'])) {
        return;
    }
    update_user_meta($user_id, 'chronicler_slack_user_id', sanitize_text_field(wp_unslash($_POST['chronicler_slack_user_id'])));
}
add_action('personal_options_update', 'chronicler_sheets_save_profile_field');
add_action('edit_user_profile_update', 'chronicler_sheets_save_profile_field');

// --- Stat Block meta box ----------------------------------------------------

// The stat grid needs the wide main column, so it registers 'normal'/'high'
// (top of the main column), never 'side'. WP gotcha: once a user drags any
// meta box, do_meta_boxes() replays their saved meta-box-order_chr_character
// on every load, and that stored order overrides this context. So an admin
// who once dragged this box into the side rail keeps seeing it there even
// though 'normal' is the default. The non-destructive remedy is to drag it
// back (WP persists the new position); a fresh user always gets the main
// column. We deliberately do NOT force-reset the stored order from here —
// that would clobber every user's whole layout preference.
function chronicler_sheets_add_stat_block_box(): void {
    add_meta_box('chronicler-stat-block', 'Stat Block', 'chronicler_sheets_render_stat_block_box', 'chr_character', 'normal', 'high');
}
add_action('add_meta_boxes_chr_character', 'chronicler_sheets_add_stat_block_box');

function chronicler_sheets_render_stat_block_box(WP_Post $post): void {
    $template = chronicler_sheets_template_for_character($post->ID);
    if ($template === null) {
        echo '<p>No sheet template is configured yet.</p>';
        return;
    }
    // GM-only fields (e.g. GM Notes) are editable only by a game master —
    // someone who can edit characters they don't own. An owning player editing
    // their own sheet never sees them. Unrendered fields emit no
    // chr_stat_present flag, so the save handler leaves their values untouched.
    // owner_only needs no filter here or in the save handler: reaching this
    // screen requires edit_post on the character, so every viewer is already
    // inside that audience.
    $is_gm = current_user_can('edit_others_chr_characters');
    wp_nonce_field('chronicler_stat_block', 'chronicler_stat_block_nonce');
    echo '<div class="chr-stat-block">';
    foreach (chronicler_sheets_layout_sections($template) as $section) {
        $properties = array_values(array_filter(
            $section['properties'],
            function ($id) use ($template, $is_gm, $post) {
                $property = $template['properties'][$id];
                if (!$is_gm && chronicler_sheets_is_gm_only($property)) {
                    return false;
                }
                // Opinions (#183) exist on NPC pages only — a PC's edit
                // screen has no sets to edit, so the property (and a section
                // it would leave empty) drops here like the front end.
                if ($property['type'] === 'opinions' && !chronicler_sheets_is_npc($post->ID)) {
                    return false;
                }
                return true;
            }
        ));
        if ($properties === []) {
            continue; // a section emptied by the filters above leaves no header
        }
        echo '<fieldset class="chr-stat-section">';
        echo '<legend class="chr-stat-section__title">' . esc_html($section['section']) . '</legend>';
        echo '<div class="chr-stat-section__grid">';
        foreach ($properties as $id) {
            $property = $template['properties'][$id];
            $value = chronicler_sheets_get_value($post->ID, $property);
            // Derived properties are computed on read (#88): show the value
            // and its formula, render no input and no present-flag, so the
            // save handler never touches them.
            if (isset($property['derived'])) {
                echo '<div class="chr-stat chr-stat--' . esc_attr($property['type']) . ' chr-stat--derived">';
                echo '<span class="chr-stat__label">' . esc_html($property['label']) . '</span>';
                echo '<strong>' . esc_html(chronicler_sheets_display_value($property, $value)) . '</strong>';
                echo '<p class="description">Computed: <code>' . esc_html($property['derived']) . '</code></p>';
                echo '</div>';
                continue;
            }
            // Opinions (#183): one rating + notes group per PC. The sets and
            // their editability come from the same visibility authority as
            // the front end; a set the viewer can't write renders disabled
            // (disabled inputs post nothing, so the save leaves it alone —
            // and the save re-checks the per-PC gate regardless).
            if ($property['type'] === 'opinions') {
                $sets = chronicler_sheets_visible_opinion_sets($post->ID, $property);
                if ($sets === null || $sets === []) {
                    continue;
                }
                echo '<div class="chr-stat chr-stat--opinions">';
                echo '<span class="chr-stat__label">' . esc_html($property['label']) . '</span>';
                echo '<input type="hidden" name="chr_stat_present[' . esc_attr($id) . ']" value="1">';
                foreach ($sets as $set) {
                    $row = 'chr_stat[' . esc_attr($id) . '][' . (int) $set['pc'] . ']';
                    $disabled = $set['canEdit'] ? '' : ' disabled';
                    echo '<fieldset class="chr-opinion-row">';
                    echo '<legend>' . esc_html($set['name'] . '’s ' . $property['label']) . '</legend>';
                    echo '<label>Rating <input type="number" class="chr-input--number" name="' . $row . '[rating]" value="'
                        . (int) $set['value']['rating'] . '" min="0" max="' . (int) $property['length'] . '"' . $disabled . '>'
                        . ' <span class="description">of ' . (int) $property['length'] . '</span></label>';
                    echo '<textarea name="' . $row . '[notes]" rows="2" class="widefat" placeholder="Notes"' . $disabled . '>'
                        . esc_textarea($set['value']['notes']) . '</textarea>';
                    echo '</fieldset>';
                }
                echo '<p class="description">One private set per player character — each player\'s personal notebook, written from the page itself and visible only to them and the GM. Editing here is the GM override.</p>';
                echo '</div>';
                continue;
            }
            echo '<div class="chr-stat chr-stat--' . esc_attr($property['type']) . '">';
            echo '<span class="chr-stat__label">' . esc_html($property['label']) . '</span>';
            echo '<input type="hidden" name="chr_stat_present[' . esc_attr($id) . ']" value="1">';
            chronicler_sheets_admin_field('chr_stat[' . $id . ']', $property, $value, 'top');
            if (isset($property['detail'])) {
                $override = (string) get_post_meta($post->ID, 'chr_detail_' . $id, true);
                echo '<input type="text" class="chr-detail-input" name="chr_detail[' . esc_attr($id) . ']" value="'
                    . esc_attr($override) . '" placeholder="' . esc_attr($property['detail']) . '">';
                echo '<p class="description">What it\'s used for — blank keeps the system default. If you have custom moves, list them with a comma between each.</p>';
            }
            echo '</div>';
        }
        echo '</div></fieldset>';
    }
    echo '</div>';
}

/**
 * One admin form control for a scalar or list property. $context is 'top'
 * for direct properties and 'row' inside list entries — top-level longtext
 * gets the rich-text editor, entry longtext stays a compact textarea
 * (TinyMCE cannot initialize inside cloned <template> rows).
 */
function chronicler_sheets_admin_field(string $name, array $property, $value, string $context = 'top'): void {
    $name_attr = esc_attr($name);
    switch ($property['type']) {
        case 'number':
        case 'track':
        case 'counter':
            [$min, $max] = chronicler_sheets_bounds($property);
            echo '<input type="number" class="chr-input--number" name="' . $name_attr . '" value="' . esc_attr((string) (int) $value) . '"'
                . ($min !== null ? ' min="' . (int) $min . '"' : '')
                . ($max !== null ? ' max="' . (int) $max . '"' : '') . '>';
            if ($property['type'] === 'track') {
                echo ' <span class="description">of ' . (int) $property['length'] . '</span>';
            }
            break;
        case 'toggle':
            // Hidden 0 first: unchecked submits '0', checked submits '0' then '1' (last wins).
            echo '<input type="hidden" name="' . $name_attr . '" value="0">';
            echo '<input type="checkbox" name="' . $name_attr . '" value="1"' . checked((bool) $value, true, false) . '>';
            break;
        case 'select':
            echo '<select name="' . $name_attr . '">';
            foreach ($property['options'] as $opt) {
                echo '<option value="' . esc_attr($opt['id']) . '"' . selected($value, $opt['id'], false) . '>' . esc_html($opt['label']) . '</option>';
            }
            echo '</select>';
            break;
        case 'checklist':
            echo '<div class="chr-checklist-grid">';
            foreach ($property['options'] as $opt) {
                $on = in_array($opt['id'], (array) $value, true);
                echo '<label><input type="checkbox" name="' . $name_attr . '[]" value="' . esc_attr($opt['id']) . '"' . checked($on, true, false) . '> ' . esc_html($opt['label']) . '</label>';
            }
            echo '</div>';
            break;
        case 'list':
            chronicler_sheets_admin_list_rows($name, $property, (array) $value);
            break;
        case 'dice':
            // Player-written dice notation (2026-07-25). Writes are lenient —
            // it stores as text like the move text beside it — so the row
            // renders a parse-error flag on reload instead of failing the save
            // (see chronicler_sheets_admin_list_row).
            echo '<input type="text" name="' . $name_attr . '" value="' . esc_attr((string) $value)
                . '" class="regular-text code chr-input--dice" placeholder="2d6 + {cool}" spellcheck="false">';
            break;
        case 'longtext':
            if ($context === 'top') {
                // Editor ids allow [a-z0-9_] only; the bracketed POST name
                // travels via textarea_name. Known WP caveat: TinyMCE breaks
                // if the meta box is drag-reordered until the page reloads.
                $editor_id = preg_replace('/[^a-z0-9_]/', '_', $name);
                // Custom "Insert Image" button (bio-image.js), not WP's own
                // media_buttons flow: core's "Add Media" bakes width/height/
                // alignment into the inserted <img>, which is exactly the
                // hand-set-width problem issue #68 reports. This button
                // inserts a bare <img>; sheet.css sizes it automatically.
                echo '<p><button type="button" class="button chr-insert-image" data-editor-id="'
                    . esc_attr($editor_id) . '">Insert Image</button></p>';
                wp_editor((string) $value, $editor_id, [
                    'textarea_name' => $name,
                    'textarea_rows' => 6,
                    'media_buttons' => false,
                    'teeny' => true,
                    'quicktags' => true,
                ]);
            } else {
                echo '<textarea name="' . $name_attr . '" rows="3" class="widefat">' . esc_textarea((string) $value) . '</textarea>';
            }
            break;
        default: // text
            echo '<input type="text" name="' . $name_attr . '" value="' . esc_attr((string) $value) . '" class="regular-text chr-input--text">';
    }
}

/** Repeatable rows for a list property, plus a <template> row for stat-block.js. */
function chronicler_sheets_admin_list_rows(string $name, array $property, array $entries): void {
    echo '<div class="chr-list-rows" data-list-name="' . esc_attr($name) . '">';
    foreach ($entries as $i => $entry) {
        chronicler_sheets_admin_list_row($name, $property, (int) $i, $entry);
    }
    echo '</div>';
    echo '<template class="chr-list-template">';
    chronicler_sheets_admin_list_row($name, $property, -1, []);
    echo '</template>';
    echo '<button type="button" class="button chr-list-add">Add '
        . esc_html($property['entry_label'] ?? $property['label'] . ' entry') . '</button>';
}

function chronicler_sheets_admin_list_row(string $name, array $property, int $index, array $entry): void {
    // Index -1 marks the cloneable template row; stat-block.js rewrites __i__.
    $key = $index === -1 ? '__i__' : (string) $index;
    echo '<fieldset class="chr-list-row">';
    foreach ($property['fields'] as $field) {
        $value = $entry[$field['id']] ?? chronicler_sheets_default_value($field);
        // data-field lets stat-block.js find the toggle that gates "when"
        // fields; gated fields start hidden when their expression is false.
        // Only a bare sibling-toggle reference gets live show/hide wiring —
        // richer expressions re-evaluate server-side on save/reload.
        $attrs = ' data-field="' . esc_attr($field['id']) . '"';
        if (isset($field['when'])) {
            foreach ($property['fields'] as $sibling) {
                if ($sibling['id'] === $field['when'] && $sibling['type'] === 'toggle') {
                    $attrs .= ' data-when="' . esc_attr($field['when']) . '"';
                    break;
                }
            }
            if (!chronicler_sheets_when_holds($property, $field, $entry)) {
                $attrs .= ' hidden';
            }
        }
        echo '<label class="chr-field chr-field--' . esc_attr($field['type']) . '"' . $attrs . '>'
            . '<span class="chr-field__name">' . esc_html($field['label']) . '</span>';
        chronicler_sheets_admin_field($name . '[' . $key . '][' . $field['id'] . ']', $field, $value, 'row');
        // A dice value that doesn't parse saved anyway (lenient write — a
        // strict save_post rejection would silently discard the whole list),
        // so the flag here is the error surface: visible, with the parser's
        // own message, and the string still in the input to fix.
        if ($field['type'] === 'dice' && trim((string) $value) !== '') {
            $parsed_dice = chronicler_sheets_parse_dice((string) $value);
            if (is_wp_error($parsed_dice)) {
                echo '<span class="chr-dice-error" style="color:#b32d2e;font-size:12px;">'
                    . esc_html($parsed_dice->get_error_message()) . ' This entry offers no roll until it parses.</span>';
            }
        }
        echo '</label>';
    }
    echo '<button type="button" class="button-link-delete chr-list-remove" aria-label="Remove entry">Remove</button></fieldset>';
}

function chronicler_sheets_save_stat_block(int $post_id): void {
    if (
        !isset($_POST['chronicler_stat_block_nonce'])
        || !wp_verify_nonce($_POST['chronicler_stat_block_nonce'], 'chronicler_stat_block')
        || !current_user_can('edit_post', $post_id)
        || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)
    ) {
        return;
    }
    $template = chronicler_sheets_template_for_character($post_id);
    if ($template === null) {
        return;
    }
    $submitted = isset($_POST['chr_stat']) && is_array($_POST['chr_stat']) ? wp_unslash($_POST['chr_stat']) : [];
    $present = isset($_POST['chr_stat_present']) && is_array($_POST['chr_stat_present']) ? $_POST['chr_stat_present'] : [];
    foreach ($template['properties'] as $id => $property) {
        if (!isset($present[$id])) {
            continue; // field wasn't rendered (e.g. quick edit) — leave value alone
        }
        // The present-flag is client-supplied and the box never renders GM-only
        // fields for a non-GM, so a forged flag is the only way one arrives here.
        // Enforce the same audience gate on save that the box does on render.
        if (chronicler_sheets_is_gm_only($property) && !current_user_can('edit_others_chr_characters')) {
            continue;
        }
        // Opinions (#183) bypass the whole-property path: each posted set is
        // written on its own, behind the same per-PC edit_post gate the REST
        // route enforces — the box renders un-writable sets disabled, but a
        // forged row must not get past that. Unknown/NPC pc ids are dropped;
        // non-NPC characters have no sets to write at all.
        if ($property['type'] === 'opinions') {
            $raw = $submitted[$id] ?? null;
            if (!is_array($raw) || !chronicler_sheets_is_npc($post_id)) {
                continue;
            }
            $pcs = array_flip(chronicler_sheets_player_characters());
            foreach ($raw as $pc_id => $set) {
                $pc_id = (int) $pc_id;
                if (!isset($pcs[$pc_id]) || !is_array($set) || !current_user_can('edit_post', $pc_id)) {
                    continue;
                }
                chronicler_sheets_set_opinion($post_id, $property, $pc_id, [
                    'rating' => (int) ($set['rating'] ?? 0),
                    'notes' => sanitize_textarea_field((string) ($set['notes'] ?? '')),
                ]);
            }
            continue;
        }
        $raw = $submitted[$id] ?? ($property['type'] === 'checklist' || $property['type'] === 'list' ? [] : null);
        if ($raw === null) {
            continue;
        }
        if ($property['type'] === 'list' && is_array($raw)) {
            $raw = array_values($raw); // discard the sparse row indexes the form produces
        }
        if (is_string($raw)) {
            $raw = $property['type'] === 'longtext' ? wp_kses_post($raw) : sanitize_text_field($raw);
        }
        if ($property['type'] === 'list' && is_array($raw)) {
            foreach ($raw as &$entry) {
                if (!is_array($entry)) {
                    continue;
                }
                foreach ($entry as $fid => $fval) {
                    if (is_string($fval)) {
                        $entry[$fid] = sanitize_textarea_field($fval);
                    }
                }
            }
            unset($entry);
        }
        $result = chronicler_sheets_apply_op($property, chronicler_sheets_get_value($post_id, $property), 'set', $raw);
        if (!is_wp_error($result)) {
            // Form controls constrain inputs, so rejections here are hand-crafted
            // requests; skipping them (keeping the old value) is the safe answer.
            chronicler_sheets_set_value($post_id, $property, $result);
        }
        if (isset($property['detail'])) {
            $override = isset($_POST['chr_detail'][$id])
                ? sanitize_text_field(wp_unslash($_POST['chr_detail'][$id]))
                : '';
            // Blank — or retyping the default — clears the override, so
            // template edits keep flowing through to unmodified characters.
            if ($override === '' || $override === $property['detail']) {
                delete_post_meta($post_id, 'chr_detail_' . $id);
            } else {
                update_post_meta($post_id, 'chr_detail_' . $id, $override);
            }
        }
    }
}
add_action('save_post_chr_character', 'chronicler_sheets_save_stat_block', 8);

function chronicler_sheets_stat_block_assets(string $hook): void {
    if ($hook !== 'post.php' && $hook !== 'post-new.php') {
        return;
    }
    if (get_current_screen()?->post_type !== 'chr_character') {
        return;
    }
    wp_enqueue_script(
        'chronicler-stat-block',
        plugins_url('sheets/stat-block.js', CHRONICLER_PLUGIN_FILE),
        [],
        CHRONICLER_VERSION,
        true
    );
    wp_enqueue_style(
        'chronicler-stat-block',
        plugins_url('sheets/stat-block.css', CHRONICLER_PLUGIN_FILE),
        [],
        CHRONICLER_VERSION
    );
    // wp_enqueue_media() registers wp.media (the upload/library modal) —
    // core only loads it automatically for the default "Add Media" button,
    // which the top-level longtext fields turn off (media_buttons: false)
    // above, so bio-image.js's own button needs it enqueued explicitly.
    wp_enqueue_media();
    wp_enqueue_script(
        'chronicler-bio-image',
        plugins_url('sheets/bio-image.js', CHRONICLER_PLUGIN_FILE),
        ['media-editor'],
        CHRONICLER_VERSION,
        true
    );
}
add_action('admin_enqueue_scripts', 'chronicler_sheets_stat_block_assets');
