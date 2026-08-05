<?php
// Effect instances on a character (the 2026-08-04 effects design): the
// chr_active_effects meta row is ONE JSON list, appended to when the GM
// applies an effect and filtered when they clear one. Reuses the shared
// get_post_meta / update_post_meta / wp_slash / wp_unslash stubs
// template-store.test.php already defined — same meta round trip, same
// slash and read-back reasons.

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v) { return json_encode($v); }
}

require __DIR__ . '/../sheets/effects.php';

/** A clean meta registry per scenario; $stored seeds the raw meta row. */
function chr_effects_test_reset(?string $stored = null): void {
    $GLOBALS['chr_test_post_meta'] = $stored === null ? [] : [7 => [CHRONICLER_EFFECTS_META => $stored]];
    $GLOBALS['chr_test_post_meta_writes'] = [];
}

// --- named instances: the vocabulary id IS the instance ------------------------
chr_effects_test_reset();
check('add: a named effect stores', chronicler_sheets_effects_add(7, ['effect' => 'queasy', 'applied_by' => 3]) === true);
$chr_fx = chronicler_sheets_effects_get(7);
check('get: one instance comes back', count($chr_fx) === 1);
$chr_one = $chr_fx[0] ?? [];
check(
    'get: a named instance defaults to amount 1 and carries no behavior of its own',
    ($chr_one['effect'] ?? null) === 'queasy'
        && ($chr_one['amount'] ?? null) === 1
        && array_key_exists('label', $chr_one) && $chr_one['label'] === null
        && $chr_one['target'] === null && $chr_one['modifier'] === null
        && $chr_one['applied_by'] === 3 && $chr_one['note'] === ''
);
check('get: the store stamps applied_at', ($chr_one['applied_at'] ?? 0) > 0);
check(
    'add: the write is slashed against update_post_meta\'s unslash',
    count($GLOBALS['chr_test_post_meta_writes']) === 1
        && wp_unslash($GLOBALS['chr_test_post_meta_writes'][0][2]) === wp_json_encode([$chr_one])
);

chr_effects_test_reset();
chronicler_sheets_effects_add(7, ['effect' => 'queasy', 'label' => 'Ignore Me', 'modifier' => 9, 'target' => 'rizz']);
$chr_one = chronicler_sheets_effects_get(7)[0];
check(
    'add: a named instance drops label/modifier/target — the definition is the authority',
    $chr_one['label'] === null && $chr_one['modifier'] === null && $chr_one['target'] === null
);

// --- one-offs: everything the template never heard of --------------------------
chr_effects_test_reset();
check(
    'add: a one-off stores',
    chronicler_sheets_effects_add(7, [
        'label' => 'Acne',
        'modifier' => -2,
        'target' => 'rizz',
        'note' => 'until the dance',
        'applied_by' => 3,
    ]) === true
);
$chr_one = chronicler_sheets_effects_get(7)[0];
check(
    'get: the one-off round-trips whole',
    $chr_one['effect'] === null && $chr_one['label'] === 'Acne' && $chr_one['modifier'] === -2
        && $chr_one['target'] === 'rizz' && $chr_one['amount'] === 1 && $chr_one['note'] === 'until the dance'
);

chr_effects_test_reset();
chronicler_sheets_effects_add(7, ['label' => '  Stage Fright  ', 'modifier' => 1]);
$chr_one = chronicler_sheets_effects_get(7)[0];
check(
    'add: an untargeted one-off keeps its label trimmed and applies to everything',
    $chr_one['label'] === 'Stage Fright' && $chr_one['target'] === null
);

// A note is user prose: it must survive addslashes/stripslashes byte-for-byte,
// which is only non-identity on quotes and backslashes (the template-store
// lesson — without those bytes the check would pass on an unslashed write).
chr_effects_test_reset();
$chr_note = 'she said "no" \\ and meant it';
chronicler_sheets_effects_add(7, ['label' => 'Dressing Down', 'modifier' => -1, 'note' => $chr_note]);
check('add: the note survives the slash round trip byte-for-byte', chronicler_sheets_effects_get(7)[0]['note'] === $chr_note);

// --- stacking and clearing -----------------------------------------------------
chr_effects_test_reset();
chronicler_sheets_effects_add(7, ['effect' => 'queasy']);
chronicler_sheets_effects_add(7, ['effect' => 'queasy', 'amount' => 2]);
chronicler_sheets_effects_add(7, ['effect' => 'ongoing_basics']);
chronicler_sheets_effects_add(7, ['label' => 'Acne', 'modifier' => -2]);
check('add: re-applying an effect stacks a second instance', count(chronicler_sheets_effects_get(7)) === 4);
check(
    'get: instances come back oldest first',
    array_column(chronicler_sheets_effects_get(7), 'amount') === [1, 2, 1, 1]
);
check('clear: an effect id takes every instance of it', chronicler_sheets_effects_clear(7, 'queasy') === 2);
check('clear: the rest of the list is untouched', count(chronicler_sheets_effects_get(7)) === 2);
check('clear: an id spoken with spaces still finds it', chronicler_sheets_effects_clear(7, 'Ongoing Basics') === 1);
check('clear: a one-off answers to its label, spoken any way', chronicler_sheets_effects_clear(7, ' ACNE ') === 1);
check('clear: the row is empty afterwards', chronicler_sheets_effects_get(7) === []);

