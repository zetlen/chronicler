<?php
// The dice grammar behind a template's `rolls:` table: standard dice notation
// — NdM, NdMkhK / NdMklK (advantage, disadvantage, 4d6-drop-lowest), integer
// constants — with {…} placeholders holding an Expression Language expression
// evaluated against the character and substituted as a number.
//
// Schema-level, because template SAVE validates dice (sheets/schema.php calls
// it). Pure aside from WP_Error, and the randomizer is injected, so parsing
// and rolling are both testable without WordPress.
//
// Dice notation deliberately sits OUTSIDE the expression language (design
// 2026-07-25 §4a): Symfony's EL takes new functions but its operator table is
// private and its lexer's operator set is a hardcoded regex, so `2d6` is
// unreachable there and `d(2, 6)` is the ceiling — a worse authoring surface.
// More importantly, a registered d() would make `derived: "d(2,6)"`
// expressible, and derived formulas are recomputed on every read: a stat that
// silently rerolls itself whenever anyone loads the page. Notation for the
// random part, EL inside the braces for the arithmetic, and randomness stays
// structurally incapable of reaching `derived`.

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

// Bounds, so a template typo can't ask the server for a million dice.
const CHRONICLER_DICE_MAX_COUNT = 100;
const CHRONICLER_DICE_MAX_SIDES = 1000;
const CHRONICLER_DICE_MAX_CONST = 10000;
const CHRONICLER_DICE_MAX_TERMS = 10;

/**
 * Parse one dice string into ['terms' => [...]], or a WP_Error naming what is
 * wrong with it in the words a template author would use. Term shapes:
 *
 *   ['kind' => 'dice',  'count' => int, 'sides' => int,
 *    'keep' => null|['dir' => 'h'|'l', 'n' => int], 'sign' => 1|-1, 'notation' => string]
 *   ['kind' => 'const', 'value' => int, 'sign' => 1|-1, 'notation' => string]
 *   ['kind' => 'expr',  'expression' => string, 'sign' => 1|-1, 'notation' => string]
 *
 * The `expression` is NOT checked here — it is EL, and validating it needs the
 * template's property list; chronicler_sheets_parse_template() runs it through
 * the same fence `derived` uses. Pure.
 */
function chronicler_sheets_parse_dice(string $expr) {
    $bad = function (string $message) {
        return new WP_Error('chronicler_invalid_dice', $message);
    };
    $raw = trim($expr);
    if ($raw === '') {
        return $bad('A roll needs dice notation, e.g. "2d6 + {cool}".');
    }

    // Split on top-level "+"/"-" only: a placeholder holds an expression that
    // may contain its own operators ({floor(dex / 2) - 1}), so anything inside
    // braces is carried through whole.
    $chunks = [];
    $buf = '';
    $sign = 1;
    $depth = 0;
    $signed_first = false;
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth < 0) {
                return $bad("\"$raw\" closes a \"}\" that was never opened.");
            }
        } elseif ($depth === 0 && ($c === '+' || $c === '-')) {
            if (trim($buf) === '') {
                if ($chunks === [] && !$signed_first) {
                    $signed_first = true; // a leading sign belongs to the first term
                    $sign = $c === '-' ? -1 : 1;
                    continue;
                }
                return $bad("\"$raw\" has a \"$c\" with nothing before it to add to.");
            }
            $chunks[] = ['sign' => $sign, 'text' => trim($buf)];
            $buf = '';
            $sign = $c === '-' ? -1 : 1;
            continue;
        }
        $buf .= $c;
    }
    if ($depth !== 0) {
        return $bad("\"$raw\" opens a \"{\" that is never closed.");
    }
    if (trim($buf) === '') {
        return $bad("\"$raw\" ends with an operator and nothing after it.");
    }
    $chunks[] = ['sign' => $sign, 'text' => trim($buf)];

    if (count($chunks) > CHRONICLER_DICE_MAX_TERMS) {
        return $bad('A roll adds at most ' . CHRONICLER_DICE_MAX_TERMS . ' terms; "' . $raw . '" has ' . count($chunks) . '.');
    }

    $terms = [];
    foreach ($chunks as $chunk) {
        $term = chronicler_sheets_parse_dice_term($chunk['text'], $chunk['sign']);
        if (is_wp_error($term)) {
            return $term;
        }
        $terms[] = $term;
    }
    return ['terms' => $terms];
}

