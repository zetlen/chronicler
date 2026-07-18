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
$chr_legacy_parsed = chronicler_sheets_parse_template($chr_legacy_rules);
check('a stored template with a legacy rules key still parses', is_array($chr_legacy_parsed));
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