chr_effects_test_reset();
chronicler_sheets_effects_add(7, ['effect' => 'queasy']);
chronicler_sheets_effects_add(7, ['label' => 'Acne', 'modifier' => -2]);
$GLOBALS['chr_test_post_meta_writes'] = [];
check('clear: a word nothing answers to removes nothing', chronicler_sheets_effects_clear(7, 'forward') === 0);
check('clear: a no-op clear writes nothing', $GLOBALS['chr_test_post_meta_writes'] === []);
check('clear: a label does not reach a named instance, nor an id a one-off', chronicler_sheets_effects_clear(7, 'acne') === 1);
check('clear: null takes everything left', chronicler_sheets_effects_clear(7, null) === 1);
check('clear: nothing to clear returns 0', chronicler_sheets_effects_clear(7, null) === 0);

chr_effects_test_reset();
chronicler_sheets_effects_add(7, ['effect' => 'queasy']);
chronicler_sheets_effects_add(8, ['effect' => 'queasy']);
chronicler_sheets_effects_clear(7, null);
check('clear: another character\'s effects are their own', count(chronicler_sheets_effects_get(8)) === 1);

// --- a mangled row rolls plainly rather than fatally ---------------------------
chr_effects_test_reset('this is not json {');
check('get: a garbage meta row reads as no effects', chronicler_sheets_effects_get(7) === []);

chr_effects_test_reset(wp_json_encode(['effect' => 'queasy']));
check('get: a lone instance stored where a list belongs reads as no effects', chronicler_sheets_effects_get(7) === []);

chr_effects_test_reset(wp_json_encode([['effect' => 'queasy'], 'nope', ['label' => 'Acne', 'modifier' => 0]]));
$chr_fx = chronicler_sheets_effects_get(7);
check(
    'get: unreadable entries drop and their readable neighbours survive',
    count($chr_fx) === 1 && $chr_fx[0]['effect'] === 'queasy'
);

// --- refusals: nothing half-shaped reaches the row -----------------------------
chr_effects_test_reset();
check('add: amount 0 is refused', chronicler_sheets_effects_add(7, ['effect' => 'queasy', 'amount' => 0]) === false);
check(
    'add: the refusal stored nothing at all',
    $GLOBALS['chr_test_post_meta_writes'] === [] && chronicler_sheets_effects_get(7) === []
);
check('add: a negative amount is refused', chronicler_sheets_effects_add(7, ['effect' => 'queasy', 'amount' => -1]) === false);
check('add: a non-integer amount is refused', chronicler_sheets_effects_add(7, ['effect' => 'queasy', 'amount' => 'lots']) === false);
check(
    'add: an amount spoken as digits is read as one',
    chronicler_sheets_effects_add(7, ['effect' => 'queasy', 'amount' => '2']) === true
        && chronicler_sheets_effects_get(7)[0]['amount'] === 2
);

chr_effects_test_reset();
check('add: an effect id outside the id pattern is refused', chronicler_sheets_effects_add(7, ['effect' => 'Queasy!']) === false);
check('add: a one-off with no label is refused', chronicler_sheets_effects_add(7, ['modifier' => -2]) === false);
check('add: a one-off with no modifier is refused', chronicler_sheets_effects_add(7, ['label' => 'Acne']) === false);
check(
    'add: a one-off modifier of 0 is refused — it would print on every roll and do nothing',
    chronicler_sheets_effects_add(7, ['label' => 'Acne', 'modifier' => 0]) === false
);
check(
    'add: a target outside the id pattern is refused',
    chronicler_sheets_effects_add(7, ['label' => 'Acne', 'modifier' => -2, 'target' => 'Rizz Pool']) === false
);
check('add: every refusal left the row empty', chronicler_sheets_effects_get(7) === []);

// --- the read-back guard (the template-store precedent) ------------------------
chr_effects_test_reset();
$GLOBALS['chr_test_post_meta_fail'] = true;
check('add: a write that does not land reports failure', chronicler_sheets_effects_add(7, ['effect' => 'queasy']) === false);
$GLOBALS['chr_test_post_meta_fail'] = false;

chr_effects_test_reset();
chronicler_sheets_effects_add(7, ['effect' => 'queasy']);
$GLOBALS['chr_test_post_meta_fail'] = true;
check('clear: a removal that does not land reports nothing removed', chronicler_sheets_effects_clear(7, 'queasy') === 0);
$GLOBALS['chr_test_post_meta_fail'] = false;
check('clear: the unlanded removal left the instance in place', count(chronicler_sheets_effects_get(7)) === 1);

// Leave the shared meta globals clean for the suites downstream.
chr_effects_test_reset();
