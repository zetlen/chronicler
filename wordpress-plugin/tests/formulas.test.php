<?php
// Derived-formula engine tests (#88). Included by run.php after schema.php
// and formulas.php; needs the plugin's vendor tree (symfony/expression-
// language), which scripts/test-php.mjs provisions via the composer image.

// --- the canonical template ----------------------------------------------------

$formula_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Formula Test',
    'version' => 1,
    'properties' => [
        ['id' => 'vigor', 'label' => 'Vigor', 'type' => 'number', 'min' => 0, 'max' => 12],
        ['id' => 'armor', 'label' => 'Armor', 'type' => 'number', 'min' => 0],
        ['id' => 'harm', 'label' => 'Harm', 'type' => 'track', 'length' => 7],
        ['id' => 'ammo', 'label' => 'Ammo', 'type' => 'counter', 'start' => 3],
        ['id' => 'playbook', 'label' => 'Playbook', 'type' => 'select', 'options' => [
            ['id' => 'mundane', 'label' => 'The Mundane'],
            ['id' => 'spooky', 'label' => 'The Spooky'],
        ]],
        ['id' => 'moves', 'label' => 'Moves', 'type' => 'checklist', 'options' => [
            ['id' => 'a', 'label' => 'A'],
            ['id' => 'read_about_this', 'label' => "I've Read About This Sort of Thing"],
        ]],
        ['id' => 'toughness', 'label' => 'Toughness', 'type' => 'number', 'max' => 12, 'derived' => 'floor(vigor / 2) + 2 + armor'],
        ['id' => 'bloodied', 'label' => 'Bloodied', 'type' => 'toggle', 'derived' => 'harm["current"] >= harm["max"] / 2'],
        ['id' => 'doubled', 'label' => 'Doubled', 'type' => 'number', 'derived' => 'toughness * 2'],
        // A checklist option is a 0/1 fact about the character, so a formula
        // can branch on one (2026-07-25 §4b: this is what lets a MOVE change
        // what a character can roll).
        ['id' => 'prepared', 'label' => 'Prepared', 'type' => 'number', 'derived' => 'moves["read_about_this"] ? 2 : 0'],
    ],
    'layout' => [],
]));
check('formula fixture template parses', is_array($formula_template), is_wp_error($formula_template) ? $formula_template->get_error_message() : '');

// --- context shape --------------------------------------------------------------

$ctx = chronicler_sheets_formula_context($formula_template, ['vigor' => 7, 'harm' => 3, 'ammo' => 2, 'playbook' => 'spooky']);
check('context: number is scalar', $ctx['vigor'] === 7);
check('context: track becomes {current, max}', $ctx['harm'] === ['current' => 3, 'max' => 7]);
check('context: counter without max has only current', $ctx['ammo'] === ['current' => 2]);
check('context: select is its id string', $ctx['playbook'] === 'spooky');
// A checklist contributes its OPTION IDS as parts, each 0 or 1 — the same
// shape track/counter use, addressed the same bracket way (§4b).
check('context: unchecked checklist is a 0/1 map of its options', $ctx['moves'] === ['a' => 0, 'read_about_this' => 0]);
$ctx_checked = chronicler_sheets_formula_context($formula_template, ['moves' => ['read_about_this']]);
check('context: a checked option is 1', $ctx_checked['moves'] === ['a' => 0, 'read_about_this' => 1]);

// --- fence + reference checking -------------------------------------------------

$ok = chronicler_sheets_formula_check('floor(vigor / 2) + 2 + armor', $formula_template);
check('check: canonical arithmetic passes', is_array($ok));
check('check: refs collected', is_array($ok) && $ok['refs'] === ['vigor', 'armor']);

$ok = chronicler_sheets_formula_check('harm["current"] < harm["max"] / 2 ? 1 : 0', $formula_template);
check('check: bracket refs + ternary pass', is_array($ok), is_wp_error($ok) ? $ok->get_error_message() : '');

// The instinctive dot spelling gets pointed at the bracket one.
$err = chronicler_sheets_formula_check('harm.current + 1', $formula_template);
check(
    'check: dot access is guided to brackets',
    is_wp_error($err)
        && str_contains($err->get_error_message(), "Don't use dots")
        && str_contains($err->get_error_message(), 'harm["current"]')
);

