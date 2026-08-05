<?php
// `/game effect` — the GM-applied modifiers of the 2026-08-04 design, handed
// out and taken away from Slack (4.31.0).
//
// Effect::respond() is the whole command minus the WordPress lookups: query,
// template, who it addresses and what they carry in, Slack body out. Its one
// impurity is the store itself (sheets/effects.php), which writes through the
// same meta stubs effects-store.test.php drives — so a mutation here is
// asserted twice over: what the channel is told, and what the row now holds.
//
// Reuses the stubs template-store.test.php (meta round trip), render.test.php
// (roster, titles) and slack-my.test.php (the Slack-id lookup) already define.

use Chronicler\Slack\Bot\Commands;
use Chronicler\Slack\Bot\Effect;

check('Effect exists', class_exists(Effect::class));

// --- Fixture -----------------------------------------------------------------
// A system with both modifier forms and a trait to target, plus a gm_only stat
// no effect here reads (the security rule's own coverage is slack-roll's).
$chr_fx_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Hot Dog Hoedown',
    'version' => 1,
    'properties' => [
        ['id' => 'rizz', 'label' => 'Rizz', 'type' => 'dice'],
        ['id' => 'crowd', 'label' => 'Crowd', 'type' => 'counter', 'max' => 10],
    ],
    'rolls' => [
        ['id' => 'nausea_check', 'label' => 'Nausea Check', 'dice' => '2d6', 'traits' => ['check' => true]],
        ['id' => 'taunt', 'label' => 'Taunt', 'dice' => '{rizz}'],
    ],
    'effects' => [
        ['id' => 'queasy', 'label' => 'Queasy', 'detail' => 'the bill for the last greedy thing you did',
         'applies_to' => 'nausea_check', 'modifier' => -1, 'cap' => -3],
        ['id' => 'forward', 'label' => 'Forward', 'modifier' => 1, 'cap' => 2],
        ['id' => 'fortified', 'label' => 'Fortified', 'applies_to' => 'check', 'modifier' => 1],
        ['id' => 'taunted', 'label' => 'Taunted', 'modifier' => "'rizz' in roll['uses'] ? -amount : 0", 'cap' => -2],
    ],
]));
check('effect fixture template parses', is_array($chr_fx_template), is_wp_error($chr_fx_template) ? $chr_fx_template->get_error_message() : '');

$chr_fx_roster = [7 => 'Barrel', 9 => 'Big Marty'];
$chr_fx_who = ['id' => 7, 'name' => 'Barrel'];
$chr_fx_gm = ['id' => 3, 'is_gm' => true];
$chr_fx_player = ['id' => 4, 'is_gm' => false];

/** Run the command against a clean meta registry, seeded with $carried. */
$chr_fx_run = function (string $query, array $caller, array $carried = [], array $appliers = [])
    use ($chr_fx_template, $chr_fx_who, $chr_fx_roster): array {
    $GLOBALS['chr_test_post_meta'] = [];
    $GLOBALS['chr_test_post_meta_writes'] = [];
    foreach ($carried as $instance) {
        chronicler_sheets_effects_add(7, $instance);
    }
    $GLOBALS['chr_test_post_meta_writes'] = [];
    return Effect::respond(
        $query,
        $chr_fx_template,
        $chr_fx_who,
        $caller,
        chronicler_sheets_effects_get(7),
        $chr_fx_roster,
        $appliers
    );
};

// --- The grammar --------------------------------------------------------------
// A character's name is where the command splits, and only the roster knows
// how many words it is.

$chr_fx_parse = fn(string $q): array => Effect::parse($q, $chr_fx_roster);
check('parse: nothing means your own', $chr_fx_parse('') === ['kind' => 'list', 'character' => null, 'who' => '']);
check('parse: a bare name lists theirs', $chr_fx_parse('Barrel')['character'] === 7);
check('parse: a name is matched whole and case-insensitively', $chr_fx_parse('big marty')['character'] === 9);
check('parse: a name nobody answers to still comes back as typed',
    $chr_fx_parse('Barrelll') === ['kind' => 'list', 'character' => null, 'who' => 'Barrelll']);
