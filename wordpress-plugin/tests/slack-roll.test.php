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
        // A list whose entries can carry their own dice (2026-07-25). Empty by
        // default, so sheets that don't set it contribute nothing and every
        // pre-existing assertion below is undisturbed.
        ['id' => 'playbook_moves', 'label' => 'Playbook Moves', 'type' => 'list', 'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'effect', 'label' => 'Description', 'type' => 'longtext'],
            ['id' => 'has', 'label' => 'Has it', 'type' => 'toggle'],
            ['id' => 'dice', 'label' => 'Roll', 'type' => 'dice', 'when' => 'has'],
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
$chr_roll_values = ['cool' => 2, 'sharp' => 3, 'tough' => -1, 'weird' => 1, 'luck' => 5, 'curse' => 4, 'moves' => []];
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

// --- Wiring ------------------------------------------------------------------

check('help advertises roll', str_contains(Commands::dispatch(['text' => 'help'])['text'], Commands::COMMAND . ' roll'));
check('roll is no longer advertised as coming soon', !str_contains(
    Commands::dispatch(['text' => 'help'])['text'],
    'coming soon'
));
$manifest = \Chronicler\Settings\Screen::manifest_yaml();
// The hint is what Slack greys out beside the command box, so it shows each
// subcommand WITH its argument and leads with the two people type daily.
check('the manifest usage_hint names my and roll', str_contains($manifest, 'usage_hint: "my <thing> | roll <name> | link <character> | help"'));
check('the manifest usage_hint shows my takes an argument', str_contains($manifest, 'my <thing>'));

$unlinked = Commands::dispatch(['text' => 'roll act under pressure', 'user_id' => 'UNOBODY']);
check('an unlinked caller is answered ephemerally', $unlinked['response_type'] === 'ephemeral');
check('an unlinked caller is pointed at /game link', str_contains($unlinked['text'], Commands::COMMAND . ' link'));
