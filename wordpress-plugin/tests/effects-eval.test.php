<?php
// What a character's effects DO to one roll (the 2026-08-04 effects design).
// Pure: instances + template + roll + context in, labeled terms out. No meta,
// no WordPress — the store's round trip is effects-store.test.php's business.

// A small system with both targeting vocabularies: traits saying what a roll
// IS, and pools the dice reach.
$chr_ev_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Effects', 'version' => 1,
    'properties' => [
        ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'rizz', 'label' => 'Rizz', 'type' => 'dice'],
    ],
    'rolls' => [
        ['id' => 'nausea_check', 'label' => 'Nausea Check', 'dice' => '2d6', 'traits' => ['check' => true]],
        ['id' => 'taunt', 'label' => 'Taunt', 'dice' => '{rizz}', 'traits' => ['social' => true]],
        ['id' => 'use_magic', 'label' => 'Use Magic', 'dice' => '2d6 + {cool}'],
    ],
    'effects' => [
        ['id' => 'queasy', 'label' => 'Queasy', 'applies_to' => 'nausea_check', 'modifier' => -1, 'cap' => -3],
        ['id' => 'forward', 'label' => 'Forward', 'modifier' => 1, 'cap' => 2],
        ['id' => 'steadied', 'label' => 'Steadied', 'applies_to' => 'social', 'modifier' => 1],
        ['id' => 'taunted', 'label' => 'Taunted', 'modifier' => "'rizz' in roll['uses'] ? -amount : 0", 'cap' => -2],
        // Two effects that save fine — the dry run at default values finds
        // nothing wrong with either — and then come apart against a real
        // character on a real roll. That gap is exactly what the roll-time
        // refusal exists for.
        ['id' => 'garbled', 'label' => 'Garbled', 'modifier' => "roll['check'] ? roll['label'] : 0"],
        ['id' => 'haywire', 'label' => 'Haywire', 'modifier' => '1 / (cool - 2)'],
    ],
]));
check('eval: the fixture template parses', is_array($chr_ev_template), is_wp_error($chr_ev_template) ? $chr_ev_template->get_error_message() : '');

/** One union-shaped roll: the two keys evaluation reads, plus its names. */
$chr_ev_roll = function (string $id, array $overrides = []) use ($chr_ev_template): array {
    $roll = $chr_ev_template['rolls'][$id] ?? ['id' => $id, 'label' => $id, 'section' => null, 'traits' => []];
    return array_merge($roll, ['uses' => [], 'section' => $roll['section'] ?? null], $overrides);
};
$chr_ev_context = chronicler_sheets_formula_context($chr_ev_template, ['cool' => 2]);
/** Evaluate against the fixture, as [label => value] pairs plus the error. */
$chr_ev = function (array $instances, array $roll) use ($chr_ev_template, $chr_ev_context): array {
    $result = chronicler_sheets_effects_for_roll($instances, $chr_ev_template, $roll, $chr_ev_context);
    return [
        'terms' => array_map(static function (array $t): array {
            return [$t['label'], $t['value']];
        }, $result['terms']),
        'error' => $result['error'],
    ];
};
/** A stored instance, already normalized the way the store hands them over. */
$chr_ev_named = function (string $effect, int $amount = 1): array {
    return chronicler_sheets_effects_normalize(['effect' => $effect, 'amount' => $amount]);
};
$chr_ev_one_off = function (string $label, int $modifier, ?string $target = null, int $amount = 1): array {
    return chronicler_sheets_effects_normalize([
        'label' => $label, 'modifier' => $modifier, 'target' => $target, 'amount' => $amount,
    ]);
};

// --- nothing applied, nothing printed ----------------------------------------
check('eval: no instances is no terms', $chr_ev([], $chr_ev_roll('taunt')) === ['terms' => [], 'error' => null]);