$chr_fx_add = $chr_fx_parse('add Big Marty forward 2 -- before the third dog');
check('parse: add splits a two-word name off the effect', $chr_fx_add['character'] === 9
    && $chr_fx_add['word'] === 'forward', json_encode($chr_fx_add));
check('parse: a trailing number is the amount', $chr_fx_add['amount'] === 2);
check('parse: everything after -- is the note', $chr_fx_add['note'] === 'before the third dog');
check('parse: no trailing number leaves the amount unsaid',
    $chr_fx_parse('add Barrel queasy')['amount'] === null);
check('parse: a multi-word effect keeps its words',
    $chr_fx_parse('add Barrel ongoing basics')['word'] === 'ongoing basics');
$chr_fx_off = $chr_fx_parse('add Barrel "Acne" -2 rizz -- until the dance');
check('parse: a quoted label makes it a one-off', $chr_fx_off['kind'] === 'one_off'
    && $chr_fx_off['label'] === 'Acne' && $chr_fx_off['modifier'] === -2 && $chr_fx_off['target'] === 'rizz');
check('parse: a one-off\'s note rides along too', $chr_fx_off['note'] === 'until the dance');
check('parse: a one-off\'s target is optional',
    $chr_fx_parse('add Barrel "Wet Bun" +1')['target'] === null);
check('parse: a one-off with no number is refused, not guessed at',
    $chr_fx_parse('add Barrel "Acne" rizz')['kind'] === 'usage');
check('parse: a target that isn\'t one word is refused',
    $chr_fx_parse('add Barrel "Acne" -2 Rizz Pool')['kind'] === 'usage');
check('parse: clear takes the word to clear', $chr_fx_parse('clear Barrel queasy')['word'] === 'queasy');
check('parse: clear all', $chr_fx_parse('clear Barrel all')['word'] === 'all');
check('parse: clear reads a quoted label as the label', $chr_fx_parse('clear Barrel "Acne"')['word'] === 'Acne');
check('parse: add with nothing to add asks how', $chr_fx_parse('add Barrel')['kind'] === 'usage');
check('parse: clear with nothing to clear asks how', $chr_fx_parse('clear Barrel')['kind'] === 'usage');

// --- Resolution: id → label → unique prefix, never a guess --------------------

$chr_fx_res = fn(string $w): string => Effect::resolve($w, $chr_fx_template)['effect']['id']
    ?? Effect::resolve($w, $chr_fx_template)['kind'];
check('resolve: an effect id', $chr_fx_res('queasy') === 'queasy');
check('resolve: a label, case-insensitively', $chr_fx_res('QUEASY') === 'queasy');
check('resolve: a unique prefix', $chr_fx_res('quea') === 'queasy');
check('resolve: an ambiguous prefix is ambiguous, not a pick', $chr_fx_res('fo') === 'ambiguous');
check('resolve: a word nothing answers to', $chr_fx_res('nonesuch') === 'none');

// --- Listing: anyone may look -------------------------------------------------

$chr_fx_empty = $chr_fx_run('', $chr_fx_player);
check('list: an empty sheet says so', $chr_fx_empty['text'] === '*Barrel* has no active effects.');
check('list: a listing is ephemeral — a menu is not a state change', $chr_fx_empty['response_type'] === 'ephemeral');

$chr_fx_carried = [
    ['effect' => 'queasy', 'amount' => 2, 'applied_by' => 3, 'applied_at' => 1785844800, 'note' => 'he ate the whole thing'],
    ['label' => 'Acne', 'modifier' => -2, 'target' => 'rizz', 'applied_by' => 3, 'applied_at' => 1785844800],
    ['effect' => 'taunted', 'applied_by' => 3, 'applied_at' => 1785844800],
    ['effect' => 'bogus', 'applied_by' => 3, 'applied_at' => 1785844800],
];
$chr_fx_list = $chr_fx_run('', $chr_fx_player, $chr_fx_carried, [3 => 'Alice']);
check('list: the character leads', str_starts_with($chr_fx_list['text'], '*Barrel* — active effects:'));
check(
    'list: a named instance shows its definition\'s label, contribution, amount and target',
    str_contains($chr_fx_list['text'], '• *Queasy* -1 ×2 — on nausea_check — _he ate the whole thing_'),
    $chr_fx_list['text']
);
check('list: a one-off shows its own label and number', str_contains($chr_fx_list['text'], '• *Acne* -2 — on rizz'));
check('list: an amount of one goes unsaid', !str_contains($chr_fx_list['text'], '*Acne* -2 ×1'));
check('list: an expression modifier prints as expr — what it adds depends on the roll',
    str_contains($chr_fx_list['text'], '• *Taunted* `expr`'));
