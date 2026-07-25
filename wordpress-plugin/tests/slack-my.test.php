<?php
// `/game my` — the resolver and the Block Kit builders (4.26.0).
//
// Both halves are pure by design: My::resolve() takes the sheet array
// chronicler_sheets_sheet_for_viewer() returns and answers with a resolution,
// and BlockKit turns one property (or one section) into blocks. So the whole
// precedence contract and every per-type rendering arm is covered here without
// WordPress; only My::handle()'s viewer resolution is left to wp-env.
//
// Reuses the harness stubs render.test.php and template-store.test.php already
// define. wp_strip_all_tags is the one addition (longtext flattening).

use Chronicler\Slack\Bot\BlockKit;
use Chronicler\Slack\Bot\Commands;
use Chronicler\Slack\Bot\My;

// The Slack-id → character lookup lives in post-types.php (WP queries, not
// loaded here). Stubbed over a global so a suite can hand back a character;
// left empty, every caller reads as unlinked — which is the branch this file
// asserts end to end.
if (!function_exists('chronicler_sheets_character_for_slack_id')) {
    function chronicler_sheets_character_for_slack_id(string $slack_id) {
        return $GLOBALS['chr_test_slack_characters'][$slack_id] ?? null;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false) {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        return $remove_breaks ? trim(preg_replace('/[\r\n\t ]+/', ' ', $text)) : trim($text);
    }
}

check('My exists', class_exists(My::class));
check('BlockKit exists', class_exists(BlockKit::class));

// --- The fixture sheet -------------------------------------------------------
// Shaped exactly like sheet_for_viewer's return: layout entries carrying ids,
// properties carrying value/display/detail. The layout deliberately contains
// the two collisions the spec calls out: a section whose heading derives
// `moves_gear` while a `gear` PROPERTY exists, and a second section that
// DECLARES id `gear`, so property-id-beats-section-id is exercised head-on.
$chr_my_template = chronicler_sheets_parse_template(json_encode([
    'system' => 'Monster of the Week',
    'version' => 1,
    'properties' => [
        ['id' => 'charm', 'label' => 'Charm', 'type' => 'number', 'min' => -1, 'max' => 3, 'detail' => 'manipulate someone'],
        ['id' => 'cool', 'label' => 'Cool', 'type' => 'number', 'min' => -1, 'max' => 3],
        ['id' => 'harm', 'label' => 'Harm', 'type' => 'track', 'length' => 7],
        ['id' => 'charges', 'label' => 'Charges', 'type' => 'counter', 'max' => 10],
        ['id' => 'unstable', 'label' => 'Unstable', 'type' => 'toggle'],
        ['id' => 'hunter_type', 'label' => 'Hunter Type', 'type' => 'select', 'options' => [
            ['id' => 'chosen', 'label' => 'The Chosen'],
            ['id' => 'expert', 'label' => 'The Expert'],
        ]],
        ['id' => 'moves', 'label' => 'Moves', 'type' => 'checklist', 'options' => [
            ['id' => 'the_big_entrance', 'label' => 'The Big Entrance'],
            ['id' => 'nine_lives', 'label' => 'Nine Lives'],
            ['id' => 'crime_pays', 'label' => 'Crime Pays'],
        ]],
        ['id' => 'gear', 'label' => 'Gear', 'type' => 'list', 'entry_label' => 'Item', 'fields' => [
            ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['id' => 'tags', 'label' => 'Tags', 'type' => 'text'],
            ['id' => 'is_weapon', 'label' => 'Weapon?', 'type' => 'toggle'],
            ['id' => 'harm_rating', 'label' => 'Harm', 'type' => 'number', 'min' => 0, 'max' => 5, 'when' => 'is_weapon'],
        ]],
        ['id' => 'backpack', 'label' => 'Backpack', 'type' => 'text'],
        ['id' => 'player_notes', 'label' => 'Player Notes', 'type' => 'longtext', 'always_show' => true],
        ['id' => 'bio', 'label' => 'Bio', 'type' => 'longtext'],
        ['id' => 'grudges', 'label' => 'Grudges', 'type' => 'opinions', 'length' => 5],
    ],
    'layout' => [
        ['section' => 'Hunter', 'masthead' => true, 'properties' => ['hunter_type']],
        ['id' => 'stats', 'section' => 'Ratings', 'properties' => ['charm', 'cool']],
        ['section' => 'Status', 'properties' => ['harm', 'charges', 'unstable']],
        ['section' => 'Moves & Gear', 'properties' => ['moves', 'gear']],
        ['id' => 'gear', 'section' => 'Kit', 'properties' => ['backpack']],
    ],
]));
check('my fixture template parses', is_array($chr_my_template), is_wp_error($chr_my_template) ? $chr_my_template->get_error_message() : '');

