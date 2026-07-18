<?php
// Schema logic for character sheets: parsing, defaults, ops, display.
// Pure functions only (WP_Error aside) — behaviorally tested without
// WordPress by tests/run.php, and shared by every write surface.

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

const CHRONICLER_SHEETS_TYPES = ['number', 'track', 'counter', 'toggle', 'select', 'checklist', 'text', 'longtext', 'list'];
const CHRONICLER_SHEETS_LIST_FIELD_TYPES = ['text', 'longtext', 'number', 'toggle', 'select'];
const CHRONICLER_SHEETS_ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

// Every key a property / list-field / option spec may carry, in the order
// template.schema.json declares them (schema-drift.test.php pins the lists
// together). The write path rejects anything else: an unrecognized key is
// most dangerous when it's a typo'd audience flag, which used to save
// silently and publish the very value it meant to hide.
const CHRONICLER_SHEETS_PROPERTY_KEYS = ['id', 'label', 'type', 'live', 'gm_only', 'owner_only', 'always_show', 'detail', 'derived', 'min', 'max', 'start', 'length', 'options', 'fields', 'entry_label'];
const CHRONICLER_SHEETS_LIST_FIELD_KEYS = ['id', 'label', 'type', 'when', 'min', 'max', 'options'];
const CHRONICLER_SHEETS_OPTION_KEYS = ['id', 'label'];

/**
 * Decode a template SOURCE (JSON or YAML) into an associative array (#138).
 * JSON is tried first, so every template already stored as JSON decodes on
 * exactly today's path. Anything json_decode does not turn into an array is
 * offered to the YAML parser. Returns a WP_Error when the source is neither a
 * valid JSON object/array nor valid YAML that yields one.
 */
function chronicler_sheets_decode_template(string $source) {
    $json = json_decode($source, true);
    if (is_array($json)) {
        return $json;
    }
    // Not JSON (or JSON that is not an object/array). Try YAML — but only if the
    // parser is present; a vendor-less install keeps the original JSON error.
    if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
        return new WP_Error('chronicler_invalid_json', 'Template is not valid JSON: ' . json_last_error_msg());
    }
    try {
        $yaml = \Symfony\Component\Yaml\Yaml::parse($source);
    } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
        return new WP_Error('chronicler_invalid_template', 'Template is not valid JSON or YAML: ' . $e->getMessage());
    }
    if (!is_array($yaml)) {
        return new WP_Error('chronicler_invalid_template', 'Template must be a JSON or YAML object.');
    }
    return $yaml;
}

/**
 * A WP_Error naming the first key of $spec outside $known, or null when every
 * key is recognized. $where prefixes the message; the closest known key
 * (edit distance under 3) earns a "did you mean" hint.
 */
function chronicler_sheets_unknown_key_error(array $spec, array $known, string $where): ?WP_Error {
    foreach (array_keys($spec) as $key) {
        if (in_array($key, $known, true)) {
            continue;
        }
        $best = null;
        $bestDist = 3;
        foreach ($known as $candidate) {
            $dist = levenshtein(strtolower((string) $key), $candidate);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $candidate;
            }
        }
        $hint = $best === null ? '' : " Did you mean \"$best\"?";
        return new WP_Error('chronicler_invalid_template', "$where: unknown key \"$key\".$hint");
    }
    return null;
}

/**
 * $lenient (#140): reads must tolerate shapes a stored template saved under
 * older, looser rules, so a write-path rule tightened later doesn't retroactively
 * brick every document written before it. The write path (admin.php) always
 * parses strict; read paths (post-types.php) parse lenient.
 *
 * $source (#138) may be JSON or YAML — chronicler_sheets_decode_template()
 * detects which. Everything after decoding is format-agnostic.
 */
