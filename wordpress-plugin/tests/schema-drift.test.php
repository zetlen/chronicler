<?php
// Drift guards for sheets/template.schema.json (#138). Included by run.php after
// schema.php. Check #1 (this file) needs no Composer deps; check #2 (appended in
// a later task) needs opis/json-schema.

$chr_schema_path = __DIR__ . '/../sheets/template.schema.json';
$chr_schema_raw = @file_get_contents($chr_schema_path);
$chr_schema = $chr_schema_raw ? json_decode($chr_schema_raw, true) : null;

check('template.schema.json exists and is valid JSON', is_array($chr_schema));

check(
    'schema property type enum matches CHRONICLER_SHEETS_TYPES (same values, same order)',
    is_array($chr_schema)
        && ($chr_schema['definitions']['property']['properties']['type']['enum'] ?? null) === CHRONICLER_SHEETS_TYPES
);

check(
    'schema list-field type enum matches CHRONICLER_SHEETS_LIST_FIELD_TYPES',
    is_array($chr_schema)
        && ($chr_schema['definitions']['listField']['properties']['type']['enum'] ?? null) === CHRONICLER_SHEETS_LIST_FIELD_TYPES
);

check(
    'schema id pattern matches CHRONICLER_SHEETS_ID_PATTERN',
    is_array($chr_schema)
        && '/' . ($chr_schema['definitions']['property']['properties']['id']['pattern'] ?? '') . '/' === CHRONICLER_SHEETS_ID_PATTERN
);

check(
    'schema markdownEnumDescriptions covers every type',
    is_array($chr_schema)
        && count($chr_schema['definitions']['property']['properties']['type']['markdownEnumDescriptions'] ?? []) === count(CHRONICLER_SHEETS_TYPES)
);
check(
    'schema listField markdownEnumDescriptions covers every list-field type',
    is_array($chr_schema)
        && count($chr_schema['definitions']['listField']['properties']['type']['markdownEnumDescriptions'] ?? []) === count(CHRONICLER_SHEETS_LIST_FIELD_TYPES)
);
// The PHP parser rejects unknown keys on the write path using these
// allowlists; the schema rejects them structurally (additionalProperties:
// false). Pinning key lists AND order keeps the two authorities identical.
check(
    'schema property keys match CHRONICLER_SHEETS_PROPERTY_KEYS (same values, same order)',
    is_array($chr_schema)
        && array_keys($chr_schema['definitions']['property']['properties'] ?? []) === CHRONICLER_SHEETS_PROPERTY_KEYS
);
check(
    'schema listField keys match CHRONICLER_SHEETS_LIST_FIELD_KEYS',
    is_array($chr_schema)
        && array_keys($chr_schema['definitions']['listField']['properties'] ?? []) === CHRONICLER_SHEETS_LIST_FIELD_KEYS
);
check(
    'schema option keys match CHRONICLER_SHEETS_OPTION_KEYS',
    is_array($chr_schema)
        && array_keys($chr_schema['definitions']['option']['properties'] ?? []) === CHRONICLER_SHEETS_OPTION_KEYS
);

$chr_id_pattern = $chr_schema['definitions']['property']['properties']['id']['pattern'] ?? null;
check(
    'schema option id pattern matches the property id pattern',
    is_array($chr_schema) && ($chr_schema['definitions']['option']['properties']['id']['pattern'] ?? null) === $chr_id_pattern
);
check(
    'schema listField id pattern matches the property id pattern',
    is_array($chr_schema) && ($chr_schema['definitions']['listField']['properties']['id']['pattern'] ?? null) === $chr_id_pattern
);

// --- Drift check #3: formula vocabulary (#149) -------------------------------
// The Phase B editor's formula autocomplete and lint hints read the
// x-chronicler-formula block from the schema (annotation-only: opis and the
// editor's structural validator both ignore unknown keywords). These guards
// keep that block literally equal to the PHP formula engine's constants.
$chr_formula = is_array($chr_schema) ? ($chr_schema['x-chronicler-formula'] ?? null) : null;
check(
    'schema x-chronicler-formula function names match CHRONICLER_FORMULA_FUNCTIONS',
    is_array($chr_formula)
        && array_map(function ($f) {
            return is_array($f) ? ($f['name'] ?? null) : null;
        }, $chr_formula['functions'] ?? []) === CHRONICLER_FORMULA_FUNCTIONS
);
check(
    'schema x-chronicler-formula refTypes match CHRONICLER_FORMULA_REF_TYPES',
    is_array($chr_formula) && ($chr_formula['refTypes'] ?? null) === CHRONICLER_FORMULA_REF_TYPES
);
check(
    'schema x-chronicler-formula functions all carry author docs',
    is_array($chr_formula) && array_filter($chr_formula['functions'] ?? [], function ($f) {
        return !is_string($f['doc'] ?? null) || $f['doc'] === '' || !is_string($f['args'] ?? null);
    }) === []
);