check(
    'list: an instance the template no longer declares is flagged under the id it was applied with',
    str_contains($chr_fx_list['text'], '• *bogus* (no longer in this system — clear it?)')
);
check('list: each instance says who applied it and when',
    str_contains($chr_fx_list['text'], '_— Alice, <!date^1785844800^{date_short_pretty}|Aug 4, 2026>_'));
check('list: a player is not shown the GM\'s verbs', !str_contains($chr_fx_list['text'], 'effect add'));
check('list: … nor the system\'s catalogue', !str_contains($chr_fx_list['text'], 'hands out'));

$chr_fx_gm_list = $chr_fx_run('', $chr_fx_gm, $chr_fx_carried, [3 => 'Alice']);
check('list: a GM gets the vocabulary they can apply from',
    str_contains($chr_fx_gm_list['text'], 'This system hands out: *Queasy*, *Forward*, *Fortified*, *Taunted*.'));
check('list: … and the two verbs, addressed to this character',
    str_contains($chr_fx_gm_list['text'], '`' . Commands::COMMAND . ' effect add Barrel <effect>`')
        && str_contains($chr_fx_gm_list['text'], '`' . Commands::COMMAND . ' effect clear Barrel <effect>`'));

// --- Mutations are the game master's ------------------------------------------

$chr_fx_refused = $chr_fx_run('add Barrel queasy', $chr_fx_player);
check('add: a player is refused', $chr_fx_refused['response_type'] === 'ephemeral'
    && str_contains($chr_fx_refused['text'], 'game master'));
check('add: … pointed at what they CAN do', str_contains($chr_fx_refused['text'], Commands::COMMAND . ' effect Barrel'));
check('add: … and nothing was written', $GLOBALS['chr_test_post_meta_writes'] === []);
$chr_fx_refused_clear = $chr_fx_run('clear Barrel queasy', $chr_fx_player, [['effect' => 'queasy']]);
check('clear: a player is refused too', str_contains($chr_fx_refused_clear['text'], 'game master'));
check('clear: … and the instance is still there', count(chronicler_sheets_effects_get(7)) === 1);

// --- add, named form ----------------------------------------------------------

$chr_fx_applied = $chr_fx_run('add Barrel queasy 2 -- he ate the whole thing', $chr_fx_gm);
check('add: a mutation posts in_channel — a state change is social', $chr_fx_applied['response_type'] === 'in_channel');
check(
    'add: the confirmation shows the instance as the sheet will',
    $chr_fx_applied['text'] === '✨ *Barrel* takes *Queasy* -1 ×2 — on nausea_check — _he ate the whole thing_',
    $chr_fx_applied['text']
);
$chr_fx_stored = chronicler_sheets_effects_get(7);
check('add: … and the row holds exactly that', count($chr_fx_stored) === 1
    && $chr_fx_stored[0]['effect'] === 'queasy' && $chr_fx_stored[0]['amount'] === 2
    && $chr_fx_stored[0]['note'] === 'he ate the whole thing');
check('add: the instance records who applied it', $chr_fx_stored[0]['applied_by'] === 3);
check('add: a named instance carries no behavior of its own — the definition is the authority',
    $chr_fx_stored[0]['modifier'] === null && $chr_fx_stored[0]['label'] === null);