function chronicler_sheets_parse_template(string $source, bool $lenient = false) {
    $data = chronicler_sheets_decode_template($source);
    if (is_wp_error($data)) {
        return $data;
    }
    if (!is_string($data['system'] ?? null) || $data['system'] === '') {
        return new WP_Error('chronicler_invalid_template', '"system" must be a non-empty string.');
    }
    if (!is_int($data['version'] ?? null)) {
        return new WP_Error('chronicler_invalid_template', '"version" must be an integer.');
    }
    if (!is_array($data['properties'] ?? null) || $data['properties'] === []) {
        return new WP_Error('chronicler_invalid_template', '"properties" must be a non-empty list.');
    }

    $properties = [];
    foreach ($data['properties'] as $i => $prop) {
        $where = '"properties" entry ' . ($i + 1);
        if (!is_array($prop)) {
            return new WP_Error('chronicler_invalid_template', "$where must be an object.");
        }
        $id = $prop['id'] ?? null;
        if (!is_string($id) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $id)) {
            return new WP_Error('chronicler_invalid_template', "$where: \"id\" must match [a-z][a-z0-9_]*.");
        }
        if (isset($properties[$id])) {
            return new WP_Error('chronicler_invalid_template', "Duplicate property id \"$id\".");
        }
        // Unknown keys fail the save (lenient reads still tolerate them, so
        // documents stored under older, looser parsers keep rendering).
        if (!$lenient) {
            $err = chronicler_sheets_unknown_key_error($prop, CHRONICLER_SHEETS_PROPERTY_KEYS, "Property \"$id\"");
            if ($err !== null) {
                return $err;
            }
        }
        if (!is_string($prop['label'] ?? null) || $prop['label'] === '') {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"label\" must be a non-empty string.");
        }
        $type = $prop['type'] ?? null;
        if (!in_array($type, CHRONICLER_SHEETS_TYPES, true)) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": unknown type \"" . (is_string($type) ? $type : '?') . '".');
        }
        if (isset($prop['live']) && !is_bool($prop['live'])) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"live\" must be true or false.");
        }
        if (($prop['live'] ?? false) && $type === 'list') {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": lists cannot be live — they are edited in wp-admin only.");
        }
        if (isset($prop['gm_only']) && !is_bool($prop['gm_only'])) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"gm_only\" must be true or false.");
        }
        if (isset($prop['owner_only']) && !is_bool($prop['owner_only'])) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"owner_only\" must be true or false.");
            }
            // The flag shipped after parsers that silently kept unknown keys,
            // so a stored document CAN carry a malformed owner_only that never
            // saw validation. Degrade CLOSED: truthy junk asked for privacy
            // and gets it; only an explicit falsy stays public.
            $prop['owner_only'] = !empty($prop['owner_only']);
        }
        // The two audience flags contradict: gm_only excludes the owning
        // player, owner_only exists precisely to include them.
        if (($prop['gm_only'] ?? false) && ($prop['owner_only'] ?? false)) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"owner_only\" and \"gm_only\" cannot combine — gm_only hides a property from the owning player; owner_only shows it to them and the GM.");
            }
            // A stored contradiction resolves to the stricter audience:
            // gm_only keeps excluding the owner, exactly what it always meant.
            $prop['owner_only'] = false;
        }
        // `live` is exactly what the public write surfaces (front-end / REST
        // /sheet) may change; a gm_only property must never be player-writable.
        // Forbidding the combination here keeps the dangerous shape from ever
        // existing — the write path also refuses gm_only defensively.
        if (($prop['live'] ?? false) && ($prop['gm_only'] ?? false)) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": a gm_only property cannot be live — GM-only fields are edited in wp-admin only.");
            }
            // A document saved before this rule existed is not malformed by the
            // rule's own intent (#140) — gm_only wins and live is dropped, which
            // is exactly what the write path would have enforced at save time.
            $prop['live'] = false;
        }
        // "always_show" keeps a property on the sheet even with nothing filled
        // in (e.g. Player Notes, GM Notes) — see chronicler_sheets_is_unfilled().
        if (isset($prop['always_show']) && !is_bool($prop['always_show'])) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"always_show\" must be true or false.");
        }
        // "detail" is the system-default annotation for what a property does
        // (e.g. the basic moves a rating rolls); characters may override it.
        if (isset($prop['detail']) && (!is_string($prop['detail']) || $prop['detail'] === '')) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"detail\" must be a non-empty string.");
        }
        // "derived" (#88): a formula computes this property from others on
        // every read; it is never stored and never editable. The formula
        // itself is checked after the loop, once every id is declared.
        if (isset($prop['derived'])) {
            if (!is_string($prop['derived']) || trim($prop['derived']) === '') {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"derived\" must be a formula string.");
            }
            if (!in_array($type, ['number', 'toggle'], true)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"derived\" is supported on number and toggle properties.");
            }
            if ($prop['live'] ?? false) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": a derived property is computed — it cannot be live.");
            }
        }
        // Audience flags gate whole properties; nothing consults them inside
        // a list entry, so a flagged field would render publicly while the
        // template reads as if it were private. The write path refuses; a
        // lenient read drops the flagged field whole — hidden from everyone
        // beats leaked to everyone. A stored list whose EVERY field carried a
        // truthy flag ends up with no fields and still fails the parse in
        // check_constraints: such a list had no visible content anyway, and
        // post-types.php logs the reason before falling back.
        if ($type === 'list' && is_array($prop['fields'] ?? null)) {
            foreach ($prop['fields'] as $fi => $field) {
                if (!is_array($field)) {
                    continue; // check_constraints reports the malformed field
                }
                foreach (['gm_only', 'owner_only'] as $flag) {
                    if (!array_key_exists($flag, $field)) {
                        continue;
                    }
                    if (!$lenient) {
                        $fid = is_string($field['id'] ?? null) ? $field['id'] : '?';
                        return new WP_Error('chronicler_invalid_template', "Property \"$id\": field \"$fid\": \"$flag\" applies to whole properties, not list fields — audience-gate the list itself, or move the field to its own property.");
                    }
                    if (!empty($field[$flag])) {
                        unset($prop['fields'][$fi]);
                        continue 2;
                    }
                    unset($prop['fields'][$fi][$flag]);
                }
            }
            $prop['fields'] = array_values($prop['fields']);
        }
        $err = chronicler_sheets_check_constraints($id, $type, $prop, $lenient);
        if (is_wp_error($err)) {
            return $err;
        }
        $properties[$id] = $prop;
    }

    // Derived formulas (#88), checked with every property declared: grammar
    // fence + reference validity per formula, a dry run against default
    // values to catch result-type mismatches (skipped when defaults make
    // the run fail, e.g. division by zero — read-time fails soft), and a
    // cycle check across the whole derived graph.
    $draft = ['properties' => $properties];
    foreach ($properties as $id => $prop) {
        if (!isset($prop['derived'])) {
            continue;
        }
        $checked = chronicler_sheets_formula_check($prop['derived'], $draft);
        if (is_wp_error($checked)) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": " . $checked->get_error_message());
        }
        $dry = chronicler_sheets_formula_evaluate(
            $prop['derived'],
            chronicler_sheets_formula_context($draft, [])
        );
        if (!is_wp_error($dry)) {
            if ($prop['type'] === 'toggle' && !is_bool($dry)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": the formula must produce true/false for a toggle (use a comparison).");
            }
            if ($prop['type'] === 'number' && !is_numeric($dry)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": the formula must produce a number.");
            }
        }
    }
    $order = chronicler_sheets_derived_order($draft);
    if (is_wp_error($order)) {
        return new WP_Error('chronicler_invalid_template', $order->get_error_message());
    }

    $layout = [];
    $seen = [];
    foreach (($data['layout'] ?? []) as $i => $section) {
        $where = '"layout" entry ' . ($i + 1);
        if (!is_array($section) || !is_string($section['section'] ?? null) || !is_array($section['properties'] ?? null)) {
            return new WP_Error('chronicler_invalid_template', "$where must be {\"section\": name, \"properties\": [ids]}.");
        }
        foreach ($section['properties'] as $pid) {
            if (!isset($properties[$pid])) {
                return new WP_Error('chronicler_invalid_template', "$where references undeclared property \"$pid\".");
            }
            if (isset($seen[$pid])) {
                return new WP_Error('chronicler_invalid_template', "Property \"$pid\" appears in the layout twice.");
            }
            $seen[$pid] = true;
        }
        // A masthead section renders inside the header card (first property
        // prominent, the rest as label: value lines), not as a body section.
        if (isset($section['masthead']) && !is_bool($section['masthead'])) {
            return new WP_Error('chronicler_invalid_template', "$where: \"masthead\" must be true or false.");
        }
        $layout[] = [
            'section' => $section['section'],
            'properties' => array_values($section['properties']),
            'masthead' => (bool) ($section['masthead'] ?? false),
        ];
    }

    return [
        'system' => $data['system'],
        'version' => $data['version'],
        'properties' => $properties,
        'layout' => $layout,
    ];
}

