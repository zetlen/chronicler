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
