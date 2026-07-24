<?php
// Pure checks for src/Editor/Generation.php (#102): the deep-link seeding
// contract's WordPress-free pieces (query parsing, seeded/pattern content,
// seed titles) and the pattern definition. Included by run.php. The
// nonce/capability/session-existence gates and the block/pattern/script
// registrations are WordPress behavior, verified at runtime in wp-env.

use Chronicler\Editor\Generation;

// ---------------------------------------------------------------------
// Contract constants
// ---------------------------------------------------------------------
check('block name is chronicler/session-placeholder', Generation::BLOCK === 'chronicler/session-placeholder');
check('pattern name is chronicler/session-log', Generation::PATTERN === 'chronicler/session-log');
check('pattern category is chronicler', Generation::CATEGORY === 'chronicler');
check('default template is session-log', Generation::DEFAULT_TEMPLATE === 'session-log');
check('seeding nonce action is chronicler_draft_session', Generation::NONCE_ACTION === 'chronicler_draft_session');

// ---------------------------------------------------------------------
// requestedSessionId: the ?chronicler_session= parser. Stricter than
// absint — anything mangled means "no seed", never a partial parse.
// ---------------------------------------------------------------------
check('no param → null', Generation::requestedSessionId([]) === null);
check('numeric string parses', Generation::requestedSessionId(['chronicler_session' => '5']) === 5);
check('int passes through', Generation::requestedSessionId(['chronicler_session' => 12]) === 12);
check('zero → null', Generation::requestedSessionId(['chronicler_session' => '0']) === null);
check('negative int → null', Generation::requestedSessionId(['chronicler_session' => -3]) === null);
check('trailing garbage → null (absint would say 5)', Generation::requestedSessionId(['chronicler_session' => '5abc']) === null);
check('negative string → null', Generation::requestedSessionId(['chronicler_session' => '-5']) === null);
check('array → null', Generation::requestedSessionId(['chronicler_session' => ['5']]) === null);
check('float string → null', Generation::requestedSessionId(['chronicler_session' => '5.5']) === null);

// ---------------------------------------------------------------------
// Placeholder grammar + seeded/pattern content
// ---------------------------------------------------------------------
check(
    'unseeded placeholder is a bare void block',
    Generation::placeholderBlock() === '<!-- wp:chronicler/session-placeholder /-->'
);
check(
    'seeded placeholder carries the sessionId attribute',
    Generation::placeholderBlock(41) === '<!-- wp:chronicler/session-placeholder {"sessionId":41} /-->'
);

$seeded = Generation::seededContent(41);
check('seeded content contains the seeded placeholder', str_contains($seeded, '<!-- wp:chronicler/session-placeholder {"sessionId":41} /-->'));
check('seeded content contains the More cut', str_contains($seeded, '<!-- wp:more -->'));
check('seeded content contains the summary paragraph slot', str_contains($seeded, '<!-- wp:paragraph'));
check('seeded content ends with the placeholder (transcript goes below the fold)', str_ends_with($seeded, '/-->'));

// The placeholder's serialized attribute JSON must actually be JSON — the
// block parser reads everything between the block name and the comment close.
$ok = preg_match('/<!-- wp:chronicler\/session-placeholder ({.*}) \/-->/', $seeded, $m) === 1;
check('seeded placeholder attributes parse as JSON', $ok && json_decode($m[1], true) === ['sessionId' => 41]);

check('pattern content is the skeleton with an unseeded placeholder',
    Generation::patternContent() === str_replace(' {"sessionId":41}', '', $seeded));

// ---------------------------------------------------------------------
// Template registry (#12): every entry is a plain inserter pattern for
// posts. blockTypes must be ABSENT — a core/post-content entry there would
// surface the pattern in the new-post starter modal, which #102 explicitly
// avoids.
// ---------------------------------------------------------------------
$templates = Generation::templates();
check('templates are adventure-log then session-log (picker order, first = default pick)',
    array_keys($templates) === ['adventure-log', 'session-log']);
check('default template slug exists in the registry', array_key_exists(Generation::DEFAULT_TEMPLATE, $templates));