$ok = chronicler_sheets_formula_check('playbook == "spooky" and vigor > 3', $formula_template);
check('check: select comparison with a string passes', is_array($ok), is_wp_error($ok) ? $ok->get_error_message() : '');

$ok = chronicler_sheets_formula_check('moves["read_about_this"] and vigor > 3', $formula_template);
check('check: a checklist option reference passes', is_array($ok), is_wp_error($ok) ? $ok->get_error_message() : '');
check('check: a checklist ref is collected under its property id', is_array($ok) && $ok['refs'] === ['moves', 'vigor']);

$err = chronicler_sheets_formula_check('moves + 1', $formula_template);
check(
    'check: bare checklist ref is rejected with guidance',
    is_wp_error($err) && str_contains($err->get_error_message(), 'moves["a"]')
);

$err = chronicler_sheets_formula_check('moves["read_about_that"]', $formula_template);
check(
    'check: an unknown checklist option names itself and the real ones',
    is_wp_error($err)
        && str_contains($err->get_error_message(), 'read_about_that')
        && str_contains($err->get_error_message(), 'read_about_this')
);

$err = chronicler_sheets_formula_check('vigour / 2', $formula_template);
check('check: unknown name is a positional error', is_wp_error($err) && str_contains($err->get_error_message(), 'vigour'));

$err = chronicler_sheets_formula_check('harm + 1', $formula_template);
check('check: bare track ref is rejected with guidance', is_wp_error($err) && str_contains($err->get_error_message(), 'harm["current"]'));

$err = chronicler_sheets_formula_check('harm["bogus"]', $formula_template);
check('check: unknown part names the real ones', is_wp_error($err) && str_contains($err->get_error_message(), '"current"'));

$err = chronicler_sheets_formula_check('vigor["current"]', $formula_template);
check('check: subscripting a scalar is rejected', is_wp_error($err));

// Unregistered functions die at the engine's own parse step (named in the
// message); the FunctionNode fence stays as defense-in-depth.
$err = chronicler_sheets_formula_check('sqrt(vigor)', $formula_template);
check('check: unknown function is rejected by name', is_wp_error($err) && str_contains($err->get_error_message(), 'sqrt'));

$err = chronicler_sheets_formula_check('[1, 2, 3]', $formula_template);
check('check: array literals are fenced', is_wp_error($err));

// --- ordering + cycles ----------------------------------------------------------

$order = chronicler_sheets_derived_order($formula_template);
check('order: chained derived resolves dependencies first', is_array($order) && array_search('toughness', $order) < array_search('doubled', $order));

$cyclic = chronicler_sheets_parse_template(json_encode([
    'system' => 'Cycle', 'version' => 1,
    'properties' => [
        ['id' => 'a', 'label' => 'A', 'type' => 'number', 'derived' => 'b + 1'],
        ['id' => 'b', 'label' => 'B', 'type' => 'number', 'derived' => 'a + 1'],
    ],
    'layout' => [],
]));
check('parse: a derived cycle is rejected', is_wp_error($cyclic) && str_contains($cyclic->get_error_message(), 'cycle'));

// --- computation ----------------------------------------------------------------

$values = ['vigor' => 7, 'armor' => 1, 'harm' => 4, 'ammo' => 3, 'playbook' => 'mundane', 'moves' => []];
$computed = chronicler_sheets_compute_derived($formula_template, $values);
check('compute: SWADE toughness floors explicitly', $computed['toughness'] === 6);
check('compute: bloodied-at-half uses real division (4 >= 3.5)', $computed['bloodied'] === true);
check('compute: chained derived builds on computed values', $computed['doubled'] === 12);

$computed = chronicler_sheets_compute_derived($formula_template, ['vigor' => 7, 'armor' => 1, 'harm' => 3] + $values);
check('compute: bloodied false below the threshold (3 < 3.5)', $computed['bloodied'] === false);

$computed = chronicler_sheets_compute_derived($formula_template, ['vigor' => 12, 'armor' => 99, 'harm' => 0] + $values);
check('compute: numbers clamp to the property bounds', $computed['toughness'] === 12);