/** One already-split term: NdM[kh|kl K], a whole number, or {expression}. */
function chronicler_sheets_parse_dice_term(string $text, int $sign) {
    $bad = function (string $message) {
        return new WP_Error('chronicler_invalid_dice', $message);
    };

    if ($text[0] === '{') {
        $inner = substr($text, -1) === '}' ? trim(substr($text, 1, -1)) : null;
        if ($inner === null || strpos($inner, '{') !== false || strpos($inner, '}') !== false) {
            return $bad("\"$text\" is not one {expression} — a placeholder is a whole term.");
        }
        if ($inner === '') {
            return $bad('"{}" is empty — put a property name or a formula between the braces.');
        }
        return ['kind' => 'expr', 'expression' => $inner, 'sign' => $sign, 'notation' => '{' . $inner . '}'];
    }

    if (preg_match('/^(\d+)[dD](\d+)(?:[kK]([hHlL])(\d+))?$/', $text, $m)) {
        $count = (int) $m[1];
        $sides = (int) $m[2];
        if ($count < 1) {
            return $bad("\"$text\" rolls no dice — the number before \"d\" must be at least 1.");
        }
        if ($count > CHRONICLER_DICE_MAX_COUNT) {
            return $bad("\"$text\" rolls more than " . CHRONICLER_DICE_MAX_COUNT . ' dice.');
        }
        if ($sides < 1) {
            return $bad("\"$text\" needs at least one side.");
        }
        if ($sides > CHRONICLER_DICE_MAX_SIDES) {
            return $bad("\"$text\" has more than " . CHRONICLER_DICE_MAX_SIDES . ' sides.');
        }
        $keep = null;
        if (($m[3] ?? '') !== '') {
            $n = (int) $m[4];
            $dir = strtolower($m[3]);
            if ($n < 1) {
                return $bad("\"$text\" keeps no dice — the number after \"k$dir\" must be at least 1.");
            }
            if ($n > $count) {
                return $bad("\"$text\" keeps $n dice but only rolls $count.");
            }
            $keep = ['dir' => $dir, 'n' => $n];
        }
        return [
            'kind' => 'dice',
            'count' => $count,
            'sides' => $sides,
            'keep' => $keep,
            'sign' => $sign,
            'notation' => $count . 'd' . $sides . ($keep === null ? '' : 'k' . $keep['dir'] . $keep['n']),
        ];
    }

    if (preg_match('/^\d+$/', $text)) {
        $value = (int) $text;
        if ($value > CHRONICLER_DICE_MAX_CONST) {
            return $bad("\"$text\" is larger than " . CHRONICLER_DICE_MAX_CONST . '.');
        }
        return ['kind' => 'const', 'value' => $value, 'sign' => $sign, 'notation' => (string) $value];
    }

    return $bad("\"$text\" is not dice notation — write dice as 2d6 or 4d6kh3, a whole number, or {a property}.");
}

/**
 * The expression string of every {…} in a parsed roll, in order, each once.
 * What template validation fences and what /game roll resolves before rolling.
 */
function chronicler_sheets_dice_placeholders(array $parsed): array {
    $placeholders = [];
    foreach ($parsed['terms'] as $term) {
        if ($term['kind'] === 'expr') {
            $placeholders[] = $term['expression'];
        }
    }
    return array_values(array_unique($placeholders));
}

/**
 * The production randomizer: fn(int $min, int $max): int. wp_rand() when
 * WordPress is loaded (it seeds better than rand()), random_int() otherwise so
 * the harness and CLI callers work on a bare checkout.
 */
function chronicler_sheets_dice_default_rng(): callable {
    return function (int $min, int $max): int {
        return function_exists('wp_rand') ? wp_rand($min, $max) : random_int($min, $max);
    };
}

/**
 * Roll a parsed expression. $values maps a placeholder's whole expression
 * string (the key chronicler_sheets_dice_placeholders() returns) to its
 * resolved number — callers must resolve every placeholder first; an
 * unresolved one counts as 0, and a fractional one rounds to the nearest whole
 * number, because a roll total is an integer.
 *
 * Returns ['terms' => [...], 'total' => int]: each term is its parsed shape
 * plus a signed 'subtotal', dice terms additionally carrying 'dice' — every
 * face in rolled order with the 'kept' flag kh/kl decided — so output can show
 * the dice it added AND the ones it dropped. $rng is injected (fn(int $min,
 * int $max): int) so tests assert exact faces; production omits it.
 */
function chronicler_sheets_roll_dice(array $parsed, array $values, ?callable $rng = null): array {
    $roll = $rng ?? chronicler_sheets_dice_default_rng();
    $terms = [];
    $total = 0;
    foreach ($parsed['terms'] as $term) {
        if ($term['kind'] === 'dice') {
            $faces = [];
            for ($i = 0; $i < $term['count']; $i++) {
                $faces[] = (int) $roll(1, $term['sides']);
            }
            $kept = chronicler_sheets_dice_kept($faces, $term['keep']);
            $dice = [];
            $subtotal = 0;
            foreach ($faces as $i => $face) {
                $is_kept = in_array($i, $kept, true);
                $dice[] = ['value' => $face, 'kept' => $is_kept];
                if ($is_kept) {
                    $subtotal += $face;
                }
            }
            $term += ['dice' => $dice, 'subtotal' => $term['sign'] * $subtotal];
        } elseif ($term['kind'] === 'const') {
            $term += ['subtotal' => $term['sign'] * $term['value']];
        } else {
            $resolved = $values[$term['expression']] ?? 0;
            $value = is_numeric($resolved) ? (int) round((float) $resolved) : 0;
            $term += ['value' => $value, 'subtotal' => $term['sign'] * $value];
        }
        $total += $term['subtotal'];
        $terms[] = $term;
    }
    return ['terms' => $terms, 'total' => $total];
}