// Build the viewer-shaped sheet the way rest.php's serializer does.
$chr_my_sheet = function (array $values) use ($chr_my_template): array {
    $props = [];
    foreach ($chr_my_template['properties'] as $id => $property) {
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
        'canEdit' => true,
        'system' => $chr_my_template['system'],
        'layout' => $chr_my_template['layout'],
        'properties' => $props,
    ];
};
$chr_my_values = [
    'charm' => 2,
    'cool' => -1,
    'harm' => 3,
    'charges' => 4,
    'unstable' => true,
    'hunter_type' => 'chosen',
    'moves' => ['the_big_entrance', 'nine_lives'],
    'gear' => [
        ['name' => 'Machete', 'tags' => 'messy', 'is_weapon' => true, 'harm_rating' => 2],
        ['name' => 'Lucky Zippo', 'tags' => '', 'is_weapon' => false, 'harm_rating' => 4],
    ],
    'backpack' => 'a canvas satchel',
    // `bio` is deliberately left unset: it is the unfilled, non-always_show
    // property that group renders must drop.
];
$sheet = $chr_my_sheet($chr_my_values);

// --- My::resolve(): the precedence table is the contract ---------------------

$kind = function ($r) {
    return is_array($r) ? ($r['kind'] ?? '?') : '?';
};
$pid = function ($r) {
    return is_array($r) && isset($r['property']['id']) ? $r['property']['id'] : null;
};
$sec = function ($r) {
    return is_array($r) ? ($r['section'] ?? null) : null;
};

// 1. Property id, exact — and it OUTRANKS a section declaring the same id.
check('property id resolves to that property', $pid(My::resolve('cool', $sheet)) === 'cool');
check('a property id beats a section declaring the same id', $kind(My::resolve('gear', $sheet)) === 'property');
check('… and it is the list property, not the Kit section', $pid(My::resolve('gear', $sheet)) === 'gear');

// 2. Section id, exact — including the one the template DECLARES.
check('a declared section id resolves the section', $kind(My::resolve('stats', $sheet)) === 'section');
check('… naming the heading the author wrote', $sec(My::resolve('stats', $sheet)) === 'Ratings');
check('a derived section id resolves too', $sec(My::resolve('moves_gear', $sheet)) === 'Moves & Gear');
check('a section id spelled with spaces still resolves', $sec(My::resolve('moves gear', $sheet)) === 'Moves & Gear');

// 3/4. Labels and headings, exact, case- and whitespace-insensitively.
check('a property label resolves', $pid(My::resolve('Hunter Type', $sheet)) === 'hunter_type');
check('a property label is case-insensitive', $pid(My::resolve('hUnTeR tYpE', $sheet)) === 'hunter_type');
check('a section heading resolves', $sec(My::resolve('Moves & Gear', $sheet)) === 'Moves & Gear');
check('surrounding and inner whitespace is collapsed', $pid(My::resolve('  Hunter   Type ', $sheet)) === 'hunter_type');

// A section resolution carries the properties it groups, in layout order.
$stats = My::resolve('stats', $sheet);
check('a section resolution carries its properties', array_map(static function ($p) {
    return $p['id'];
}, $stats['properties'] ?? []) === ['charm', 'cool']);

// 5. Whole-sheet words.
foreach (['all', 'sheet', 'everything', 'ALL'] as $word) {
    check("\"$word\" resolves to the whole sheet", $kind(My::resolve($word, $sheet)) === 'all');
}

// 6. Unique prefix, and NEVER a guess when more than one candidate matches.
check('a unique prefix resolves', $pid(My::resolve('back', $sheet)) === 'backpack');
$amb = My::resolve('mov', $sheet);
check('an ambiguous prefix is ambiguous, not a guess', $kind($amb) === 'ambiguous');
check('ambiguity names the property candidate', in_array('Moves', $amb['candidates'] ?? [], true));
check('ambiguity names the section candidate', in_array('Moves & Gear', $amb['candidates'] ?? [], true));
check('ambiguity offers exactly the two candidates', count($amb['candidates'] ?? []) === 2);

// 7. No match.
check('an unknown word resolves to none', $kind(My::resolve('nonsense', $sheet)) === 'none');
check('an empty query is the overview', $kind(My::resolve('', $sheet)) === 'overview');

// Properties the layout never places still resolve — they land in the
// synthetic trailing "Other", exactly as the HTML sheet groups them.
check('an unplaced property still resolves', $pid(My::resolve('bio', $sheet)) === 'bio');
check('the synthetic Other section resolves by id', $sec(My::resolve('other', $sheet)) === 'Other');