// --- the four sugar stages, one at a time ------------------------------------
check(
    'eval: applies_to matches a roll id',
    $chr_ev([$chr_ev_named('queasy')], $chr_ev_roll('nausea_check'))['terms'] === [['Queasy', -1]]
);
check(
    'eval: … and leaves every other roll alone',
    $chr_ev([$chr_ev_named('queasy')], $chr_ev_roll('taunt'))['terms'] === []
);
check(
    'eval: applies_to matches a roll label spoken with underscores',
    $chr_ev([$chr_ev_one_off('Backlash', -1, 'use_magic')], $chr_ev_roll('use_magic'))['terms'] === [['Backlash', -1]]
);
check(
    'eval: applies_to matches a trait that is on',
    $chr_ev([$chr_ev_named('steadied')], $chr_ev_roll('taunt'))['terms'] === [['Steadied', 1]]
);
check(
    'eval: a trait the roll doesn\'t wear matches nothing',
    $chr_ev([$chr_ev_named('steadied')], $chr_ev_roll('nausea_check'))['terms'] === []
);
check(
    'eval: a target naming a property matches every roll whose dice reach it',
    $chr_ev([$chr_ev_one_off('Acne', -2, 'rizz')], $chr_ev_roll('taunt', ['uses' => ['rizz']]))['terms'] === [['Acne', -2]]
);
check(
    'eval: … and not the roll that doesn\'t use it',
    $chr_ev([$chr_ev_one_off('Acne', -2, 'rizz')], $chr_ev_roll('use_magic', ['uses' => ['cool']]))['terms'] === []
);
check(
    'eval: no target at all means every roll',
    $chr_ev([$chr_ev_named('forward')], $chr_ev_roll('use_magic'))['terms'] === [['Forward', 1]]
        && $chr_ev([$chr_ev_named('forward')], $chr_ev_roll('nausea_check'))['terms'] === [['Forward', 1]]
);

// --- amount scales the sugar --------------------------------------------------
check(
    'eval: amount multiplies a sugar modifier',
    $chr_ev([$chr_ev_named('queasy', 2)], $chr_ev_roll('nausea_check'))['terms'] === [['Queasy', -2]]
);
check(
    'eval: an untargeted one-off scales too',
    $chr_ev([$chr_ev_one_off('Wet Bun', -1, null, 3)], $chr_ev_roll('taunt'))['terms'] === [['Wet Bun', -3]]
);

// --- stacking: one effect id sums, then the cap clamps, then ONE term ----------
check(
    'eval: two instances of one effect print as one summed term',
    $chr_ev([$chr_ev_named('queasy'), $chr_ev_named('queasy')], $chr_ev_roll('nausea_check'))['terms'] === [['Queasy', -2]]
);
check(
    'eval: the cap bounds the SUM, not each instance (1 + 2 capped at -3)',
    $chr_ev([$chr_ev_named('queasy'), $chr_ev_named('queasy', 2)], $chr_ev_roll('nausea_check'))['terms'] === [['Queasy', -3]]
);
check(
    'eval: a cap that isn\'t reached changes nothing',
    $chr_ev([$chr_ev_named('forward')], $chr_ev_roll('taunt'))['terms'] === [['Forward', 1]]
);
check(
    'eval: a positive cap is a ceiling',
    $chr_ev([$chr_ev_named('forward'), $chr_ev_named('forward'), $chr_ev_named('forward')], $chr_ev_roll('taunt'))['terms'] === [['Forward', 2]]
);
check(
    'eval: two one-offs stand alone even under one label — nothing to stack into',
    $chr_ev([$chr_ev_one_off('Acne', -2), $chr_ev_one_off('Acne', -2)], $chr_ev_roll('taunt'))['terms'] === [['Acne', -2], ['Acne', -2]]
);
check(
    'eval: different effects each get their own term, in the order applied',
    $chr_ev(
        [$chr_ev_named('forward'), $chr_ev_named('queasy'), $chr_ev_one_off('Acne', -2)],
        $chr_ev_roll('nausea_check')
    )['terms'] === [['Forward', 1], ['Queasy', -1], ['Acne', -2]]
);