// Both states of the gating move, since that is the whole point of §4b.
check('compute: an unchecked option reads as 0', chronicler_sheets_compute_derived($formula_template, $values)['prepared'] === 0);
check(
    'compute: a checked option reads as 1',
    chronicler_sheets_compute_derived($formula_template, ['moves' => ['read_about_this']] + $values)['prepared'] === 2
);

// --- parse-time validation ------------------------------------------------------

$base = [
    'system' => 'V', 'version' => 1, 'layout' => [],
    'properties' => [['id' => 'x', 'label' => 'X', 'type' => 'number']],
];

$t = $base;
$t['properties'][] = ['id' => 'd', 'label' => 'D', 'type' => 'text', 'derived' => 'x + 1'];
$err = chronicler_sheets_parse_template(json_encode($t));
check('parse: derived on text is rejected', is_wp_error($err));

$t = $base;
$t['properties'][] = ['id' => 'd', 'label' => 'D', 'type' => 'number', 'live' => true, 'derived' => 'x + 1'];
$err = chronicler_sheets_parse_template(json_encode($t));
check('parse: derived cannot be live', is_wp_error($err));

$t = $base;
$t['properties'][] = ['id' => 'd', 'label' => 'D', 'type' => 'toggle', 'derived' => 'x + 1'];
$err = chronicler_sheets_parse_template(json_encode($t));
check('parse: toggle formula must produce true/false', is_wp_error($err) && str_contains($err->get_error_message(), 'true/false'));

$t = $base;
$t['properties'][] = ['id' => 'd', 'label' => 'D', 'type' => 'number', 'derived' => 'x > 1'];
$err = chronicler_sheets_parse_template(json_encode($t));
check('parse: number formula must produce a number', is_wp_error($err) && str_contains($err->get_error_message(), 'number'));

// --- Dice pools are splice-only (2026-08-04) --------------------------------------
// A `dice` property holds notation, not a number. A roll placeholder that is
// exactly its id splices the character's own pool; every other use of one is a
// save-time error, because there is no arithmetic on "2d6+1d4".

$chr_pool_template = ['properties' => [
    'gut' => ['id' => 'gut', 'label' => 'Gut', 'type' => 'dice'],
    'nerve' => ['id' => 'nerve', 'label' => 'Nerve', 'type' => 'number', 'min' => 0],
]];
check('pool ref: a bare dice-property id is a splice', chronicler_sheets_formula_pool_ref('gut', $chr_pool_template) === 'gut');
check('pool ref: the braces\' own whitespace doesn\'t matter', chronicler_sheets_formula_pool_ref(' gut ', $chr_pool_template) === 'gut');
check('pool ref: a number property is not a pool', chronicler_sheets_formula_pool_ref('nerve', $chr_pool_template) === null);
check('pool ref: an undeclared name is not a pool', chronicler_sheets_formula_pool_ref('nonesuch', $chr_pool_template) === null);
check('pool ref: a pool inside arithmetic is not a splice', chronicler_sheets_formula_pool_ref('floor(gut / 2)', $chr_pool_template) === null);
check('pool ref: two pools added are not a splice', chronicler_sheets_formula_pool_ref('gut + gut', $chr_pool_template) === null);

$err = chronicler_sheets_formula_check('floor(gut / 2)', $chr_pool_template);
check(
    'check: a pool asked to be a number is refused, by name',
    is_wp_error($err) && str_contains($err->get_error_message(), 'gut')
        && str_contains($err->get_error_message(), "can't be used as a number")
);
check('… and the refusal shows where a pool DOES go', is_wp_error($err) && str_contains($err->get_error_message(), '{gut}'));
check(
    'check: a pool has no parts either',
    is_wp_error(chronicler_sheets_formula_check('gut["current"]', $chr_pool_template))
);

