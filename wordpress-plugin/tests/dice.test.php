<?php
// Behavioral tests for sheets/dice.php — the dice grammar a template's
// `rolls:` table is written in. Included by run.php. Pure: the randomizer is
// injected, so every face below is asserted exactly.

// --- parsing: the valid forms ------------------------------------------------
$chr_dice = function (string $expr) {
    $parsed = chronicler_sheets_parse_dice($expr);
    return is_array($parsed) ? $parsed['terms'] : null;
};

$d_2d6 = $chr_dice('2d6');
check('2d6 parses to one term', is_array($d_2d6) && count($d_2d6) === 1);
check(
    '2d6 is two six-sided dice, kept whole, added',
    is_array($d_2d6)
        && $d_2d6[0]['kind'] === 'dice'
        && $d_2d6[0]['count'] === 2
        && $d_2d6[0]['sides'] === 6
        && $d_2d6[0]['keep'] === null
        && $d_2d6[0]['sign'] === 1
);

$d_flat = $chr_dice('1d20+5');
check('1d20+5 parses to two terms', is_array($d_flat) && count($d_flat) === 2);
check(
    'the trailing 5 is a positive constant',
    is_array($d_flat) && $d_flat[1]['kind'] === 'const' && $d_flat[1]['value'] === 5 && $d_flat[1]['sign'] === 1
);

$d_placeholder = $chr_dice('2d6 + {cool}');
check('2d6 + {cool} parses to two terms', is_array($d_placeholder) && count($d_placeholder) === 2);
check(
    'a {placeholder} keeps its expression verbatim',
    is_array($d_placeholder) && $d_placeholder[1]['kind'] === 'expr' && $d_placeholder[1]['expression'] === 'cool'
);

$d_kh = $chr_dice('4d6kh3');
check(
    '4d6kh3 keeps the highest three',
    is_array($d_kh) && $d_kh[0]['count'] === 4 && $d_kh[0]['keep'] === ['dir' => 'h', 'n' => 3]
);
$d_kl = $chr_dice('2d20kl1');
check(
    '2d20kl1 keeps the lowest one',
    is_array($d_kl) && $d_kl[0]['sides'] === 20 && $d_kl[0]['keep'] === ['dir' => 'l', 'n' => 1]
);

// A placeholder is a whole term, so the arithmetic inside it — including its
// own "-" — must not be mistaken for a term separator.
$d_mixed = $chr_dice('1d20 + {floor(dex / 2)} - 1');
check('a mixed roll parses to three terms', is_array($d_mixed) && count($d_mixed) === 3);
check(
    'the placeholder holds the whole expression, operators and all',
    is_array($d_mixed) && $d_mixed[1]['kind'] === 'expr' && $d_mixed[1]['expression'] === 'floor(dex / 2)'
);
check(
    'a "-" between terms makes the term negative',
    is_array($d_mixed) && $d_mixed[2]['kind'] === 'const' && $d_mixed[2]['value'] === 1 && $d_mixed[2]['sign'] === -1
);
check(
    'a "-" inside a placeholder is not a term separator',
    ($x = $chr_dice('2d6 + {cool - 1}')) !== null && count($x) === 2 && $x[1]['expression'] === 'cool - 1'
);
check(
    'a bracket lookup survives inside a placeholder',
    ($x = $chr_dice('1d6 + {harm["current"]}')) !== null && $x[1]['expression'] === 'harm["current"]'
);

check(
    'whitespace around operators and ends is tolerated',
    ($x = $chr_dice("  2d6   +   3  ")) !== null
        && count($x) === 2 && $x[0]['count'] === 2 && $x[1]['value'] === 3
);
check('an uppercase D is the same die', ($x = $chr_dice('2D6KH1')) !== null && $x[0]['keep'] === ['dir' => 'h', 'n' => 1]);
check('a leading minus signs the first term', ($x = $chr_dice('-2 + 1d4')) !== null && $x[0]['sign'] === -1);

