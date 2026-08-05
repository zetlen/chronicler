<?php
// Schema logic for character sheets: parsing, defaults, ops, display.
// Pure functions only (WP_Error aside) — behaviorally tested without
// WordPress by tests/run.php, and shared by every write surface.

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

const CHRONICLER_SHEETS_TYPES = ['number', 'track', 'counter', 'toggle', 'select', 'checklist', 'text', 'longtext', 'list', 'opinions', 'dice'];
const CHRONICLER_SHEETS_LIST_FIELD_TYPES = ['text', 'longtext', 'number', 'toggle', 'select', 'dice'];
const CHRONICLER_SHEETS_ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

// Every key a property / list-field / option spec may carry, in the order
// template.schema.json declares them (schema-drift.test.php pins the lists
// together). The write path rejects anything else: an unrecognized key is
// most dangerous when it's a typo'd audience flag, which used to save
// silently and publish the very value it meant to hide.
const CHRONICLER_SHEETS_PROPERTY_KEYS = ['id', 'label', 'type', 'live', 'gm_only', 'owner_only', 'always_show', 'detail', 'derived', 'min', 'max', 'start', 'length', 'options', 'fields', 'entry_label', 'label_field', 'traits'];
const CHRONICLER_SHEETS_LIST_FIELD_KEYS = ['id', 'label', 'type', 'when', 'min', 'max', 'options', 'traits'];
const CHRONICLER_SHEETS_OPTION_KEYS = ['id', 'label'];
const CHRONICLER_SHEETS_SECTION_KEYS = ['id', 'section', 'properties', 'masthead'];
const CHRONICLER_SHEETS_ROLL_KEYS = ['id', 'label', 'section', 'dice', 'detail', 'traits'];
const CHRONICLER_SHEETS_EFFECT_KEYS = ['id', 'label', 'detail', 'modifier', 'applies_to', 'cap'];

/**
 * The names a roll answers to at evaluation time — its own keys plus `uses`
 * (the property ids its dice reach). A roll's author-defined `traits` are
 * flattened in alongside them, so a trait by one of these names would shadow
 * the real thing silently: the validator refuses it instead
 * (chronicler_sheets_validate_traits).
 */
const CHRONICLER_SHEETS_RESERVED_ROLL_KEYS = ['id', 'label', 'section', 'dice', 'detail', 'uses', 'traits'];
/** The whole document's keys — the last allowlist the parser was missing. */
const CHRONICLER_SHEETS_TOP_KEYS = ['system', 'version', 'properties', 'layout', 'rolls', 'effects'];

/**
 * Property types a roll's {…} placeholder may add up. A roll produces a
 * number, so only properties that ARE numbers can contribute to one — text,
 * select and the rest have no arithmetic meaning at the table.
 */
const CHRONICLER_SHEETS_ROLL_REF_TYPES = ['number', 'track', 'counter'];

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
 * A layout section's machine id derived from its heading — the same id/label
 * split properties use, applied to sections. Lowercase, every run of
 * non-alphanumerics collapsed to one "_", the result trimmed of "_":
 * "Moves & Gear" → "moves_gear". Null when what comes out cannot satisfy
 * CHRONICLER_SHEETS_ID_PATTERN (a heading starting with a digit, one with no
 * letters at all), which the parser turns into "name this section yourself"
 * rather than inventing something the author never wrote.
 */
function chronicler_sheets_section_id(string $heading): ?string {
    $id = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($heading)), '_');
    return preg_match(CHRONICLER_SHEETS_ID_PATTERN, $id) ? $id : null;
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
 * A `traits` map — the semi-open corner of an otherwise closed roll object.
 * Authors label what a roll IS ("save: dexterity", "check: true") so effects
 * can target a category, without every system's vocabulary having to become a
 * core key. Names follow the property id pattern and values are scalars, since
 * an effect expression only ever compares them. Two refusals earn their keep:
 * a name outside the pattern is unreachable from an expression, and a name
 * that shadows a reserved roll key (CHRONICLER_SHEETS_RESERVED_ROLL_KEYS)
 * would quietly outrank the real one at evaluation time. Returns WP_Error
 * naming the offending trait, or null. $where prefixes the message.
 */
