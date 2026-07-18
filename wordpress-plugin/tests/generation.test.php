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
// Pattern definition: plain inserter pattern for posts. blockTypes must be
// ABSENT — a core/post-content entry there would surface the pattern in the
// new-post starter modal, which #102 explicitly avoids.
// ---------------------------------------------------------------------
$pattern = Generation::patternDefinition();
check('pattern targets exactly the post post type', ($pattern['postTypes'] ?? null) === ['post']);
check('pattern registers NO blockTypes (starter modal stays off)', !array_key_exists('blockTypes', $pattern));
check('pattern carries a title', is_string($pattern['title'] ?? null) && $pattern['title'] !== '');
check('pattern content is patternContent()', ($pattern['content'] ?? null) === Generation::patternContent());
check('pattern content contains the placeholder block', str_contains($pattern['content'], '<!-- wp:chronicler/session-placeholder /-->'));
check('pattern uses a stock category', ($pattern['categories'] ?? null) === ['text']);

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
