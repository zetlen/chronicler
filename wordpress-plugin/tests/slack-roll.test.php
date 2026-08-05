<?php
// `/game roll` — resolving a declared roll, refusing one the caller can't see
// the stats for, and reporting every die face (4.26.0).
//
// Roll::respond() is the whole command minus the viewer resolution: template
// and viewer-filtered sheet in, Slack body out, randomizer injected. That
// makes the security rule testable here rather than only at runtime — a roll
// whose placeholder names a property the FILTERED sheet doesn't carry must
// refuse, because chronicler_sheets_roll_dice() treats a missing placeholder
// as 0 and would otherwise leak a GM secret arithmetically (or, worse, report
// a total that is quietly wrong).

use Chronicler\Slack\Bot\Commands;
use Chronicler\Slack\Bot\Roll;

check('Roll exists', class_exists(Roll::class));

// --- Fixture -----------------------------------------------------------------
// `curse` is gm_only and `1d6 + {curse}` references it: the sheet a player
// sees has no such property, and that roll must refuse rather than roll a 0.
$chr_roll_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Monster of the Week',
    'version' => 1,
    'properties' => [
        ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'sharp', 'label' => 'Sharp', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'tough', 'label' => 'Tough', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'weird', 'label' => 'Weird', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'luck', 'label' => 'Luck', 'type' => 'track', 'length' => 7],
        ['id' => 'moves', 'label' => 'Moves', 'type' => 'checklist', 'options' => [
            ['id' => 'read_about_this', 'label' => "I've Read About This Sort of Thing"],
            ['id' => 'nine_lives', 'label' => 'Nine Lives'],
        ]],
        ['id' => 'curse', 'label' => 'Curse', 'type' => 'number', 'min' => 0, 'max' => 6, 'gm_only' => true],
        // Dice pools (2026-08-04): the character's own notation, spliceable
        // into a system roll and rollable on its own. Empty on the sheets
        // built below unless a test gives one a value, so every assertion
        // written before pools existed is undisturbed.
        ['id' => 'gut', 'label' => 'Gut', 'type' => 'dice', 'detail' => 'the pool that can end you',
         'traits' => ['check' => true]],
        ['id' => 'hex_dice', 'label' => 'Hex Dice', 'type' => 'dice', 'gm_only' => true],
        ['id' => 'harm', 'label' => 'Harm', 'type' => 'track', 'length' => 7],
        ['id' => 'look', 'label' => 'Look', 'type' => 'text'],
        // A list whose entries can carry their own dice (2026-07-25). Empty by
        // default, so sheets that don't set it contribute nothing and every
        // pre-existing assertion below is undisturbed.
        ['id' => 'playbook_moves', 'label' => 'Playbook Moves', 'type' => 'list', 'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'effect', 'label' => 'Description', 'type' => 'longtext'],
            ['id' => 'has', 'label' => 'Has it', 'type' => 'toggle'],
            ['id' => 'dice', 'label' => 'Roll', 'type' => 'dice', 'when' => 'has'],
        ]],
        // Phase B's motivating shape: gear whose dice add the gear's OWN
        // numbers. The entry-level `harm` field deliberately shares its name
        // with the character-level track above — the entry namespace exists
        // because that collision is real on the live MotW sheet.
        ['id' => 'gear', 'label' => 'Gear', 'type' => 'list', 'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'notes', 'label' => 'Notes', 'type' => 'longtext'],
            ['id' => 'weapon', 'label' => 'Weapon?', 'type' => 'toggle'],
            ['id' => 'harm_rating', 'label' => 'Harm Rating', 'type' => 'number', 'min' => 0, 'max' => 5],
            ['id' => 'harm', 'label' => 'Harm', 'type' => 'number', 'min' => 0, 'max' => 5],
            ['id' => 'roll', 'label' => 'Roll', 'type' => 'dice', 'when' => 'weapon'],
        ]],
    ],
    'layout' => [
        ['id' => 'stats', 'section' => 'Ratings', 'properties' => ['cool', 'sharp', 'tough', 'weird']],
        ['section' => 'Status', 'properties' => ['luck', 'moves', 'curse']],
    ],
    'rolls' => [
        ['id' => 'act_under_pressure', 'label' => 'Act Under Pressure', 'section' => 'Basic Moves',
         'dice' => '2d6 + {cool}', 'detail' => 'when you do something under fire'],
        ['id' => 'kick_some_ass', 'label' => 'Kick Some Ass', 'section' => 'Basic Moves',
         'dice' => '2d6 + {tough}'],
        ['id' => 'ability_check', 'label' => 'Ability Check', 'section' => 'Attacks',
         'dice' => '2d20kh1 + {cool} - 1'],
        ['id' => 'curse_check', 'label' => 'Curse Check', 'section' => 'Attacks',
         'dice' => '1d6 + {curse}'],
        ['id' => 'use_magic', 'label' => 'Use Magic',
         'dice' => '2d6 + {weird + floor(luck["current"] / 2)}'],
        // A system roll over each character's OWN pool — the thing a rolls:
        // table could not say before pools existed.
        ['id' => 'nausea_check', 'label' => 'Nausea Check', 'section' => 'Attacks',
         'dice' => '{gut} + {cool}', 'detail' => 'beat 4 or hurl'],
        ['id' => 'hex_check', 'label' => 'Hex Check', 'section' => 'Attacks', 'dice' => '{hex_dice} + 1'],
        ['id' => 'everything', 'label' => 'Everything At Once', 'section' => 'Attacks',
         'dice' => '1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + 1 + {gut}'],
    ],
    // GM-applied modifiers (2026-08-04). Nothing applies until a GM says so,
    // so every assertion written before effects existed is undisturbed —
    // respond() is handed no instances by default.
    'effects' => [
        ['id' => 'queasy', 'label' => 'Queasy', 'applies_to' => 'nausea_check', 'modifier' => -1, 'cap' => -3],
        ['id' => 'forward', 'label' => 'Forward', 'modifier' => 1, 'cap' => 2],
        ['id' => 'taunted', 'label' => 'Taunted', 'modifier' => "'cool' in roll['uses'] ? -amount : 0"],
        // An expression reaching a gm_only stat: the security rule's business,
        // not the evaluator's. It saves fine — `curse` is a real property —
        // and refuses at roll time for a viewer whose sheet doesn't carry it.
        ['id' => 'hexed', 'label' => 'Hexed', 'modifier' => 'curse > 3 ? -2 : 0'],
        // Saves (the dry run at default values finds nothing wrong) and then
        // produces a label instead of a number against a real roll.
        ['id' => 'garbled', 'label' => 'Garbled', 'modifier' => "roll['check'] ? roll['label'] : 0"],
    ],
]));
check('roll fixture template parses', is_array($chr_roll_template), is_wp_error($chr_roll_template) ? $chr_roll_template->get_error_message() : '');