function chronicler_sheets_validate_traits($traits, string $where): ?WP_Error {
    if (!is_array($traits)) {
        return new WP_Error('chronicler_invalid_template', "$where: \"traits\" must be a map of names to values, e.g. {save: dexterity}.");
    }
    foreach ($traits as $key => $value) {
        if (!is_string($key) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $key)) {
            return new WP_Error('chronicler_invalid_template', "$where: trait \"$key\" must match [a-z][a-z0-9_]*.");
        }
        if (in_array($key, CHRONICLER_SHEETS_RESERVED_ROLL_KEYS, true)) {
            return new WP_Error('chronicler_invalid_template', "$where: trait \"$key\" is one of a roll's own names — call it something else.");
        }
        if (!is_string($value) && !is_int($value) && !is_bool($value)) {
            return new WP_Error('chronicler_invalid_template', "$where: trait \"$key\" must be text, a whole number or true/false.");
        }
    }
    return null;
}

/**
 * Every trait name declared anywhere in a template — on a roll, on a dice
 * property, on a dice list field — as one flat, deduplicated list.
 *
 * This union is what an effect expression may ask a roll about. It exists
 * because targeting is written once and evaluated against every roll: an
 * effect keyed on `save` must be answerable on a roll that has no `save`, so
 * the roll context null-fills the whole union and the fence checks names
 * against it. Declared somewhere means askable everywhere; declared nowhere
 * is a typo. Junk names are skipped rather than refused — the write path
 * already refused them, and a lenient read must not fail here.
 */
