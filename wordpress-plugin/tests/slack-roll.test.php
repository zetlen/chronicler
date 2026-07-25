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
    ],
    'layout' => [
        ['id' => 'stats', 'section' => 'Ratings', 'properties' => ['cool', 'sharp', 'tough', 'weird']],
        ['section' => 'Status', 'properties' => ['luck', 'moves', 'curse']],
    ],
    'rolls' => [
        ['id' => 'act_under_pressure', 'label' => 'Act Under Pressure', 'section' => 'Basic Moves',
         'dice' => '2d6 + {cool}', 'detail' => 'when you do something under fire'],
        // §4b: the same move, rolled with a different stat, and ONLY for a
        // character who has taken it. Both entries exist — nothing is
        // substituted, because the move is situational.
        ['id' => 'act_under_pressure_read_about', 'label' => "Act Under Pressure (I've Read About This Sort of Thing)",
         'section' => 'Basic Moves', 'when' => 'moves["read_about_this"]', 'dice' => '2d6 + {sharp}'],
        ['id' => 'kick_some_ass', 'label' => 'Kick Some Ass', 'section' => 'Basic Moves',
         'dice' => '2d6 + {tough}'],
        ['id' => 'ability_check', 'label' => 'Ability Check', 'section' => 'Attacks',
         'dice' => '2d20kh1 + {cool} - 1'],
        ['id' => 'curse_check', 'label' => 'Curse Check', 'section' => 'Attacks',
         'dice' => '1d6 + {curse}'],
        // A gate over a stat only the GM can see: unavailable to a player
        // SILENTLY, since announcing it would announce the stat.
        ['id' => 'break_the_curse', 'label' => 'Break the Curse', 'section' => 'Attacks',
         'when' => 'curse > 0', 'dice' => '2d6'],
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
// The same player, after taking the move that changes Act Under Pressure.
$read_about_sheet = $chr_roll_sheet(['moves' => ['read_about_this']] + $chr_roll_values, ['curse']);

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

// --- §4b: a move that changes a roll -----------------------------------------
// A roll whose `when` is false does not exist for that character: absent from
// the menu AND not rollable by exact name. The reply is the one an unknown
// name gets, because saying "you don't have that" would reveal that it exists.

$off_menu = json_encode(
    Roll::respond('', $chr_roll_template, $player_sheet, $url, $scripted([])),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('a roll whose when is false is absent from the listing', !str_contains($off_menu, 'Read About'));
check('… while its ungated twin is still listed', str_contains($off_menu, 'Act Under Pressure'));

$off_name = Roll::respond('act_under_pressure_read_about', $chr_roll_template, $player_sheet, $url, $scripted([4, 3]));
$off_name_text = json_encode($off_name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a gated-off roll is not rollable by its exact id', $off_name['response_type'] === 'ephemeral');
check('… and answers exactly as an unknown name does', str_contains(strtolower($off_name_text), "don't know that roll"));
check('… without revealing that the roll exists', !str_contains($off_name_text, 'Read About'));
check('… and without throwing a die', !str_contains($off_name_text, '[4]'));
check(
    '… nor by its exact label',
    str_contains(
        strtolower(json_encode(Roll::respond("Act Under Pressure (I've Read About This Sort of Thing)", $chr_roll_template, $player_sheet, $url, $scripted([4, 3])))),
        "don't know that roll"
    )
);

// Same character, same template, one move checked.
$on_menu = json_encode(
    Roll::respond('', $chr_roll_template, $read_about_sheet, $url, $scripted([])),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
check('checking the move puts its roll on the menu', str_contains($on_menu, 'Read About'));
check('… beside the ungated one — nothing is substituted', str_contains($on_menu, 'Act Under Pressure'));

$on_roll = Roll::respond('act_under_pressure_read_about', $chr_roll_template, $read_about_sheet, $url, $scripted([4, 3]));
$on_roll_text = json_encode($on_roll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('a gated-on roll rolls', $on_roll['response_type'] === 'in_channel');
check('… with its own stat (sharp 3, not cool 2)', str_contains($on_roll_text, '+3') && str_contains($on_roll_text, '=  *10*'));
check(
    'the ungated twin still rolls its own stat for the same character',
    str_contains(
        json_encode(Roll::respond('act_under_pressure', $chr_roll_template, $read_about_sheet, $url, $scripted([4, 3]))),
        '=  *9*'
    )
);

// A `when` the viewer-filtered sheet can't evaluate fails CLOSED and SILENT —
// deliberately unlike the placeholder rule, which refuses out loud. There the
// player named a roll they can see and deserves to know why it won't run; here
// an explicit refusal would announce a GM-gated move.
$gate_hidden = Roll::respond('break the curse', $chr_roll_template, $player_sheet, $url, $scripted([1, 1]));
$gate_hidden_text = strtolower(json_encode($gate_hidden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
check('a when over an invisible stat makes the roll unavailable', str_contains($gate_hidden_text, "don't know that roll"));
check('… silently — no "you can\'t see that" refusal', !str_contains($gate_hidden_text, "can't see"));
check('… and the roll is not named', !str_contains($gate_hidden_text, 'break the curse'));
$gate_gm = Roll::respond('break the curse', $chr_roll_template, $gm_sheet, $url, $scripted([1, 1]));
check('the viewer who CAN see the stat gets the roll', $gate_gm['response_type'] === 'in_channel');

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