/** Per-type constraint validation for one property definition. */
function chronicler_sheets_check_constraints(string $id, string $type, array $prop, bool $lenient = false) {
    $intOrNull = function ($key) use ($prop) {
        return !isset($prop[$key]) || is_int($prop[$key]);
    };
    if ($type === 'number') {
        if (!$intOrNull('min') || !$intOrNull('max')) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": min/max must be integers.");
        }
        if (isset($prop['min'], $prop['max']) && $prop['min'] > $prop['max']) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": min exceeds max.");
        }
    }
    if ($type === 'track' && (!is_int($prop['length'] ?? null) || $prop['length'] < 1)) {
        return new WP_Error('chronicler_invalid_template', "Property \"$id\": track needs a positive integer \"length\".");
    }
    if ($type === 'counter' && (!$intOrNull('max') || !$intOrNull('start'))) {
        return new WP_Error('chronicler_invalid_template', "Property \"$id\": counter max/start must be integers.");
    }
    if ($type === 'select' || $type === 'checklist') {
        $options = $prop['options'] ?? null;
        if (!is_array($options) || $options === []) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": $type needs a non-empty \"options\" list.");
        }
        $ids = [];
        foreach ($options as $opt) {
            $oid = is_array($opt) ? ($opt['id'] ?? null) : null;
            if (!is_string($oid) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $oid) || !is_string($opt['label'] ?? null)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": each option needs a valid \"id\" and \"label\".");
            }
            if (isset($ids[$oid])) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": duplicate option id \"$oid\".");
            }
            if (!$lenient) {
                $err = chronicler_sheets_unknown_key_error($opt, CHRONICLER_SHEETS_OPTION_KEYS, "Property \"$id\": option \"$oid\"");
                if ($err !== null) {
                    return $err;
                }
            }
            $ids[$oid] = true;
        }
    }
    if ($type === 'list') {
        if (isset($prop['entry_label']) && (!is_string($prop['entry_label']) || $prop['entry_label'] === '')) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"entry_label\" must be a non-empty string.");
        }
        $fields = $prop['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return new WP_Error('chronicler_invalid_template', "Property \"$id\": list needs a non-empty \"fields\" array.");
        }
        $fieldIds = [];
        foreach ($fields as $field) {
            $fid = is_array($field) ? ($field['id'] ?? null) : null;
            if (!is_string($fid) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $fid)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": each field needs a valid \"id\".");
            }
            if (isset($fieldIds[$fid])) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": duplicate field id \"$fid\".");
            }
            if (!is_string($field['label'] ?? null) || $field['label'] === '') {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": field \"$fid\" needs a label.");
            }
            $ftype = $field['type'] ?? null;
            if (!in_array($ftype, CHRONICLER_SHEETS_LIST_FIELD_TYPES, true)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": field \"$fid\" type must be one of " . implode(', ', CHRONICLER_SHEETS_LIST_FIELD_TYPES) . '.');
            }
            if (!$lenient) {
                $err = chronicler_sheets_unknown_key_error($field, CHRONICLER_SHEETS_LIST_FIELD_KEYS, "Property \"$id\": field \"$fid\"");
                if ($err !== null) {
                    return $err;
                }
            }
            $fieldIds[$fid] = $ftype;
            $err = chronicler_sheets_check_constraints("$id.$fid", $ftype, $field, $lenient);
            if (is_wp_error($err)) {
                return $err;
            }
        }
        // Second pass so "when" may reference fields declared in either order.
        // `when` is an expression in the same fenced language as "derived",
        // scoped to the entry's own referencable fields (2026-07-13).
        foreach ($fields as $field) {
            if (!isset($field['when'])) {
                continue;
            }
            $fid = $field['id'];
            $when = $field['when'];
            if (!is_string($when) || trim($when) === '') {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": field \"$fid\": \"when\" must be an expression string.");
            }
            $checked = chronicler_sheets_formula_check($when, chronicler_sheets_formula_entry_template($prop));
            if (is_wp_error($checked)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": field \"$fid\": \"when\": " . $checked->get_error_message());
            }
            if (in_array($fid, $checked['refs'], true)) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": field \"$fid\": \"when\" can't reference the field it gates.");
            }
        }
    }
    return null;
}