function chronicler_sheets_template_traits(array $template): array {
    $keys = [];
    $collect = function ($traits) use (&$keys): void {
        foreach ((array) $traits as $key => $value) {
            if (is_string($key) && preg_match(CHRONICLER_SHEETS_ID_PATTERN, $key)) {
                $keys[$key] = true;
            }
        }
    };
    foreach (($template['properties'] ?? []) as $property) {
        $collect($property['traits'] ?? []);
        foreach ((array) ($property['fields'] ?? []) as $field) {
            $collect(is_array($field) ? ($field['traits'] ?? []) : []);
        }
    }
    foreach (($template['rolls'] ?? []) as $roll) {
        $collect(is_array($roll) ? ($roll['traits'] ?? []) : []);
    }
    return array_keys($keys);
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
    // The document's own keys, allowlisted like every nested spec's are: a
    // mistyped "rols:" or a stale "rules:" used to save silently and do
    // nothing, since only template.schema.json ever objected.
    if (!$lenient) {
        $err = chronicler_sheets_unknown_key_error($data, CHRONICLER_SHEETS_TOP_KEYS, 'Template');
        if ($err !== null) {
            return $err;
        }
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
        // "entry" is reserved for entry-scoped roll formulas (a dice field's
        // {entry["…"]}, 2026-07-25) — a property by that name would shadow the
        // namespace. List FIELD ids stay free; entry scoping never nests.
        // Lenient reads keep a stored one rendering, as always.
        if (!$lenient && $id === 'entry') {
            return new WP_Error('chronicler_invalid_template', 'Property "entry": that id is reserved — formulas use entry["…"] to reach a list entry\'s own fields. Name the property something else.');
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
        // Opinions (#183) are per-player-character by construction: each set is
        // a personal notebook — read and written by ITS PC's owning player (and
        // GMs) — so the property-wide write flag and audience flags contradict
        // the type. `live` would say "whoever can edit this character may
        // write" (the per-set gate is per-PC instead), and gm_only/owner_only
        // would gate on the SUBJECT character's editors when each set already
        // carries its own audience. Refuse the combinations on the write path;
        // a lenient read degrades the way each flag's own polarity demands:
        // live is dropped (never widen writes), the audience flags are KEPT
        // (the generic gates then hide the sets from more viewers than intended
        // — hidden beats leaked, same reasoning as owner_only above).
        if ($type === 'opinions' && ($prop['live'] ?? false)) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": opinions are always editable by each player character's own player — no \"live\" flag needed.");
            }
            $prop['live'] = false;
        }
        if ($type === 'opinions' && (($prop['gm_only'] ?? false) || !empty($prop['owner_only']))) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"gm_only\"/\"owner_only\" don't apply to opinions — each player's set is already private to them and the GM.");
            }
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
        // "traits" label a ROLL for effect targeting, and the only property
        // that is itself rollable is a dice pool — so on anything else the map
        // would sit there meaning nothing, the same silent-no-op the key
        // allowlists exist to catch.
        if (isset($prop['traits'])) {
            if ($type !== 'dice') {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"traits\" only applies to dice properties — they label the roll a property offers.");
            }
            $err = chronicler_sheets_validate_traits($prop['traits'], "Property \"$id\"");
            if ($err !== null) {
                return $err;
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
    $section_ids = [];
    foreach (($data['layout'] ?? []) as $i => $section) {
        $where = '"layout" entry ' . ($i + 1);
        if (!is_array($section) || !is_string($section['section'] ?? null) || !is_array($section['properties'] ?? null)) {
            return new WP_Error('chronicler_invalid_template', "$where must be {\"section\": name, \"properties\": [ids]}.");
        }
        // Sections had no key allowlist until section ids arrived, so a
        // typo'd "mastheed: true" saved silently and then did nothing at the
        // table — the same hazard the property allowlist closed.
        if (!$lenient) {
            $err = chronicler_sheets_unknown_key_error($section, CHRONICLER_SHEETS_SECTION_KEYS, $where);
            if ($err !== null) {
                return $err;
            }
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
        // The section's machine name: what /game my resolves "stats" against.
        // Authored id wins, else it derives from the heading. It has to exist
        // and it has to be unique, because a section nobody can name — or two
        // sections answering to one name — is a section nobody can ask for.
        $sid = $section['id'] ?? null;
        if ($sid !== null && (!is_string($sid) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $sid))) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "$where: \"id\" must be lowercase letters, digits and underscores, starting with a letter.");
            }
            $sid = null;
        }
        if ($sid === null) {
            $sid = chronicler_sheets_section_id($section['section']);
        }
        if ($sid === null || isset($section_ids[$sid])) {
            if (!$lenient) {
                return $sid === null
                    ? new WP_Error('chronicler_invalid_template', "$where: no id can be derived from the heading \"{$section['section']}\" — give the section an \"id\".")
                    : new WP_Error('chronicler_invalid_template', "$where: duplicate section id \"$sid\" — give one of the two an explicit \"id\".");
            }
            // A template stored before ids existed keeps rendering: a
            // positional id is addressable, just not memorable.
            $sid = 'section_' . (count($layout) + 1);
            while (isset($section_ids[$sid])) {
                $sid .= '_';
            }
        }
        $section_ids[$sid] = true;
        $layout[] = [
            'id' => $sid,
            'section' => $section['section'],
            'properties' => array_values($section['properties']),
            'masthead' => (bool) ($section['masthead'] ?? false),
        ];
    }

    // "rolls" (2026-07-25): the named things a character does — "Act Under
    // Pressure", "Longsword" — each with its own dice and its own id
    // namespace, so /game roll resolves a name the same disciplined way
    // /game my does. Checked HERE, at save, with every property declared:
    // that is the whole advantage of a declared table over a free-text field,
    // and it turns a typo'd {col} into an editor error instead of a mystery
    // at the table.
    $rolls = [];
    $declared_rolls = $data['rolls'] ?? [];
    if (!is_array($declared_rolls)) {
        if (!$lenient) {
            return new WP_Error('chronicler_invalid_template', '"rolls" must be a list of rolls.');
        }
        $declared_rolls = [];
    }
    foreach ($declared_rolls as $i => $roll) {
        $parsed_roll = chronicler_sheets_parse_roll($roll, $draft, '"rolls" entry ' . ($i + 1), $lenient);
        if (is_wp_error($parsed_roll)) {
            if (!$lenient) {
                return $parsed_roll;
            }
            // A roll nobody can roll must not cost the sheet its render (#140):
            // drop the roll, keep the character.
            continue;
        }
        if (isset($rolls[$parsed_roll['id']])) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "Duplicate roll id \"{$parsed_roll['id']}\".");
            }
            continue;
        }
        $rolls[$parsed_roll['id']] = $parsed_roll;
    }

    // "effects" (2026-08-04): the modifiers this system knows how to hand
    // out. A template declares only the VOCABULARY — nothing lands on a
    // character until a game master applies it, and what an applied instance
    // does is read back from here at roll time, so editing a definition
    // retroactively fixes every instance of it. Checked after rolls because
    // an effect's targeting is written in terms of them.
    $effects = [];
    $declared_effects = $data['effects'] ?? [];
    if (!is_array($declared_effects)) {
        if (!$lenient) {
            return new WP_Error('chronicler_invalid_template', '"effects" must be a list of effects.');
        }
        $declared_effects = [];
    }
    $trait_keys = chronicler_sheets_template_traits(['properties' => $properties, 'rolls' => $rolls]);
    foreach ($declared_effects as $i => $effect) {
        $parsed_effect = chronicler_sheets_parse_effect($effect, $draft, $trait_keys, '"effects" entry ' . ($i + 1), $lenient);
        if (is_wp_error($parsed_effect)) {
            if (!$lenient) {
                return $parsed_effect;
            }
            // An effect nobody can apply must not cost the sheet its render
            // (#140), exactly like a roll nobody can roll: drop it, keep the
            // character. Its instances then read as unknown and get flagged.
            continue;
        }
        if (isset($effects[$parsed_effect['id']])) {
            if (!$lenient) {
                return new WP_Error('chronicler_invalid_template', "Duplicate effect id \"{$parsed_effect['id']}\".");
            }
            continue;
        }
        $effects[$parsed_effect['id']] = $parsed_effect;
    }

    return [
        'system' => $data['system'],
        'version' => $data['version'],
        'properties' => $properties,
        'layout' => $layout,
        'rolls' => $rolls,
        'effects' => $effects,
    ];
}