// --- Drift check #4: list-field formula entry vocabulary (single-expression-conditions) --
// CHRONICLER_FORMULA_ENTRY_REF_TYPES (formulas.php) is the set of list-field
// types a field-scoped `when` expression may reference. Semantically it's
// "every CHRONICLER_SHEETS_LIST_FIELD_TYPES entry except longtext" (longtext
// has no meaningful expression value), and CHRONICLER_SHEETS_LIST_FIELD_TYPES
// is already pinned to the schema's listField.type enum above, so this keeps
// the entry-ref list transitively anchored to the schema too. The two
// constants list their elements in a different order, so this compares as a
// set rather than as ordered arrays (unlike the other checks in this file).
$chr_entry_ref_set = CHRONICLER_FORMULA_ENTRY_REF_TYPES;
sort($chr_entry_ref_set);
$chr_expected_entry_ref_set = array_values(array_diff(CHRONICLER_SHEETS_LIST_FIELD_TYPES, ['longtext']));
sort($chr_expected_entry_ref_set);
check(
    'CHRONICLER_FORMULA_ENTRY_REF_TYPES matches CHRONICLER_SHEETS_LIST_FIELD_TYPES minus longtext (as a set)',
    $chr_entry_ref_set === $chr_expected_entry_ref_set
);

// --- Drift check #2: curated corpus agreement (needs opis/json-schema) -------
// Every valid-* fixture must be accepted by BOTH the schema and the PHP
// validator; every schema-invalid-* fixture must be REJECTED by the schema
// (Phase B's editor flags it inline). Relational/formula-only violations are
// intentionally NOT in this corpus — the schema cannot express them and PHP
// already covers them elsewhere.
if (!class_exists(\Opis\JsonSchema\Validator::class)) {
    check(
        'opis/json-schema available for the corpus drift check',
        false,
        'provision dev deps: rm -rf wordpress-plugin/vendor && npm run test:php'
    );
} else {
    $chr_schema_obj = json_decode($chr_schema_raw); // stdClass tree for opis
    $chr_validator = new \Opis\JsonSchema\Validator();
    $chr_schema_accepts = function (array $tpl) use ($chr_validator, $chr_schema_obj): bool {
        $data = json_decode(json_encode($tpl)); // JSON-typed tree (objects as stdClass)
        return $chr_validator->validate($data, $chr_schema_obj)->isValid();
    };

    $chr_valid_files = glob(__DIR__ . '/fixtures/templates/valid-*.{yaml,yml,json}', GLOB_BRACE);
    $chr_invalid_files = glob(__DIR__ . '/fixtures/templates/schema-invalid-*.{yaml,yml,json}', GLOB_BRACE);
    check('valid corpus fixtures present', count($chr_valid_files) > 0);
    check('schema-invalid corpus fixtures present', count($chr_invalid_files) > 0);

    foreach ($chr_valid_files as $file) {
        $name = basename($file);
        $decoded = chronicler_sheets_decode_template(file_get_contents($file));
        check("corpus: $name decodes", is_array($decoded), is_wp_error($decoded) ? $decoded->get_error_message() : '');
        if (!is_array($decoded)) {
            continue;
        }
        check("corpus: $name is accepted by the schema", $chr_schema_accepts($decoded));
        $parsed = chronicler_sheets_parse_template(file_get_contents($file));
        check("corpus: $name is accepted by the PHP validator", is_array($parsed), is_wp_error($parsed) ? $parsed->get_error_message() : '');
    }

    foreach ($chr_invalid_files as $file) {
        $name = basename($file);
        $decoded = chronicler_sheets_decode_template(file_get_contents($file));
        // These fixtures are syntactically valid YAML/JSON but structurally wrong.
        check("corpus: $name decodes (syntactically fine)", is_array($decoded));
        if (!is_array($decoded)) {
            continue;
        }
        check("corpus: $name is rejected by the schema", !$chr_schema_accepts($decoded));
    }
}