/** Whether a property may be written from the play surfaces (front-end, /sheet). */
function chronicler_sheets_is_live(array $property): bool {
    // Strict === true is this gate's fail-closed polarity: `live` WIDENS
    // write access, so malformed input must read as not-live.
    return ($property['live'] ?? false) === true;
}

/**
 * Whether a property is GM-only: rendered on the sheet solely for a game
 * master (someone who can edit characters they don't own) and omitted from
 * the markup entirely for players — including the owning player — and the
 * public. The GM's private annotations (e.g. "GM Notes") live on the
 * character but must never reach its page source. Truthy check rather than
 * === true: the parser only stores a bool here, but should an unparsed
 * array ever reach this gate, malformed input must read as hidden (fail
 * closed), never as public.
 */
function chronicler_sheets_is_gm_only(array $property): bool {
    return !empty($property['gm_only']);
}

/**
 * Whether a property is owner-only: rendered on the sheet solely for viewers
 * who can edit this character — its author (the owning player) and game
 * masters — and omitted from the markup and REST responses for everyone
 * else: the public AND fellow players alike. Player-private notes live on
 * the character but must never reach a stranger's page source. gm_only
 * excludes the owning player; owner_only exists precisely to include them
 * (a GM sees both) — the two flags may not combine. Orthogonal to
 * always_show, same as gm_only: the audience gate still wins for a viewer
 * outside the audience. Truthy check for the same fail-closed reason as
 * chronicler_sheets_is_gm_only().
 */