/**
 * One `effects` entry, normalized to
 * ['id', 'label', 'detail'|null, 'modifier', 'applies_to'|null, 'cap'|null]
 * — or a WP_Error naming the problem. $draft is the properties-only template
 * an expression modifier is fenced against.
 *
 * `modifier` is the whole behavior and is required in one of two forms. An
 * INTEGER is the sugar: "contribute this much, times the instance's amount,
 * to every roll `applies_to` names" — one target word, matched against the
 * roll's id, its label, a truthy trait, then the properties its dice reach.
 * A STRING is an expression in the same fenced language as `derived`, which
 * decides for itself which rolls it touches; `applies_to` alongside one is
 * refused rather than silently ignored, because an author who wrote both
 * believes both are doing something.
 *
 * `cap` bounds the SUM of one effect id's stacked instances (two Taunts stop
 * at -2) — the rule that otherwise drifts between prose and a property bound.
 *
 * $trait_keys is the template's declared-trait union: an expression may ask a
 * roll about any of them and about a roll's own names, and about nothing else
 * (chronicler_sheets_formula_effect_scope), so a misspelled trait is an error
 * here rather than an effect that quietly never fires.
 */
function chronicler_sheets_parse_effect($effect, array $draft, array $trait_keys, string $where, bool $lenient = false) {
    if (!is_array($effect)) {
        return new WP_Error('chronicler_invalid_template', "$where must be an object.");
    }
    $id = $effect['id'] ?? null;
    if (!is_string($id) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $id)) {
        return new WP_Error('chronicler_invalid_template', "$where: \"id\" must match [a-z][a-z0-9_]*.");
    }
    if (!$lenient) {
        $err = chronicler_sheets_unknown_key_error($effect, CHRONICLER_SHEETS_EFFECT_KEYS, "Effect \"$id\"");
        if ($err !== null) {
            return $err;
        }
    }
    if (!is_string($effect['label'] ?? null) || $effect['label'] === '') {
        return new WP_Error('chronicler_invalid_template', "Effect \"$id\": \"label\" must be a non-empty string.");
    }
    if (isset($effect['detail']) && (!is_string($effect['detail']) || $effect['detail'] === '')) {
        return new WP_Error('chronicler_invalid_template', "Effect \"$id\": \"detail\" must be a non-empty string.");
    }
    // is_int, not is_numeric: true reads as 1 and "-1" reads as -1 in PHP's
    // looser comparisons, and an effect that quietly means something other
    // than what it says is the failure the whole design is arguing against.
    $modifier = $effect['modifier'] ?? null;
    $expression = is_string($modifier) && trim($modifier) !== '' ? trim($modifier) : null;
    if (!is_int($modifier) && $expression === null) {
        return new WP_Error('chronicler_invalid_template', "Effect \"$id\": \"modifier\" must be a whole number (how much it contributes) or a formula string.");
    }
    $applies_to = $effect['applies_to'] ?? null;
    if ($applies_to !== null) {
        if (!is_string($applies_to) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $applies_to)) {
            return new WP_Error('chronicler_invalid_template', "Effect \"$id\": \"applies_to\" must be one target word — a roll id, a roll label, a trait or a property id.");
        }
        if ($expression !== null) {
            return new WP_Error('chronicler_invalid_template', "Effect \"$id\": \"applies_to\" goes with a whole-number modifier — a formula picks its own rolls, so one of the two isn't doing anything.");
        }
    }
    if (isset($effect['cap']) && !is_int($effect['cap'])) {
        return new WP_Error('chronicler_invalid_template', "Effect \"$id\": \"cap\" must be a whole number — the most this effect's stacked instances can add up to.");
    }
    if ($expression !== null) {
        // The two names an effect expression gets that a derived formula
        // doesn't are planted over property scope, so a system that already
        // has one is told to rename it rather than losing the property
        // silently inside every effect it writes.
        foreach (['roll', 'amount'] as $reserved) {
            if (isset($draft['properties'][$reserved])) {
                return new WP_Error('chronicler_invalid_template', "Effect \"$id\": a formula reads the roll it's touching as \"roll\" and its own magnitude as \"amount\", and this system has a property named \"$reserved\" — rename the property.");
            }
        }
        $scope = chronicler_sheets_formula_effect_scope($draft, $trait_keys);
        $checked = chronicler_sheets_formula_check($expression, $scope);
        if (is_wp_error($checked)) {
            return new WP_Error('chronicler_invalid_template', "Effect \"$id\": " . $checked->get_error_message());
        }
        // The dry run derived formulas get, for the same reason: an
        // expression over perfectly good names can still produce something
        // that is not a number, and an effect discovering that mid-roll
        // refuses the roll in front of the table. Against a roll with nothing
        // filled in — every trait null — which is the shape most effects are
        // supposed to answer 0 to. Skipped when the run itself fails.
        $dry = chronicler_sheets_formula_evaluate($expression, chronicler_sheets_formula_effect_context(
            chronicler_sheets_formula_context($draft, []),
            [],
            1,
            $trait_keys
        ));
        if (!is_wp_error($dry) && !is_numeric($dry)) {
            return new WP_Error('chronicler_invalid_template', "Effect \"$id\": the formula must produce a number — how much this effect adds to the roll (0 to leave it alone).");
        }
    }
    return [
        'id' => $id,
        'label' => $effect['label'],
        'detail' => $effect['detail'] ?? null,
        'modifier' => $expression ?? $modifier,
        'applies_to' => $applies_to,
        'cap' => $effect['cap'] ?? null,
    ];
}