// --- parsing: the rejected forms ---------------------------------------------
$chr_dice_bad = function (string $expr, string $desc) {
    $parsed = chronicler_sheets_parse_dice($expr);
    check($desc, is_wp_error($parsed), is_array($parsed) ? 'parsed instead' : '');
};
$chr_dice_bad('d6', 'a die with no count is rejected');
$chr_dice_bad('2d', 'a die with no sides is rejected');
$chr_dice_bad('2x6', 'a non-dice separator is rejected');
$chr_dice_bad('2d6kh', 'kh with no number is rejected');
$chr_dice_bad('2d6kh4', 'keeping more dice than are rolled is rejected');
$chr_dice_bad('2d6kh0', 'keeping zero dice is rejected');
$chr_dice_bad('0d6', 'rolling zero dice is rejected');
$chr_dice_bad('1d0', 'a zero-sided die is rejected');
$chr_dice_bad('101d6', 'more than 100 dice is rejected');
$chr_dice_bad('1d1001', 'more than 1000 sides is rejected');
$chr_dice_bad('1d6 + 10001', 'a constant over 10000 is rejected');
$chr_dice_bad('+', 'a lone operator is rejected');
$chr_dice_bad('', 'an empty roll is rejected');
$chr_dice_bad('   ', 'a whitespace-only roll is rejected');
$chr_dice_bad('2d6 +', 'a trailing operator is rejected');
$chr_dice_bad('2d6 + + 1', 'a doubled operator is rejected');
$chr_dice_bad('1d6 + {unclosed', 'an unclosed placeholder is rejected');
$chr_dice_bad('1d6 + unclosed}', 'a stray closing brace is rejected');
$chr_dice_bad('1d6 + {}', 'an empty placeholder is rejected');
$chr_dice_bad('1d6 + {a}{b}', 'two placeholders jammed into one term are rejected');
$chr_dice_bad(implode(' + ', array_fill(0, 11, '1')), 'an eleventh term is rejected');
check('exactly ten terms is fine', is_array(chronicler_sheets_parse_dice(implode(' + ', array_fill(0, 10, '1')))));

$chr_dice_err = chronicler_sheets_parse_dice('2d6kh4');
check(
    'the keep-too-many error says how many dice there actually are',
    is_wp_error($chr_dice_err) && strpos($chr_dice_err->get_error_message(), '2d6kh4') !== false
);

// --- placeholder extraction --------------------------------------------------
check(
    'placeholders come back in order',
    chronicler_sheets_dice_placeholders(chronicler_sheets_parse_dice('2d6 + {cool} - {tough}')) === ['cool', 'tough']
);
check(
    'a repeated placeholder is listed once',
    chronicler_sheets_dice_placeholders(chronicler_sheets_parse_dice('2d6 + {cool} + {cool}')) === ['cool']
);
check(
    'a roll with no placeholders has none',
    chronicler_sheets_dice_placeholders(chronicler_sheets_parse_dice('2d6 + 1')) === []
);

// --- rolling: scripted randomizer, exact faces -------------------------------
// $rng is fn(int $min, int $max): int — the test scripts every face in order
// and records the bounds it was asked for.
$chr_dice_script = function (array $faces, ?array &$asked = null) {
    $i = 0;
    return function (int $min, int $max) use (&$i, $faces, &$asked) {
        $asked[] = [$min, $max];
        return $faces[$i++];
    };
};

$chr_asked = [];
$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('2d6+1'), [], $chr_dice_script([4, 3], $chr_asked));
check('a simple roll totals its faces plus the constant', $r['total'] === 8, json_encode($r));
check('every face is reported', array_column($r['terms'][0]['dice'], 'value') === [4, 3]);
check('an unmodified term keeps every die', array_column($r['terms'][0]['dice'], 'kept') === [true, true]);
check('the constant term carries its signed subtotal', $r['terms'][1]['subtotal'] === 1);
check('the randomizer is asked for 1..sides', $chr_asked === [[1, 6], [1, 6]]);

$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('4d6kh3'), [], $chr_dice_script([1, 5, 6, 2]));
check('keep-highest drops the lowest die', $r['total'] === 13, json_encode($r));
check(
    'the dropped die is reported, marked not kept',
    array_column($r['terms'][0]['dice'], 'kept') === [false, true, true, true]
);

$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('2d20kl1'), [], $chr_dice_script([17, 4]));
check('keep-lowest keeps the smaller die', $r['total'] === 4);
check('keep-lowest drops the larger die', array_column($r['terms'][0]['dice'], 'kept') === [false, true]);

$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('2d6 - 3'), [], $chr_dice_script([6, 6]));
check('a negative term subtracts', $r['total'] === 9);
check('a negative term reports a negative subtotal', $r['terms'][1]['subtotal'] === -3);

$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('2d6 + {cool}'), ['cool' => 2], $chr_dice_script([1, 1]));
check('a placeholder substitutes its resolved value', $r['total'] === 4, json_encode($r));
check('the placeholder term reports the value it used', $r['terms'][1]['value'] === 2);

$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('1d6 - {cool}'), ['cool' => 3], $chr_dice_script([5]));
check('a negative placeholder subtracts its value', $r['total'] === 2);

$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('1d6 + {floor(dex / 2)}'), ['floor(dex / 2)' => 2.0], $chr_dice_script([3]));
check('a placeholder is keyed by its whole expression', $r['total'] === 5, json_encode($r));