// --- expressions decide for themselves ----------------------------------------
check(
    'eval: an expression effect reads what the dice reach',
    $chr_ev([$chr_ev_named('taunted')], $chr_ev_roll('taunt', ['uses' => ['rizz']]))['terms'] === [['Taunted', -1]]
);
check(
    'eval: … and evaluates to 0 on a roll it doesn\'t touch, printing nothing',
    $chr_ev([$chr_ev_named('taunted')], $chr_ev_roll('use_magic', ['uses' => ['cool']]))['terms'] === []
);
check(
    'eval: an expression scales itself by amount',
    $chr_ev([$chr_ev_named('taunted', 2)], $chr_ev_roll('taunt', ['uses' => ['rizz']]))['terms'] === [['Taunted', -2]]
);
check(
    'eval: an expression\'s instances sum and clamp like sugar\'s',
    $chr_ev(
        [$chr_ev_named('taunted', 2), $chr_ev_named('taunted', 2)],
        $chr_ev_roll('taunt', ['uses' => ['rizz']])
    )['terms'] === [['Taunted', -2]]
);

// --- the two silences, and the one refusal ------------------------------------
check(
    'eval: an instance of an effect the template dropped is skipped, quietly',
    $chr_ev([$chr_ev_named('nonesuch'), $chr_ev_named('forward')], $chr_ev_roll('taunt'))
        === ['terms' => [['Forward', 1]], 'error' => null]
);
check(
    'eval: a modifier of 0 prints nothing rather than a bare +0',
    $chr_ev([$chr_ev_one_off('Placebo', 1, 'nausea_check')], $chr_ev_roll('taunt'))['terms'] === []
);
$chr_ev_broken = $chr_ev([$chr_ev_named('forward'), $chr_ev_named('garbled')], $chr_ev_roll('nausea_check'));
check(
    'eval: an expression producing a non-number refuses the whole roll',
    $chr_ev_broken === ['terms' => [], 'error' => 'Garbled']
);
check(
    'eval: … and the same effect is silent on a roll it evaluates to 0 on',
    $chr_ev([$chr_ev_named('garbled')], $chr_ev_roll('taunt')) === ['terms' => [], 'error' => null]
);
check(
    'eval: an expression that throws refuses too, naming its effect and nothing else',
    $chr_ev([$chr_ev_named('haywire')], $chr_ev_roll('taunt')) === ['terms' => [], 'error' => 'Haywire']
);

// --- the matcher on its own ----------------------------------------------------
$chr_ev_taunt = ['id' => 'taunt', 'label' => 'Taunt', 'traits' => ['social' => true, 'quiet' => false], 'uses' => ['rizz', 'crowd']];
check('targets: no word matches everything', chronicler_sheets_effects_targets(null, $chr_ev_taunt) === true);
check('targets: an id hit', chronicler_sheets_effects_targets('taunt', $chr_ev_taunt) === true);
check('targets: a label hit, underscores for spaces', chronicler_sheets_effects_targets('nausea_check', ['id' => null, 'label' => 'Nausea Check']) === true);
check('targets: a trait that is on', chronicler_sheets_effects_targets('social', $chr_ev_taunt) === true);
check('targets: a trait that is off is not a hit', chronicler_sheets_effects_targets('quiet', $chr_ev_taunt) === false);
check('targets: a property the dice reach', chronicler_sheets_effects_targets('crowd', $chr_ev_taunt) === true);
check('targets: a word nothing answers to', chronicler_sheets_effects_targets('forward', $chr_ev_taunt) === false);
check(
    'targets: a character roll carries no id, so nothing matches one by accident',
    chronicler_sheets_effects_targets('taunt', ['id' => null, 'label' => 'Machete', 'traits' => [], 'uses' => []]) === false
);
