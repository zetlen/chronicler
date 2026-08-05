<?php
// Effect instances on a character (the 2026-08-04 effects design): the GM
// applies an effect — one named by the template's `effects:` vocabulary, or
// a one-off the template never heard of — and it rides every roll it
// matches until somebody clears it. Nothing expires by itself; the modifier
// printing on a roll it no longer belongs on IS the expiry mechanism.
//
// Storage follows the template-store precedent (#163): ONE meta row,
// chr_active_effects, holding the whole list as JSON, wp_slash'd on the way
// in because update_post_meta unslashes, and read back before anyone is
// told the write landed. A GM who is told an effect applied must be able to
// find it there.
//
// Two halves live here: the STORE — what an instance is and how it survives
// a round trip — and EVALUATION, what the instances on a character do to one
// roll. Who may apply one is the /game effect command's business, not this
// file's.

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

/** The meta key holding a character's active effect instances. */
const CHRONICLER_EFFECTS_META = 'chr_active_effects';

/**
 * A character's active instances, oldest first.
 *
 * Defensive to the bone, because this row is read on every roll: anything
 * that is not a JSON list of instances reads as no effects at all, and one
 * unreadable entry drops instead of poisoning its neighbours. A mangled row
 * must cost the table a plain roll, never a fatal.
 *
 * Vocabulary membership is NOT checked here — the store has no template.
 * An id the template dropped since it was applied still reads back, so the
 * lister can flag it and the GM can clear it. Nothing disappears behind
 * their back.
 */
function chronicler_sheets_effects_get(int $character_id): array {
    $raw = get_post_meta($character_id, CHRONICLER_EFFECTS_META, true);
    $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($stored)) {
        return [];
    }
    $instances = [];
    foreach ($stored as $entry) {
        $instance = is_array($entry) ? chronicler_sheets_effects_normalize($entry) : null;
        if ($instance !== null) {
            $instances[] = $instance;
        }
    }
    return $instances;
}

/**
 * Append an instance, returning whether the list read back carrying it —
 * the template-store read-back for the same reason: update_post_meta's raw
 * return cannot tell a failed write from an unchanged one, so the caller
 * must not announce an effect on it.
 *
 * Input that cannot be shaped into an instance is refused outright rather
 * than stored half-formed. An effect stored without the thing that makes it
 * do something is worse than one that never applied: it prints on the sheet
 * and lies about what the roll owes it.
 */
function chronicler_sheets_effects_add(int $character_id, array $instance): bool {
    $normalized = chronicler_sheets_effects_normalize($instance);
    if ($normalized === null) {
        return false;
    }
    $instances = chronicler_sheets_effects_get($character_id);
    $instances[] = $normalized;
    return chronicler_sheets_effects_write($character_id, $instances);
}

/**
 * Remove instances and return how many went: $key takes named instances by
 * effect id (spoken with spaces or underscores, the /game roll courtesy)
 * and one-offs by label (matched normalized — the GM types what the sheet
 * shows, in whatever case it lands); null clears everything.
 *
 * A named instance never answers to a label and a one-off never to an id:
 * the label of a named instance lives in the template, not on the
 * character, so the two namespaces cannot collide by accident.
 *
 * An unlanded write reports 0 rather than a count — "cleared 2" about
 * instances still on the sheet is the one answer worse than "cleared
 * nothing", which at least invites the GM to try again.
 */
function chronicler_sheets_effects_clear(int $character_id, ?string $key): int {
    $instances = chronicler_sheets_effects_get($character_id);
    if ($instances === []) {
        return 0;
    }
    if ($key === null) {
        $kept = [];
    } else {
        $wanted = chronicler_sheets_effects_normalize_key($key);
        $wanted_id = str_replace(' ', '_', $wanted);
        $kept = array_values(array_filter($instances, static function (array $instance) use ($wanted, $wanted_id): bool {
            return $instance['effect'] !== null
                ? $instance['effect'] !== $wanted_id
                : chronicler_sheets_effects_normalize_key((string) $instance['label']) !== $wanted;
        }));
    }
    $removed = count($instances) - count($kept);
    if ($removed === 0) {
        return 0;
    }
    return chronicler_sheets_effects_write($character_id, $kept) ? $removed : 0;
}