// Ties keep the earlier die, so the report is deterministic.
$r = chronicler_sheets_roll_dice(chronicler_sheets_parse_dice('3d6kh1'), [], $chr_dice_script([4, 4, 2]));
check('a tie for highest keeps the first of the tied dice', array_column($r['terms'][0]['dice'], 'kept') === [true, false, false]);

// --- the collector (2026-07-25: a move carries its own roll) -----------------
// chronicler_sheets_character_rolls() turns a VIEWER-FILTERED sheet's list
// entries into roll-shaped contributions. Pure: sheet in, contributions out.

/** A serialized sheet property the way sheet_for_viewer() builds one. */
$chr_cr_moves = function (array $entries, array $overrides = []) {
    return array_merge([
        'id' => 'moves', 'label' => 'Moves', 'type' => 'list',
        'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'effect', 'label' => 'Description', 'type' => 'longtext'],
            ['id' => 'has', 'label' => 'Has it', 'type' => 'toggle'],
            ['id' => 'dice', 'label' => 'Roll', 'type' => 'dice', 'when' => 'has'],
        ],
    ], $overrides, ['value' => $entries]);
};
$chr_cr_sheet = function (array $properties) {
    return ['title' => 'Alec', 'system' => 'MotW', 'properties' => $properties];
};

$chr_cr = chronicler_sheets_character_rolls($chr_cr_sheet([$chr_cr_moves([
    ['name' => "I've Read About This Sort of Thing", 'effect' => "Act under pressure with +Sharp\nwhen you've read about it.", 'has' => true, 'dice' => '2d6 + {sharp}'],
])]));
check('a named, taken, parseable entry contributes one roll', count($chr_cr) === 1, json_encode($chr_cr));
check('the contribution has no id (character rolls match on label only)', $chr_cr !== [] && $chr_cr[0]['id'] === null);
check('the label comes from the first text field', $chr_cr !== [] && $chr_cr[0]['label'] === "I've Read About This Sort of Thing");
check('the section is the list property\'s label', $chr_cr !== [] && $chr_cr[0]['section'] === 'Moves');
check('the dice string carries through trimmed', $chr_cr !== [] && $chr_cr[0]['dice'] === '2d6 + {sharp}');
check('the dice arrive pre-parsed like a system roll\'s', $chr_cr !== [] && is_array($chr_cr[0]['parsed']) && $chr_cr[0]['parsed']['terms'][0]['kind'] === 'dice');
check('the detail is the first longtext field\'s FIRST LINE', $chr_cr !== [] && $chr_cr[0]['detail'] === 'Act under pressure with +Sharp');
// Phase B: each contribution carries its entry's referencable fields, ready
// to be the entry["…"] namespace when Roll::values() builds the context.
check(
    'the contribution carries its entry\'s referencable fields (toggle as 0/1)',
    $chr_cr !== [] && $chr_cr[0]['entry'] === ['name' => "I've Read About This Sort of Thing", 'has' => 1]
);

// The gates, one by one.
$chr_cr_untaken = chronicler_sheets_character_rolls($chr_cr_sheet([$chr_cr_moves([
    ['name' => 'Listed But Untaken', 'effect' => '', 'has' => false, 'dice' => '2d6 + {sharp}'],
])]));
check('an entry whose when is false contributes nothing', $chr_cr_untaken === []);

$chr_cr_no_dice = chronicler_sheets_character_rolls($chr_cr_sheet([$chr_cr_moves([
    ['name' => 'A Move Without Dice', 'effect' => 'Narrative only.', 'has' => true, 'dice' => ''],
])]));
check('an entry with no dice value contributes nothing', $chr_cr_no_dice === []);

$chr_cr_unnamed = chronicler_sheets_character_rolls($chr_cr_sheet([$chr_cr_moves([
    ['name' => '', 'effect' => 'x', 'has' => true, 'dice' => '2d6'],
])]));
check('an unnamed entry contributes nothing (an unnamed roll is unpickable)', $chr_cr_unnamed === []);

$chr_cr_bad = chronicler_sheets_character_rolls($chr_cr_sheet([$chr_cr_moves([
    ['name' => 'Typo Move', 'effect' => '', 'has' => true, 'dice' => '2x6 garbage'],
    ['name' => 'Fine Move', 'effect' => '', 'has' => true, 'dice' => '2d6 + {cool}'],
])]));
check('an unparseable dice string is inert, per entry', count($chr_cr_bad) === 1 && $chr_cr_bad[0]['label'] === 'Fine Move');

$chr_cr_placeholder_row = chronicler_sheets_character_rolls($chr_cr_sheet([$chr_cr_moves([
    ['name' => '[quantum backpack object 1]', 'effect' => '', 'has' => false, 'dice' => ''],
])]));
check('a placeholder-only row contributes nothing', $chr_cr_placeholder_row === []);