$chr_pool_base = [
    'system' => 'Pools', 'version' => 1, 'layout' => [],
    'properties' => [
        ['id' => 'gut', 'label' => 'Gut', 'type' => 'dice'],
        ['id' => 'nerve', 'label' => 'Nerve', 'type' => 'number', 'min' => 0],
    ],
];
$t = $chr_pool_base;
$t['rolls'] = [['id' => 'check', 'label' => 'Check', 'dice' => '{gut} + {nerve}']];
$chr_pool_saved = chronicler_sheets_parse_template(json_encode($t));
check(
    'parse: a roll splicing a bare pool saves',
    is_array($chr_pool_saved),
    is_wp_error($chr_pool_saved) ? $chr_pool_saved->get_error_message() : ''
);

$t = $chr_pool_base;
$t['rolls'] = [['id' => 'check', 'label' => 'Check', 'dice' => '1d6 + {floor(gut / 2)}']];
$err = chronicler_sheets_parse_template(json_encode($t));
check(
    'parse: a pool inside roll arithmetic is a save error naming it',
    is_wp_error($err) && str_contains($err->get_error_message(), 'gut')
        && str_contains($err->get_error_message(), "can't be used as a number")
);

$t = $chr_pool_base;
$t['rolls'] = [['id' => 'check', 'label' => 'Check', 'dice' => '1d6 + {gut + 1}']];
check(
    'parse: adding to a pool is a save error too',
    is_wp_error(chronicler_sheets_parse_template(json_encode($t)))
);

$t = $chr_pool_base;
$t['properties'][] = ['id' => 'half', 'label' => 'Half', 'type' => 'number', 'derived' => 'floor(gut / 2)'];
$err = chronicler_sheets_parse_template(json_encode($t));
check(
    'parse: a derived formula can\'t read a pool either',
    is_wp_error($err) && str_contains($err->get_error_message(), 'dice pool')
);

// --- write gate ------------------------------------------------------------------

$derived_prop = $formula_template['properties']['toughness'];
$err = chronicler_sheets_apply_op($derived_prop, 5, 'set', 9);
check('apply_op: derived properties reject writes', is_wp_error($err) && str_contains($err->get_error_message(), 'computed'));

// --- List-field "when" expressions (2026-07-13 simplification): the same
// fenced language as "derived", scoped to one entry's own fields. ---
$chr_when_gear = [
    'id' => 'gear', 'label' => 'Gear', 'type' => 'list',
    'fields' => [
        ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
        ['id' => 'is_weapon', 'label' => 'Weapon?', 'type' => 'toggle'],
        ['id' => 'harm_rating', 'label' => 'Harm', 'type' => 'number', 'min' => 0, 'max' => 5],
        ['id' => 'diary', 'label' => 'Diary', 'type' => 'longtext'],
    ],
];
$chr_when_field = ['id' => 'notes', 'label' => 'Notes', 'type' => 'text', 'when' => 'is_weapon and harm_rating >= 3'];
$chr_bare_field = ['id' => 'notes', 'label' => 'Notes', 'type' => 'text', 'when' => 'is_weapon'];

check('entry template keeps only referencable field types', array_keys(chronicler_sheets_formula_entry_template($chr_when_gear)['properties']) === ['name', 'is_weapon', 'harm_rating']);
check('when: bare toggle on shows', chronicler_sheets_when_holds($chr_when_gear, $chr_bare_field, ['is_weapon' => true]) === true);
check('when: bare toggle off hides', chronicler_sheets_when_holds($chr_when_gear, $chr_bare_field, ['is_weapon' => false]) === false);
check('when: expression true', chronicler_sheets_when_holds($chr_when_gear, $chr_when_field, ['is_weapon' => true, 'harm_rating' => 3]) === true);
check('when: expression false', chronicler_sheets_when_holds($chr_when_gear, $chr_when_field, ['is_weapon' => true, 'harm_rating' => 2]) === false);
check('when: missing entry values use field defaults', chronicler_sheets_when_holds($chr_when_gear, $chr_when_field, []) === false);
check('when: absent when always shows', chronicler_sheets_when_holds($chr_when_gear, ['id' => 'name', 'label' => 'Name', 'type' => 'text'], []) === true);
check('when: runtime failure fails soft to hidden', chronicler_sheets_when_holds($chr_when_gear, ['id' => 'x', 'label' => 'X', 'type' => 'text', 'when' => 'harm_rating % 0 == 0'], ['harm_rating' => 1]) === false);

