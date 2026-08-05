<?php
// Behavioral tests for sheets/schema.php. Included by run.php.

$motw = json_encode([
    'system' => 'Monster of the Week',
    'version' => 1,
    'properties' => [
        ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'harm', 'label' => 'Harm', 'type' => 'track', 'length' => 7],
        ['id' => 'luck', 'label' => 'Luck', 'type' => 'track', 'length' => 7],
        ['id' => 'moves', 'label' => 'Moves', 'type' => 'checklist', 'options' => [
            ['id' => 'the_big_entrance', 'label' => 'The Big Entrance'],
            ['id' => 'crime_pays', 'label' => 'Crime Pays'],
        ]],
        ['id' => 'look', 'label' => 'Look', 'type' => 'text'],
    ],
    'layout' => [
        ['section' => 'Ratings', 'properties' => ['cool']],
        ['section' => 'Status', 'properties' => ['harm', 'luck']],
    ],
]);

$t = chronicler_sheets_parse_template($motw);
check('valid template parses', is_array($t));
check('properties keyed by id', is_array($t) && isset($t['properties']['harm']));
check('property order preserved', is_array($t) && array_keys($t['properties'])[0] === 'cool');
check('layout preserved', is_array($t) && $t['layout'][1]['section'] === 'Status');
check('unlisted properties are fine (render-time Other section)', is_array($t) && !in_array('moves', $t['layout'][0]['properties'], true));

check('garbage json is an error', is_wp_error(chronicler_sheets_parse_template('{nope')));
check('missing properties is an error', is_wp_error(chronicler_sheets_parse_template('{"system":"x","version":1,"layout":[]}')));