check('add: a prefix reaches the effect', str_contains(
    $chr_fx_run('add Barrel quea', $chr_fx_gm)['text'],
    '*Queasy*'
));
check('add: no amount means once', $chr_fx_run('add Barrel queasy', $chr_fx_gm)['text']
    === '✨ *Barrel* takes *Queasy* -1 — on nausea_check');
$chr_fx_ambiguous = $chr_fx_run('add Barrel fo', $chr_fx_gm);
check('add: an ambiguous word asks rather than picks', str_contains($chr_fx_ambiguous['text'], 'Which one?')
    && str_contains($chr_fx_ambiguous['text'], '*Forward*') && str_contains($chr_fx_ambiguous['text'], '*Fortified*'));
check('add: … and applies nothing', chronicler_sheets_effects_get(7) === []);
$chr_fx_unknown = $chr_fx_run('add Barrel nonesuch', $chr_fx_gm);
check('add: an unknown effect names what this system does hand out',
    str_contains($chr_fx_unknown['text'], 'This system hands out: *Queasy*'));
check('add: … and offers the one-off form as the way to mean it anyway',
    str_contains($chr_fx_unknown['text'], '"nonesuch" -1'));
check('add: an amount of zero is refused', str_contains(
    $chr_fx_run('add Barrel queasy 0', $chr_fx_gm)['text'],
    'at least once'
));

// Applying again STACKS a second instance; the cap bounds the sum at roll time.
$chr_fx_run('add Barrel queasy', $chr_fx_gm);
chronicler_sheets_effects_add(7, chronicler_sheets_effects_normalize(['effect' => 'queasy']));
check('add: re-applying stacks rather than replacing', count(chronicler_sheets_effects_get(7)) === 2);

// --- add, one-off form --------------------------------------------------------

$chr_fx_one_off = $chr_fx_run('add Barrel "Acne" -2 rizz -- until the dance', $chr_fx_gm);
check(
    'add: a one-off confirms under its own label',
    $chr_fx_one_off['text'] === '✨ *Barrel* takes *Acne* -2 — on rizz — _until the dance_',
    $chr_fx_one_off['text']
);
$chr_fx_stored = chronicler_sheets_effects_get(7)[0];
check('add: the one-off round-trips whole', $chr_fx_stored['effect'] === null
    && $chr_fx_stored['label'] === 'Acne' && $chr_fx_stored['modifier'] === -2
    && $chr_fx_stored['target'] === 'rizz' && $chr_fx_stored['note'] === 'until the dance');
check('add: an untargeted one-off applies to everything',
    $chr_fx_run('add Barrel "Wet Bun" +1', $chr_fx_gm)['text'] === '✨ *Barrel* takes *Wet Bun* +1');
check('add: a one-off worth nothing is refused', str_contains(
    $chr_fx_run('add Barrel "Placebo" 0', $chr_fx_gm)['text'],
    'change none of them'
));
check('add: … and stored nothing', chronicler_sheets_effects_get(7) === []);

// --- clear --------------------------------------------------------------------

$chr_fx_cleared = $chr_fx_run('clear Barrel queasy', $chr_fx_gm, [
    ['effect' => 'queasy'], ['effect' => 'queasy', 'amount' => 2], ['label' => 'Acne', 'modifier' => -2],
]);
check('clear: a cleared effect is announced in_channel', $chr_fx_cleared['response_type'] === 'in_channel');
check('clear: … saying how many went', $chr_fx_cleared['text'] === '🧼 *Queasy* is off *Barrel* — 2 effects gone.');
check('clear: … and leaving the rest alone', count(chronicler_sheets_effects_get(7)) === 1);

check('clear: one instance is cleared without a count',
    $chr_fx_run('clear Barrel queasy', $chr_fx_gm, [['effect' => 'queasy']])['text']
        === '🧼 *Queasy* is off *Barrel*.');