// The sheet as ONE viewer sees it. $hidden lists ids the authority withheld.
$chr_roll_sheet = function (array $values, array $hidden = []) use ($chr_roll_template): array {
    $props = [];
    foreach ($chr_roll_template['properties'] as $id => $property) {
        if (in_array($id, $hidden, true)) {
            continue;
        }
        $value = array_key_exists($id, $values) ? $values[$id] : chronicler_sheets_default_value($property);
        $entry = $property + [
            'value' => $value,
            'display' => chronicler_sheets_display_value($property, $value),
        ];
        $entry['detail'] = (string) ($property['detail'] ?? '');
        $props[] = $entry;
    }
    return [
        'characterId' => 7,
        'title' => 'Alec Baker',
        'canEdit' => false,
        'system' => $chr_roll_template['system'],
        'layout' => $chr_roll_template['layout'],
        'properties' => $props,
    ];
};
$chr_roll_values = ['cool' => 2, 'sharp' => 3, 'tough' => -1, 'weird' => 1, 'luck' => 5, 'curse' => 4, 'moves' => [], 'harm' => 3, 'look' => 'Wiry, watchful'];
$player_sheet = $chr_roll_sheet($chr_roll_values, ['curse']);
$gm_sheet = $chr_roll_sheet($chr_roll_values);

// A scripted randomizer, so faces and totals are asserted exactly.
$scripted = function (array $faces): callable {
    $i = 0;
    return function (int $min, int $max) use ($faces, &$i): int {
        return $faces[$i++] ?? $min;
    };
};
$url = 'https://blog.test/character/alec';

// --- Roll::resolve(): id → label → unique prefix, never a guess --------------

$rolls = $chr_roll_template['rolls'];
$rid = function ($r) {
    return is_array($r) && isset($r['roll']['id']) ? $r['roll']['id'] : ($r['kind'] ?? '?');
};
check('a roll id resolves', $rid(Roll::resolve('act_under_pressure', $rolls)) === 'act_under_pressure');
check('a roll label resolves', $rid(Roll::resolve('Act Under Pressure', $rolls)) === 'act_under_pressure');
check('a roll label is case-insensitive', $rid(Roll::resolve('kick SOME ass', $rolls)) === 'kick_some_ass');
check('an id spoken with spaces resolves', $rid(Roll::resolve('use magic', $rolls)) === 'use_magic');
check('a unique prefix resolves', $rid(Roll::resolve('kick', $rolls)) === 'kick_some_ass');
$amb = Roll::resolve('a', $rolls);
check('an ambiguous prefix is ambiguous, not a guess', ($amb['kind'] ?? '') === 'ambiguous');
check('ambiguity names both rolls', in_array('Act Under Pressure', $amb['candidates'] ?? [], true)
    && in_array('Ability Check', $amb['candidates'] ?? [], true));
check('an unknown roll resolves to none', (Roll::resolve('nonsense', $rolls)['kind'] ?? '') === 'none');

// --- Roll::respond(): the whole command, minus the viewer resolution ---------