// A template that authors its OWN "other" wins the collision (the synthetic
// one is an artifact of grouping; the author's is intentional).
$chr_my_other = $chr_my_sheet($chr_my_values);
$chr_my_other['layout'][] = ['id' => 'other', 'section' => 'Odds & Ends', 'properties' => ['bio'], 'masthead' => false];
check('an authored "other" beats the synthetic one', $sec(My::resolve('other', $chr_my_other)) === 'Odds & Ends');

// --- BlockKit: one arm per property type ------------------------------------

$byId = [];
foreach ($sheet['properties'] as $p) {
    $byId[$p['id']] = $p;
}
// JSON escaping would turn "3/7" into "3\/7" and mangle every URL, so the
// haystack these assertions search is encoded the way Slack receives it.
$flat = function (array $blocks): string {
    return json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$number = BlockKit::property($byId['charm']);
check('a number renders one or more blocks', $number !== [] && ($number[0]['type'] ?? '') === 'section');
check('a signed number renders +2', str_contains($flat($number), '+2'));
check('a number names its label', str_contains($flat($number), 'Charm'));
check('a number carries its detail as context', str_contains($flat($number), 'manipulate someone'));
check('a negative number keeps its sign', str_contains($flat(BlockKit::property($byId['cool'])), '-1'));

$track = BlockKit::property($byId['harm']);
check('a track renders filled and empty circles', str_contains($flat($track), '●●●○○○○'));
check('a track renders its fraction', str_contains($flat($track), '3/7'));

check('a counter renders its fraction', str_contains($flat(BlockKit::property($byId['charges'])), '4/10'));
check('a toggle renders a check when on', str_contains($flat(BlockKit::property($byId['unstable'])), '✓'));
$off = $byId['unstable'];
$off['value'] = false;
$off['display'] = 'off';
check('a toggle renders a cross when off', str_contains($flat(BlockKit::property($off)), '✗'));

$select = BlockKit::property($byId['hunter_type']);
check('a select renders the option label', str_contains($flat($select), 'The Chosen'));
check('a select never renders the option id', !str_contains($flat($select), 'chosen"') && !str_contains($flat($select), '*chosen*'));

$checklist = BlockKit::property($byId['moves']);
check('a checklist lists what is checked', str_contains($flat($checklist), 'The Big Entrance'));
check('a checklist omits what is not', !str_contains($flat($checklist), 'Crime Pays'));
check('a checklist reports how many are checked', str_contains($flat($checklist), '2/3 checked'));

$list = BlockKit::property($byId['gear']);
check('a list bolds each entry name', str_contains($flat($list), '*Machete*'));
check('a list renders remaining fields as label: value', str_contains($flat($list), 'Tags: messy'));
check('a list honors a when-gated field', str_contains($flat($list), 'Harm: 2'));
check('a list omits a when-gated field that is off', !str_contains($flat($list), 'Harm: 4'));
$empty = $byId['gear'];
$empty['value'] = [];
$empty['display'] = '0 entries';
check('an empty list says None yet', str_contains($flat(BlockKit::property($empty)), 'None yet.'));

$bio = $byId['bio'];
$bio['value'] = str_repeat('Alec grew up in the swamp. ', 40);
$long = BlockKit::property($bio, 'https://blog.test/character/alec');
check('a longtext over 500 chars truncates', str_contains($flat($long), '…'));
check('… to no more than the cap plus the ellipsis', mb_strlen(json_decode($flat($long), true)[0]['text']['text']) < 600);
check('a truncated longtext links the full sheet', str_contains($flat($long), 'https://blog.test/character/alec'));
$html = $byId['bio'];
$html['value'] = '<p>Plain <b>enough</b></p>';
check('a longtext is flattened to plain text', !str_contains($flat(BlockKit::property($html)), '<b>'));

check('opinions are skipped entirely', BlockKit::property($byId['grudges']) === []);

$blank = $byId['backpack'];
$blank['value'] = '';
$blank['display'] = '';
check('an unfilled property still renders, saying it is empty', str_contains(
    strtolower($flat(BlockKit::property($blank))),
    'nothing recorded'
));

// Values are user content: Slack's three mrkdwn control characters escape.
$evil = $byId['backpack'];
$evil['value'] = 'a <script> & a >thing<';
$evil['display'] = $evil['value'];
check('property values are mrkdwn-escaped', !str_contains($flat(BlockKit::property($evil)), '<script>'));

// --- BlockKit::section() -----------------------------------------------------

$section = BlockKit::section('Ratings', [$byId['charm'], $byId['cool']]);
check('a section leads with a header block', ($section[0]['type'] ?? '') === 'header');
check('the header carries the heading', ($section[0]['text']['text'] ?? '') === 'Ratings');
check('a section renders each property', str_contains($flat($section), 'Charm') && str_contains($flat($section), 'Cool'));

// --- BlockKit::cap(): Slack's 50-block ceiling ------------------------------

$many = array_fill(0, 51, BlockKit::text('x'));
$capped = BlockKit::cap($many, 'https://blog.test/character/alec');
check('51 blocks are capped to 50', count($capped) === 50);
check('the last block is a context line', end($capped)['type'] === 'context');
check('the cap says what was dropped', str_contains($flat([end($capped)]), '2'));
check('the cap links the full sheet', str_contains($flat([end($capped)]), 'https://blog.test/character/alec'));
check('50 blocks pass through untouched', BlockKit::cap(array_fill(0, 50, BlockKit::text('x'))) === array_fill(0, 50, BlockKit::text('x')));

// --- My::reply(): pure rendering of each resolution --------------------------

$url = 'https://blog.test/character/alec';
$all = My::reply(My::resolve('all', $sheet), $sheet, $url);
check('my is ephemeral — it can carry owner_only fields', $all['response_type'] === 'ephemeral');
check('my always carries a text fallback', is_string($all['text'] ?? null) && $all['text'] !== '');
check('my carries blocks', is_array($all['blocks'] ?? null) && $all['blocks'] !== []);
check('my never exceeds Slack\'s block ceiling', count($all['blocks']) <= BlockKit::MAX_BLOCKS);
// Headings ride `plain_text` blocks, which Slack does NOT read as mrkdwn —
// escaping them would print a literal "&amp;" at the table.
check('all renders every section heading', str_contains($flat($all['blocks']), 'Ratings')
    && str_contains($flat($all['blocks']), 'Moves & Gear'));
check('all names the character', str_contains($flat($all['blocks']), 'Alec Baker'));

// Group renders drop unfilled properties — a chat message of blank rows is
// noise — but always_show keeps its prompt.
check('a group drops an unfilled property', !str_contains($flat($all['blocks']), 'Bio'));
check('a group keeps an always_show property', str_contains($flat($all['blocks']), 'Player Notes'));

// A single-property query renders even when unfilled.
$one = My::reply(My::resolve('bio', $sheet), $chr_my_sheet([]), $url);
check('a single unfilled property still renders', str_contains($flat($one['blocks']), 'Bio'));

$overview = My::reply(My::resolve('', $sheet), $sheet, $url);
check('the overview names the character', str_contains($flat($overview['blocks']), 'Alec Baker'));
check('the overview links the public page', str_contains($flat($overview['blocks']), $url));
check('the overview names the system', str_contains($flat($overview['blocks']), 'Monster of the Week'));
check('the overview shows the masthead section', str_contains($flat($overview['blocks']), 'The Chosen'));
check('the overview says what else you can ask for', str_contains($flat($overview['blocks']), 'Ratings'));

$none = My::reply(My::resolve('nonsense', $sheet), $sheet, $url);
check('an unknown query is ephemeral', $none['response_type'] === 'ephemeral');
check('an unknown query lists the sections', str_contains($none['text'], 'Ratings'));
check('an unknown query lists property labels', str_contains($none['text'], 'Charm'));
check('an unknown query offers the whole sheet', str_contains($none['text'], 'all'));

$ambiguous = My::reply(My::resolve('mov', $sheet), $sheet, $url);
check('an ambiguous query is ephemeral', $ambiguous['response_type'] === 'ephemeral');
check('an ambiguous query names both candidates', str_contains($ambiguous['text'], 'Moves')
    && str_contains($ambiguous['text'], 'Moves &amp; Gear'));

// --- Wiring ------------------------------------------------------------------

check('help advertises my', str_contains(Commands::dispatch(['text' => 'help'])['text'], Commands::COMMAND . ' my'));
check('help no longer advertises the retired stats subcommand', !in_array('stats', array_map(
    static fn($s) => $s[0],
    Commands::SUBCOMMANDS
), true));
check('help no longer advertises the retired sheet subcommand', !in_array('sheet', array_map(
    static fn($s) => $s[0],
    Commands::SUBCOMMANDS
), true));

// An unlinked caller gets the /game link invitation, never a sheet. The
// harness has no linked characters, so dispatch takes exactly that path.
$unlinked = Commands::dispatch(['text' => 'my', 'user_id' => 'UNOBODY']);
check('an unlinked caller is answered ephemerally', $unlinked['response_type'] === 'ephemeral');
check('an unlinked caller is pointed at /game link', str_contains($unlinked['text'], Commands::COMMAND . ' link'));
check('an unlinked caller gets no blocks', !isset($unlinked['blocks']));