// --- The entry["…"] scope (2026-07-25 Phase B): a character-carried dice
// expression reaches the entry that declared it through the `entry` namespace
// — never merged into property scope, because the live MotW shape has both a
// character `harm` track and a gear-entry harm number, and silent shadowing
// mid-session is the danger the namespace exists to prevent. ---

// The value map the collector attaches: referencable fields only, defaults
// for what the entry doesn't store, toggles 0/1 like checklist options.
check(
    'entry values map the referencable fields, in field order',
    chronicler_sheets_formula_entry_values($chr_when_gear, ['name' => 'Machete', 'is_weapon' => true, 'harm_rating' => 2, 'diary' => 'Dear diary…'])
        === ['name' => 'Machete', 'is_weapon' => 1, 'harm_rating' => 2]
);
check(
    'entry values: a toggle reads 0/1, and missing fields take their defaults',
    chronicler_sheets_formula_entry_values($chr_when_gear, ['is_weapon' => false])
        === ['name' => '', 'is_weapon' => 0, 'harm_rating' => 0]
);

// The fence, against a template augmented with the synthetic member.
$chr_entry_base = ['properties' => [
    'sharp' => ['id' => 'sharp', 'label' => 'Sharp', 'type' => 'number', 'min' => -1, 'max' => 3],
    'harm' => ['id' => 'harm', 'label' => 'Harm', 'type' => 'track', 'length' => 7],
]];
$chr_entry_scope = chronicler_sheets_formula_entry_scope($chr_entry_base, ['name', 'is_weapon', 'harm']);
$chr_entry_ok = chronicler_sheets_formula_check('entry["harm"] + sharp', $chr_entry_scope);
check('the scope admits entry["…"] beside property refs', is_array($chr_entry_ok), is_wp_error($chr_entry_ok) ? $chr_entry_ok->get_error_message() : '');
check('the scope reports entry among the refs', is_array($chr_entry_ok) && in_array('entry', $chr_entry_ok['refs'], true));
check(
    'the namespace shadows cleanly: harm["current"] is the track, entry["harm"] the entry',
    is_array(chronicler_sheets_formula_check('harm["current"] + entry["harm"]', $chr_entry_scope))
);
$chr_entry_unknown = chronicler_sheets_formula_check('entry["nonesuch"]', $chr_entry_scope);
check(
    'an unknown entry field is a loud error naming the parts the entry has',
    is_wp_error($chr_entry_unknown)
        && strpos($chr_entry_unknown->get_error_message(), 'nonesuch') !== false
        && strpos($chr_entry_unknown->get_error_message(), 'is_weapon') !== false
);
$chr_entry_bare = chronicler_sheets_formula_check('entry + 1', $chr_entry_scope);
check(
    'bare entry is refused, pointing at the bracket spelling',
    is_wp_error($chr_entry_bare) && strpos($chr_entry_bare->get_error_message(), 'entry["') !== false
);
check(
    'without the scope, entry stays an unknown name (system rolls, derived)',
    is_wp_error(chronicler_sheets_formula_check('entry["harm"]', $chr_entry_base))
);
// The synthetic member's type is internal: no author can declare a property
// of that type, so the namespace cannot be forged from a template.
check('the synthetic entry type is not an authorable property type', !in_array(CHRONICLER_FORMULA_ENTRY_TYPE, CHRONICLER_SHEETS_TYPES, true));

// --- The effect scope (2026-08-04): roll and amount ----------------------------
// An effect's modifier is a formula about a roll, not about the sheet, so the
// fence plants two synthetic members the way `entry` is planted for a list
// roll's dice: `roll` (what is being rolled, its own names plus every trait
// the template declares, null-filled) and `amount` (the instance's magnitude).
$chr_fx_base = ['properties' => [
    'cool' => ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3],
    'rizz' => ['id' => 'rizz', 'label' => 'Rizz', 'type' => 'dice'],
]];
$chr_fx_scope = chronicler_sheets_formula_effect_scope($chr_fx_base, ['basic_move', 'save']);