function chronicler_sheets_is_owner_only(array $property): bool {
    return !empty($property['owner_only']);
}

/**
 * Whether a property always renders even when unfilled (chronicler_sheets_is_unfilled),
 * instead of being dropped from the sheet as a blank row — e.g. Player Notes
 * and GM Notes, which should read as an empty prompt rather than vanish.
 * Orthogonal to the audience flags (gm_only, owner_only): an always_show
 * property still disappears entirely for a viewer outside its audience —
 * the audience gate wins.
 * Intended for body/notes-style properties in ordinary sections. A masthead
 * trait flagged always_show but left empty is not specially handled: it will
 * still occupy the masthead's primary slot with a blank line rather than
 * yielding that slot to the next filled trait (see
 * chronicler_sheets_render_masthead in render.php).
 */
function chronicler_sheets_is_always_show(array $property): bool {
    return ($property['always_show'] ?? false) === true;
}

function chronicler_sheets_default_value(array $property) {
    switch ($property['type']) {
        case 'number':
            return $property['min'] ?? 0;
        case 'track':
            return 0;
        case 'counter':
            return $property['start'] ?? 0;
        case 'toggle':
            return false;
        case 'select':
            return $property['options'][0]['id'];
        case 'checklist':
            return [];
        case 'list':
            return [];
        default: // text, longtext
            return '';
    }
}

/**
 * Whether a string is unfilled for display: blank once trimmed, or wholly
 * wrapped in a single pair of square brackets — an authoring placeholder
 * (e.g. "[quantum backpack object 1]") that was never replaced with real
 * content.
 */
function chronicler_sheets_is_placeholder_text(string $value): bool {
    $trimmed = trim($value);
    return $trimmed === '' || preg_match('/^\[[^][]*\]$/su', $trimmed) === 1;
}

/**
 * Whether a property's current value counts as "unfilled" and should be
 * dropped from the public sheet as a blank row. Text is unfilled when blank
 * or a bare placeholder; a list is unfilled once its placeholder-only rows
 * are stripped (see chronicler_sheets_filter_placeholder_entries) and
 * nothing real remains. Every other type (number, track, counter, toggle,
 * select, checklist) always carries a meaningful value — zero, off, and the
 * first option are real game state, not "nothing entered" — so those are
 * never hidden this way. A property flagged always_show should bypass this
 * check entirely; see chronicler_sheets_is_always_show().
 */
function chronicler_sheets_is_unfilled(array $property, $value): bool {
    switch ($property['type']) {
        case 'text':
        case 'longtext':
            return chronicler_sheets_is_placeholder_text((string) $value);
        case 'list':
            return chronicler_sheets_filter_placeholder_entries($property, (array) $value) === [];
        default:
            return false;
    }
}

/**
 * A list's entries with placeholder-only rows dropped. A row is a
 * placeholder if every field is at its blank/default state: text left empty
 * or as a bracketed placeholder, and any other field type still equal to its
 * own chronicler_sheets_default_value(). One real field anywhere in the row
 * is enough to keep it.
 */
