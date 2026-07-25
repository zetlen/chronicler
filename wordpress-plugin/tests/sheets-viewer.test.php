<?php
// The visibility authority. chronicler_sheets_sheet_for_viewer() is the ONE
// function every audience-facing reader goes through — the public REST route,
// and (from 4.26.0) the Slack bot, which runs logged-out unless the handler
// sets a user. chronicler_sheets_get_value() filters nothing, so a surface
// that reads it directly leaks GM secrets; this suite is what keeps that from
// happening silently.
//
// The same sheet is therefore read as THREE viewers — a game master, the
// character's owning player, and another player — and each read is asserted
// twice: the property/layout entries the viewer must not receive are absent,
// AND the withheld values appear nowhere in the whole serialized payload.
//
// Reuses the shared harness stubs rather than shadowing them: render.test.php
// defines current_user_can (over chr_test_is_gm / chr_test_can_edit),
// get_the_title (chr_test_titles), chronicler_sheets_template_for_character /
// _get_value / _get_detail / _is_npc, template-store.test.php defines
// get_post_meta (chr_test_post_meta), and surfaces.test.php requires
// sheets/rest.php and defines get_post / WP_REST_Request.

// Shared globals this suite drives, saved and restored below so the suites
// after it see the fixtures they set up.
$chr_v_saved = [
    'template' => $GLOBALS['chr_test_template'] ?? null,
    'values' => $GLOBALS['chr_test_values'] ?? null,
    'is_gm' => $GLOBALS['chr_test_is_gm'] ?? null,
    'can_edit' => $GLOBALS['chr_test_can_edit'] ?? null,
    'titles' => $GLOBALS['chr_test_titles'] ?? null,
    'meta' => $GLOBALS['chr_test_post_meta'] ?? null,
];

check('the visibility authority exists', function_exists('chronicler_sheets_sheet_for_viewer'));

// One plain property, one gm_only, one owner_only — the three audiences, with
// the gated pair split across two layout sections so both the "id stripped
// from a surviving section" and the "emptied section dropped" behaviors are
// exercised.
$chr_v_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Visibility',
    'version' => 1,
    'properties' => [
        ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3, 'detail' => 'act under pressure'],
        ['id' => 'weakness', 'label' => 'Weakness', 'type' => 'text', 'gm_only' => true],
        ['id' => 'diary', 'label' => 'Diary', 'type' => 'longtext', 'owner_only' => true],
    ],
    'layout' => [
        ['section' => 'Ratings', 'properties' => ['cool', 'weakness']],
        ['section' => 'Diary', 'properties' => ['diary']],
    ],
]));
check('viewer fixture parses', is_array($chr_v_template));

$GLOBALS['chr_test_template'] = $chr_v_template;
$GLOBALS['chr_test_values'] = [
    'cool' => 2,
    'weakness' => 'GM_ONLY_SECRET',
    'diary' => 'OWNER_ONLY_SECRET',
];
$GLOBALS['chr_test_titles'][77] = 'Alec Baker';

$chr_v_props = function ($sheet): array {
    $by = [];
    foreach ((is_array($sheet) ? $sheet['properties'] : []) as $p) {
        $by[$p['id']] = $p;
    }
    return $by;
};
$chr_v_layout_ids = function ($sheet): array {
    $ids = [];
    foreach ((is_array($sheet) ? $sheet['layout'] : []) as $section) {
        foreach ($section['properties'] as $pid) {
            $ids[] = $pid;
        }
    }
    return $ids;
};
$chr_v_headings = function ($sheet): array {
    return array_map(function ($s) {
        return $s['section'];
    }, is_array($sheet) ? $sheet['layout'] : []);
};

// --- The game master: edit_others_chr_characters AND edit_post. Both gates
// open, so the sheet comes back whole. ---
$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$chr_v_gm = chronicler_sheets_sheet_for_viewer(77);
$chr_v_gm_props = $chr_v_props($chr_v_gm);