check('clear: a one-off answers to its own label', str_contains(
    $chr_fx_run('clear Barrel acne', $chr_fx_gm, [['label' => 'Acne', 'modifier' => -2]])['text'],
    '*acne* is off *Barrel*'
));
$chr_fx_clear_all = $chr_fx_run('clear Barrel all', $chr_fx_gm, [
    ['effect' => 'queasy'], ['label' => 'Acne', 'modifier' => -2],
]);
check('clear: all takes everything', $chr_fx_clear_all['text'] === '🧼 *Barrel* is clear — 2 effects gone.');
check('clear: … leaving the row empty', chronicler_sheets_effects_get(7) === []);
check('clear: an instance of a dropped definition still clears — nothing is stuck on a sheet', str_contains(
    $chr_fx_run('clear Barrel bogus', $chr_fx_gm, [['effect' => 'bogus']])['text'],
    'is off *Barrel*'
));
check('clear: … and it is gone', chronicler_sheets_effects_get(7) === []);
$chr_fx_nothing = $chr_fx_run('clear Barrel queasy', $chr_fx_gm, [['effect' => 'forward']]);
check('clear: a real effect nobody is carrying says so, quietly', $chr_fx_nothing['response_type'] === 'ephemeral'
    && $chr_fx_nothing['text'] === '*Barrel* isn\'t carrying *Queasy*.');
check('clear: … and touched nothing', count(chronicler_sheets_effects_get(7)) === 1);
$chr_fx_nonesuch = $chr_fx_run('clear Barrel nonesuch', $chr_fx_gm, [['effect' => 'forward']]);
check('clear: a word nothing answers to lists what this system has',
    $chr_fx_nonesuch['response_type'] === 'ephemeral'
        && str_contains($chr_fx_nonesuch['text'], 'This system hands out:'));
check('clear: nothing at all to clear says that instead',
    $chr_fx_run('clear Barrel all', $chr_fx_gm)['text'] === '*Barrel* has no active effects to clear.');

// --- A write that doesn't land is never announced -----------------------------

$GLOBALS['chr_test_post_meta'] = [];
$GLOBALS['chr_test_post_meta_fail'] = true;
$chr_fx_unsaved = Effect::respond('add Barrel queasy', $chr_fx_template, $chr_fx_who, $chr_fx_gm, [], $chr_fx_roster);
check('add: an unlanded write refuses to claim it applied', $chr_fx_unsaved['response_type'] === 'ephemeral'
    && str_contains($chr_fx_unsaved['text'], 'didn\'t save'));
$GLOBALS['chr_test_post_meta_fail'] = false;

// --- Usage and unknown names --------------------------------------------------

check('usage: the whole grammar is one message', str_contains(Effect::usage()['text'], 'effect add <character>')
    && str_contains(Effect::usage()['text'], 'effect clear <character>'));
$chr_fx_stranger = Effect::stranger('Barrelll', $chr_fx_roster);
check('stranger: the typo is quoted back', str_contains($chr_fx_stranger['text'], 'No character goes by `Barrelll`'));
check('stranger: … with the roster', str_contains($chr_fx_stranger['text'], '*Barrel*, *Big Marty*'));
check('stranger: no name at all points at /game link', str_contains(
    Effect::stranger('', $chr_fx_roster)['text'],
    Commands::COMMAND . ' link'
));

// --- Wiring -------------------------------------------------------------------

check('help advertises effect', str_contains(
    Commands::dispatch(['text' => 'help'])['text'],
    Commands::COMMAND . ' effect'
));
// The dispatch path stops at "who is this about" before touching WordPress
// identity, so an unlinked caller with an empty roster is answerable here.
$chr_fx_unlinked = Commands::dispatch(['text' => 'effect', 'user_id' => 'UNOBODY']);
check('an unlinked caller is answered ephemerally', $chr_fx_unlinked['response_type'] === 'ephemeral');
check('… and pointed at /game link', str_contains($chr_fx_unlinked['text'], Commands::COMMAND . ' link'));
$chr_fx_nobody = Commands::dispatch(['text' => 'effect add nobody queasy', 'user_id' => 'UNOBODY']);
check('a name nobody answers to is refused before anything is applied',
    str_contains($chr_fx_nobody['text'], 'No character goes by `nobody`'));

// Leave the shared meta globals clean for the suites downstream.
$GLOBALS['chr_test_post_meta'] = [];
$GLOBALS['chr_test_post_meta_writes'] = [];