function chronicler_sheets_filter_placeholder_entries(array $property, array $entries): array {
    return array_values(array_filter($entries, function ($entry) use ($property) {
        foreach ($property['fields'] as $field) {
            $value = $entry[$field['id']] ?? chronicler_sheets_default_value($field);
            $blank = in_array($field['type'], ['text', 'longtext'], true)
                ? chronicler_sheets_is_placeholder_text((string) $value)
                : $value === chronicler_sheets_default_value($field);
            if (!$blank) {
                return true; // a real value — keep the row
            }
        }
        return false; // every field was blank or at its default — drop it
    }));
}

function chronicler_sheets_clamp(int $n, ?int $min, ?int $max): int {
    if ($min !== null && $n < $min) {
        return $min;
    }
    if ($max !== null && $n > $max) {
        return $max;
    }
    return $n;
}

/** [min, max] bounds for a numeric property; null means unbounded. */
function chronicler_sheets_bounds(array $property): array {
    switch ($property['type']) {
        case 'number':
            return [$property['min'] ?? null, $property['max'] ?? null];
        case 'track':
            return [0, $property['length']];
        case 'counter':
            return [0, $property['max'] ?? null];
    }
    return [null, null];
}

function chronicler_sheets_to_int($value) {
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^[+-]?\d+$/', trim($value))) {
        return (int) trim($value);
    }
    return null;
}

function chronicler_sheets_apply_op(array $property, $current, string $op, $value) {
    $type = $property['type'];
    if ($current === null || $current === '') {
        $current = chronicler_sheets_default_value($property);
    }
    $bad = function ($message) use ($property) {
        return new WP_Error('chronicler_bad_value', $property['label'] . ': ' . $message);
    };

    // Derived values are computed on read (#88); no write surface may set
    // one. This central gate covers REST ops and the Stat Block save.
    if (isset($property['derived'])) {
        return $bad('is computed from a formula and cannot be edited directly.');
    }

    if (!in_array($op, ['set', 'adjust', 'toggle'], true)) {
        return $bad("unknown operation \"$op\".");
    }

    if (in_array($type, ['number', 'track', 'counter'], true)) {
        if ($op === 'toggle') {
            return $bad('cannot toggle a numeric value.');
        }
        $n = chronicler_sheets_to_int($value);
        if ($n === null) {
            return $bad('needs a whole number.');
        }
        [$min, $max] = chronicler_sheets_bounds($property);
        return chronicler_sheets_clamp($op === 'adjust' ? ((int) $current) + $n : $n, $min, $max);
    }

    if ($type === 'toggle') {
        if ($op === 'toggle') {
            return !$current;
        }
        if ($op === 'adjust') {
            return $bad('cannot adjust an on/off value.');
        }
        if (is_bool($value)) {
            return $value;
        }
        $truthy = ['true', 'on', 'yes', '1'];
        $falsy = ['false', 'off', 'no', '0'];
        $s = is_string($value) ? strtolower(trim($value)) : null;
        if (in_array($s, $truthy, true)) {
            return true;
        }
        if (in_array($s, $falsy, true)) {
            return false;
        }
        return $bad('needs on or off.');
    }

    $optionIds = array_map(function ($o) {
        return $o['id'];
    }, $property['options'] ?? []);

    if ($type === 'select') {
        if ($op !== 'set') {
            return $bad('choose one of: ' . implode(', ', $optionIds) . '.');
        }
        if (!in_array($value, $optionIds, true)) {
            return $bad('"' . (is_string($value) ? $value : '?') . '" is not one of: ' . implode(', ', $optionIds) . '.');
        }
        return $value;
    }

    if ($type === 'checklist') {
        $current = is_array($current) ? array_values($current) : [];
        // `/sheet moves crime_pays` arrives as a set with an option id; the
        // ergonomic reading is toggle.
        if ($op === 'set' && is_string($value) && in_array($value, $optionIds, true)) {
            $op = 'toggle';
        }
        if ($op === 'toggle') {
            if (!is_string($value) || !in_array($value, $optionIds, true)) {
                return $bad('"' . (is_string($value) ? $value : '?') . '" is not one of: ' . implode(', ', $optionIds) . '.');
            }
            $without = array_values(array_diff($current, [$value]));
            return count($without) < count($current) ? $without : array_merge($current, [$value]);
        }
        if ($op === 'set') {
            if (!is_array($value)) {
                return $bad('needs a list of option ids or one id to toggle.');
            }
            foreach ($value as $v) {
                if (!in_array($v, $optionIds, true)) {
                    return $bad('"' . (is_string($v) ? $v : '?') . '" is not one of: ' . implode(', ', $optionIds) . '.');
                }
            }
            return array_values(array_unique($value));
        }
        return $bad('cannot adjust a checklist.');
    }

    if ($type === 'list') {
        if ($op !== 'set') {
            return $bad('lists are replaced whole — edit them in wp-admin.');
        }
        if (!is_array($value)) {
            return $bad('needs a list of entries.');
        }
        $entries = [];
        foreach (array_values($value) as $entry) {
            if (!is_array($entry)) {
                return $bad('each entry must be an object.');
            }
            $clean = [];
            foreach ($property['fields'] as $field) {
                $fid = $field['id'];
                if (!array_key_exists($fid, $entry)) {
                    $clean[$fid] = chronicler_sheets_default_value($field);
                    continue;
                }
                $result = chronicler_sheets_apply_op($field, null, 'set', $entry[$fid]);
                if (is_wp_error($result)) {
                    return $result;
                }
                $clean[$fid] = $result;
            }
            // Unknown keys are dropped, mirroring the parser's permissiveness.
            $entries[] = $clean;
        }
        return $entries;
    }

    // text, longtext
    if ($op !== 'set') {
        return $bad('text can only be set.');
    }
    if (!is_string($value)) {
        return $bad('needs text.');
    }
    return trim($value);
}