check('GM: a sheet comes back', is_array($chr_v_gm));
check(
    'GM: the shape is the documented sheet response',
    is_array($chr_v_gm) && array_keys($chr_v_gm) === ['characterId', 'title', 'canEdit', 'system', 'layout', 'properties']
);
check('GM: characterId is the character', ($chr_v_gm['characterId'] ?? null) === 77);
check('GM: title is the character title', ($chr_v_gm['title'] ?? null) === 'Alec Baker');
check('GM: system names the template', ($chr_v_gm['system'] ?? null) === 'Visibility');
check('GM: canEdit true', ($chr_v_gm['canEdit'] ?? null) === true);
check('GM: the plain property is served', isset($chr_v_gm_props['cool']));
check('GM: the gm_only property is served', isset($chr_v_gm_props['weakness']));
check('GM: the owner_only property is served', isset($chr_v_gm_props['diary']));
check('GM: the gm_only VALUE is served', ($chr_v_gm_props['weakness']['value'] ?? null) === 'GM_ONLY_SECRET');
check('GM: the owner_only VALUE is served', ($chr_v_gm_props['diary']['value'] ?? null) === 'OWNER_ONLY_SECRET');
check('GM: every property carries its display string', ($chr_v_gm_props['cool']['display'] ?? null) === '+2');
check('GM: the layout names every property', $chr_v_layout_ids($chr_v_gm) === ['cool', 'weakness', 'diary']);
check('GM: both sections survive', $chr_v_headings($chr_v_gm) === ['Ratings', 'Diary']);

// --- The owning player: edit_post on THIS character, but not the game-master
// capability. Keeps their owner_only property; loses the gm_only one. ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = true;
$chr_v_owner = chronicler_sheets_sheet_for_viewer(77);
$chr_v_owner_props = $chr_v_props($chr_v_owner);

check('owner: the plain property is served', isset($chr_v_owner_props['cool']));
check('owner: the owner_only property is served — this IS their audience', isset($chr_v_owner_props['diary']));
check('owner: the owner_only value is served', ($chr_v_owner_props['diary']['value'] ?? null) === 'OWNER_ONLY_SECRET');
check('owner: the gm_only property is ABSENT', !isset($chr_v_owner_props['weakness']));
check('owner: the gm_only value appears nowhere in the payload', strpos(json_encode($chr_v_owner), 'GM_ONLY_SECRET') === false);
check('owner: the gm_only id appears nowhere in the payload', strpos(json_encode($chr_v_owner), 'weakness') === false);
check('owner: the layout never names the filtered property', !in_array('weakness', $chr_v_layout_ids($chr_v_owner), true));
check('owner: the layout keeps what survived', $chr_v_layout_ids($chr_v_owner) === ['cool', 'diary']);
check('owner: canEdit true', ($chr_v_owner['canEdit'] ?? null) === true);

// --- Another player: neither capability. The player-facing worst case, and
// the one the Slack bot hits most — only the plain property may come back. ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$chr_v_other = chronicler_sheets_sheet_for_viewer(77);
$chr_v_other_props = $chr_v_props($chr_v_other);

check('other player: exactly one property is served', array_keys($chr_v_other_props) === ['cool']);
check('other player: the gm_only property is ABSENT', !isset($chr_v_other_props['weakness']));
check('other player: the owner_only property is ABSENT', !isset($chr_v_other_props['diary']));
check('other player: the gm_only value appears nowhere in the payload', strpos(json_encode($chr_v_other), 'GM_ONLY_SECRET') === false);
check('other player: the owner_only value appears nowhere in the payload', strpos(json_encode($chr_v_other), 'OWNER_ONLY_SECRET') === false);
check(
    'other player: neither flagged id appears anywhere in the payload',
    strpos(json_encode($chr_v_other), 'weakness') === false && strpos(json_encode($chr_v_other), 'diary') === false
);
check('other player: the layout names only the plain property', $chr_v_layout_ids($chr_v_other) === ['cool']);
check('other player: the section emptied by the filter is dropped', $chr_v_headings($chr_v_other) === ['Ratings']);
check('other player: canEdit false', ($chr_v_other['canEdit'] ?? null) === false);

