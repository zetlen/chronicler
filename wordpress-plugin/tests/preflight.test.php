<?php
// The Game System editor's live-validation endpoint (#149): the callback runs
// the exact Save-time parse against an unsaved buffer. Loaded by run.php after
// surfaces.test.php, which already required sheets/rest.php and its stubs.

// Valid JSON template → valid, echoing the system name.
$chr_pf_valid_json = json_encode([
    'system' => 'Preflight Demo',
    'version' => 1,
    'properties' => [
        ['id' => 'vigor', 'label' => 'Vigor', 'type' => 'number', 'min' => 0, 'max' => 12],
    ],
]);
$chr_pf = chronicler_sheets_rest_template_preflight(new WP_REST_Request(['source' => $chr_pf_valid_json]));
check('preflight: valid JSON is valid', ($chr_pf['valid'] ?? null) === true);
check('preflight: valid JSON echoes system', ($chr_pf['system'] ?? null) === 'Preflight Demo');

// Valid YAML with a multi-line derived formula → valid.
$chr_pf_yaml = <<<'YAML'
system: Preflight YAML
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
    min: 0
  - id: toughness
    label: Toughness
    type: number
    derived: |
      floor(vigor / 2)
        + 2
YAML;
$chr_pf = chronicler_sheets_rest_template_preflight(new WP_REST_Request(['source' => $chr_pf_yaml]));
check(
    'preflight: valid YAML with block-scalar formula is valid',
    ($chr_pf['valid'] ?? null) === true,
    $chr_pf['message'] ?? ''
);

// Structural violation → the PHP validator's own message comes through.
$chr_pf = chronicler_sheets_rest_template_preflight(new WP_REST_Request([
    'source' => json_encode(['system' => 'X', 'version' => 1, 'properties' => [['id' => 'a', 'label' => 'A', 'type' => 'wizard']]]),
]));
check('preflight: structural violation is invalid', ($chr_pf['valid'] ?? null) === false);
check('preflight: structural message names the problem', strpos($chr_pf['message'] ?? '', 'unknown type') !== false, $chr_pf['message'] ?? '');

// Formula syntax error → the Expression Language message comes through.
$chr_pf = chronicler_sheets_rest_template_preflight(new WP_REST_Request([
    'source' => json_encode([
        'system' => 'X',
        'version' => 1,
        'properties' => [
            ['id' => 'a', 'label' => 'A', 'type' => 'number'],
            ['id' => 'b', 'label' => 'B', 'type' => 'number', 'derived' => 'a + + '],
        ],
    ]),
]));
check('preflight: formula syntax error is invalid', ($chr_pf['valid'] ?? null) === false);
check('preflight: formula error names the property', strpos($chr_pf['message'] ?? '', '"b"') !== false, $chr_pf['message'] ?? '');

// Empty / missing source → friendly "empty" verdict, never a PHP notice.
$chr_pf = chronicler_sheets_rest_template_preflight(new WP_REST_Request(['source' => "  \n"]));
check('preflight: blank source reports empty', ($chr_pf['code'] ?? null) === 'chronicler_empty');
$chr_pf = chronicler_sheets_rest_template_preflight(new WP_REST_Request([]));
check('preflight: missing source reports empty', ($chr_pf['code'] ?? null) === 'chronicler_empty');

// The permission gate is manage_options (site configuration, like Save).
check('preflight: permission callback exists', function_exists('chronicler_sheets_rest_can_manage'));