// A dice field with NO when contributes whenever non-empty — the gate is
// explicit (decision 6), and forgetting it is legal, not fatal.
$chr_cr_ungated = chronicler_sheets_character_rolls($chr_cr_sheet([[
    'id' => 'gear', 'label' => 'Gear', 'type' => 'list',
    'fields' => [
        ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
        ['id' => 'dice', 'label' => 'Damage', 'type' => 'dice'],
    ],
    'value' => [['name' => 'Machete', 'dice' => '1d8']],
]]));
check('a dice field without a when contributes whenever non-empty', count($chr_cr_ungated) === 1 && $chr_cr_ungated[0]['label'] === 'Machete');

// The entry map in full: defaults fill what the entry doesn't store, and
// longtext/dice fields stay out — notation and prose are not referencable.
$chr_cr_entry_map = chronicler_sheets_character_rolls($chr_cr_sheet([[
    'id' => 'gear', 'label' => 'Gear', 'type' => 'list',
    'fields' => [
        ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
        ['id' => 'weapon', 'label' => 'Weapon?', 'type' => 'toggle'],
        ['id' => 'harm_rating', 'label' => 'Harm', 'type' => 'number', 'min' => 0, 'max' => 5],
        ['id' => 'notes', 'label' => 'Notes', 'type' => 'longtext'],
        ['id' => 'dice', 'label' => 'Damage', 'type' => 'dice'],
    ],
    'value' => [['name' => 'Machete', 'weapon' => true, 'dice' => '1d8 + {entry["harm_rating"]}']],
]]));
check(
    'the entry map fills defaults and excludes longtext and dice fields',
    count($chr_cr_entry_map) === 1
        && $chr_cr_entry_map[0]['entry'] === ['name' => 'Machete', 'weapon' => 1, 'harm_rating' => 0]
);

// label_field wins over the first-text-field convention.
$chr_cr_lf = chronicler_sheets_character_rolls($chr_cr_sheet([[
    'id' => 'gear', 'label' => 'Gear', 'type' => 'list', 'label_field' => 'nickname',
    'fields' => [
        ['id' => 'maker', 'label' => 'Maker', 'type' => 'text'],
        ['id' => 'nickname', 'label' => 'Nickname', 'type' => 'text'],
        ['id' => 'dice', 'label' => 'Damage', 'type' => 'dice'],
    ],
    'value' => [['maker' => 'Ithaca', 'nickname' => 'Old Reliable', 'dice' => '2d8']],
]]));
check('label_field beats the first text field', count($chr_cr_lf) === 1 && $chr_cr_lf[0]['label'] === 'Old Reliable');

// Two contributing lists: sheet property order, entries in list order.
$chr_cr_two = chronicler_sheets_character_rolls($chr_cr_sheet([
    $chr_cr_moves([
        ['name' => 'First Move', 'effect' => '', 'has' => true, 'dice' => '2d6'],
        ['name' => 'Second Move', 'effect' => '', 'has' => true, 'dice' => '2d6'],
    ]),
    [
        'id' => 'gear', 'label' => 'Gear', 'type' => 'list',
        'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'dice', 'label' => 'Damage', 'type' => 'dice'],
        ],
        'value' => [['name' => 'Machete', 'dice' => '1d8']],
    ],
]));
check(
    'two lists contribute in sheet order, entries in list order',
    array_column($chr_cr_two, 'label') === ['First Move', 'Second Move', 'Machete']
        && array_column($chr_cr_two, 'section') === ['Moves', 'Moves', 'Gear']
);

// The regression case: no dice field anywhere, nothing contributed.
$chr_cr_none = chronicler_sheets_character_rolls($chr_cr_sheet([
    ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'value' => 2],
    ['id' => 'gear', 'label' => 'Gear', 'type' => 'list',
     'fields' => [['id' => 'name', 'label' => 'Name', 'type' => 'text']],
     'value' => [['name' => 'Rope']]],
]));
check('a sheet with no dice field contributes nothing', $chr_cr_none === []);

// A long first line truncates to one legible menu line.
$chr_cr_long = chronicler_sheets_character_rolls($chr_cr_sheet([$chr_cr_moves([
    ['name' => 'Wordy', 'effect' => str_repeat('very ', 40) . 'long', 'has' => true, 'dice' => '2d6'],
])]));
check(
    'a long detail truncates with an ellipsis',
    $chr_cr_long !== []
        && mb_strlen($chr_cr_long[0]['detail']) <= 120
        && mb_substr($chr_cr_long[0]['detail'], -1) === '…'
);