foreach ($templates as $slug => $template) {
    $pattern = $template['pattern'] ?? null;
    check("[$slug] carries a picker label", is_string($template['label'] ?? null) && $template['label'] !== '');
    check("[$slug] targets exactly the post post type", ($pattern['postTypes'] ?? null) === ['post']);
    check("[$slug] registers NO blockTypes (starter modal stays off)", !array_key_exists('blockTypes', $pattern));
    check("[$slug] carries a title", is_string($pattern['title'] ?? null) && $pattern['title'] !== '');
    check("[$slug] files under the chronicler category", in_array(Generation::CATEGORY, $pattern['categories'] ?? [], true));
    check(
        "[$slug] content contains exactly one unseeded placeholder (the seed swap point)",
        substr_count($pattern['content'] ?? '', Generation::placeholderBlock()) === 1
    );
}

check('session-log pattern content is patternContent()',
    ($templates['session-log']['pattern']['content'] ?? null) === Generation::patternContent());
check('adventure-log pattern content is adventureLogContent()',
    ($templates['adventure-log']['pattern']['content'] ?? null) === Generation::adventureLogContent());

$adventure = Generation::adventureLogContent();
// Both templates share the above-the-fold opening: standfirst, then More.
$standfirst = explode("\n\n" . Generation::placeholderBlock(), Generation::patternContent())[0];
check('adventure log opens with the same standfirst + More cut as session-log',
    str_contains($standfirst, '<!-- wp:more -->') && str_starts_with($adventure, $standfirst));
check('adventure log carries the recap/session/loot/next-time sections',
    str_contains($adventure, '>Previously<')
    && str_contains($adventure, '>The Session<')
    && str_contains($adventure, '>Loot &amp; Rewards<')
    && str_contains($adventure, '>Next Time<'));
check('adventure log headings use the 6.2+ serialized class (block validation)',
    substr_count($adventure, '<h2 class="wp-block-heading">') === 4);

// ---------------------------------------------------------------------
// requestedTemplateSlug: the ?chronicler_template= parser. Anything but a
// known slug means the default — layout degrades, seeding never does.
// ---------------------------------------------------------------------
check('no template param → default', Generation::requestedTemplateSlug([]) === Generation::DEFAULT_TEMPLATE);
check('known slug passes through', Generation::requestedTemplateSlug(['chronicler_template' => 'adventure-log']) === 'adventure-log');
check('default slug passes through', Generation::requestedTemplateSlug(['chronicler_template' => 'session-log']) === 'session-log');
check('unknown slug → default', Generation::requestedTemplateSlug(['chronicler_template' => 'grocery-list']) === Generation::DEFAULT_TEMPLATE);
check('array → default', Generation::requestedTemplateSlug(['chronicler_template' => ['adventure-log']]) === Generation::DEFAULT_TEMPLATE);
check('empty string → default', Generation::requestedTemplateSlug(['chronicler_template' => '']) === Generation::DEFAULT_TEMPLATE);

// ---------------------------------------------------------------------
// Template-aware seeding: the chosen template's content with the session id
// swapped into its placeholder.
// ---------------------------------------------------------------------
$seededAdventure = Generation::seededContent(41, 'adventure-log');
check('adventure-log seeding swaps the placeholder in place',
    $seededAdventure === str_replace(Generation::placeholderBlock(), Generation::placeholderBlock(41), $adventure));
check('adventure-log seeding leaves no unseeded placeholder',
    !str_contains($seededAdventure, Generation::placeholderBlock()));
check('seededContent default template matches the one-arg call',
    Generation::seededContent(41, Generation::DEFAULT_TEMPLATE) === Generation::seededContent(41));

// ---------------------------------------------------------------------
// titleFor: "#channel — <date>" with graceful fallbacks
// ---------------------------------------------------------------------
$session = [
    'id' => 7,
    'channel' => ['id' => 'C0700', 'name' => 'dargon-kween'],
    'start' => '2026-07-10T19:00',
    'end' => '2026-07-11T02:00',
];
check('title is channel + formatted start date', Generation::titleFor($session) === '#dargon-kween — July 10, 2026');

$noName = $session;
$noName['channel']['name'] = '';
check('title falls back to the channel id', Generation::titleFor($noName) === '#C0700 — July 10, 2026');

$badStart = $session;
$badStart['start'] = 'not-a-date';
check('unparseable start stays raw', Generation::titleFor($badStart) === '#dargon-kween — not-a-date');

$noStart = $session;
$noStart['start'] = '';
check('no start → channel only', Generation::titleFor($noStart) === '#dargon-kween');

check('empty session → generic label', Generation::titleFor([]) === 'Chronicler session');