/**
 * Coerce untrusted input into the instance shape, or null when it cannot be
 * one. Two shapes, told apart by `effect`:
 *
 * - **Named**: `effect` is a vocabulary id and label/target/modifier stay
 *   null — behavior comes from the definition at evaluation time, so a
 *   template edit retroactively changes what an applied effect does. That
 *   is the design: the definition is the authority.
 * - **One-off**: no `effect`; the instance carries its own label, non-zero
 *   integer modifier and optional target word, because nothing in the
 *   template knows about it. Sugar semantics only — no inline expressions.
 *
 * `amount` is the instance's magnitude and defaults to 1; below 1 there is
 * no instance to apply. `applied_at` is stamped here when the caller has
 * none, so a stored instance always knows when it arrived.
 */
function chronicler_sheets_effects_normalize(array $instance): ?array {
    $amount = chronicler_sheets_to_int($instance['amount'] ?? 1);
    if ($amount === null || $amount < 1) {
        return null;
    }
    $effect = $instance['effect'] ?? null;
    $label = null;
    $target = null;
    $modifier = null;
    if ($effect !== null && $effect !== '') {
        if (!is_string($effect) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $effect)) {
            return null;
        }
    } else {
        $effect = null;
        $label = is_string($instance['label'] ?? null) ? trim($instance['label']) : '';
        $modifier = chronicler_sheets_to_int($instance['modifier'] ?? null);
        // A one-off contributing 0 would print on every roll it matches and
        // change nothing — the GM meant something by applying it.
        if ($label === '' || $modifier === null || $modifier === 0) {
            return null;
        }
        $target = $instance['target'] ?? null;
        $target = ($target === null || $target === '') ? null : $target;
        if ($target !== null && (!is_string($target) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $target))) {
            return null;
        }
    }
    $applied_by = chronicler_sheets_to_int($instance['applied_by'] ?? 0) ?? 0;
    $applied_at = chronicler_sheets_to_int($instance['applied_at'] ?? 0) ?? 0;
    return [
        'effect' => $effect,
        'label' => $label,
        'amount' => $amount,
        'target' => $target,
        'modifier' => $modifier,
        'applied_by' => max(0, $applied_by),
        'applied_at' => $applied_at > 0 ? $applied_at : time(),
        'note' => is_string($instance['note'] ?? null) ? trim($instance['note']) : '',
    ];
}

// -- Evaluation ---------------------------------------------------------------

/**
 * What a character's active effects contribute to ONE roll:
 * ['terms' => [['label' => …, 'value' => int], …], 'error' => ?string].
 *
 * $roll is a union roll (Roll::union) — its id, label, section, `traits` and
 * `uses`. $context is the character's formula context, the same one `derived`
 * runs against. Pure: rolling never writes, because nothing here expires.
 *
 * Each instance contributes through one of two paths. SUGAR — an integer
 * modifier and a target word — contributes `modifier × amount` to the rolls
 * chronicler_sheets_effects_targets() matches, and nothing to the rest. An
 * EXPRESSION decides for itself, reading `roll` and `amount` (fenced at save;
 * chronicler_sheets_formula_effect_scope).
 *
 * Then the arithmetic that makes stacking legible. Instances of one named
 * effect SUM and the sum is clamped by the definition's `cap`, and what comes
 * out is ONE term wearing the effect's label: three Taunteds capped at -2
 * print `-2 (Taunted)`, not three terms the table has to add up and then
 * argue about. One-offs stand alone — each is its own thing the GM invented,
 * with no vocabulary to stack into. A contribution of 0 produces no term at
 * all: an effect that doesn't touch this roll stays out of it.
 *
 * Two things this deliberately does NOT do. An instance naming an effect the
 * template no longer declares is skipped silently here — it is still listed
 * on the sheet, flagged, for the GM to clear, and a deleted definition must
 * not stop the table rolling. And a failed expression comes back as `error`
 * carrying only the effect's LABEL, because sheets/ has no idea which surface
 * is about to word the refusal — but effects are public, so naming one leaks
 * nothing.
 */