/**
 * One `rolls` entry, normalized to
 * ['id', 'label', 'section'|null, 'dice', 'detail'|null, 'parsed']
 * — where 'parsed' is chronicler_sheets_parse_dice()'s term list, so nothing
 * reparses dice at roll time — or a WP_Error naming the problem. $draft is
 * the properties-only template the placeholder fence checks against.
 *
 * A roll once took a `when` (2026-07-25 §4b, removed the same day): a
 * per-character gate whose only real case — a playbook move changing a
 * move — is a dice field on the character's own list entry now. A stored
 * template that declared one reads leniently like any unknown key: the
 * key drops, the roll stays.
 */
function chronicler_sheets_parse_roll($roll, array $draft, string $where, bool $lenient = false) {
    if (!is_array($roll)) {
        return new WP_Error('chronicler_invalid_template', "$where must be an object.");
    }
    $id = $roll['id'] ?? null;
    if (!is_string($id) || !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $id)) {
        return new WP_Error('chronicler_invalid_template', "$where: \"id\" must match [a-z][a-z0-9_]*.");
    }
    if (!$lenient) {
        $err = chronicler_sheets_unknown_key_error($roll, CHRONICLER_SHEETS_ROLL_KEYS, "Roll \"$id\"");
        if ($err !== null) {
            return $err;
        }
    }
    if (!is_string($roll['label'] ?? null) || $roll['label'] === '') {
        return new WP_Error('chronicler_invalid_template', "Roll \"$id\": \"label\" must be a non-empty string.");
    }
    // "section" groups the /game roll listing once a system has more than a
    // handful. Free text with no id, unlike a layout section: nothing
    // addresses a roll section, it only orders output.
    foreach (['section', 'detail'] as $optional) {
        if (isset($roll[$optional]) && (!is_string($roll[$optional]) || $roll[$optional] === '')) {
            return new WP_Error('chronicler_invalid_template', "Roll \"$id\": \"$optional\" must be a non-empty string.");
        }
    }
    $dice = $roll['dice'] ?? null;
    if (!is_string($dice) || trim($dice) === '') {
        return new WP_Error('chronicler_invalid_template', "Roll \"$id\": \"dice\" must be dice notation, e.g. \"2d6 + {cool}\".");
    }
    $parsed = chronicler_sheets_parse_dice($dice);
    if (is_wp_error($parsed)) {
        return new WP_Error('chronicler_invalid_template', "Roll \"$id\": " . $parsed->get_error_message());
    }
    foreach (chronicler_sheets_dice_placeholders($parsed) as $expression) {
        $err = chronicler_sheets_check_roll_placeholder($expression, $draft, "Roll \"$id\"");
        if ($err !== null) {
            return $err;
        }
    }
    // "traits" (2026-08-04): what this roll IS, in the author's own words, so
    // an effect can target a category rather than reciting a list of rolls.
    // Always present on the way out, empty when undeclared — every consumer
    // reads the same shape whether or not the system has an opinion.
    if (isset($roll['traits'])) {
        $err = chronicler_sheets_validate_traits($roll['traits'], "Roll \"$id\"");
        if ($err !== null) {
            return $err;
        }
    }
    return [
        'id' => $id,
        'label' => $roll['label'],
        'section' => $roll['section'] ?? null,
        'dice' => trim($dice),
        'detail' => $roll['detail'] ?? null,
        'traits' => is_array($roll['traits'] ?? null) ? $roll['traits'] : [],
        'parsed' => $parsed,
    ];
}

