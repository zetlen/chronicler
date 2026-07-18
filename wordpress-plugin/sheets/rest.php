<?php
// Same-origin browser write surface. Cookie + X-WP-Nonce auth is fine here:
// shared hosts that strip the Authorization header don't affect REST nonces.
//
// These routes are part of the documented chronicler/v1 contract
// (openapi.yaml, docs/rest-api.md) with per-route permission callbacks —
// unlike the Routes::definitions() core table, which is uniformly
// chronicler_compose-gated. lib/wordpress/openapi.test.ts parses the
// register_rest_route calls below to keep openapi.yaml honest, so keep
// their formatting (quoted namespace + route, 'methods' on the next line).

if (!defined('ABSPATH')) {
    exit;
}

function chronicler_sheets_register_routes(): void {
    register_rest_route('chronicler/v1', '/characters/(?P<id>\d+)/sheet', [
        'methods' => 'GET',
        'callback' => 'chronicler_sheets_rest_get_sheet',
        'permission_callback' => '__return_true', // sheets are public-read
        'args' => ['id' => ['validate_callback' => 'chronicler_sheets_validate_numeric']],
    ]);
    register_rest_route('chronicler/v1', '/characters/(?P<id>\d+)/properties/(?P<prop>[a-z][a-z0-9_]*)', [
        'methods' => 'POST',
        'callback' => 'chronicler_sheets_rest_update_property',
        'permission_callback' => 'chronicler_sheets_rest_can_edit',
        'args' => ['id' => ['validate_callback' => 'chronicler_sheets_validate_numeric']],
    ]);
    register_rest_route('chronicler/v1', '/characters/names', [
        'methods' => 'GET',
        'callback' => 'chronicler_sheets_rest_character_names',
        'permission_callback' => 'chronicler_sheets_rest_can_read_names',
    ]);
    register_rest_route('chronicler/v1', '/template/preflight', [
        'methods' => 'POST',
        'callback' => 'chronicler_sheets_rest_template_preflight',
        'permission_callback' => 'chronicler_sheets_rest_can_manage',
    ]);
}
add_action('rest_api_init', 'chronicler_sheets_register_routes');

/** Template preflight is site configuration — same gate as the Save form. */
function chronicler_sheets_rest_can_manage(): bool {
    return current_user_can('manage_options');
}

/**
 * Live validation for the Game System editor (#149): run the exact Save-time
 * parse over an unsaved buffer so authors get the authoritative verdict —
 * formula syntax, cycles, and relational rules included — while typing.
 * Read-only: nothing is stored, and Save still re-validates the POSTed form.
 */
function chronicler_sheets_rest_template_preflight(WP_REST_Request $request): array {
    $source = $request->get_param('source');
    if (!is_string($source) || trim($source) === '') {
        return ['valid' => false, 'code' => 'chronicler_empty', 'message' => 'The template is empty.'];
    }
    $parsed = chronicler_sheets_parse_template($source);
    if (is_wp_error($parsed)) {
        return [
            'valid' => false,
            'code' => $parsed->get_error_code(),
            'message' => $parsed->get_error_message(),
        ];
    }
    return ['valid' => true, 'system' => $parsed['system']];
}

/**
 * WP_REST_Request::has_valid_params() invokes validate_callback with
 * ($value, $request, $param) — three args. is_numeric() takes exactly one,
 * so passing it directly is a PHP 8 ArgumentCountError fatal on every
 * request that hits a validated route. This wrapper declares one parameter;
 * PHP silently ignores the extra arguments passed by core's callback invocation.
 */
function chronicler_sheets_validate_numeric($value): bool {
    return is_numeric($value);
}

function chronicler_sheets_rest_can_edit(WP_REST_Request $request): bool {
    return current_user_can('edit_post', (int) $request['id']);
}

/** The character post + its parsed template, or a WP_Error with status. */
function chronicler_sheets_rest_context(int $post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'chr_character') {
        return new WP_Error('chronicler_not_found', 'No such character.', ['status' => 404]);
    }
    $template = chronicler_sheets_template_for_character($post_id);
    if ($template === null) {
        return new WP_Error('chronicler_no_template', 'No sheet template is configured.', ['status' => 404]);
    }
    return [$post, $template];
}

function chronicler_sheets_rest_get_sheet(WP_REST_Request $request) {
    $context = chronicler_sheets_rest_context((int) $request['id']);
    if (is_wp_error($context)) {
        return $context;
    }
    [$post, $template] = $context;
    // The endpoint is public-read, but only for published characters: a draft,
    // pending, private, or trashed sheet is visible solely to someone who can
    // edit it. Everyone else gets the same 404 as a missing character, so an
    // unpublished sheet's existence stays hidden.
    if ($post->post_status !== 'publish' && !current_user_can('edit_post', $post->ID)) {
        return new WP_Error('chronicler_not_found', 'No such character.', ['status' => 404]);
    }
    // A password-protected sheet (#158) is gated the same way: the caller
    // either presents core's password cookie (REST callers normally won't) or
    // can edit the character. Same 404, so existence stays hidden here too.
    if (post_password_required($post) && !current_user_can('edit_post', $post->ID)) {
        return new WP_Error('chronicler_not_found', 'No such character.', ['status' => 404]);
    }
    // GM-only properties are withheld from callers who can't edit characters
    // they don't own — the game-master capability, mirroring the front-end
    // render. Owner-only properties are withheld from callers who can't edit
    // THIS character — everyone but the author and game masters. A caller
    // outside an audience sees neither the value nor its id in the layout,
    // so the property's existence stays hidden. Only a GM passes both gates
    // and gets the sheet whole; the author keeps their owner-only properties
    // but still loses gm_only ones.
    $is_gm = current_user_can('edit_others_chr_characters');
    $can_edit = current_user_can('edit_post', $post->ID);
    $properties = [];
    foreach ($template['properties'] as $id => $property) {
        if (!$is_gm && chronicler_sheets_is_gm_only($property)) {
            continue;
        }
        if (!$can_edit && chronicler_sheets_is_owner_only($property)) {
            continue;
        }
        $value = chronicler_sheets_get_value($post->ID, $property);
        $properties[] = $property + [
            'value' => $value,
            'display' => chronicler_sheets_display_value($property, $value),
        ];
    }
    return [
        'characterId' => $post->ID,
        'title' => get_the_title($post),
        'canEdit' => $can_edit,
        'system' => $template['system'],
        'layout' => chronicler_sheets_visible_layout($template, $is_gm, $can_edit),
        'properties' => $properties,
    ];
}