function chronicler_sheets_effects_for_roll(array $instances, array $template, array $roll, array $context): array {
    $definitions = is_array($template['effects'] ?? null) ? $template['effects'] : [];
    $trait_keys = chronicler_sheets_template_traits($template);
    $totals = [];
    foreach (array_values($instances) as $index => $instance) {
        $effect = $instance['effect'] ?? null;
        if ($effect !== null) {
            $definition = $definitions[$effect] ?? null;
            if (!is_array($definition)) {
                continue; // the template dropped it; the sheet still lists it
            }
            $key = 'effect:' . $effect;
            $label = (string) $definition['label'];
            $modifier = $definition['modifier'];
            $target = $definition['applies_to'] ?? null;
            $cap = $definition['cap'] ?? null;
        } else {
            // A one-off is its own vocabulary of one, so it never sums with
            // anything and has nothing to cap.
            $key = 'one_off:' . $index;
            $label = (string) $instance['label'];
            $modifier = $instance['modifier'];
            $target = $instance['target'] ?? null;
            $cap = null;
        }
        $amount = (int) ($instance['amount'] ?? 1);
        if (is_string($modifier)) {
            $result = chronicler_sheets_formula_evaluate(
                $modifier,
                chronicler_sheets_formula_effect_context($context, $roll, $amount, $trait_keys)
            );
            if (is_wp_error($result) || !is_numeric($result)) {
                return ['terms' => [], 'error' => $label];
            }
            $value = (int) round((float) $result);
        } else {
            $value = chronicler_sheets_effects_targets($target, $roll) ? ((int) $modifier) * $amount : 0;
        }
        if (!isset($totals[$key])) {
            $totals[$key] = ['label' => $label, 'value' => 0, 'cap' => $cap];
        }
        $totals[$key]['value'] += $value;
    }
    $terms = [];
    foreach ($totals as $total) {
        $value = $total['value'];
        if ($total['cap'] !== null) {
            // A cap bounds in the direction of its own sign: a debuff's cap
            // is the floor it can't sink past, a bonus's is the ceiling.
            $value = $total['cap'] < 0 ? max($value, $total['cap']) : min($value, $total['cap']);
        }
        if ($value === 0) {
            continue;
        }
        $terms[] = ['label' => $total['label'], 'value' => $value];
    }
    return ['terms' => $terms, 'error' => null];
}

/**
 * Whether one sugar target word names this roll. Four stages, in order, and
 * the first hit wins:
 *
 *   1. the roll's id            — `applies_to: nausea_check`
 *   2. its label, spoken        — `applies_to: use_magic` finds "Use Magic",
 *                                 the /game roll courtesy for a word that is
 *                                 an id written with underscores
 *   3. a trait of this roll that is ON — `applies_to: basic_move` catches a
 *                                 whole category the author named
 *   4. a property the dice reach — `applies_to: rizz` catches every roll with
 *                                 Rizz in it, spliced or added
 *
 * No target at all means every roll. Trait VALUES (save == "dexterity") are
 * the expression form's job; this is the word an author or a GM types.
 */
function chronicler_sheets_effects_targets(?string $target, array $roll): bool {
    $want = chronicler_sheets_effects_normalize_key((string) $target);
    if ($want === '') {
        return true;
    }
    if (($roll['id'] ?? null) !== null && (string) $roll['id'] === $want) {
        return true;
    }
    if (chronicler_sheets_effects_normalize_key((string) ($roll['label'] ?? '')) === str_replace('_', ' ', $want)) {
        return true;
    }
    $traits = is_array($roll['traits'] ?? null) ? $roll['traits'] : [];
    if (!empty($traits[$want])) {
        return true;
    }
    return in_array($want, (array) ($roll['uses'] ?? []), true);
}

/** Lowercase, trimmed, inner whitespace collapsed — how Roll matches words. */
function chronicler_sheets_effects_normalize_key(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', strtolower(trim($text))));
}

/** The whole list, slashed and verified. Private to this file's writers. */
function chronicler_sheets_effects_write(int $character_id, array $instances): bool {
    $json = wp_json_encode(array_values($instances));
    if (!is_string($json)) {
        return false;
    }
    update_post_meta($character_id, CHRONICLER_EFFECTS_META, wp_slash($json));
    return get_post_meta($character_id, CHRONICLER_EFFECTS_META, true) === $json;
}