$hit = Roll::respond('act under pressure', $chr_roll_template, $player_sheet, $url, $scripted([4, 3]));
check('a roll is in_channel — dice are social', $hit['response_type'] === 'in_channel');
$hit_text = json_encode($hit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a roll names the character', str_contains($hit_text, 'Alec Baker'));
check('a roll names the roll', str_contains($hit_text, 'Act Under Pressure'));
check('a roll shows the declared notation', str_contains($hit_text, '2d6 + {cool}'));
check('a roll shows every die face', str_contains($hit_text, '[4]') && str_contains($hit_text, '[3]'));
check('a roll shows the resolved modifier', str_contains($hit_text, '+2'));
check('a roll shows the total', str_contains($hit_text, '9'));
check('a roll carries a text fallback', is_string($hit['text'] ?? null) && $hit['text'] !== '');
check('a roll shows its detail', str_contains($hit_text, 'when you do something under fire'));

// 4d6-style keep-highest: the dropped die is shown, struck through, so the
// total is auditable. 2d20kh1 + {cool} - 1 over faces 20,7 is 20 + 2 - 1.
$kh = Roll::respond('ability check', $chr_roll_template, $player_sheet, $url, $scripted([20, 7]));
$kh_text = json_encode($kh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a kh roll still shows the dropped die', str_contains($kh_text, '7'));
check('a kh roll marks the dropped die struck through', str_contains($kh_text, '~[7]~'));
check('a kh roll leaves the kept die unmarked', str_contains($kh_text, '[20]') && !str_contains($kh_text, '~[20]~'));
check('a kh roll subtracts a negative term', str_contains($kh_text, '21'));

// Arithmetic inside the placeholder: weird 1 + floor(luck 5 / 2) = 3.
$magic = Roll::respond('use magic', $chr_roll_template, $player_sheet, $url, $scripted([6, 6]));
$magic_text = json_encode($magic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a placeholder formula is evaluated against the character', str_contains($magic_text, '+3'));
check('… and the total adds up', str_contains($magic_text, '15'));

// --- The security rule ------------------------------------------------------
// roll_dice() reads a missing placeholder as 0. A roll over a stat the viewer
// cannot see must therefore REFUSE, not roll — otherwise the GM's Curse leaks
// through the arithmetic, and the player gets a wrong number besides.

$refused = Roll::respond('curse check', $chr_roll_template, $player_sheet, $url, $scripted([5]));
$refused_text = json_encode($refused, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a roll over an invisible stat refuses', str_contains(strtolower($refused_text), "can't see"));
check('a refusal is ephemeral, not broadcast', $refused['response_type'] === 'ephemeral');
check('a refusal carries no dice', !str_contains($refused_text, '[5]'));
check('a refusal never reveals the hidden value', !str_contains($refused_text, '4'));
check('a refusal names the roll it declined', str_contains($refused_text, 'Curse Check'));

// The same roll, as a GM whose sheet DOES carry the property: 5 + 4 = 9.
$allowed = Roll::respond('curse check', $chr_roll_template, $gm_sheet, $url, $scripted([5]));
$allowed_text = json_encode($allowed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a viewer who CAN see the stat rolls it', $allowed['response_type'] === 'in_channel');
check('… with the die shown', str_contains($allowed_text, '[5]'));
check('… and the stat applied', str_contains($allowed_text, '+4') && str_contains($allowed_text, '9'));

// --- No argument: the listing ------------------------------------------------

$listing = Roll::respond('', $chr_roll_template, $player_sheet, $url, $scripted([]));
$listing_text = json_encode($listing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('the listing is ephemeral — a menu is not a roll', $listing['response_type'] === 'ephemeral');
check('the listing names every roll', str_contains($listing_text, 'Act Under Pressure')
    && str_contains($listing_text, 'Kick Some Ass')
    && str_contains($listing_text, 'Ability Check')
    && str_contains($listing_text, 'Use Magic'));
check('the listing groups by section', str_contains($listing_text, 'Basic Moves')
    && str_contains($listing_text, 'Attacks'));
check('the listing keeps declaration order', strpos($listing_text, 'Basic Moves') < strpos($listing_text, 'Attacks'));
check('sectionless rolls fall into a trailing Other', str_contains($listing_text, 'Other')
    && strpos($listing_text, 'Attacks') < strpos($listing_text, 'Other'));
check('the listing shows each notation', str_contains($listing_text, '2d20kh1'));

// The listing is a menu of what you can roll, so it lists a roll you would be
// refused — naming a gm_only STAT is what leaks, not naming the roll.
check('the listing still names a roll you cannot make', str_contains($listing_text, 'Curse Check'));

// --- A system with no rolls at all -------------------------------------------

$chr_roll_bare = chronicler_sheets_parse_template(json_encode([
    'system' => 'Freeform',
    'version' => 1,
    'properties' => [['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3]],
]));
check('bare fixture parses', is_array($chr_roll_bare));
$bare = Roll::respond('anything', $chr_roll_bare, $chr_roll_sheet([]), $url, $scripted([1]));
check('a system with no rolls says so', str_contains(strtolower($bare['text']), 'no rolls'));
check('a system with no rolls names itself', str_contains($bare['text'], 'Freeform'));
check('a system with no rolls answers ephemerally', $bare['response_type'] === 'ephemeral');

// --- Unknown and ambiguous replies ------------------------------------------

$miss = Roll::respond('nonsense', $chr_roll_template, $player_sheet, $url, $scripted([1]));
check('an unknown roll is ephemeral', $miss['response_type'] === 'ephemeral');
check('an unknown roll lists what is available', str_contains(
    json_encode($miss, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'Act Under Pressure'
));
$ambiguous = Roll::respond('a', $chr_roll_template, $player_sheet, $url, $scripted([1]));
check('an ambiguous roll is ephemeral', $ambiguous['response_type'] === 'ephemeral');
check('an ambiguous roll names the candidates', str_contains($ambiguous['text'], 'Ability Check'));
check('an ambiguous roll never rolls', !str_contains($ambiguous['text'], '='));

// --- The merged menu (2026-07-25: a move carries its own roll) ---------------
// A list entry with dice on it contributes a roll, and /game roll serves the
// union: system rolls first, then each contributing list's section.

$chr_moves_taken = [
    ['name' => "I've Read About This Sort of Thing", 'effect' => "Act under pressure with +Sharp instead.\nSecond line never shows.", 'has' => true, 'dice' => '2d6 + {sharp}'],
    ['name' => 'Listed But Untaken', 'effect' => '', 'has' => false, 'dice' => '2d6'],
];
$moves_sheet = $chr_roll_sheet(['playbook_moves' => $chr_moves_taken] + $chr_roll_values, ['curse']);

$merged_menu = json_encode(
    Roll::respond('', $chr_roll_template, $moves_sheet, $url, $scripted([])),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('a taken move with dice appears on the menu', str_contains($merged_menu, "I've Read About This Sort of Thing"));
check('… under its list\'s label as the section', str_contains($merged_menu, 'Playbook Moves'));
check('… after every system section', strpos($merged_menu, 'Attacks') < strpos($merged_menu, 'Playbook Moves'));
check('… with its notation shown', str_contains($merged_menu, '2d6 + {sharp}'));
check('… and its first description line as detail', str_contains($merged_menu, 'Act under pressure with +Sharp instead.')
    && !str_contains($merged_menu, 'Second line never shows'));
check('an untaken move stays off the menu', !str_contains($merged_menu, 'Listed But Untaken'));

// Rolling a character-carried move: resolves by label, rolls the entry's own
// dice with the character's stat substituted (sharp 3: 4 + 3 + 3 = 10).
$move_roll = Roll::respond("i've read about this sort of thing", $chr_roll_template, $moves_sheet, $url, $scripted([4, 3]));
$move_roll_text = json_encode($move_roll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a character roll rolls in_channel like any other', $move_roll['response_type'] === 'in_channel');
check('… with the character\'s stat substituted', str_contains($move_roll_text, '+3') && str_contains($move_roll_text, '=  *10*'));
check('… showing the entry\'s notation', str_contains($move_roll_text, '2d6 + {sharp}'));

// A unique prefix reaches a character roll the same as a system one.
check(
    'a unique prefix resolves a character roll',
    str_contains(
        json_encode(Roll::respond("i've read", $chr_roll_template, $moves_sheet, $url, $scripted([4, 3]))),
        '=  *10*'
    )
);

// A character roll whose label collides with a system roll's label: the
// AMBIGUITY reply, never a silent pick and never an override (decision 4).
$collide_sheet = $chr_roll_sheet(['playbook_moves' => [
    ['name' => 'Act Under Pressure', 'effect' => '', 'has' => true, 'dice' => '3d6'],
]] + $chr_roll_values, ['curse']);
$collide = Roll::respond('Act Under Pressure', $chr_roll_template, $collide_sheet, $url, $scripted([1, 1]));
check('a label collision with a system roll is ambiguous', $collide['response_type'] === 'ephemeral'
    && str_contains($collide['text'], 'Which one?'));
check('… and never rolls', !str_contains($collide['text'], '='));
// The system roll's ID still wins outright — ids are stage 1, and character
// rolls have none, so an id-shaped query cannot ambiguate.
check(
    'the exact id still resolves the system roll outright',
    str_contains(
        json_encode(Roll::respond('act_under_pressure', $chr_roll_template, $collide_sheet, $url, $scripted([4, 3]))),
        '=  *9*'
    )
);

// Two entries sharing a name: ambiguous, never a guess.
$twin_sheet = $chr_roll_sheet(['playbook_moves' => [
    ['name' => 'Favored Weapon', 'effect' => '', 'has' => true, 'dice' => '1d8'],
    ['name' => 'Favored Weapon', 'effect' => '', 'has' => true, 'dice' => '1d10'],
]] + $chr_roll_values, ['curse']);
$twins = Roll::respond('Favored Weapon', $chr_roll_template, $twin_sheet, $url, $scripted([1]));
check('two same-named entries are ambiguous', str_contains($twins['text'], 'Which one?'));

// The security rule holds for character dice too: an entry referencing a
// stat the viewer can't see refuses for the player and rolls for the GM.
$secret_moves = [['name' => 'Channel the Curse', 'effect' => '', 'has' => true, 'dice' => '1d6 + {curse}']];
$secret_player = Roll::respond(
    'Channel the Curse',
    $chr_roll_template,
    $chr_roll_sheet(['playbook_moves' => $secret_moves] + $chr_roll_values, ['curse']),
    $url,
    $scripted([5])
);
check('a character roll over an invisible stat refuses', $secret_player['response_type'] === 'ephemeral'
    && str_contains(strtolower($secret_player['text']), "can't see"));
$secret_gm = Roll::respond(
    'Channel the Curse',
    $chr_roll_template,
    $chr_roll_sheet(['playbook_moves' => $secret_moves] + $chr_roll_values),
    $url,
    $scripted([5])
);
check('the GM rolls the same entry (5 + curse 4 = 9)', $secret_gm['response_type'] === 'in_channel'
    && str_contains(json_encode($secret_gm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '=  *9*'));

// A system with an empty rolls table but a contributing list still serves a
// menu — the sheet is a legitimate source of rolls on its own.
$chr_sheet_only_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Freeform',
    'version' => 1,
    'properties' => [
        ['id' => 'edge', 'label' => 'Edge', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'tricks', 'label' => 'Tricks', 'type' => 'list', 'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'dice', 'label' => 'Roll', 'type' => 'dice'],
        ]],
    ],
]));
check('sheet-only fixture parses', is_array($chr_sheet_only_template), is_wp_error($chr_sheet_only_template) ? $chr_sheet_only_template->get_error_message() : '');
$chr_sheet_only = [
    'characterId' => 9, 'title' => 'Nix', 'canEdit' => false, 'system' => 'Freeform', 'layout' => [],
    'properties' => [
        $chr_sheet_only_template['properties']['edge'] + ['value' => 2, 'display' => '+2'],
        $chr_sheet_only_template['properties']['tricks'] + ['value' => [['name' => 'Card Trick', 'dice' => '1d4 + {edge}']], 'display' => '1 entry'],
    ],
];
$sheet_only_menu = Roll::respond('', $chr_sheet_only_template, $chr_sheet_only, $url, $scripted([]));
check('a rolls-less system with sheet dice serves a menu, not a refusal', str_contains($sheet_only_menu['text'], 'Card Trick'));
$sheet_only_roll = Roll::respond('card trick', $chr_sheet_only_template, $chr_sheet_only, $url, $scripted([3]));
check('… and the sheet roll rolls (3 + 2 = 5)', $sheet_only_roll['response_type'] === 'in_channel'
    && str_contains(json_encode($sheet_only_roll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '=  *5*'));

// A truly empty union keeps refusing, mentioning both sources.
$chr_sheet_only_empty = [
    'characterId' => 9, 'title' => 'Nix', 'canEdit' => false, 'system' => 'Freeform', 'layout' => [],
    'properties' => [$chr_sheet_only_template['properties']['edge'] + ['value' => 2, 'display' => '+2']],
];
$empty_union = Roll::respond('anything', $chr_sheet_only_template, $chr_sheet_only_empty, $url, $scripted([1]));
check('an empty union still says no rolls', str_contains(strtolower($empty_union['text']), 'no rolls'));
check('… and mentions dice on the sheet as the other source', str_contains(strtolower($empty_union['text']), 'dice'));

// --- entry["…"] placeholders (2026-07-25 Phase B) -----------------------------
// A character-carried roll may reach the entry that declared it, through the
// `entry` namespace only. This is why gear damage works on paper: a weapon's
// dice add the weapon's OWN harm rating, not a character stat.

$chr_gear_rows = [
    ['name' => 'Machete', 'notes' => 'Big knife. Never jams.', 'weapon' => true, 'harm_rating' => 2, 'harm' => 1,
     'roll' => '1d8 + {entry["harm_rating"]}'],
    ['name' => 'Crossbow', 'notes' => '', 'weapon' => true, 'harm_rating' => 3, 'harm' => 0,
     'roll' => '1d6 + {entry["harm_rating"]}'],
];
$gear_sheet = $chr_roll_sheet(['gear' => $chr_gear_rows] + $chr_roll_values, ['curse']);

$machete = Roll::respond('machete', $chr_roll_template, $gear_sheet, $url, $scripted([5]));
$machete_text = json_encode($machete, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('entry["…"] rolls with the entry\'s own number (5 + rating 2 = 7)', $machete['response_type'] === 'in_channel'
    && str_contains($machete_text, '+2') && str_contains($machete_text, '=  *7*'));
check('… showing the notation as written', str_contains($machete['text'], '1d8 + {entry["harm_rating"]}'));
$crossbow_text = json_encode(
    Roll::respond('crossbow', $chr_roll_template, $gear_sheet, $url, $scripted([2])),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('two entries in one list roll their own values (2 + rating 3 = 5)', str_contains($crossbow_text, '+3')
    && str_contains($crossbow_text, '=  *5*'));

// The shadowing case the namespace exists for: the character has a `harm`
// TRACK (current 3) and the entry a `harm` NUMBER (1). One expression, both
// meanings, no silent winner.
$shadow_sheet = $chr_roll_sheet(['gear' => [
    ['name' => 'Cursed Blade', 'notes' => '', 'weapon' => true, 'harm_rating' => 0, 'harm' => 1,
     'roll' => '1d8 + {harm["current"]} + {entry["harm"]}'],
]] + $chr_roll_values, ['curse']);
$shadow_text = json_encode(
    Roll::respond('cursed blade', $chr_roll_template, $shadow_sheet, $url, $scripted([4])),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('{harm["current"]} resolves the character\'s track', str_contains($shadow_text, '+3'));
check('{entry["harm"]} resolves the entry (4 + 3 + 1 = 8)', str_contains($shadow_text, '+1')
    && str_contains($shadow_text, '=  *8*'));

// An unknown entry field fails LOUD at roll time — roll_dice() would read it
// as 0, and a quietly wrong total is the one thing a dice bot must never post.
$mystery_sheet = $chr_roll_sheet(['gear' => [
    ['name' => 'Mystery Tool', 'notes' => '', 'weapon' => true, 'harm_rating' => 1, 'harm' => 0,
     'roll' => '1d8 + {entry["nonesuch"]}'],
    $chr_gear_rows[0],
]] + $chr_roll_values, ['curse']);
$mystery = Roll::respond('mystery tool', $chr_roll_template, $mystery_sheet, $url, $scripted([5]));
check('an unknown entry field errors loudly, never a silent 0', $mystery['response_type'] === 'ephemeral'
    && str_contains($mystery['text'], "didn't work out")
    && str_contains($mystery['text'], 'nonesuch'));
// Inert read unchanged: the bad expression costs its neighbors nothing.
$mystery_menu = json_encode(
    Roll::respond('', $chr_roll_template, $mystery_sheet, $url, $scripted([])),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('a bad entry expression still lists, and lists its neighbors', str_contains($mystery_menu, 'Mystery Tool')
    && str_contains($mystery_menu, 'Machete'));
check('… and the neighbor still rolls', str_contains(
    json_encode(Roll::respond('machete', $chr_roll_template, $mystery_sheet, $url, $scripted([5])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    '=  *7*'
));

// Mixed refs keep the security rule: {curse} is gm_only, and riding next to
// an entry ref changes nothing about it — refuse the player, roll for the GM.
$hexed_rows = [['name' => 'Hexed Gun', 'notes' => '', 'weapon' => true, 'harm_rating' => 2, 'harm' => 0,
    'roll' => '1d6 + {curse} + {entry["harm_rating"]}']];
$hexed_player = Roll::respond(
    'hexed gun',
    $chr_roll_template,
    $chr_roll_sheet(['gear' => $hexed_rows] + $chr_roll_values, ['curse']),
    $url,
    $scripted([2])
);
check('mixed refs: a gm_only stat still refuses for a player', $hexed_player['response_type'] === 'ephemeral'
    && str_contains(strtolower($hexed_player['text']), "can't see"));
$hexed_gm = Roll::respond(
    'hexed gun',
    $chr_roll_template,
    $chr_roll_sheet(['gear' => $hexed_rows] + $chr_roll_values),
    $url,
    $scripted([2])
);
check('mixed refs: the GM rolls it (2 + curse 4 + rating 2 = 8)', $hexed_gm['response_type'] === 'in_channel'
    && str_contains(json_encode($hexed_gm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '=  *8*'));

// A placeholder that works out to TEXT errors rather than adding a silent 0
// — the Phase A softness, tightened for entry fields and properties alike.
$vain_sheet = $chr_roll_sheet(['gear' => [
    ['name' => 'Nameless Charm', 'notes' => '', 'weapon' => true, 'harm_rating' => 0, 'harm' => 0,
     'roll' => '1d4 + {entry["name"]}'],
], 'playbook_moves' => [
    ['name' => 'Vain Strike', 'effect' => '', 'has' => true, 'dice' => '2d6 + {look}'],
]] + $chr_roll_values, ['curse']);
$vain_entry = Roll::respond('nameless charm', $chr_roll_template, $vain_sheet, $url, $scripted([2]));
check('a text-valued entry field errors instead of adding 0', $vain_entry['response_type'] === 'ephemeral'
    && str_contains($vain_entry['text'], "didn't work out"));
$vain_prop = Roll::respond('vain strike', $chr_roll_template, $vain_sheet, $url, $scripted([2, 2]));
check('a text-valued property errors the same way', $vain_prop['response_type'] === 'ephemeral'
    && str_contains($vain_prop['text'], "didn't work out"));

// --- Dice interpolation (2026-08-04) -----------------------------------------
// `{gut}` is not a number: it splices THIS character's pool into the roll, so
// one system roll finally means "roll your own dice plus the shared math".

$pool_sheet = $chr_roll_sheet(['gut' => '2d4'] + $chr_roll_values, ['curse', 'hex_dice']);
$spliced = Roll::respond('nausea check', $chr_roll_template, $pool_sheet, $url, $scripted([1, 4]));
$spliced_text = json_encode($spliced, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a spliced pool rolls in_channel like any other roll', $spliced['response_type'] === 'in_channel', $spliced_text);
check('… showing the pool\'s own faces', str_contains($spliced_text, '[1]') && str_contains($spliced_text, '[4]'));
check('… with the rest of the roll intact (1 + 4 + cool 2 = 7)', str_contains($spliced_text, '+2')
    && str_contains($spliced_text, '=  *7*'));
check('… and the notation as the system declared it', str_contains($spliced_text, '{gut} + {cool}'));

// A pool the viewer CAN see, holding nothing rollable: named, because it is
// their own property and the sheet is where they fix it.
$blank_pool = Roll::respond('nausea check', $chr_roll_template, $player_sheet, $url, $scripted([1, 4]));
check('an empty pool refuses instead of rolling', $blank_pool['response_type'] === 'ephemeral');
check('… naming the roll and the pool', str_contains($blank_pool['text'], 'Nausea Check')
    && str_contains($blank_pool['text'], 'Gut'));
check('… saying what dice look like', str_contains($blank_pool['text'], '2d6+1d4'));
check('… and that the sheet is blank', str_contains($blank_pool['text'], 'blank'));
check('… without rolling anything', !str_contains($blank_pool['text'], '['));

$junk_pool = Roll::respond(
    'nausea check',
    $chr_roll_template,
    $chr_roll_sheet(['gut' => 'as much as it takes'] + $chr_roll_values, ['curse', 'hex_dice']),
    $url,
    $scripted([1, 4])
);
check('an unparseable pool refuses too', $junk_pool['response_type'] === 'ephemeral');
check('… quoting what the sheet actually holds', str_contains($junk_pool['text'], 'as much as it takes'));

// The security rule reaches pools unchanged: a gm_only pool is refused in the
// same words as a gm_only number, and the refusal names neither.
$hidden_pool = Roll::respond('hex check', $chr_roll_template, $pool_sheet, $url, $scripted([3]));
check('a roll splicing an invisible pool refuses', $hidden_pool['response_type'] === 'ephemeral'
    && str_contains(strtolower($hidden_pool['text']), "can't see"));
check('… and never names the pool', !str_contains($hidden_pool['text'], 'Hex Dice'));
$hidden_pool_gm = Roll::respond(
    'hex check',
    $chr_roll_template,
    $chr_roll_sheet(['gut' => '2d4', 'hex_dice' => '1d6'] + $chr_roll_values),
    $url,
    $scripted([3])
);
check('… while the GM, who can see it, rolls it (3 + 1 = 4)', $hidden_pool_gm['response_type'] === 'in_channel'
    && str_contains(json_encode($hidden_pool_gm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '=  *4*'));

// Term limits are a POST-expansion rule: nine constants plus a three-term
// pool is twelve terms, and the roll refuses in the parser's own words.
$fat_pool = Roll::respond(
    'everything at once',
    $chr_roll_template,
    $chr_roll_sheet(['gut' => '1d4+1d6+1d8'] + $chr_roll_values, ['curse', 'hex_dice']),
    $url,
    $scripted([1, 1, 1])
);
check('a pool that overruns the term limit refuses', $fat_pool['response_type'] === 'ephemeral');
check('… in the parser\'s existing words', str_contains($fat_pool['text'], 'at most 10 terms'));
check(
    '… while the same roll under a smaller pool still rolls (9 + 1 + 1 = 11)',
    str_contains(
        json_encode(
            Roll::respond('everything at once', $chr_roll_template, $pool_sheet, $url, $scripted([1, 1])),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        '=  *11*'
    )
);

// A character-carried roll may splice a pool too — the entry scope changes
// nothing about what a pool is.
$entry_pool = Roll::respond(
    'gut punch',
    $chr_roll_template,
    $chr_roll_sheet([
        'gut' => '2d4',
        'playbook_moves' => [['name' => 'Gut Punch', 'effect' => '', 'has' => true, 'dice' => '{gut} + {sharp}']],
    ] + $chr_roll_values, ['curse', 'hex_dice']),
    $url,
    $scripted([2, 3])
);
check('a sheet entry can splice a pool as well (2 + 3 + sharp 3 = 8)', $entry_pool['response_type'] === 'in_channel'
    && str_contains(json_encode($entry_pool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '=  *8*'));

// A pool asked to be a number fails LOUD at roll time. Save-time validation
// catches this in a rolls: table; a character writes their own dice with no
// such gate, so the roll path owes the refusal.
$half_pool = Roll::respond(
    'half measures',
    $chr_roll_template,
    $chr_roll_sheet([
        'gut' => '2d4',
        'playbook_moves' => [['name' => 'Half Measures', 'effect' => '', 'has' => true, 'dice' => '1d6 + {floor(gut / 2)}']],
    ] + $chr_roll_values, ['curse', 'hex_dice']),
    $url,
    $scripted([5])
);
check('a pool used as a number errors instead of adding 0', $half_pool['response_type'] === 'ephemeral'
    && str_contains($half_pool['text'], "didn't work out"));
check('… saying a pool is not a number', str_contains($half_pool['text'], "can't be used as a number"));

// --- Pools join the union, and every roll declares what it is ----------------
// A dice property is a roll under its own name, and every roll in the union
// now carries `traits` (what it IS) and `uses` (what its dice reach) — the
// two keys effect targeting reads.

$chr_union = Roll::union($chr_roll_template, $pool_sheet);
check('a valued pool joins the union under its own id', ($chr_union['pool:gut']['id'] ?? null) === 'gut');
check('… keyed out of the id namespace, like sheet rolls', !isset($chr_union['gut']));
check(
    '… shaped like a declared roll',
    ($chr_union['pool:gut']['label'] ?? null) === 'Gut'
        && $chr_union['pool:gut']['section'] === null
        && $chr_union['pool:gut']['dice'] === '2d4'
        && $chr_union['pool:gut']['detail'] === 'the pool that can end you'
);
check('… carrying the property\'s traits', ($chr_union['pool:gut']['traits'] ?? null) === ['check' => true]);
check('… and using itself', ($chr_union['pool:gut']['uses'] ?? null) === ['gut']);
check('a system roll splicing a pool uses the pool AND its properties',
    ($chr_union['nausea_check']['uses'] ?? null) === ['gut', 'cool'], json_encode($chr_union['nausea_check']['uses'] ?? null));
check('a system roll with no traits declared carries none',
    ($chr_union['act_under_pressure']['traits'] ?? null) === []);
check('an ordinary system roll uses the properties it adds',
    ($chr_union['act_under_pressure']['uses'] ?? null) === ['cool']);
check('a formula placeholder contributes every property it reads',
    ($chr_union['use_magic']['uses'] ?? null) === ['weird', 'luck']);
$chr_union_entry = Roll::union($chr_roll_template, $chr_roll_sheet([
    'playbook_moves' => [['name' => 'Gut Punch', 'effect' => '', 'has' => true, 'dice' => '{gut} + {sharp}']],
    'gut' => '2d4',
] + $chr_roll_values, ['curse', 'hex_dice']));
check('a sheet roll gets the two keys too', ($chr_union_entry['sheet:0']['traits'] ?? null) === []
    && ($chr_union_entry['sheet:0']['uses'] ?? null) === ['gut', 'sharp']);
check('a pool the viewer cannot see joins no union', !isset(Roll::union(
    $chr_roll_template,
    $chr_roll_sheet(['hex_dice' => '1d6'] + $chr_roll_values, ['curse', 'hex_dice'])
)['pool:hex_dice']));
check('an unvalued pool joins no union either', !isset(Roll::union($chr_roll_template, $player_sheet)['pool:gut']));

// The menu and the roll: a pool is picked by label or by id, like anything.
$pool_menu = json_encode(
    Roll::respond('', $chr_roll_template, $pool_sheet, $url, $scripted([])),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('the menu lists the pool by label', str_contains($pool_menu, 'Gut'));
check('… with its notation', str_contains($pool_menu, '2d4'));
check('… and its detail', str_contains($pool_menu, 'the pool that can end you'));
$pool_roll = Roll::respond('gut', $chr_roll_template, $pool_sheet, $url, $scripted([3, 2]));
$pool_roll_text = json_encode($pool_roll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('/game roll gut rolls the pool (3 + 2 = 5)', $pool_roll['response_type'] === 'in_channel'
    && str_contains($pool_roll_text, '=  *5*'), $pool_roll_text);
check('… naming it as the sheet does', str_contains($pool_roll_text, 'Gut'));
check(
    '… and a unique prefix reaches it too',
    str_contains(
        json_encode(Roll::respond('gu', $chr_roll_template, $pool_sheet, $url, $scripted([1, 1]))),
        '=  *2*'
    )
);

// --- Effects ride the roll they touch (2026-08-04) ---------------------------
// A GM-applied effect prints as its own labeled, signed term inside the faces
// line and lands in the total. That printing IS the lifecycle: nothing here
// expires, so a stale effect is caught in front of the whole table at the
// moment it costs somebody something.

/** An instance as the store hands it over. */
$chr_fx_named = function (string $effect, int $amount = 1): array {
    return chronicler_sheets_effects_normalize(['effect' => $effect, 'amount' => $amount]);
};
$chr_fx_one_off = function (string $label, int $modifier, ?string $target = null): array {
    return chronicler_sheets_effects_normalize(['label' => $label, 'modifier' => $modifier, 'target' => $target]);
};

// Two Queasy at amount 1: 1 + 4 (the pool) + cool 2 - 2 = 5.
$queasy = Roll::respond('nausea check', $chr_roll_template, $pool_sheet, $url, $scripted([1, 4]), [
    $chr_fx_named('queasy'),
    $chr_fx_named('queasy'),
]);
$queasy_text = json_encode($queasy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('an applied effect still rolls in_channel', $queasy['response_type'] === 'in_channel', $queasy_text);
check('… printing its term labeled and signed', str_contains($queasy_text, '-2 (Queasy)'));
check('… on the faces line, beside the dice it changed', str_contains($queasy_text, '[1] [4]  +2  -2 (Queasy)  =  *5*'), $queasy_text);
check('… and in the total (1 + 4 + cool 2 - 2 = 5)', str_contains($queasy_text, '=  *5*'));
check('… and in the text fallback, which is all a notification gets',
    str_contains($queasy['text'], '= 5') && str_contains($queasy['text'], '(-2 Queasy)'));

// The same instances on a roll they don't target: not a +0, not a mention.
$untouched = Roll::respond('act under pressure', $chr_roll_template, $pool_sheet, $url, $scripted([4, 3]), [
    $chr_fx_named('queasy'),
]);
check('an effect that doesn\'t touch this roll stays out of it', !str_contains($untouched['text'], 'Queasy'));
check('… leaving the total alone (4 + 3 + cool 2 = 9)',
    str_contains(json_encode($untouched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '=  *9*'));

// The cap bounds the SUM of one effect's instances, and prints once.
$capped = Roll::respond('nausea check', $chr_roll_template, $pool_sheet, $url, $scripted([1, 4]), [
    $chr_fx_named('queasy'),
    $chr_fx_named('queasy'),
    $chr_fx_named('queasy', 2),
]);
$capped_text = json_encode($capped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('stacked instances print as ONE capped term', str_contains($capped_text, '-3 (Queasy)')
    && substr_count($capped_text, '(Queasy)') === 1);
check('… and the total takes the cap, not the sum (7 - 3 = 4)', str_contains($capped_text, '=  *4*'));

// A one-off the GM invented on the spot, targeting a property: it rides every
// roll whose dice reach `cool`, and no other.
$acne = Roll::respond('act under pressure', $chr_roll_template, $pool_sheet, $url, $scripted([4, 3]), [
    $chr_fx_one_off('Acne', -2, 'cool'),
]);
$acne_text = json_encode($acne, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a one-off prints under its own label (4 + 3 + 2 - 2 = 7)', str_contains($acne_text, '-2 (Acne)')
    && str_contains($acne_text, '=  *7*'));
check('… and not on a roll that never reaches its target', !str_contains(json_encode(
    Roll::respond('gut', $chr_roll_template, $pool_sheet, $url, $scripted([3, 2]), [$chr_fx_one_off('Acne', -2, 'cool')]),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
), 'Acne'));

// Several effects on one roll: each its own term, in the order applied.
$stacked = json_encode(
    Roll::respond('nausea check', $chr_roll_template, $pool_sheet, $url, $scripted([1, 4]), [
        $chr_fx_named('forward'),
        $chr_fx_named('queasy'),
    ]),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('two effects print as two terms, in the order applied', str_contains($stacked, '+1 (Forward)  -1 (Queasy)'));
check('… and both land in the total (7 + 1 - 1 = 7)', str_contains($stacked, '=  *7*'));

// THE SECURITY RULE, through an effect's formula. `hexed` reads `curse`, which
// is gm_only: the player's filtered sheet doesn't carry it, so the roll must
// refuse in the same unrevealing words a placeholder gets — the effect is
// public, the property is not.
$hexed_roll = Roll::respond('act under pressure', $chr_roll_template, $player_sheet, $url, $scripted([4, 3]), [
    $chr_fx_named('hexed'),
]);
check('an effect formula over an invisible stat refuses the roll', $hexed_roll['response_type'] === 'ephemeral'
    && str_contains(strtolower($hexed_roll['text']), "can't see"));
check('… naming neither the property nor its value', !str_contains($hexed_roll['text'], 'Curse')
    && !str_contains($hexed_roll['text'], '4'));
check('… and rolling nothing', !str_contains($hexed_roll['text'], '['));
$hexed_gm_roll = Roll::respond('act under pressure', $chr_roll_template, $gm_sheet, $url, $scripted([4, 3]), [
    $chr_fx_named('hexed'),
]);
$hexed_gm_text = json_encode($hexed_gm_roll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('… while the GM, whose sheet carries it, rolls (4 + 3 + 2 - 2 = 7)',
    $hexed_gm_roll['response_type'] === 'in_channel' && str_contains($hexed_gm_text, '-2 (Hexed)')
        && str_contains($hexed_gm_text, '=  *7*'), $hexed_gm_text);

// An effect that comes apart mid-roll: the whole roll refuses, naming the
// effect and nothing else. A wrong total posted in_channel is the failure a
// dice bot exists to prevent, and a labeled effect is public by design.
$garbled = Roll::respond('gut', $chr_roll_template, $pool_sheet, $url, $scripted([3, 2]), [
    $chr_fx_named('garbled'),
]);
check('an effect that produces a non-number refuses the roll', $garbled['response_type'] === 'ephemeral');
check('… naming the roll and the effect', $garbled['text'] === "*Gut* can't roll: the *Garbled* effect didn't work out.");
check('… and posting no dice', !str_contains($garbled['text'], '['));
check(
    '… while the same effect, evaluating to 0 elsewhere, rolls normally (7)',
    str_contains(
        json_encode(
            Roll::respond('nausea check', $chr_roll_template, $pool_sheet, $url, $scripted([1, 4]), [$chr_fx_named('garbled')]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        '=  *7*'
    )
);

// An instance of an effect the template no longer declares costs the table
// nothing: skipped here, still listed on the sheet for the GM to clear.
check(
    'an instance of a dropped definition neither prints nor stops the roll',
    str_contains(
        json_encode(
            Roll::respond('nausea check', $chr_roll_template, $pool_sheet, $url, $scripted([1, 4]), [$chr_fx_named('nonesuch')]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        '=  *7*'
    )
);

// --- Wiring ------------------------------------------------------------------

check('help advertises roll', str_contains(Commands::dispatch(['text' => 'help'])['text'], Commands::COMMAND . ' roll'));
check('roll is no longer advertised as coming soon', !str_contains(
    Commands::dispatch(['text' => 'help'])['text'],
    'coming soon'
));
$manifest = \Chronicler\Settings\Screen::manifest_yaml();
// The hint is what Slack greys out beside the command box, so it shows each
// subcommand WITH its argument and leads with the two people type daily.
check('the manifest usage_hint names my and roll', str_contains($manifest, 'usage_hint: "my <thing> | roll <name> | effect <character> | link <character> | help"'));
check('the manifest usage_hint shows my takes an argument', str_contains($manifest, 'my <thing>'));

$unlinked = Commands::dispatch(['text' => 'roll act under pressure', 'user_id' => 'UNOBODY']);
check('an unlinked caller is answered ephemerally', $unlinked['response_type'] === 'ephemeral');
check('an unlinked caller is pointed at /game link', str_contains($unlinked['text'], Commands::COMMAND . ' link'));