/**
 * One {…} placeholder from a roll: a real Expression Language expression, run
 * through exactly the fence, reference check and dry run `derived` uses, plus
 * the one rule rolls add — what it references must be a number, since the
 * result is added to dice. Returns WP_Error or null.
 */
function chronicler_sheets_check_roll_placeholder(string $expression, array $draft, string $where): ?WP_Error {
    // …with one placeholder that is not an expression at all (2026-08-04): a
    // bare dice-property id SPLICES that character's own notation into the
    // roll, so it never reaches the engine and has no number to produce. Any
    // other use of a pool falls through to the fence, which refuses it.
    if (chronicler_sheets_formula_pool_ref($expression, $draft) !== null) {
        return null;
    }
    $checked = chronicler_sheets_formula_check($expression, $draft);
    if (is_wp_error($checked)) {
        return new WP_Error('chronicler_invalid_template', "$where: {" . $expression . '}: ' . $checked->get_error_message());
    }
    foreach ($checked['refs'] as $ref) {
        $type = $draft['properties'][$ref]['type'] ?? null;
        if (!in_array($type, CHRONICLER_SHEETS_ROLL_REF_TYPES, true)) {
            $is = $type === null ? 'is not a declared property' : "is a $type property";
            return new WP_Error(
                'chronicler_invalid_template',
                "$where: {" . $expression . "}: \"$ref\" $is — a roll can only add numbers ("
                    . implode(', ', CHRONICLER_SHEETS_ROLL_REF_TYPES) . ').'
            );
        }
    }
    // The dry run catches what the reference check can't see: an expression
    // over perfectly numeric properties that still produces something you
    // can't add to dice, e.g. a comparison. Skipped when default values make
    // the run itself fail, exactly as derived's dry run is.
    $dry = chronicler_sheets_formula_evaluate($expression, chronicler_sheets_formula_context($draft, []));
    if (!is_wp_error($dry) && !is_numeric($dry)) {
        return new WP_Error('chronicler_invalid_template', "$where: {" . $expression . '}: must produce a number to add to the dice.');
    }
    return null;
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
    if (($type === 'track' || $type === 'opinions') && (!is_int($prop['length'] ?? null) || $prop['length'] < 1)) {
        return new WP_Error('chronicler_invalid_template', "Property \"$id\": $type needs a positive integer \"length\".");
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
    // "label_field" designates which field names an entry in roll menus
    // (2026-07-25). It only means something on a list, and a wrong
    // designation must be a save error, not a silently wrong label.
    if (isset($prop['label_field']) && $type !== 'list') {
        return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"label_field\" only applies to list properties.");
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
            // A dice field's traits ride every roll its entries contribute —
            // tag the field once and every weapon on every sheet is an
            // "attack". On any other field type there is no roll to label,
            // which is the same silent no-op a dice property's traits are
            // refused for (2026-08-04).
            if (isset($field['traits'])) {
                if ($ftype !== 'dice') {
                    return new WP_Error('chronicler_invalid_template', "Property \"$id\": field \"$fid\": \"traits\" only applies to dice fields — they label the roll an entry contributes.");
                }
                $err = chronicler_sheets_validate_traits($field['traits'], "Property \"$id\": field \"$fid\"");
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
        if (isset($prop['label_field'])) {
            $lf = $prop['label_field'];
            if (!is_string($lf) || !isset($fieldIds[$lf])) {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"label_field\" must name one of this list's fields.");
            }
            if ($fieldIds[$lf] !== 'text') {
                return new WP_Error('chronicler_invalid_template', "Property \"$id\": \"label_field\" must name a text field — \"$lf\" is a {$fieldIds[$lf]} field.");
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
 * One player character's stored opinion set (#183), normalized: rating an int
 * clamped to the property's track bounds, notes a string. Tolerates any raw
 * shape (missing meta, junk) so every read surface agrees on the fallback.
 */
function chronicler_sheets_normalize_opinion(array $property, $raw): array {
    $set = is_array($raw) ? $raw : [];
    $rating = chronicler_sheets_to_int($set['rating'] ?? 0) ?? 0;
    return [
        'rating' => chronicler_sheets_clamp($rating, 0, $property['length']),
        'notes' => is_string($set['notes'] ?? null) ? $set['notes'] : '',
    ];
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
        case 'opinions':
            return []; // pc id => set; sets materialize as players write them
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

    // Opinions (#183) are written one PC's set at a time, each behind its own
    // per-PC permission — never as one whole-property value through this
    // generic path. The opinions endpoint and Stat Block save handle them.
    if ($type === 'opinions') {
        return $bad('is recorded per player character — edit each opinion on the page itself.');
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
        case 'opinions':
            $filled = 0;
            foreach ((array) $value as $set) {
                $set = chronicler_sheets_normalize_opinion($property, $set);
                if ($set['rating'] > 0 || trim($set['notes']) !== '') {
                    $filled++;
                }
            }
            return $filled === 1 ? '1 opinion' : $filled . ' opinions';
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
        $sections[] = ['id' => 'other', 'section' => 'Other', 'properties' => array_values($rest), 'masthead' => false];
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