$chr_fx_ok = chronicler_sheets_formula_check("roll['basic_move'] ? -amount : 0", $chr_fx_scope);
check('effect scope: a declared trait and amount are known names', is_array($chr_fx_ok), is_wp_error($chr_fx_ok) ? $chr_fx_ok->get_error_message() : '');
check(
    'effect scope: a roll\'s own names are reachable too',
    is_array(chronicler_sheets_formula_check("roll['id'] == 'taunt' ? -amount : 0", $chr_fx_scope))
);
check(
    'effect scope: membership over roll["uses"] is what targets a pool',
    is_array(chronicler_sheets_formula_check("'rizz' in roll['uses'] ? -amount : 0", $chr_fx_scope))
);
check(
    'effect scope: the character\'s own properties are still in scope',
    is_array(chronicler_sheets_formula_check('cool > 0 ? amount : 0', $chr_fx_scope))
);
$chr_fx_typo = chronicler_sheets_formula_check("roll['basic_moove'] ? -amount : 0", $chr_fx_scope);
check(
    'effect scope: a trait nothing declares is a save error naming the typo',
    is_wp_error($chr_fx_typo) && str_contains($chr_fx_typo->get_error_message(), 'basic_moove')
);
check('… and the refusal lists what a roll does answer to', is_wp_error($chr_fx_typo) && str_contains($chr_fx_typo->get_error_message(), 'save'));
$chr_fx_dynamic = chronicler_sheets_formula_check("roll[roll['id']] ? -amount : 0", $chr_fx_scope);
check(
    'effect scope: a subscript that is not a constant is refused',
    is_wp_error($chr_fx_dynamic)
);
$chr_fx_bare = chronicler_sheets_formula_check('roll + 1', $chr_fx_scope);
check(
    'effect scope: bare roll is refused, pointing at the bracket spelling',
    is_wp_error($chr_fx_bare) && str_contains($chr_fx_bare->get_error_message(), 'roll["')
);
check(
    'effect scope: without it, roll and amount are unknown names (derived, rolls)',
    is_wp_error(chronicler_sheets_formula_check("roll['basic_move']", $chr_fx_base))
        && is_wp_error(chronicler_sheets_formula_check('amount', $chr_fx_base))
);
// The synthetic type is internal by the same construction `entry`'s is.
check('the synthetic roll type is not an authorable property type', !in_array(CHRONICLER_FORMULA_ROLL_TYPE, CHRONICLER_SHEETS_TYPES, true));

// The member the fence describes and the member evaluation hands over are
// built by one function, so they cannot disagree about what a roll answers to.
$chr_fx_member = chronicler_sheets_formula_roll_member(
    ['id' => 'taunt', 'label' => 'Taunt', 'section' => 'Moves', 'uses' => ['rizz', 'crowd'], 'traits' => ['social' => true]],
    ['basic_move', 'social']
);
check(
    'roll member: the roll\'s own names ride alongside its traits',
    $chr_fx_member['id'] === 'taunt' && $chr_fx_member['label'] === 'Taunt'
        && $chr_fx_member['section'] === 'Moves' && $chr_fx_member['uses'] === ['rizz', 'crowd']
        && $chr_fx_member['social'] === true
);
check(
    'roll member: a trait this roll lacks is null, never a missing key',
    array_key_exists('basic_move', $chr_fx_member) && $chr_fx_member['basic_move'] === null
);
$chr_fx_empty = chronicler_sheets_formula_roll_member([], ['basic_move']);
check(
    'roll member: an empty roll still answers to every name the fence allows',
    array_keys($chr_fx_empty) === array_merge(CHRONICLER_SHEETS_RESERVED_ROLL_KEYS, ['basic_move'])
);
check(
    'roll member: a trait named like a reserved key can\'t outrank the real one',
    chronicler_sheets_formula_roll_member(['id' => 'taunt', 'traits' => ['id' => 'nope']], [])['id'] === 'taunt'
);
$chr_fx_context = chronicler_sheets_formula_effect_context(['cool' => 2], ['id' => 'taunt'], 3, ['social']);
check(
    'effect context: the character context keeps its own names, plus roll and amount',
    $chr_fx_context['cool'] === 2 && $chr_fx_context['amount'] === 3 && $chr_fx_context['roll']['id'] === 'taunt'
);

