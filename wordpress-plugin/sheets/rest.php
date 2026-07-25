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
    register_rest_route('chronicler/v1', '/characters/(?P<id>\d+)/opinions/(?P<prop>[a-z][a-z0-9_]*)', [
        'methods' => 'POST',
        'callback' => 'chronicler_sheets_rest_update_opinion',
        'permission_callback' => 'chronicler_sheets_rest_can_edit_opinion',
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

/**
 * The opinions write gate (#183): edit_post on the PLAYER CHARACTER whose
 * set is being written — its owning player or a GM — not on the target
 * character. That inversion is the whole feature: a player writing their
 * opinion of an NPC holds no rights on the NPC itself. The handler
 * re-verifies the pc param is a real published PC; here it only needs to be
 * a post the caller may edit (a nonexistent id fails current_user_can).
 */
function chronicler_sheets_rest_can_edit_opinion(WP_REST_Request $request): bool {
    $pc = $request->get_param('pc');
    return is_numeric($pc) && current_user_can('edit_post', (int) $pc);
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

/**
 * One property's serialized entry: the template definition, plus the
 * character's current value, its human-readable display string, and the
 * annotation for what the property does.
 *
 * `detail` is assigned rather than unioned in because a template may declare
 * its own default — and `+` keeps the LEFT operand's key, which would serve
 * the system default while silently discarding the character's chr_detail_
 * override (playbooks amend what a rating rolls).
 */
function chronicler_sheets_serialize_property(int $post_id, array $property, $value): array {
    $entry = $property + [
        'value' => $value,
        'display' => chronicler_sheets_display_value($property, $value),
    ];
    $entry['detail'] = chronicler_sheets_get_detail($post_id, $property);
    return $entry;
}

/**
 * A character's sheet as ONE viewer — the current WordPress user — is allowed
 * to see it. This is the ONLY sanctioned way to read a sheet for an audience.
 *
 * chronicler_sheets_get_value() performs no capability checks at all: it hands
 * back gm_only and owner_only values to anybody who asks, by design, because
 * filtering is the reading surface's job. A surface that forgets is a surface
 * that leaks GM secrets, so surfaces do not each write their own filter loop
 * — they call this. The Slack bot (`/game my`, `/game roll`) depends on it in
 * particular: bot handlers run logged-out unless the handler resolves the
 * caller to a WP user first, and this function is what makes that resolution
 * mean anything.
 *
 * Returns the documented Sheet shape (openapi.yaml): characterId, title,
 * canEdit, system, layout, properties — each property the full template
 * definition plus its `value`, its human-readable `display`, and the resolved
 * per-character `detail` annotation. gm_only, owner_only, the NPC withhold,
 * and the per-PC opinion-set authority are all applied; a property outside
 * the viewer's audience loses its layout entry too, so its very existence
 * stays hidden. Null when the character has no template — never a partial
 * sheet a caller could mistake for an empty one.
 *
 * It deliberately does NOT gate on post status or password. Those decide
 * whether the caller may ask about this character at all — a different
 * question from what they may see of it — and they answer with a 404, which
 * only an HTTP surface can serve; they stay in
 * chronicler_sheets_rest_get_sheet(). Every other caller owes its own
 * reachability rule before calling this.
 */
function chronicler_sheets_sheet_for_viewer(int $post_id): ?array {
    $template = chronicler_sheets_template_for_character($post_id);
    if ($template === null) {
        return null;
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
    $can_edit = current_user_can('edit_post', $post_id);
    // An NPC (#176) withholds its whole stat block from callers who can't
    // edit it, mirroring the front-end render — hiding the markup on the
    // page would be theater if this public-read endpoint still served the
    // values. Editors get the sheet as usual.
    $is_npc = chronicler_sheets_is_npc($post_id);
    $properties = [];
    foreach ($template['properties'] as $id => $property) {
        if (!$is_gm && chronicler_sheets_is_gm_only($property)) {
            continue;
        }
        if (!$can_edit && chronicler_sheets_is_owner_only($property)) {
            continue;
        }
        // Opinions (#183): the page render's visibility authority decides —
        // NPC pages only, and each set only for its own player (GMs see all;
        // a set is a personal notebook) — so this endpoint serves exactly
        // the sets the page shows. The map is keyed by PC id; `pcs` names
        // each set and carries the caller's per-PC write right. Exempt from
        // the NPC withhold below: NPC pages are where opinions live.
        if ($property['type'] === 'opinions') {
            $sets = chronicler_sheets_visible_opinion_sets($post_id, $property);
            if ($sets === null || $sets === []) {
                continue;
            }
            $value = [];
            $pcs = [];
            foreach ($sets as $set) {
                $value[$set['pc']] = $set['value'];
                $pcs[] = ['id' => $set['pc'], 'name' => $set['name'], 'canEdit' => $set['canEdit']];
            }
            $properties[] = chronicler_sheets_serialize_property($post_id, $property, $value) + [
                'pcs' => $pcs,
            ];
            continue;
        }
        if ($is_npc && !$can_edit) {
            continue;
        }
        $properties[] = chronicler_sheets_serialize_property(
            $post_id,
            $property,
            chronicler_sheets_get_value($post_id, $property)
        );
    }
    // The layout must agree with the property list — a withheld property's
    // ID stays hidden too, not just its value. Two gates diverge from
    // visible_layout: the NPC withhold (#176) drops everything a non-editor
    // would see, EXCEPT opinions (#183), which appear exactly when they were
    // served above (they also vanish on non-NPC characters, where the
    // property loop skipped them for every caller).
    $served = [];
    foreach ($properties as $p) {
        $served[$p['id']] = true;
    }
    $layout = [];
    foreach (chronicler_sheets_visible_layout($template, $is_gm, $can_edit) as $section) {
        $section['properties'] = array_values(array_filter(
            $section['properties'],
            function ($pid) use ($template, $served, $is_npc, $can_edit) {
                if (($template['properties'][$pid]['type'] ?? null) === 'opinions') {
                    return isset($served[$pid]);
                }
                return !$is_npc || $can_edit;
            }
        ));
        if ($section['properties'] !== []) {
            $layout[] = $section;
        }
    }
    return [
        'characterId' => $post_id,
        'title' => get_the_title($post_id),
        'canEdit' => $can_edit,
        'system' => $template['system'],
        'layout' => $layout,
        'properties' => $properties,
    ];
}

/**
 * The HTTP wrapper around chronicler_sheets_sheet_for_viewer(): reachability
 * (does this caller get to ask about this character at all?) lives here, and
 * audience filtering lives there. Deliberately thin — a second filter loop in
 * this handler is exactly the duplication the authority exists to prevent.
 */
function chronicler_sheets_rest_get_sheet(WP_REST_Request $request) {
    $context = chronicler_sheets_rest_context((int) $request['id']);
    if (is_wp_error($context)) {
        return $context;
    }
    // The template comes back with the post here only so a character without
    // one 404s before the status checks, exactly as it always has; the sheet
    // itself is resolved by the authority below, which takes an id.
    [$post] = $context;
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
    $sheet = chronicler_sheets_sheet_for_viewer($post->ID);
    if ($sheet === null) {
        return new WP_Error('chronicler_no_template', 'No sheet template is configured.', ['status' => 404]);
    }
    return $sheet;
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

    // Opinions (#183) never write through this whole-property route — each
    // set rides the opinions endpoint behind its own per-PC permission. The
    // schema also refuses in apply_op; this earlier gate exists for the
    // honest error message (the not-live text below would say "level-up").
    if ($property['type'] === 'opinions') {
        return new WP_Error(
            'chronicler_use_opinions',
            $property['label'] . ' is recorded per player character — write it through the opinions endpoint.',
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

/**
 * The opinions write surface (#183): apply {op, value} to ONE field (rating
 * or notes) of ONE player character's opinion set on an NPC. The rating
 * borrows track semantics (set/adjust, clamped 0..length); notes borrow
 * text semantics (set, trimmed) and are sanitized to plain multi-line text
 * — the render escapes them, never treats them as markup.
 */
function chronicler_sheets_rest_update_opinion(WP_REST_Request $request) {
    $context = chronicler_sheets_rest_context((int) $request['id']);
    if (is_wp_error($context)) {
        return $context;
    }
    [$post, $template] = $context;
    $prop_id = (string) $request['prop'];
    $property = $template['properties'][$prop_id] ?? null;
    if ($property === null || $property['type'] !== 'opinions') {
        return new WP_Error('chronicler_no_property', "The sheet has no \"$prop_id\" opinions property.", ['status' => 404]);
    }
    // Reads render opinions on NPC pages only; a write surface that accepted
    // more would store values no read surface ever shows.
    if (!chronicler_sheets_is_npc($post->ID)) {
        return new WP_Error('chronicler_no_property', 'Opinions live on NPC pages.', ['status' => 404]);
    }
    $pc_id = (int) $request->get_param('pc');
    $pc = get_post($pc_id);
    if (!$pc || $pc->post_type !== 'chr_character' || $pc->post_status !== 'publish' || chronicler_sheets_is_npc($pc_id)) {
        return new WP_Error('chronicler_no_pc', 'No such player character.', ['status' => 404]);
    }
    // Backstop of the route's permission_callback, same defense-in-depth as
    // the gm_only/owner_only gates on the properties route: the per-PC gate
    // must hold even if that callback is ever loosened or the handler gains
    // another caller.
    if (!current_user_can('edit_post', $pc_id)) {
        return new WP_Error('chronicler_forbidden', 'Only ' . get_the_title($pc_id) . "'s player (or a GM) may write this opinion.", ['status' => 403]);
    }
    $field = $request->get_param('field');
    if (!in_array($field, ['rating', 'notes'], true)) {
        return new WP_Error('chronicler_bad_request', 'Body must name a "field": "rating" or "notes".', ['status' => 400]);
    }
    $op = $request->get_param('op');
    if (!is_string($op)) {
        return new WP_Error('chronicler_bad_request', 'Body must include an "op".', ['status' => 400]);
    }
    $value = $request->get_param('value');
    if (is_string($value)) {
        $value = sanitize_textarea_field($value);
    }
    $current = chronicler_sheets_get_opinion($post->ID, $property, $pc_id);
    $facet = $field === 'rating'
        ? ['id' => $prop_id, 'label' => $property['label'] . ' rating', 'type' => 'track', 'length' => $property['length']]
        : ['id' => $prop_id, 'label' => $property['label'] . ' notes', 'type' => 'text'];
    $result = chronicler_sheets_apply_op($facet, $current[$field], $op, $value);
    if (is_wp_error($result)) {
        $result->add_data(['status' => 400]);
        return $result;
    }
    $current[$field] = $result;
    chronicler_sheets_set_opinion($post->ID, $property, $pc_id, $current);
    return [
        'prop' => $prop_id,
        'pc' => $pc_id,
        'value' => $current,
        'display' => $current['rating'] . '/' . (int) $property['length'],
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