function chronicler_sheets_display_value(array $property, $value): string {
    if ($value === null || $value === '') {
        $value = chronicler_sheets_default_value($property);
    }
    switch ($property['type']) {
        case 'track':
            return $value . '/' . $property['length'];
        case 'counter':
            return isset($property['max']) ? $value . '/' . $property['max'] : (string) $value;
        case 'number':
            // Stats that can go negative read better signed (+2 / -1).
            $signed = ($property['min'] ?? 0) < 0;
            return $signed && $value > 0 ? '+' . $value : (string) $value;
        case 'toggle':
            return $value ? 'on' : 'off';
        case 'select':
            foreach ($property['options'] as $opt) {
                if ($opt['id'] === $value) {
                    return $opt['label'];
                }
            }
            return (string) $value;
        case 'checklist':
            return count((array) $value) . '/' . count($property['options']) . ' checked';
        case 'list':
            $n = count((array) $value);
            return $n === 1 ? '1 entry' : $n . ' entries';
        default:
            return (string) $value;
    }
}

/**
 * Layout sections with every unreferenced property collected into a trailing
 * "Other" section. Shared by the front-end renderer and the Stat Block meta
 * box so both surfaces group properties identically.
 */
function chronicler_sheets_layout_sections(array $template): array {
    $sections = $template['layout'];
    $placed = $sections ? array_merge(...array_map(function ($s) {
        return $s['properties'];
    }, $sections)) : [];
    $rest = array_diff(array_keys($template['properties']), $placed);
    if ($rest) {
        $sections[] = ['section' => 'Other', 'properties' => array_values($rest), 'masthead' => false];
    }
    return $sections;
}

/**
 * The template's layout with audience-gated properties removed for viewers
 * outside each flag's audience, and any section left empty by that removal
 * dropped. The gates are applied per-flag so the layout always agrees with
 * the value gates in rest.php: gm_only ids survive only on $is_gm, and
 * owner_only ids only on $can_edit — never on $is_gm alone, which a
 * misconfigured role can hold without edit_post on this character. A normal
 * GM holds both and gets the layout unchanged. This applies only the
 * audience gates, not the front-end render's fuller gating: the front end
 * also hides properties that are unfilled (chronicler_sheets_is_unfilled),
 * but REST/edit surfaces deliberately do not — a GM or owning player
 * editing the sheet needs to see every visible property, filled or not,
 * to fill it in.
 */
function chronicler_sheets_visible_layout(array $template, bool $is_gm, bool $can_edit): array {
    if ($is_gm && $can_edit) {
        return $template['layout'];
    }
    $sections = [];
    foreach ($template['layout'] as $section) {
        $section['properties'] = array_values(array_filter(
            $section['properties'],
            function ($pid) use ($template, $is_gm, $can_edit) {
                $property = $template['properties'][$pid];
                if (!$is_gm && chronicler_sheets_is_gm_only($property)) {
                    return false;
                }
                return $can_edit || !chronicler_sheets_is_owner_only($property);
            }
        ));
        if ($section['properties'] !== []) {
            $sections[] = $section;
        }
    }
    return $sections;
}