// --- detail: the annotation the HTML sheet shows beside a stat, which REST
// omitted before 4.26.0. It is exactly the text a player wants next to a
// rating in chat, and the per-character override must win — a playbook amends
// what a rating rolls. ---
check('detail: the template default rides along', ($chr_v_other_props['cool']['detail'] ?? null) === 'act under pressure');
check(
    'detail: a property with no annotation still carries the key, empty',
    array_key_exists('detail', $chr_v_gm_props['diary'] ?? []) && ($chr_v_gm_props['diary']['detail'] ?? null) === ''
);

$GLOBALS['chr_test_post_meta'][77]['chr_detail_cool'] = 'kick some ass, protect someone';
$chr_v_overridden = $chr_v_props(chronicler_sheets_sheet_for_viewer(77));
check(
    'detail: a per-character chr_detail_ override beats the template default',
    ($chr_v_overridden['cool']['detail'] ?? null) === 'kick some ass, protect someone'
);
unset($GLOBALS['chr_test_post_meta'][77]['chr_detail_cool']);

// --- The NPC withhold (#176): an NPC keeps its stats but shows nobody who
// can't edit it, so a bot reading an NPC as a player gets a nameplate and
// nothing else. ---
$GLOBALS['chr_test_post_meta'][77]['chr_npc'] = '1';
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$chr_v_npc_public = chronicler_sheets_sheet_for_viewer(77);
check('NPC: a non-editor gets no properties at all', ($chr_v_npc_public['properties'] ?? null) === []);
check('NPC: a non-editor gets no layout at all', ($chr_v_npc_public['layout'] ?? null) === []);
check(
    'NPC: not one stat value leaks to a non-editor',
    strpos(json_encode($chr_v_npc_public), 'GM_ONLY_SECRET') === false
        && strpos(json_encode($chr_v_npc_public), 'OWNER_ONLY_SECRET') === false
        && strpos(json_encode($chr_v_npc_public), '"value"') === false
);

$GLOBALS['chr_test_is_gm'] = true;
$GLOBALS['chr_test_can_edit'] = true;
$chr_v_npc_gm = chronicler_sheets_sheet_for_viewer(77);
check('NPC: an editor still gets the whole sheet', count($chr_v_npc_gm['properties'] ?? []) === 3);
unset($GLOBALS['chr_test_post_meta'][77]['chr_npc']);

// --- A character with no template reads as null, never a half-built sheet a
// caller might mistake for "this character has nothing". ---
$GLOBALS['chr_test_template'] = null;
check('no template: the authority returns null', chronicler_sheets_sheet_for_viewer(77) === null);
$GLOBALS['chr_test_template'] = $chr_v_template;

// --- The REST route is now a wrapper: what it serves must be, byte for byte,
// what the authority computed for the same viewer. If the route ever grows a
// second filter loop again, this fails. ---
$GLOBALS['chr_test_is_gm'] = false;
$GLOBALS['chr_test_can_edit'] = false;
$chr_v_route = chronicler_sheets_rest_get_sheet(new WP_REST_Request(['id' => 77]));
check(
    'the REST route serves exactly what the authority computed',
    is_array($chr_v_route) && $chr_v_route === chronicler_sheets_sheet_for_viewer(77)
);
check('the REST route still withholds the gm_only value', strpos(json_encode($chr_v_route), 'GM_ONLY_SECRET') === false);

// Restore the shared fixtures for whatever runs next.
$GLOBALS['chr_test_template'] = $chr_v_saved['template'];
$GLOBALS['chr_test_values'] = $chr_v_saved['values'];
$GLOBALS['chr_test_is_gm'] = $chr_v_saved['is_gm'];
$GLOBALS['chr_test_can_edit'] = $chr_v_saved['can_edit'];
$GLOBALS['chr_test_titles'] = $chr_v_saved['titles'];
$GLOBALS['chr_test_post_meta'] = $chr_v_saved['meta'];