$dup = json_decode($motw, true);
$dup['properties'][] = ['id' => 'cool', 'label' => 'Again', 'type' => 'number'];
check('duplicate property id is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($dup))));

$badId = json_decode($motw, true);
$badId['properties'][0]['id'] = 'Cool Stat';
check('property id must match [a-z][a-z0-9_]*', is_wp_error(chronicler_sheets_parse_template(json_encode($badId))));

$badType = json_decode($motw, true);
$badType['properties'][0]['type'] = 'slider';
check('unknown type is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($badType))));

$badLayout = json_decode($motw, true);
$badLayout['layout'][0]['properties'][] = 'ghost';
check('layout referencing undeclared id is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($badLayout))));

$dupLayout = json_decode($motw, true);
$dupLayout['layout'][1]['properties'][] = 'cool';
check('layout referencing an id twice is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($dupLayout))));

$badTrack = json_decode($motw, true);
$badTrack['properties'][1]['length'] = 0;
check('track needs a positive length', is_wp_error(chronicler_sheets_parse_template(json_encode($badTrack))));

$badOptions = json_decode($motw, true);
$badOptions['properties'][3]['options'] = [];
check('checklist needs options', is_wp_error(chronicler_sheets_parse_template(json_encode($badOptions))));

// A gm_only property must never be `live`: `live` is what the public write
// surface (REST/`/sheet`) is allowed to touch, and GM-only fields must never be
// player-writable. Forbidding the combination at parse time keeps the dangerous
// shape from ever existing (issue #63's leak was on the write path).
$liveGm = json_decode($motw, true);
$liveGm['properties'][0]['live'] = true;
$liveGm['properties'][0]['gm_only'] = true;
check('a gm_only property cannot also be live', is_wp_error(chronicler_sheets_parse_template(json_encode($liveGm))));

// A stored template can predate the rule above (issue #140): a read must not
// brick the whole document over a shape that was legal when it was saved, so
// lenient parsing normalizes it instead of rejecting it — gm_only wins.
$leniently = chronicler_sheets_parse_template(json_encode($liveGm), true);
check('gm_only+live parses leniently instead of erroring', is_array($leniently));
check('lenient parse strips live in favor of gm_only', is_array($leniently) && $leniently['properties']['cool']['live'] === false);
check('lenient parse preserves gm_only', is_array($leniently) && $leniently['properties']['cool']['gm_only'] === true);

// --- rules removed (2026-07-13): the parser ignores a legacy rules key ---
$chr_legacy_rules = json_encode([
    'system' => 'MotW', 'version' => 1,
    'properties' => [
        ['id' => 'harm', 'label' => 'Harm', 'type' => 'track', 'length' => 7, 'live' => true],
        ['id' => 'doomed', 'label' => 'Doomed', 'type' => 'toggle', 'live' => true],
    ],
    'rules' => [
        ['when' => ['prop' => 'harm', 'gte' => 7], 'set' => ['prop' => 'doomed', 'value' => true]],
    ],
]);
// Since the top-level key allowlist arrived (rolls, 2026-07-25) a stale
// rules key fails the WRITE path like any other unknown key — the same
// verdict template.schema.json has always given the editor. A stored
// document still READS: lenient parsing drops the key and renders.
check('re-saving a template with a legacy rules key is an error', is_wp_error(chronicler_sheets_parse_template($chr_legacy_rules)));
$chr_legacy_parsed = chronicler_sheets_parse_template($chr_legacy_rules, true);
check('a stored template with a legacy rules key still parses leniently', is_array($chr_legacy_parsed));
check('legacy rules are dropped, not carried', is_array($chr_legacy_parsed) && !array_key_exists('rules', $chr_legacy_parsed));

// Defaults
$props = is_array($t) ? $t['properties'] : [];
check('number defaults to min', chronicler_sheets_default_value($props['cool']) === -1);
check('track defaults to 0 marked', chronicler_sheets_default_value($props['harm']) === 0);
check('checklist defaults to empty', chronicler_sheets_default_value($props['moves']) === []);
check('text defaults to empty string', chronicler_sheets_default_value($props['look']) === '');

// Counter, toggle, select coverage
$new_types = json_decode($motw, true);
$new_types['properties'] = [
    ['id' => 'counter_prop', 'label' => 'Counter', 'type' => 'counter', 'max' => 10, 'start' => 3],
    ['id' => 'toggle_prop', 'label' => 'Toggle', 'type' => 'toggle'],
    ['id' => 'select_prop', 'label' => 'Select', 'type' => 'select', 'options' => [
        ['id' => 'opt_a', 'label' => 'Option A'],
        ['id' => 'opt_b', 'label' => 'Option B'],
    ]],
];
$new_types['layout'] = [];
$nt = chronicler_sheets_parse_template(json_encode($new_types));
check('valid template with counter/toggle/select parses', is_array($nt));

// Counter constraint rejections
$bad_counter_max = json_decode($motw, true);
$bad_counter_max['properties'] = [
    ['id' => 'c', 'label' => 'C', 'type' => 'counter', 'max' => 'seven'],
];
$bad_counter_max['layout'] = [];
check('counter with non-integer max is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($bad_counter_max))));

$bad_counter_start = json_decode($motw, true);
$bad_counter_start['properties'] = [
    ['id' => 'c', 'label' => 'C', 'type' => 'counter', 'start' => 'three'],
];
$bad_counter_start['layout'] = [];
check('counter with non-integer start is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($bad_counter_start))));

// Select constraint rejections
$empty_select_options = json_decode($motw, true);
$empty_select_options['properties'] = [
    ['id' => 's', 'label' => 'S', 'type' => 'select', 'options' => []],
];
$empty_select_options['layout'] = [];
check('select with empty options is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($empty_select_options))));

$select_missing_label = json_decode($motw, true);
$select_missing_label['properties'] = [
    ['id' => 's', 'label' => 'S', 'type' => 'select', 'options' => [
        ['id' => 'opt_a'],
    ]],
];
$select_missing_label['layout'] = [];
check('select option without label is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($select_missing_label))));

$select_dup_ids = json_decode($motw, true);
$select_dup_ids['properties'] = [
    ['id' => 's', 'label' => 'S', 'type' => 'select', 'options' => [
        ['id' => 'opt_x', 'label' => 'Option X'],
        ['id' => 'opt_x', 'label' => 'Option X Again'],
    ]],
];
$select_dup_ids['layout'] = [];
check('select with duplicate option ids is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($select_dup_ids))));

// Defaults for counter, toggle, select
$nt_props = is_array($nt) ? $nt['properties'] : [];
check('counter with start defaults to start value', isset($nt_props['counter_prop']) && chronicler_sheets_default_value($nt_props['counter_prop']) === 3);

$counter_no_start = json_decode($motw, true);
$counter_no_start['properties'] = [
    ['id' => 'c', 'label' => 'C', 'type' => 'counter', 'max' => 10],
];
$counter_no_start['layout'] = [];
$cnt = chronicler_sheets_parse_template(json_encode($counter_no_start));
$cnt_props = is_array($cnt) ? $cnt['properties'] : [];
check('counter without start defaults to 0', isset($cnt_props['c']) && chronicler_sheets_default_value($cnt_props['c']) === 0);

// Toggle default
check('toggle defaults to false', isset($nt_props['toggle_prop']) && chronicler_sheets_default_value($nt_props['toggle_prop']) === false);

// Select default
check('select defaults to first option id', isset($nt_props['select_prop']) && chronicler_sheets_default_value($nt_props['select_prop']) === 'opt_a');

// --- apply_op ---
$harm = $props['harm'];
$cool = $props['cool'];
$moves = $props['moves'];

check('adjust from unset starts at default', chronicler_sheets_apply_op($harm, null, 'adjust', 2) === 2);
check('adjust clamps at track length', chronicler_sheets_apply_op($harm, 6, 'adjust', 5) === 7);
check('adjust clamps at zero', chronicler_sheets_apply_op($harm, 1, 'adjust', -4) === 0);
check('set clamps into number range', chronicler_sheets_apply_op($cool, 0, 'set', 9) === 3);
check('numeric strings coerce', chronicler_sheets_apply_op($cool, 0, 'set', '2') === 2);
check('non-numeric set on number errors', is_wp_error(chronicler_sheets_apply_op($cool, 0, 'set', 'lots')));
check('adjust on text errors', is_wp_error(chronicler_sheets_apply_op($props['look'], '', 'adjust', 1)));
check('unknown op errors', is_wp_error(chronicler_sheets_apply_op($cool, 0, 'increment', 1)));

check('checklist toggle adds', chronicler_sheets_apply_op($moves, [], 'toggle', 'crime_pays') === ['crime_pays']);
check('checklist toggle removes', chronicler_sheets_apply_op($moves, ['crime_pays'], 'toggle', 'crime_pays') === []);
check('checklist set with option id toggles', chronicler_sheets_apply_op($moves, [], 'set', 'crime_pays') === ['crime_pays']);
check('checklist set with array replaces', chronicler_sheets_apply_op($moves, ['crime_pays'], 'set', ['the_big_entrance']) === ['the_big_entrance']);
check('checklist rejects undeclared option', is_wp_error(chronicler_sheets_apply_op($moves, [], 'toggle', 'ghost_move')));

$toggle = ['id' => 'armored', 'label' => 'Armored', 'type' => 'toggle'];
check('toggle flips', chronicler_sheets_apply_op($toggle, false, 'toggle', null) === true);
check('toggle set accepts boolish strings', chronicler_sheets_apply_op($toggle, false, 'set', 'true') === true);

$select = ['id' => 'playbook', 'label' => 'Playbook', 'type' => 'select', 'options' => [
    ['id' => 'the_chosen', 'label' => 'The Chosen'],
    ['id' => 'the_mundane', 'label' => 'The Mundane'],
]];
check('select set accepts declared option', chronicler_sheets_apply_op($select, 'the_chosen', 'set', 'the_mundane') === 'the_mundane');
check('select rejects undeclared option', is_wp_error(chronicler_sheets_apply_op($select, 'the_chosen', 'set', 'the_villain')));

check('text set trims to string', chronicler_sheets_apply_op($props['look'], '', 'set', 'sunglasses at night') === 'sunglasses at night');

// --- display ---
check('track displays marked/length', chronicler_sheets_display_value($harm, 4) === '4/7');
check('number displays signed when negative-capable', chronicler_sheets_display_value($cool, 2) === '+2');
check('toggle displays on/off', chronicler_sheets_display_value($toggle, true) === 'on');
check('select displays option label', chronicler_sheets_display_value($select, 'the_mundane') === 'The Mundane');
check('checklist displays checked count', chronicler_sheets_display_value($moves, ['crime_pays']) === '1/2 checked');
check('display tolerates unset', chronicler_sheets_display_value($harm, null) === '0/7');

// --- live flag + list type ---
$lt = json_decode($motw, true);
$lt['properties'][1]['live'] = true; // harm
$lt['properties'][] = [
    'id' => 'gear', 'label' => 'Gear', 'type' => 'list',
    'fields' => [
        ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
        ['id' => 'damage', 'label' => 'Damage', 'type' => 'number', 'min' => 0, 'max' => 5],
        ['id' => 'has', 'label' => 'Has it', 'type' => 'toggle'],
        ['id' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => [
            ['id' => 'weapon', 'label' => 'Weapon'], ['id' => 'tool', 'label' => 'Tool'],
        ]],
    ],
];
$parsed_lt = chronicler_sheets_parse_template(json_encode($lt));
check('template with live + list parses', is_array($parsed_lt));
check('is_live true when flagged', is_array($parsed_lt) && chronicler_sheets_is_live($parsed_lt['properties']['harm']));
check('is_live false by default', is_array($parsed_lt) && !chronicler_sheets_is_live($parsed_lt['properties']['cool']));

$bad_live = json_decode($motw, true);
$bad_live['properties'][0]['live'] = 'yes';
check('non-bool live is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($bad_live))));

// --- gm_only flag ---
$gm = json_decode($motw, true);
$gm['properties'][] = ['id' => 'gm_notes', 'label' => 'GM Notes', 'type' => 'longtext', 'gm_only' => true];
$parsed_gm = chronicler_sheets_parse_template(json_encode($gm));
check('template with gm_only parses', is_array($parsed_gm));
check('gm_only carries through', is_array($parsed_gm) && ($parsed_gm['properties']['gm_notes']['gm_only'] ?? null) === true);
check('is_gm_only true when flagged', is_array($parsed_gm) && chronicler_sheets_is_gm_only($parsed_gm['properties']['gm_notes']));
check('is_gm_only false by default', is_array($parsed_gm) && !chronicler_sheets_is_gm_only($parsed_gm['properties']['cool']));

$bad_gm = json_decode($motw, true);
$bad_gm['properties'][0]['gm_only'] = 'yes';
check('non-bool gm_only is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($bad_gm))));

// --- owner_only flag ---
$oo = json_decode($motw, true);
$oo['properties'][] = ['id' => 'private_notes', 'label' => 'Private Notes', 'type' => 'longtext', 'owner_only' => true];
$parsed_oo = chronicler_sheets_parse_template(json_encode($oo));
check('template with owner_only parses', is_array($parsed_oo));
check('owner_only carries through', is_array($parsed_oo) && ($parsed_oo['properties']['private_notes']['owner_only'] ?? null) === true);
check('is_owner_only true when flagged', is_array($parsed_oo) && chronicler_sheets_is_owner_only($parsed_oo['properties']['private_notes']));
check('is_owner_only false by default', is_array($parsed_oo) && !chronicler_sheets_is_owner_only($parsed_oo['properties']['cool']));

$bad_oo = json_decode($motw, true);
$bad_oo['properties'][0]['owner_only'] = 'yes';
check('non-bool owner_only is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($bad_oo))));

// Parsers before 4.12 kept unknown keys silently, so a stored document CAN
// carry a malformed owner_only that never saw validation. A lenient read
// degrades instead of bricking the document — and degrades CLOSED: truthy
// junk asked for privacy and gets it.
$leniently_oo = chronicler_sheets_parse_template(json_encode($bad_oo), true);
check('non-bool owner_only parses leniently', is_array($leniently_oo));
check('lenient parse coerces truthy owner_only to private', is_array($leniently_oo) && ($leniently_oo['properties']['cool']['owner_only'] ?? null) === true);
$falsy_oo = json_decode($motw, true);
$falsy_oo['properties'][0]['owner_only'] = 0;
$leniently_falsy = chronicler_sheets_parse_template(json_encode($falsy_oo), true);
check('lenient parse coerces falsy owner_only to public', is_array($leniently_falsy) && ($leniently_falsy['properties']['cool']['owner_only'] ?? null) === false);

// Unlike gm_only, owner_only MAY combine with live: the write route's
// edit_post permission is exactly the owner_only audience.
$oo_live = json_decode($motw, true);
$oo_live['properties'][] = ['id' => 'private_notes', 'label' => 'Private Notes', 'type' => 'longtext', 'owner_only' => true, 'live' => true];
check('owner_only + live parses', is_array(chronicler_sheets_parse_template(json_encode($oo_live))));

// The two audience flags contradict: gm_only excludes the owning player,
// owner_only exists to include them.
$contradictory = json_decode($motw, true);
$contradictory['properties'][0]['gm_only'] = true;
$contradictory['properties'][0]['owner_only'] = true;
check('owner_only + gm_only is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($contradictory))));

// A stored contradiction (saved under a pre-4.12 parser that kept unknown
// keys) resolves leniently to the stricter audience: gm_only keeps
// excluding the owner, exactly what it always meant.
$leniently_both = chronicler_sheets_parse_template(json_encode($contradictory), true);
check('owner_only + gm_only parses leniently instead of erroring', is_array($leniently_both));
check(
    'lenient parse keeps gm_only and drops owner_only',
    is_array($leniently_both)
        && $leniently_both['properties']['cool']['gm_only'] === true
        && $leniently_both['properties']['cool']['owner_only'] === false
);

// --- unknown property keys: a privacy flag must fail loudly, not open ---
// A typo'd audience flag used to save without complaint and render the
// "private" value publicly forever. The write path now rejects unknown keys
// outright — matching template.schema.json's additionalProperties: false —
// while lenient reads keep tolerating documents saved under older parsers.
$typo = json_decode($motw, true);
$typo['properties'][0]['owner-only'] = true; // hyphen, not underscore
$typo_err = chronicler_sheets_parse_template(json_encode($typo));
check('a typo\'d owner_only key is a save error', is_wp_error($typo_err));
check(
    'the typo error suggests the intended key',
    is_wp_error($typo_err) && strpos($typo_err->get_error_message(), '"owner_only"') !== false
);
check('a typo\'d key still parses leniently (stored docs)', is_array(chronicler_sheets_parse_template(json_encode($typo), true)));

$stray = json_decode($motw, true);
$stray['properties'][0]['color'] = 'red';
check('an unknown property key is a save error', is_wp_error(chronicler_sheets_parse_template(json_encode($stray))));

$stray_opt = json_decode($motw, true);
$stray_opt['properties'][3]['options'][0]['color'] = 'red';
check('an unknown option key is a save error', is_wp_error(chronicler_sheets_parse_template(json_encode($stray_opt))));

// --- audience flags on list FIELDS: rejected, never silently ignored ---
// No render or REST surface consults gm_only/owner_only inside a list
// entry, so accepting the flag would render the "private" field publicly
// while the template reads as if it were protected.
$chr_mk_list_flag = function ($flag, $value) {
    return json_encode([
        'system' => 'Flags', 'version' => 1,
        'properties' => [
            ['id' => 'gear', 'label' => 'Gear', 'type' => 'list', 'fields' => [
                ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
                array_merge(['id' => 'secret', 'label' => 'Secret', 'type' => 'text'], [$flag => $value]),
            ]],
        ],
    ]);
};
check('owner_only on a list field is a save error', is_wp_error(chronicler_sheets_parse_template($chr_mk_list_flag('owner_only', true))));
check('gm_only on a list field is a save error', is_wp_error(chronicler_sheets_parse_template($chr_mk_list_flag('gm_only', true))));
$lenient_flagged = chronicler_sheets_parse_template($chr_mk_list_flag('owner_only', true), true);
check(
    'a lenient read drops the flagged field entirely (fails closed)',
    is_array($lenient_flagged)
        && array_column($lenient_flagged['properties']['gear']['fields'], 'id') === ['name']
);
$lenient_falsy_flag = chronicler_sheets_parse_template($chr_mk_list_flag('gm_only', false), true);
check(
    'a lenient read keeps a field whose audience flag is falsy',
    is_array($lenient_falsy_flag)
        && array_column($lenient_falsy_flag['properties']['gear']['fields'], 'id') === ['name', 'secret']
);
$chr_field_typo = json_encode(['system' => 'Flags', 'version' => 1, 'properties' => [
    ['id' => 'gear', 'label' => 'Gear', 'type' => 'list', 'fields' => [
        ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'wen' => 'x'],
    ]],
]]);
check('an unknown list-field key is a save error', is_wp_error(chronicler_sheets_parse_template($chr_field_typo)));

// --- always_show flag (issue #67: Player Notes / GM Notes stay visible when empty) ---
$as = json_decode($motw, true);
$as['properties'][] = ['id' => 'player_notes', 'label' => 'Player Notes', 'type' => 'longtext', 'always_show' => true];
$parsed_as = chronicler_sheets_parse_template(json_encode($as));
check('template with always_show parses', is_array($parsed_as));
check('always_show carries through', is_array($parsed_as) && ($parsed_as['properties']['player_notes']['always_show'] ?? null) === true);
check('is_always_show true when flagged', is_array($parsed_as) && chronicler_sheets_is_always_show($parsed_as['properties']['player_notes']));
check('is_always_show false by default', is_array($parsed_as) && !chronicler_sheets_is_always_show($parsed_as['properties']['cool']));

$bad_as = json_decode($motw, true);
$bad_as['properties'][0]['always_show'] = 'yes';
check('non-bool always_show is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($bad_as))));

// --- visible_layout (audience-gated properties stripped from read surfaces) ---
$vl = json_decode($motw, true);
$vl['properties'][] = ['id' => 'gm_secret', 'label' => 'GM Secret', 'type' => 'text', 'gm_only' => true];
$vl['properties'][] = ['id' => 'gm_notes', 'label' => 'GM Notes', 'type' => 'longtext', 'gm_only' => true];
$vl['properties'][] = ['id' => 'private_notes', 'label' => 'Private Notes', 'type' => 'longtext', 'owner_only' => true];
$vl['layout'] = [
    ['section' => 'Ratings', 'properties' => ['cool', 'gm_secret']], // mixed public + GM
    ['section' => 'Status', 'properties' => ['harm', 'luck']],       // all public
    ['section' => 'GM', 'properties' => ['gm_notes']],               // all GM
    ['section' => 'Private', 'properties' => ['private_notes']],     // all owner-only
];
$vl_t = chronicler_sheets_parse_template(json_encode($vl));
check('visible_layout fixture parses', is_array($vl_t));
// Each audience gate is applied per-flag: gm_only ids survive on $is_gm,
// owner_only ids on $can_edit. A normal GM holds both (map_meta_cap grants
// edit_post on every character); the (true, false) call models a
// misconfigured role holding edit_others_chr_characters WITHOUT edit_post —
// it must not receive owner_only layout ids the value loop would withhold.
$vl_gm = is_array($vl_t) ? chronicler_sheets_visible_layout($vl_t, true, true) : [];
$vl_gm_no_edit = is_array($vl_t) ? chronicler_sheets_visible_layout($vl_t, true, false) : [];
$vl_stranger = is_array($vl_t) ? chronicler_sheets_visible_layout($vl_t, false, false) : [];
$vl_owner = is_array($vl_t) ? chronicler_sheets_visible_layout($vl_t, false, true) : [];
check('visible_layout: GM keeps all sections whole', count($vl_gm) === 4 && $vl_gm[0]['properties'] === ['cool', 'gm_secret']);
check(
    'visible_layout: gm-without-edit keeps gm_only ids but loses owner_only ones',
    count($vl_gm_no_edit) === 3
        && $vl_gm_no_edit[0]['properties'] === ['cool', 'gm_secret']
        && !in_array('private_notes', array_merge(...array_column($vl_gm_no_edit, 'properties')), true)
);
check('visible_layout: stranger drops the all-GM and all-owner sections', count($vl_stranger) === 2);
check('visible_layout: stranger strips the GM id from a mixed section', $vl_stranger[0]['properties'] === ['cool']);
check('visible_layout: stranger keeps a public section intact', $vl_stranger[1]['properties'] === ['harm', 'luck']);
check('visible_layout: owner keeps the owner-only section, still loses the GM one', count($vl_owner) === 3 && $vl_owner[2]['properties'] === ['private_notes']);
check('visible_layout: owner still loses the GM id from a mixed section', $vl_owner[0]['properties'] === ['cool']);

$live_list = json_decode(json_encode($lt), true);
$live_list['properties'][count($live_list['properties']) - 1]['live'] = true;
check('live list is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($live_list))));

$no_fields = json_decode(json_encode($lt), true);
$no_fields['properties'][count($no_fields['properties']) - 1]['fields'] = [];
check('list needs non-empty fields', is_wp_error(chronicler_sheets_parse_template(json_encode($no_fields))));

$bad_field_type = json_decode(json_encode($lt), true);
$bad_field_type['properties'][count($bad_field_type['properties']) - 1]['fields'][0]['type'] = 'track';
check('list fields must be scalar types', is_wp_error(chronicler_sheets_parse_template(json_encode($bad_field_type))));

$dup_field = json_decode(json_encode($lt), true);
$dup_field['properties'][count($dup_field['properties']) - 1]['fields'][1]['id'] = 'name';
check('duplicate field id is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($dup_field))));

$gear = is_array($parsed_lt) ? $parsed_lt['properties']['gear'] : null;
check('list defaults to empty array', $gear !== null && chronicler_sheets_default_value($gear) === []);

$entries = chronicler_sheets_apply_op($gear, null, 'set', [
    ['name' => 'Silver knife', 'damage' => '9', 'has' => '1', 'kind' => 'weapon', 'ghost' => 'dropme'],
    ['name' => 'Flare gun'],
]);
check('list set validates and normalizes entries', is_array($entries) && count($entries) === 2);
check('entry numbers clamp', is_array($entries) && $entries[0]['damage'] === 5);
check('entry toggles cast', is_array($entries) && $entries[0]['has'] === true);
check('unknown entry keys dropped', is_array($entries) && !array_key_exists('ghost', $entries[0]));
check('missing entry fields default', is_array($entries) && $entries[1]['damage'] === 0 && $entries[1]['has'] === false && $entries[1]['kind'] === 'weapon');
check('bad entry select rejected', is_wp_error(chronicler_sheets_apply_op($gear, null, 'set', [['name' => 'x', 'kind' => 'spaceship']])));
check('non-array entry rejected', is_wp_error(chronicler_sheets_apply_op($gear, null, 'set', ['just a string'])));
check('list rejects adjust', is_wp_error(chronicler_sheets_apply_op($gear, [], 'adjust', 1)));
check('list rejects toggle', is_wp_error(chronicler_sheets_apply_op($gear, [], 'toggle', 'name')));
check('list displays entry count', $gear !== null && chronicler_sheets_display_value($gear, [['name' => 'a'], ['name' => 'b']]) === '2 entries');
check('list display tolerates unset', $gear !== null && chronicler_sheets_display_value($gear, null) === '0 entries');

// --- conditional list fields (when) ---
$when_ok = json_decode(json_encode($lt), true);
$gear_i = count($when_ok['properties']) - 1;
$when_ok['properties'][$gear_i]['fields'][1]['when'] = 'has'; // damage only when has
$when_t = chronicler_sheets_parse_template(json_encode($when_ok));
check('field when referencing a sibling toggle parses', is_array($when_t));
check('when carries through', is_array($when_t) && ($when_t['properties']['gear']['fields'][1]['when'] ?? null) === 'has');

$when_bad = json_decode(json_encode($lt), true);
$when_bad['properties'][$gear_i]['fields'][1]['when'] = 'ghost';
check('when referencing a missing field is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($when_bad))));

// A select sibling is a referencable type under the expression language
// (2026-07-13) — no longer restricted to toggles.
$when_bad['properties'][$gear_i]['fields'][1]['when'] = 'kind';
check('when referencing a select sibling parses', is_array(chronicler_sheets_parse_template(json_encode($when_bad))));

$when_bad['properties'][$gear_i]['fields'][1]['when'] = 'damage';
check('when referencing itself is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($when_bad))));

$when_bad['properties'][$gear_i]['fields'][1]['when'] = true;
check('non-string when is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($when_bad))));

// --- List-field "when" is an expression (2026-07-13): same fence as derived,
// scoped to the entry's sibling fields. ---
$chr_mk_when = function ($when) {
    return json_encode([
        'system' => 'When Demo', 'version' => 1,
        'properties' => [
            ['id' => 'gear', 'label' => 'Gear', 'type' => 'list', 'fields' => [
                ['id' => 'is_weapon', 'label' => 'Weapon?', 'type' => 'toggle'],
                ['id' => 'harm_rating', 'label' => 'Harm', 'type' => 'number', 'min' => 0, 'max' => 5],
                ['id' => 'diary', 'label' => 'Diary', 'type' => 'longtext'],
                array_merge(['id' => 'notes', 'label' => 'Notes', 'type' => 'text'], $when === null ? [] : ['when' => $when]),
            ]],
        ],
    ]);
};
check('when: bare toggle still parses', is_array(chronicler_sheets_parse_template($chr_mk_when('is_weapon'))));
check('when: expression parses', is_array(chronicler_sheets_parse_template($chr_mk_when('is_weapon and harm_rating >= 3'))));
check('when: unknown identifier rejected', is_wp_error(chronicler_sheets_parse_template($chr_mk_when('is_wepon'))));
check('when: longtext sibling not referencable', is_wp_error(chronicler_sheets_parse_template($chr_mk_when('diary'))));
check('when: self-reference rejected', is_wp_error(chronicler_sheets_parse_template($chr_mk_when('notes'))));
check('when: disallowed grammar rejected', is_wp_error(chronicler_sheets_parse_template($chr_mk_when('harm_rating matches "/3/"'))));
check('when: non-string rejected', is_wp_error(chronicler_sheets_parse_template($chr_mk_when(['is_weapon']))));
check('when: empty string rejected', is_wp_error(chronicler_sheets_parse_template($chr_mk_when('  '))));

// --- layout sections ---
$secs = chronicler_sheets_layout_sections($t);
check('layout sections preserve template order', $secs[0]['section'] === 'Ratings' && $secs[1]['section'] === 'Status');
check('unreferenced properties collect into trailing Other', end($secs)['section'] === 'Other' && end($secs)['properties'] === ['moves', 'look']);
$empty_layout = json_decode($motw, true);
$empty_layout['layout'] = [];
$el = chronicler_sheets_parse_template(json_encode($empty_layout));
$el_secs = is_array($el) ? chronicler_sheets_layout_sections($el) : [];
check('empty layout yields one Other section with every property', count($el_secs) === 1 && $el_secs[0]['properties'] === array_keys($el['properties']));

// --- entry_label ---
$elbl = json_decode(json_encode($lt), true);
$elbl['properties'][count($elbl['properties']) - 1]['entry_label'] = 'Gear';
check('valid entry_label parses', is_array(chronicler_sheets_parse_template(json_encode($elbl))));
$elbl['properties'][count($elbl['properties']) - 1]['entry_label'] = '';
check('empty entry_label is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($elbl))));
$elbl['properties'][count($elbl['properties']) - 1]['entry_label'] = 42;
check('non-string entry_label is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($elbl))));

// --- detail annotation ---
$dt = json_decode($motw, true);
$dt['properties'][0]['detail'] = 'Manipulate Someone';
check('valid detail parses', is_array(chronicler_sheets_parse_template(json_encode($dt))));
$dt['properties'][0]['detail'] = '';
check('empty detail is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($dt))));
$dt['properties'][0]['detail'] = ['nope'];
check('non-string detail is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($dt))));

// --- masthead sections ---
$mh = json_decode($motw, true);
$mh['layout'][0]['masthead'] = true;
$mh_t = chronicler_sheets_parse_template(json_encode($mh));
check('masthead flag parses and carries through', is_array($mh_t) && $mh_t['layout'][0]['masthead'] === true);
check('masthead defaults to false', is_array($mh_t) && $mh_t['layout'][1]['masthead'] === false);
$mh['layout'][0]['masthead'] = 'yes';
check('non-bool masthead is an error', is_wp_error(chronicler_sheets_parse_template(json_encode($mh))));
check('Other section carries masthead false', end($secs)['masthead'] === false);

// --- section ids ---
// The machine name a section is addressed by (/game my stats), mirroring the
// id/label split properties already use: `id` is the name, `section` is the
// heading. Optional in the file, guaranteed after normalization.
check('a heading derives its id', chronicler_sheets_section_id('Ratings') === 'ratings');
check('non-alphanumeric runs collapse to one underscore', chronicler_sheets_section_id('Moves & Gear') === 'moves_gear');
check('surrounding and inner whitespace collapses too', chronicler_sheets_section_id('  Spaced  Out ') === 'spaced_out');
check('a heading that cannot make a valid id derives null', chronicler_sheets_section_id('12 Things') === null);
check('a heading with nothing alphanumeric derives null', chronicler_sheets_section_id('!!!') === null);
check('an empty heading derives null', chronicler_sheets_section_id('') === null);

check(
    'an id-less template still parses and every entry gains a derived id',
    is_array($t) && $t['layout'][0]['id'] === 'ratings' && $t['layout'][1]['id'] === 'status'
);
check('the synthetic trailing Other section carries id "other"', end($secs)['id'] === 'other');

$sid_explicit = json_decode($motw, true);
$sid_explicit['layout'][0]['id'] = 'stats';
$sid_explicit_t = chronicler_sheets_parse_template(json_encode($sid_explicit));
check('an explicit id beats derivation', is_array($sid_explicit_t) && $sid_explicit_t['layout'][0]['id'] === 'stats');
check('the heading is left alone by an explicit id', is_array($sid_explicit_t) && $sid_explicit_t['layout'][0]['section'] === 'Ratings');

$sid_bad = json_decode($motw, true);
$sid_bad['layout'][0]['id'] = 'Stats!';
check('an explicit id must match the id pattern', is_wp_error(chronicler_sheets_parse_template(json_encode($sid_bad))));

// "Ratings!" derives "ratings" too — the collision the parser has to catch,
// since two sections answering to one name make /game my ambiguous.
$sid_dup = json_decode($motw, true);
$sid_dup['layout'][1]['section'] = 'Ratings!';
$sid_dup_err = chronicler_sheets_parse_template(json_encode($sid_dup));
check('two sections deriving the same id is a save error', is_wp_error($sid_dup_err));
check('the collision error names the id', is_wp_error($sid_dup_err) && strpos($sid_dup_err->get_error_message(), 'ratings') !== false);

$sid_dup_explicit = json_decode($motw, true);
$sid_dup_explicit['layout'][1]['id'] = 'ratings';
check('an explicit id colliding with a derived one is a save error', is_wp_error(chronicler_sheets_parse_template(json_encode($sid_dup_explicit))));

$sid_underivable = json_decode($motw, true);
$sid_underivable['layout'][0]['section'] = '12 Things';
$sid_underivable_err = chronicler_sheets_parse_template(json_encode($sid_underivable));
check('an underivable heading with no explicit id is a save error', is_wp_error($sid_underivable_err));
check(
    'the underivable error names the section it is about',
    is_wp_error($sid_underivable_err) && strpos($sid_underivable_err->get_error_message(), '12 Things') !== false
);
$sid_underivable['layout'][0]['id'] = 'twelve_things';
check('an explicit id rescues an underivable heading', is_array(chronicler_sheets_parse_template(json_encode($sid_underivable))));

// Section keys had no allowlist until now, so `mastheed: true` saved
// silently and did nothing — the same hazard the property allowlist closed.
$sid_typo = json_decode($motw, true);
$sid_typo['layout'][0]['mastheed'] = true;
$sid_typo_err = chronicler_sheets_parse_template(json_encode($sid_typo));
check('an unknown section key is a save error', is_wp_error($sid_typo_err));
check(
    'the unknown-key error suggests the key that was meant',
    is_wp_error($sid_typo_err) && strpos($sid_typo_err->get_error_message(), 'masthead') !== false
);

// A template stored before ids existed must keep RENDERING, so the lenient
// read path degrades to a positional id rather than failing the whole sheet.
$sid_lenient = json_decode($motw, true);
$sid_lenient['layout'][0]['section'] = '12 Things';
$sid_lenient_t = chronicler_sheets_parse_template(json_encode($sid_lenient), true);
check('a lenient read of an underivable heading still parses', is_array($sid_lenient_t));
check('the lenient fallback id is positional', is_array($sid_lenient_t) && $sid_lenient_t['layout'][0]['id'] === 'section_1');
check('the lenient fallback leaves the other ids derived', is_array($sid_lenient_t) && $sid_lenient_t['layout'][1]['id'] === 'status');

// --- unfilled / placeholder detection (issue #67) ---
check('empty text is unfilled', chronicler_sheets_is_unfilled($props['look'], ''));
check('whitespace-only text is unfilled', chronicler_sheets_is_unfilled($props['look'], "  \n "));
check('bracket-wrapped placeholder text is unfilled', chronicler_sheets_is_unfilled($props['look'], '[character name]'));
check('real text is not unfilled', !chronicler_sheets_is_unfilled($props['look'], 'Egor the Cook'));
check('an unclosed bracket is not treated as a placeholder', !chronicler_sheets_is_unfilled($props['look'], '[oops'));
check('text with two separate bracketed spans is not a placeholder', !chronicler_sheets_is_placeholder_text('[Cursed] Blade [broken]'));
check('adjacent bracket pairs are not a placeholder', !chronicler_sheets_is_placeholder_text('[a][b]'));
check('a single bracket-wrapped placeholder is still a placeholder', chronicler_sheets_is_placeholder_text('[quantum backpack object 1]'));

check('a number property is never unfilled, even at zero', is_array($nt) && !chronicler_sheets_is_unfilled($nt['properties']['counter_prop'], 0));
check('a toggle property is never unfilled when off', is_array($nt) && !chronicler_sheets_is_unfilled($nt['properties']['toggle_prop'], false));
check('a select property is never unfilled at its default option', is_array($nt) && !chronicler_sheets_is_unfilled($nt['properties']['select_prop'], 'opt_a'));
check('a checklist is never unfilled with nothing checked', !chronicler_sheets_is_unfilled($props['moves'], []));

check('a list with only placeholder-shaped entries is unfilled', $gear !== null && chronicler_sheets_is_unfilled($gear, [
    ['name' => '[quantum backpack object 1]'],
]));
check('a list with at least one real entry is not unfilled', $gear !== null && !chronicler_sheets_is_unfilled($gear, [
    ['name' => '[quantum backpack object 1]'],
    ['name' => 'Cutlass', 'damage' => 2],
]));
check('an unset list is unfilled', $gear !== null && chronicler_sheets_is_unfilled($gear, []));

$filtered = $gear !== null ? chronicler_sheets_filter_placeholder_entries($gear, [
    ['name' => '[quantum backpack object 1]'],
    ['name' => 'Cutlass', 'damage' => 2],
    ['name' => '', 'damage' => 0, 'has' => false, 'kind' => 'weapon'],
]) : [];
check('filter_placeholder_entries drops placeholder rows and keeps real ones', count($filtered) === 1 && $filtered[0]['name'] === 'Cutlass');

check('a real toggle value keeps its row even with a blank name', $gear !== null && count(chronicler_sheets_filter_placeholder_entries($gear, [
    ['name' => '', 'has' => true],
])) === 1);
check('an all-default row (name placeholder, everything else default) is dropped', $gear !== null && chronicler_sheets_filter_placeholder_entries($gear, [
    ['name' => '[quantum backpack object 1]', 'damage' => 0, 'has' => false, 'kind' => 'weapon'],
]) === []);

// --- #138: YAML source accepted; JSON stays byte-identical ------------------
$yamlMotw = "system: Monster of the Week\n"
    . "version: 1\n"
    . "properties:\n"
    . "  - id: cool\n"
    . "    label: Cool\n"
    . "    type: number\n"
    . "    min: -1\n"
    . "    max: 3\n"
    . "  - id: harm\n"
    . "    label: Harm\n"
    . "    type: track\n"
    . "    length: 7\n"
    . "layout:\n"
    . "  - section: Ratings\n"
    . "    properties: [cool]\n";
$py = chronicler_sheets_parse_template($yamlMotw);
check('YAML template parses', is_array($py), is_wp_error($py) ? $py->get_error_message() : '');
check(
    'YAML parses to the same structure as its JSON twin',
    is_array($py)
        && $py['system'] === 'Monster of the Week'
        && isset($py['properties']['harm'])
        && $py['properties']['cool']['max'] === 3
);

// A multi-line derived formula via a YAML block scalar — the #138 motivation.
$yamlDerived = "system: Formula Demo\n"
    . "version: 1\n"
    . "properties:\n"
    . "  - id: vigor\n"
    . "    label: Vigor\n"
    . "    type: number\n"
    . "    min: 0\n"
    . "    max: 12\n"
    . "  - id: armor\n"
    . "    label: Armor\n"
    . "    type: number\n"
    . "    min: 0\n"
    . "  - id: toughness\n"
    . "    label: Toughness\n"
    . "    type: number\n"
    . "    max: 12\n"
    . "    derived: |\n"
    . "      floor(vigor / 2)\n"
    . "        + 2\n"
    . "        + armor\n";
$pd = chronicler_sheets_parse_template($yamlDerived);
check('YAML multi-line derived formula parses', is_array($pd), is_wp_error($pd) ? $pd->get_error_message() : '');

// Failure modes (a real tab forces a YAML parse error; a bare scalar is not a template).
check('malformed YAML is an error', is_wp_error(chronicler_sheets_parse_template("parent:\n\tchild: 1")));
check('bare-scalar YAML is an error', is_wp_error(chronicler_sheets_parse_template('just a string')));

// The existing JSON path is untouched.
check('JSON template still parses', is_array(chronicler_sheets_parse_template($motw)));

// --- #183: the opinions type (per-PC opinion sets on NPC pages) --------------
$chr_opinions_tpl = function (array $overrides = []) {
    return json_encode([
        'system' => 'Test', 'version' => 1,
        'properties' => [
            array_merge(['id' => 'opinions', 'label' => 'Opinion', 'type' => 'opinions', 'length' => 6], $overrides),
        ],
    ]);
};

$po = chronicler_sheets_parse_template($chr_opinions_tpl());
check('opinions property parses', is_array($po), is_wp_error($po) ? $po->get_error_message() : '');
check('opinions needs a length', is_wp_error(chronicler_sheets_parse_template($chr_opinions_tpl(['length' => null]))));
check('opinions length must be positive', is_wp_error(chronicler_sheets_parse_template($chr_opinions_tpl(['length' => 0]))));

// Opinions are per-PC by construction; the whole-property write flag and the
// audience flags contradict the type and fail the write path.
check('opinions + live is rejected on the write path', is_wp_error(chronicler_sheets_parse_template($chr_opinions_tpl(['live' => true]))));
check('opinions + gm_only is rejected', is_wp_error(chronicler_sheets_parse_template($chr_opinions_tpl(['gm_only' => true]))));
check('opinions + owner_only is rejected', is_wp_error(chronicler_sheets_parse_template($chr_opinions_tpl(['owner_only' => true]))));

// Lenient (#140): live is DROPPED (never widen writes); the audience flags
// are KEPT so the generic gates hide the sets — hidden beats leaked.
$po_lenient = chronicler_sheets_parse_template($chr_opinions_tpl(['live' => true]), true);
check('lenient: opinions live flag dropped', is_array($po_lenient) && $po_lenient['properties']['opinions']['live'] === false);
$po_lenient_gm = chronicler_sheets_parse_template($chr_opinions_tpl(['gm_only' => true]), true);
check('lenient: opinions gm_only kept (fail closed)', is_array($po_lenient_gm) && chronicler_sheets_is_gm_only($po_lenient_gm['properties']['opinions']));

// There is no `private` knob: each set is private by construction (a
// player's personal notebook), so the key is unknown and fails the write
// path like any typo would.
check('a private key on opinions is rejected as unknown', is_wp_error(chronicler_sheets_parse_template($chr_opinions_tpl(['private' => true]))));

// Value plumbing: default, normalization, display, and the generic-write refusal.
$chr_op_prop = is_array($po) ? $po['properties']['opinions'] : null;
check('opinions default value is an empty map', $chr_op_prop !== null && chronicler_sheets_default_value($chr_op_prop) === []);
check(
    'normalize clamps rating to the track bounds and defaults notes',
    $chr_op_prop !== null
        && chronicler_sheets_normalize_opinion($chr_op_prop, ['rating' => 99]) === ['rating' => 6, 'notes' => '']
        && chronicler_sheets_normalize_opinion($chr_op_prop, null) === ['rating' => 0, 'notes' => '']
        && chronicler_sheets_normalize_opinion($chr_op_prop, ['rating' => '3', 'notes' => 'wary']) === ['rating' => 3, 'notes' => 'wary']
);
check(
    'opinions display counts filled sets only',
    $chr_op_prop !== null
        && chronicler_sheets_display_value($chr_op_prop, [
            21 => ['rating' => 2, 'notes' => ''],
            22 => ['rating' => 0, 'notes' => 'shifty'],
            23 => ['rating' => 0, 'notes' => ''],
        ]) === '2 opinions'
);
$chr_op_write = $chr_op_prop === null ? null : chronicler_sheets_apply_op($chr_op_prop, [], 'set', []);
check('generic apply_op refuses opinions', is_wp_error($chr_op_write));

// --- rolls (2026-07-25): the named things a character does -------------------
// A roll is declared once in a top-level table so /game roll can resolve a
// name the same disciplined way /game my does — and so a typo'd {col} is an
// error in the Game System editor rather than a mystery at the table.
$chr_roll_tpl = function (array $rolls, array $extra_properties = []) use ($motw) {
    $data = json_decode($motw, true);
    $data['properties'] = array_merge($data['properties'], $extra_properties);
    $data['rolls'] = $rolls;
    return json_encode($data);
};
$chr_why = function ($parsed) {
    return is_wp_error($parsed) ? $parsed->get_error_message() : '';
};

$chr_rolls = chronicler_sheets_parse_template($chr_roll_tpl([
    ['id' => 'act_under_pressure', 'label' => 'Act Under Pressure', 'section' => 'Basic Moves', 'dice' => '2d6 + {cool}', 'detail' => 'when you do something under fire'],
    ['id' => 'last_breath', 'label' => 'Last Breath', 'dice' => '2d6 - {harm["current"]}'],
]));
check('a rolls block parses', is_array($chr_rolls), $chr_why($chr_rolls));
check(
    'rolls normalize to a table keyed by id, in declaration order',
    is_array($chr_rolls) && array_keys($chr_rolls['rolls']) === ['act_under_pressure', 'last_breath']
);
check(
    'a roll keeps its label, section, detail and dice string',
    is_array($chr_rolls)
        && $chr_rolls['rolls']['act_under_pressure']['id'] === 'act_under_pressure'
        && $chr_rolls['rolls']['act_under_pressure']['label'] === 'Act Under Pressure'
        && $chr_rolls['rolls']['act_under_pressure']['section'] === 'Basic Moves'
        && $chr_rolls['rolls']['act_under_pressure']['detail'] === 'when you do something under fire'
        && $chr_rolls['rolls']['act_under_pressure']['dice'] === '2d6 + {cool}'
);
check(
    'a roll carries its parsed dice, so nothing reparses at roll time',
    is_array($chr_rolls)
        && $chr_rolls['rolls']['act_under_pressure']['parsed']['terms'][0]['count'] === 2
        && $chr_rolls['rolls']['act_under_pressure']['parsed']['terms'][1]['expression'] === 'cool'
);
check(
    'an omitted section and detail come back null',
    is_array($chr_rolls) && $chr_rolls['rolls']['last_breath']['section'] === null && $chr_rolls['rolls']['last_breath']['detail'] === null
);
check('a template with no rolls gets an empty rolls table', is_array($t) && $t['rolls'] === []);

$chr_roll_dup = chronicler_sheets_parse_template($chr_roll_tpl([
    ['id' => 'aim', 'label' => 'Aim', 'dice' => '2d6'],
    ['id' => 'aim', 'label' => 'Aim Again', 'dice' => '2d6'],
]));
check('a duplicate roll id is an error', is_wp_error($chr_roll_dup));
check('the duplicate-roll error names the id', is_wp_error($chr_roll_dup) && strpos($chr_roll_dup->get_error_message(), 'aim') !== false);

check(
    'a roll id must match the id pattern',
    is_wp_error(chronicler_sheets_parse_template($chr_roll_tpl([['id' => 'Act Under Pressure', 'label' => 'A', 'dice' => '2d6']])))
);
check(
    'a roll needs a label',
    is_wp_error(chronicler_sheets_parse_template($chr_roll_tpl([['id' => 'aim', 'dice' => '2d6']])))
);
check(
    'a roll needs dice',
    is_wp_error(chronicler_sheets_parse_template($chr_roll_tpl([['id' => 'aim', 'label' => 'Aim']])))
);
check(
    'an empty roll section is an error',
    is_wp_error(chronicler_sheets_parse_template($chr_roll_tpl([['id' => 'aim', 'label' => 'Aim', 'dice' => '2d6', 'section' => '']])))
);

$chr_roll_bad_dice = chronicler_sheets_parse_template($chr_roll_tpl([['id' => 'aim', 'label' => 'Aim', 'dice' => '2x6']]));
check('unparseable dice is a save error', is_wp_error($chr_roll_bad_dice));
check(
    'the dice error quotes the dice problem and names the roll',
    is_wp_error($chr_roll_bad_dice)
        && strpos($chr_roll_bad_dice->get_error_message(), '2x6') !== false
        && strpos($chr_roll_bad_dice->get_error_message(), 'aim') !== false
);

$chr_roll_typo = chronicler_sheets_parse_template($chr_roll_tpl([['id' => 'aim', 'label' => 'Aim', 'dise' => '2d6', 'dice' => '2d6']]));
check('an unknown roll key is a save error', is_wp_error($chr_roll_typo));
check(
    'the unknown-roll-key error suggests the key that was meant',
    is_wp_error($chr_roll_typo) && strpos($chr_roll_typo->get_error_message(), 'dice') !== false
);

// Top-level keys had no allowlist at all until rolls arrived: a mistyped
// "rols:" silently did nothing, which is exactly the hazard the property and
// section allowlists already closed.
$chr_top_typo = json_decode($motw, true);
$chr_top_typo['rols'] = [];
$chr_top_typo_err = chronicler_sheets_parse_template(json_encode($chr_top_typo));
check('an unknown top-level key is a save error', is_wp_error($chr_top_typo_err));
check(
    'the unknown-top-level-key error suggests the key that was meant',
    is_wp_error($chr_top_typo_err) && strpos($chr_top_typo_err->get_error_message(), 'rolls') !== false
);
check('a lenient read still tolerates an unknown top-level key', is_array(chronicler_sheets_parse_template(json_encode($chr_top_typo), true)));

// Placeholders are Expression Language, checked at save through the same
// fence and dry run `derived` uses — against the declared properties, which
// the parser has right there.
$chr_roll_ph = function (string $expression, array $extra_properties = []) use ($chr_roll_tpl) {
    return chronicler_sheets_parse_template($chr_roll_tpl(
        [['id' => 'aim', 'label' => 'Aim', 'dice' => '2d6 + {' . $expression . '}']],
        $extra_properties
    ));
};
check('a placeholder naming an undeclared property is a save error', is_wp_error($chr_roll_ph('col')));
check('a placeholder naming a text property is a save error', is_wp_error($chr_roll_ph('look')));
check('a placeholder naming a checklist property is a save error', is_wp_error($chr_roll_ph('moves')));
check('a placeholder naming a select property is a save error', is_wp_error($chr_roll_ph('kind', [
    ['id' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => [['id' => 'a', 'label' => 'A']]],
])));
check('a bare track reference is a save error (it needs a part)', is_wp_error($chr_roll_ph('harm')));
check('a track part is fine', is_array($chr_roll_ph('harm["current"]')), $chr_why($chr_roll_ph('harm["current"]')));
$chr_roll_arith = $chr_roll_ph('floor(dex / 2)', [['id' => 'dex', 'label' => 'Dexterity', 'type' => 'number', 'min' => 0]]);
check('arithmetic over declared numerics is fine', is_array($chr_roll_arith), $chr_why($chr_roll_arith));
$chr_roll_derived = $chr_roll_ph('str_mod', [
    ['id' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 0],
    ['id' => 'str_mod', 'label' => 'Strength Modifier', 'type' => 'number', 'derived' => 'floor((str - 10) / 2)'],
]);
check('a derived property is fine in a placeholder', is_array($chr_roll_derived), $chr_why($chr_roll_derived));
check('a placeholder that produces true/false rather than a number is a save error', is_wp_error($chr_roll_ph('cool > 1')));
$chr_roll_ph_err = $chr_roll_ph('col');
check(
    'the placeholder error names the roll and quotes the placeholder',
    is_wp_error($chr_roll_ph_err)
        && strpos($chr_roll_ph_err->get_error_message(), 'aim') !== false
        && strpos($chr_roll_ph_err->get_error_message(), 'col') !== false
);

// entry["…"] belongs to character-carried dice ONLY (2026-07-25 Phase B).
// A system roll and a derived formula have no entry in scope, so the strict
// fence keeps refusing the name like any other undeclared one — pinned here
// so the namespace never quietly widens.
check('a system roll placeholder reaching for entry["…"] is a save error', is_wp_error($chr_roll_ph('entry["harm"]')));
check('a derived formula reaching for entry["…"] is a save error', is_wp_error(chronicler_sheets_parse_template($chr_roll_tpl([], [
    ['id' => 'bonus', 'label' => 'Bonus', 'type' => 'number', 'derived' => 'entry["harm"] + 1'],
]))));

// "when" on a roll is GONE (2026-07-25: a move carries its own roll — the
// case it served is a dice field on the character's own list entry now).
// Strictly it is an unknown key like any other; a stored template that
// declared one still reads, the key dropped rather than the roll.
check(
    'a roll carrying when is a save error (the key is gone)',
    is_wp_error(chronicler_sheets_parse_template($chr_roll_tpl(
        [['id' => 'aim', 'label' => 'Aim', 'when' => 'moves["the_big_entrance"]', 'dice' => '2d6 + {cool}']]
    )))
);
check(
    'a roll parses without a when key in its normalized shape',
    is_array($chr_rolls) && !array_key_exists('when', $chr_rolls['rolls']['act_under_pressure'])
);

// A stored template must keep RENDERING whatever a later rule says about it:
// a broken roll drops out, the rest of the sheet is untouched — and a legacy
// `when` is tolerated as an unknown key, keeping the roll.
$chr_roll_lenient = chronicler_sheets_parse_template($chr_roll_tpl([
    ['id' => 'aim', 'label' => 'Aim', 'dice' => '2x6'],
    ['id' => 'duck', 'label' => 'Duck', 'when' => 'nonesuch', 'dice' => '2d6'],
    ['id' => 'shoot', 'label' => 'Shoot', 'dice' => '2d6 + {cool}'],
    ['id' => 'shoot', 'label' => 'Shoot Twice', 'dice' => '2d6'],
]), true);
check('a lenient read survives an unrollable roll', is_array($chr_roll_lenient), $chr_why($chr_roll_lenient));
check('the lenient read keeps the rolls that do parse', is_array($chr_roll_lenient) && array_keys($chr_roll_lenient['rolls']) === ['duck', 'shoot']);
check(
    'a stored roll with a legacy when keeps rolling, the key dropped',
    is_array($chr_roll_lenient)
        && isset($chr_roll_lenient['rolls']['duck'])
        && !array_key_exists('when', $chr_roll_lenient['rolls']['duck'])
);
check('the lenient read keeps the first of two rolls sharing an id', is_array($chr_roll_lenient) && $chr_roll_lenient['rolls']['shoot']['label'] === 'Shoot');
check('an empty rolls list parses to an empty table', ($x = chronicler_sheets_parse_template($chr_roll_tpl([]))) && is_array($x) && $x['rolls'] === []);
$chr_rolls_scalar = json_encode(array_merge(json_decode($motw, true), ['rolls' => 'nope']));
check('a non-list rolls key is a save error', is_wp_error(chronicler_sheets_parse_template($chr_rolls_scalar)));
check(
    'a lenient read of a non-list rolls key drops it',
    ($x = chronicler_sheets_parse_template($chr_rolls_scalar, true)) && is_array($x) && $x['rolls'] === []
);

// --- dice list fields (2026-07-25: a move carries its own roll) --------------
// A list entry may carry dice notation in a `dice`-typed field. The canonical
// shape gates it on a sibling toggle so only a taken move contributes.
$chr_dice_list = function (array $moves_extra = [], array $prop_extra = []) {
    return json_encode([
        'system' => 'MotW', 'version' => 1,
        'properties' => [
            ['id' => 'sharp', 'label' => 'Sharp', 'type' => 'number', 'min' => -1, 'max' => 3],
            array_merge([
                'id' => 'moves', 'label' => 'Moves', 'type' => 'list',
                'fields' => array_merge([
                    ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['id' => 'effect', 'label' => 'Description', 'type' => 'longtext'],
                    ['id' => 'has', 'label' => 'Has it', 'type' => 'toggle'],
                    ['id' => 'dice', 'label' => 'Roll', 'type' => 'dice', 'when' => 'has'],
                ], $moves_extra),
            ], $prop_extra),
        ],
    ]);
};
$chr_dice_parsed = chronicler_sheets_parse_template($chr_dice_list());
check('a list with a dice field parses', is_array($chr_dice_parsed), $chr_why($chr_dice_parsed));
check(
    'the dice field round-trips with its when',
    is_array($chr_dice_parsed)
        && $chr_dice_parsed['properties']['moves']['fields'][3]['type'] === 'dice'
        && $chr_dice_parsed['properties']['moves']['fields'][3]['when'] === 'has'
);

// label_field: which field names an entry in roll menus. Explicit beats the
// first-text-field convention, and a wrong designation is a save error rather
// than a silently wrong label.
$chr_lf_ok = chronicler_sheets_parse_template($chr_dice_list([], ['label_field' => 'name']));
check('label_field naming a declared text field parses', is_array($chr_lf_ok), $chr_why($chr_lf_ok));
check(
    'label_field carries through',
    is_array($chr_lf_ok) && ($chr_lf_ok['properties']['moves']['label_field'] ?? null) === 'name'
);
check(
    'label_field naming an undeclared field is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_dice_list([], ['label_field' => 'ghost'])))
);
check(
    'label_field naming a non-text field is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_dice_list([], ['label_field' => 'has'])))
);
$chr_lf_scalar = json_decode($motw, true);
$chr_lf_scalar['properties'][0]['label_field'] = 'name';
check(
    'label_field on a non-list property is a save error',
    is_wp_error(chronicler_sheets_parse_template(json_encode($chr_lf_scalar)))
);

// A dice VALUE is player data and writes leniently: apply_op stores it as
// trimmed text, parseable or not. The read path (the collector) is where an
// unparseable string becomes inert — never a lost save (§5 correction).
$chr_dice_prop = is_array($chr_dice_parsed) ? $chr_dice_parsed['properties']['moves'] : null;
$chr_dice_written = $chr_dice_prop === null ? null : chronicler_sheets_apply_op($chr_dice_prop, [], 'set', [
    ['name' => 'Read About This', 'has' => true, 'dice' => ' 2d6 + {sharp} '],
    ['name' => 'Typo Move', 'has' => true, 'dice' => '2x6 nonsense'],
]);
check(
    'a parseable dice value writes as trimmed text',
    is_array($chr_dice_written) && $chr_dice_written[0]['dice'] === '2d6 + {sharp}'
);
check(
    'an unparseable dice value still writes (lenient — inertness is read-side)',
    is_array($chr_dice_written) && $chr_dice_written[1]['dice'] === '2x6 nonsense'
);

// `entry` is reserved as a property id (Phase B's entry["…"] namespace).
// Strict save refuses; a hypothetical stored template keeps rendering.
$chr_entry_id = json_decode($motw, true);
$chr_entry_id['properties'][] = ['id' => 'entry', 'label' => 'Entry', 'type' => 'number'];
check(
    'a property id of "entry" is a save error (reserved)',
    is_wp_error(chronicler_sheets_parse_template(json_encode($chr_entry_id)))
);
$chr_entry_msg = chronicler_sheets_parse_template(json_encode($chr_entry_id));
check(
    'the reservation error says the word is reserved',
    is_wp_error($chr_entry_msg) && stripos($chr_entry_msg->get_error_message(), 'reserved') !== false
);
check(
    'a stored template with an entry property still parses leniently',
    is_array(chronicler_sheets_parse_template(json_encode($chr_entry_id), true))
);
// List FIELD ids stay free — entry scoping never nests, so a field named
// entry shadows nothing.
$chr_entry_field = chronicler_sheets_parse_template($chr_dice_list([
    ['id' => 'entry', 'label' => 'Entry note', 'type' => 'text'],
]));
check('a list FIELD named entry is fine', is_array($chr_entry_field), $chr_why($chr_entry_field));

// --- dice properties (2026-08-04: a pool is a property) ---------------------
// A `dice` property holds the character's own notation — the thing an
// attributes-as-list workaround used to fake. Declared like any property;
// its VALUE is player data and writes leniently, exactly as a dice field's
// does (the read path is where an unparseable pool becomes inert).
$chr_pool_tpl = function (array $overrides = []) {
    return json_encode([
        'system' => 'HOT DOG HOEDOWN', 'version' => 1,
        'properties' => [
            array_merge(['id' => 'gut', 'label' => 'Gut', 'type' => 'dice'], $overrides),
        ],
    ]);
};
$chr_pool = chronicler_sheets_parse_template($chr_pool_tpl());
check('a dice property parses', is_array($chr_pool), $chr_why($chr_pool));
check('a dice property keeps its type', is_array($chr_pool) && $chr_pool['properties']['gut']['type'] === 'dice');
$chr_pool_prop = is_array($chr_pool) ? $chr_pool['properties']['gut'] : null;
check('a dice property defaults to an empty pool', $chr_pool_prop !== null && chronicler_sheets_default_value($chr_pool_prop) === '');
check(
    'a dice value writes as trimmed text, parseable or not',
    $chr_pool_prop !== null
        && chronicler_sheets_apply_op($chr_pool_prop, '', 'set', ' 2d6+1d4 ') === '2d6+1d4'
        && chronicler_sheets_apply_op($chr_pool_prop, '', 'set', '2x6 nonsense') === '2x6 nonsense'
);

// A pool is notation, not a number: nothing computes one, so `derived` gets
// the same refusal it gives every other non-numeric type.
$chr_pool_derived = chronicler_sheets_parse_template($chr_pool_tpl(['derived' => '1 + 1']));
check('derived on a dice property is a save error', is_wp_error($chr_pool_derived));
check(
    'the derived refusal keeps the number-and-toggle wording',
    is_wp_error($chr_pool_derived)
        && strpos($chr_pool_derived->get_error_message(), 'derived') !== false
        && strpos($chr_pool_derived->get_error_message(), 'number and toggle') !== false
);

// "traits" (2026-08-04): author-defined attributes that say what a roll IS,
// so an effect can target a category ("save: dexterity") without every
// system's vocabulary becoming a core key. On a property they ride its own
// roll — which is why only a dice property may carry them.
$chr_pool_traits = chronicler_sheets_parse_template($chr_pool_tpl(['traits' => ['check' => true, 'save' => 'dexterity']]));
check('a dice property may carry traits', is_array($chr_pool_traits), $chr_why($chr_pool_traits));
check(
    'the traits map round-trips onto the parsed property',
    is_array($chr_pool_traits) && ($chr_pool_traits['properties']['gut']['traits'] ?? null) === ['check' => true, 'save' => 'dexterity']
);
$chr_pool_shadow = chronicler_sheets_parse_template($chr_pool_tpl(['traits' => ['uses' => 1]]));
check('a trait shadowing a reserved roll key is a save error', is_wp_error($chr_pool_shadow));
check(
    'the shadowed-trait error names the key',
    is_wp_error($chr_pool_shadow) && strpos($chr_pool_shadow->get_error_message(), 'uses') !== false
);
check(
    'a trait name outside the id pattern is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_pool_tpl(['traits' => ['Bad-Key' => 1]])))
);
check(
    'a non-scalar trait value is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_pool_tpl(['traits' => ['tags' => [1, 2]]])))
);
check(
    'a traits key that is not a map at all is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_pool_tpl(['traits' => 'nope'])))
);
$chr_traits_on_number = json_decode($motw, true);
$chr_traits_on_number['properties'][0]['traits'] = ['check' => true];
check(
    'traits on a property with no roll of its own is a save error',
    is_wp_error(chronicler_sheets_parse_template(json_encode($chr_traits_on_number)))
);

// --- traits on rolls and dice fields (2026-08-04) ---------------------------
// A roll wears its own traits; a dice FIELD's traits ride every roll its
// entries contribute (tag the field once and every weapon is an "attack").
// Both go through the same validator a dice property's traits do.
$chr_roll_traits = chronicler_sheets_parse_template($chr_roll_tpl([
    ['id' => 'act_under_pressure', 'label' => 'Act Under Pressure', 'dice' => '2d6 + {cool}', 'traits' => ['basic_move' => true, 'save' => 'cool']],
]));
check('a roll may carry traits', is_array($chr_roll_traits), $chr_why($chr_roll_traits));
check(
    'the roll\'s traits round-trip onto the parsed roll',
    is_array($chr_roll_traits)
        && $chr_roll_traits['rolls']['act_under_pressure']['traits'] === ['basic_move' => true, 'save' => 'cool']
);
check(
    'a roll with no traits still carries the empty map',
    is_array($chr_rolls) && $chr_rolls['rolls']['last_breath']['traits'] === []
);
$chr_roll_shadow = chronicler_sheets_parse_template($chr_roll_tpl([
    ['id' => 'act_under_pressure', 'label' => 'Act Under Pressure', 'dice' => '2d6 + {cool}', 'traits' => ['section' => 'basic']],
]));
check(
    'a roll trait shadowing a reserved roll key is a save error naming it',
    is_wp_error($chr_roll_shadow) && strpos($chr_roll_shadow->get_error_message(), 'section') !== false
);
check(
    'a non-scalar roll trait is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_roll_tpl([
        ['id' => 'act_under_pressure', 'label' => 'Act Under Pressure', 'dice' => '2d6', 'traits' => ['tags' => [1, 2]]],
    ])))
);

$chr_field_traits = chronicler_sheets_parse_template($chr_dice_list([], []));
check('the untraited dice field parses as before', is_array($chr_field_traits), $chr_why($chr_field_traits));
$chr_field_traits = chronicler_sheets_parse_template(str_replace(
    '{"id":"dice","label":"Roll","type":"dice","when":"has"}',
    '{"id":"dice","label":"Roll","type":"dice","when":"has","traits":{"attack":true}}',
    $chr_dice_list()
));
check('a dice field may carry traits', is_array($chr_field_traits), $chr_why($chr_field_traits));
check(
    'the field\'s traits round-trip onto the parsed field',
    is_array($chr_field_traits) && ($chr_field_traits['properties']['moves']['fields'][3]['traits'] ?? null) === ['attack' => true]
);
$chr_field_traits_wrong = chronicler_sheets_parse_template($chr_dice_list([
    ['id' => 'tags', 'label' => 'Tags', 'type' => 'text', 'traits' => ['attack' => true]],
]));
check(
    'traits on a field with no roll of its own is a save error naming the field',
    is_wp_error($chr_field_traits_wrong) && strpos($chr_field_traits_wrong->get_error_message(), 'tags') !== false
);
check(
    'a field trait shadowing a reserved roll key is a save error',
    is_wp_error(chronicler_sheets_parse_template(str_replace(
        '{"id":"dice","label":"Roll","type":"dice","when":"has"}',
        '{"id":"dice","label":"Roll","type":"dice","when":"has","traits":{"uses":1}}',
        $chr_dice_list()
    )))
);

// --- the effects vocabulary (2026-08-04) ------------------------------------
// A template declares WHAT modifiers exist; nothing lands on a character
// until a game master applies one. The definition is the authority at
// evaluation time, so its shape is checked here, once, at save.
$chr_effect_tpl = function (array $effects) use ($motw) {
    $data = json_decode($motw, true);
    $data['rolls'] = [
        ['id' => 'act_under_pressure', 'label' => 'Act Under Pressure', 'dice' => '2d6 + {cool}', 'traits' => ['basic_move' => true]],
    ];
    $data['effects'] = $effects;
    return json_encode($data);
};
$chr_effects = chronicler_sheets_parse_template($chr_effect_tpl([
    ['id' => 'forward', 'label' => 'Forward', 'detail' => '+1 to what you roll next', 'modifier' => 1, 'cap' => 2],
    ['id' => 'terrified', 'label' => 'Terrified', 'modifier' => "roll['basic_move'] ? -amount : 0", 'cap' => -2],
    ['id' => 'steadied', 'label' => 'Steadied', 'applies_to' => 'basic_move', 'modifier' => 1],
]));
check('an effects block parses', is_array($chr_effects), $chr_why($chr_effects));
check(
    'effects come back keyed by id, whole',
    is_array($chr_effects) && array_keys($chr_effects['effects']) === ['forward', 'terrified', 'steadied']
        && $chr_effects['effects']['forward'] === [
            'id' => 'forward', 'label' => 'Forward', 'detail' => '+1 to what you roll next',
            'modifier' => 1, 'applies_to' => null, 'cap' => 2,
        ]
);
check(
    'an expression modifier is kept as written, trimmed',
    is_array($chr_effects) && $chr_effects['effects']['terrified']['modifier'] === "roll['basic_move'] ? -amount : 0"
);
check(
    'a template with no effects block has an empty vocabulary',
    is_array($chr_rolls) && $chr_rolls['effects'] === []
);
check(
    'a non-list effects key is a save error',
    is_wp_error(chronicler_sheets_parse_template(str_replace('"effects":[]', '"effects":"none"', $chr_effect_tpl([]))))
);
check(
    'a lenient read of a non-list effects key drops it',
    ($x = chronicler_sheets_parse_template(str_replace('"effects":[]', '"effects":"none"', $chr_effect_tpl([])), true))
        && is_array($x) && $x['effects'] === []
);

check(
    'an effect id outside the id pattern is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_effect_tpl([['id' => 'Forward!', 'label' => 'Forward', 'modifier' => 1]])))
);
check(
    'two effects sharing an id is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_effect_tpl([
        ['id' => 'forward', 'label' => 'Forward', 'modifier' => 1],
        ['id' => 'forward', 'label' => 'Forward Again', 'modifier' => 2],
    ])))
);
check(
    'an effect with no label is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_effect_tpl([['id' => 'forward', 'modifier' => 1]])))
);
$chr_effect_unknown = chronicler_sheets_parse_template($chr_effect_tpl([
    ['id' => 'forward', 'label' => 'Forward', 'modifier' => 1, 'expires' => 'on_use'],
]));
check(
    'an unknown effect key is a save error naming it',
    is_wp_error($chr_effect_unknown) && strpos($chr_effect_unknown->get_error_message(), 'expires') !== false
);

$chr_effect_no_mod = chronicler_sheets_parse_template($chr_effect_tpl([['id' => 'forward', 'label' => 'Forward']]));
check(
    'an effect with no modifier is a save error — the modifier IS the behavior',
    is_wp_error($chr_effect_no_mod) && strpos($chr_effect_no_mod->get_error_message(), 'modifier') !== false
);
check(
    'a true/false modifier is a save error, not a quiet 1',
    is_wp_error(chronicler_sheets_parse_template($chr_effect_tpl([['id' => 'forward', 'label' => 'Forward', 'modifier' => true]])))
);
check(
    'an empty modifier string is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_effect_tpl([['id' => 'forward', 'label' => 'Forward', 'modifier' => '   ']])))
);

check(
    'an applies_to word outside the id pattern is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_effect_tpl([
        ['id' => 'forward', 'label' => 'Forward', 'modifier' => 1, 'applies_to' => 'Act Under Pressure'],
    ])))
);
$chr_effect_both = chronicler_sheets_parse_template($chr_effect_tpl([
    ['id' => 'forward', 'label' => 'Forward', 'modifier' => "roll['basic_move'] ? 1 : 0", 'applies_to' => 'basic_move'],
]));
check(
    'applies_to alongside a formula modifier is a save error — one of them is inert',
    is_wp_error($chr_effect_both) && strpos($chr_effect_both->get_error_message(), 'applies_to') !== false
);
check(
    'a non-integer cap is a save error',
    is_wp_error(chronicler_sheets_parse_template($chr_effect_tpl([
        ['id' => 'forward', 'label' => 'Forward', 'modifier' => 1, 'cap' => '2'],
    ])))
);