function chronicler_sheets_rest_update_property(WP_REST_Request $request) {
    $context = chronicler_sheets_rest_context((int) $request['id']);
    if (is_wp_error($context)) {
        return $context;
    }
    [$post, $template] = $context;
    $prop_id = (string) $request['prop'];
    if (!isset($template['properties'][$prop_id])) {
        return new WP_Error('chronicler_no_property', "The sheet has no \"$prop_id\" property.", ['status' => 404]);
    }
    $property = $template['properties'][$prop_id];

    // GM-only properties are never player-writable. The read surface already
    // withholds them from non-GMs (chronicler_sheets_rest_get_sheet); the write
    // surface must refuse them too, BEFORE reading or echoing the value — a
    // no-op op would otherwise reflect the current value back. schema.php also
    // forbids a gm_only property from being `live` at all, so this is the
    // defense-in-depth backstop.
    if (chronicler_sheets_is_gm_only($property) && !current_user_can('edit_others_chr_characters')) {
        return new WP_Error(
            'chronicler_forbidden',
            $property['label'] . ' is game-master only.',
            ['status' => 403]
        );
    }

    // Owner-only properties get the same backstop. The route's
    // permission_callback (edit_post on this character) already IS the
    // owner_only audience, so this refusal is unreachable through the
    // registered route today — it exists so the audience holds even if that
    // callback is ever loosened or the handler gains another caller, with
    // the same value-reflection risk as gm_only above.
    if (chronicler_sheets_is_owner_only($property) && !current_user_can('edit_post', $post->ID)) {
        return new WP_Error(
            'chronicler_forbidden',
            $property['label'] . " is private to this character's author.",
            ['status' => 403]
        );
    }

    // Derived values are compute-on-read (#88): there is nothing to set.
    if (isset($property['derived'])) {
        return new WP_Error(
            'chronicler_derived',
            $property['label'] . ' is computed from a formula; change the stats it derives from instead.',
            ['status' => 403]
        );
    }

    if (!chronicler_sheets_is_live($property)) {
        return new WP_Error(
            'chronicler_not_live',
            $property['label'] . ' changes on level-up — edit it on the character page in wp-admin.',
            ['status' => 403]
        );
    }

    $op = $request->get_param('op');
    $value = $request->get_param('value');
    if (!is_string($op)) {
        return new WP_Error('chronicler_bad_request', 'Body must include an "op".', ['status' => 400]);
    }
    // Sanitize free text at the boundary; typed values are validated by apply_op.
    if (is_string($value)) {
        $value = $property['type'] === 'longtext' ? wp_kses_post($value) : sanitize_text_field($value);
    }

    $current = chronicler_sheets_get_value($post->ID, $property);
    $result = chronicler_sheets_apply_op($property, $current, $op, $value);
    if (is_wp_error($result)) {
        $result->add_data(['status' => 400]);
        return $result;
    }
    chronicler_sheets_set_value($post->ID, $property, $result);
    // Changes ride back for the play surfaces to reconcile: #88 formula-
    // derived values are recomputed fresh (compute-on-read; sent whole,
    // since any of them may depend on what just changed).
    $derived = chronicler_sheets_derived_echo($post->ID, $template);
    return [
        'prop' => $prop_id,
        'value' => $result,
        'display' => chronicler_sheets_display_value($property, $result),
        'derived' => $derived,
    ];
}

/** Only session editors (the GM) read the Slack-id → character name map. */
function chronicler_sheets_rest_can_read_names(): bool {
    return current_user_can('edit_others_chr_characters');
}

/**
 * Slack member id → the mapped player's character log name (goes-by, else
 * title). Every WordPress account carrying a chronicler_slack_user_id whose
 * player has a resolvable character contributes one entry.
 */
function chronicler_sheets_rest_character_names(WP_REST_Request $request): object {
    $map = [];
    $user_ids = get_users([
        'meta_key' => 'chronicler_slack_user_id',
        'meta_compare' => 'EXISTS',
        'fields' => 'ID',
    ]);
    foreach ($user_ids as $uid) {
        $slack_id = get_user_meta((int) $uid, 'chronicler_slack_user_id', true);
        if (!is_string($slack_id) || $slack_id === '') {
            continue;
        }
        $character = chronicler_sheets_character_for_slack_id($slack_id);
        if (!$character) {
            continue;
        }
        $goes_by = (string) get_post_meta($character->ID, 'chr_goes_by', true);
        $map[$slack_id] = chronicler_sheets_display_name_for($goes_by, get_the_title($character));
    }
    return (object) $map;
}