/**
 * The rolls a character's own sheet contributes (2026-07-25: a move carries
 * its own roll): for every list property carrying a dice-typed field, each
 * entry that (a) has a label, (b) passes the dice field's `when`, (c) holds a
 * non-empty dice string that parses, and (d) survives placeholder-row
 * filtering, contributes one roll. Pure: sheet in, contributions out.
 *
 * Takes the VIEWER-FILTERED sheet (chronicler_sheets_sheet_for_viewer()
 * shape — each property its full template definition plus 'value'), because
 * each property already carries the field definitions needed here, and taking
 * the sheet keeps the audience filtering upstream and unforgeable.
 *
 * Each contribution matches chronicler_sheets_parse_roll()'s shape minus the
 * id — 'id' is explicitly null; character rolls have no stable id and match
 * on label only — so every downstream consumer (values, reply, listing)
 * takes both kinds without branching:
 *
 *   ['id' => null, 'label' => …, 'section' => list label, 'detail' => …|null,
 *    'dice' => …, 'parsed' => …]
 *
 * The label comes from the list's `label_field` designation, else its first
 * text field; a list with neither contributes nothing (an unnamed roll is
 * unpickable). The detail is the first longtext field's first line. An
 * unparseable dice string is INERT, per entry: it contributes no roll and
 * costs nothing around it — the strict/lenient split puts dice-value errors
 * on the sheet editor's row flag (admin.php), never on a read surface.
 */
function chronicler_sheets_character_rolls(array $sheet): array {
    $rolls = [];
    foreach (($sheet['properties'] ?? []) as $property) {
        if (($property['type'] ?? null) !== 'list' || !is_array($property['fields'] ?? null)) {
            continue;
        }
        $dice_field = null;
        $label_field = null;
        $detail_field = null;
        foreach ($property['fields'] as $field) {
            $dice_field ??= $field['type'] === 'dice' ? $field : null;
            $label_field ??= $field['type'] === 'text' ? $field : null;
            $detail_field ??= $field['type'] === 'longtext' ? $field : null;
        }
        if (isset($property['label_field'])) {
            foreach ($property['fields'] as $field) {
                if ($field['id'] === $property['label_field']) {
                    $label_field = $field;
                    break;
                }
            }
        }
        if ($dice_field === null || $label_field === null) {
            continue;
        }
        foreach (chronicler_sheets_filter_placeholder_entries($property, (array) ($property['value'] ?? [])) as $entry) {
            $label = trim((string) ($entry[$label_field['id']] ?? ''));
            if (chronicler_sheets_is_placeholder_text($label)) {
                continue;
            }
            if (!chronicler_sheets_when_holds($property, $dice_field, $entry)) {
                continue;
            }
            $dice = trim((string) ($entry[$dice_field['id']] ?? ''));
            if ($dice === '') {
                continue;
            }
            $parsed = chronicler_sheets_parse_dice($dice);
            if (is_wp_error($parsed)) {
                continue;
            }
            $detail = $detail_field === null ? ''
                : chronicler_sheets_dice_detail_line((string) ($entry[$detail_field['id']] ?? ''));
            $rolls[] = [
                'id' => null,
                'label' => $label,
                'section' => (string) ($property['label'] ?? ''),
                'detail' => $detail === '' ? null : $detail,
                'dice' => $dice,
                'parsed' => $parsed,
            ];
        }
    }
    return $rolls;
}

/**
 * A menu-worthy one-liner from a longtext value: the first non-empty line,
 * truncated to 120 characters with an ellipsis. Multibyte-safe where the
 * host has mbstring, byte-safe elsewhere (same guard as render.php).
 */
function chronicler_sheets_dice_detail_line(string $text): string {
    $line = '';
    foreach (preg_split('/\R/u', trim($text)) ?: [] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '') {
            $line = $candidate;
            break;
        }
    }
    if ($line === '') {
        return '';
    }
    if (function_exists('mb_strlen')) {
        return mb_strlen($line) <= 120 ? $line : rtrim(mb_substr($line, 0, 119)) . '…';
    }
    return strlen($line) <= 120 ? $line : rtrim(substr($line, 0, 119)) . '…';
}

/**
 * The indexes of the dice a kh/kl term keeps, ascending. No keep clause keeps
 * everything. Ties go to the earlier die, so the same faces always produce the
 * same report.
 */
function chronicler_sheets_dice_kept(array $faces, ?array $keep): array {
    if ($keep === null) {
        return array_keys($faces);
    }
    $order = array_keys($faces);
    usort($order, function ($a, $b) use ($faces, $keep) {
        if ($faces[$a] === $faces[$b]) {
            return $a <=> $b;
        }
        return $keep['dir'] === 'h' ? $faces[$b] <=> $faces[$a] : $faces[$a] <=> $faces[$b];
    });
    $kept = array_slice($order, 0, $keep['n']);
    sort($kept);
    return $kept;
}