// The trait union: every name declared anywhere in the template, once.
check(
    'trait union: rolls, dice properties and dice fields all contribute',
    chronicler_sheets_template_traits([
        'properties' => [
            'rizz' => ['type' => 'dice', 'traits' => ['social' => true]],
            'gear' => ['type' => 'list', 'fields' => [
                ['id' => 'roll', 'type' => 'dice', 'traits' => ['attack' => true]],
                ['id' => 'name', 'type' => 'text'],
            ]],
        ],
        'rolls' => ['taunt' => ['traits' => ['social' => true, 'basic_move' => true]]],
    ]) === ['social', 'attack', 'basic_move']
);
check('trait union: a template that declares none has none', chronicler_sheets_template_traits(['properties' => [], 'rolls' => []]) === []);

// And the save path, end to end: the fence runs on effect expressions, with
// the union gathered from the whole document.
$chr_fx_tpl = function (array $effects, array $extra = []) {
    return json_encode(array_merge([
        'system' => 'Effects', 'version' => 1,
        'properties' => [
            ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3],
            ['id' => 'rizz', 'label' => 'Rizz', 'type' => 'dice'],
        ],
        'rolls' => [
            ['id' => 'taunt', 'label' => 'Taunt', 'dice' => '{rizz}', 'traits' => ['social' => true]],
        ],
        'effects' => $effects,
    ], $extra));
};
$chr_fx_saved = chronicler_sheets_parse_template($chr_fx_tpl([
    ['id' => 'taunted', 'label' => 'Taunted', 'modifier' => "'rizz' in roll['uses'] ? -amount : 0", 'cap' => -2],
    ['id' => 'rattled', 'label' => 'Rattled', 'modifier' => "roll['social'] ? -amount : 0"],
]));
check('parse: effect expressions save', is_array($chr_fx_saved), is_wp_error($chr_fx_saved) ? $chr_fx_saved->get_error_message() : '');
$chr_fx_err = chronicler_sheets_parse_template($chr_fx_tpl([
    ['id' => 'rattled', 'label' => 'Rattled', 'modifier' => "roll['sociable'] ? -amount : 0"],
]));
check(
    'parse: an undeclared trait in an effect expression is a save error naming it',
    is_wp_error($chr_fx_err) && str_contains($chr_fx_err->get_error_message(), 'sociable')
);
$chr_fx_err = chronicler_sheets_parse_template($chr_fx_tpl([
    ['id' => 'rattled', 'label' => 'Rattled', 'modifier' => 'nonesuch + 1'],
]));
check(
    'parse: an undeclared property in an effect expression is a save error',
    is_wp_error($chr_fx_err)
);
$chr_fx_err = chronicler_sheets_parse_template($chr_fx_tpl([
    ['id' => 'rattled', 'label' => 'Rattled', 'modifier' => "roll['social']"],
]));
check(
    'parse: an effect formula that produces a true/false is a save error',
    is_wp_error($chr_fx_err) && str_contains($chr_fx_err->get_error_message(), 'must produce a number')
);
$chr_fx_err = chronicler_sheets_parse_template($chr_fx_tpl([
    ['id' => 'rattled', 'label' => 'Rattled', 'modifier' => "roll['social'] ? -amount : 0"],
], ['properties' => [
    ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3],
    ['id' => 'rizz', 'label' => 'Rizz', 'type' => 'dice'],
    ['id' => 'amount', 'label' => 'Amount', 'type' => 'number'],
]]));
check(
    'parse: a property named like an effect\'s own names is a save error, not a silent shadow',
    is_wp_error($chr_fx_err) && str_contains($chr_fx_err->get_error_message(), 'amount')
);
// A pool inside an effect expression is refused exactly as it is anywhere
// else: {rizz} splices dice, and there is no arithmetic on notation.
$chr_fx_err = chronicler_sheets_parse_template($chr_fx_tpl([
    ['id' => 'rattled', 'label' => 'Rattled', 'modifier' => 'rizz - 1'],
]));
check(
    'parse: an effect formula can\'t read a dice pool either',
    is_wp_error($chr_fx_err) && str_contains($chr_fx_err->get_error_message(), 'dice pool')
);
