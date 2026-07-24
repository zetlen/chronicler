<?php
// Pure-logic checks for the #109 Rules CRUD screen: the shared Rule
// validation (Rest\Schemas::ruleFieldSchemas is the single source both REST
// arg sets derive from and ruleErrors()/save_post interpret), the CPT
// capability collapse, and Rules\AdminPage's pure vocabulary/count helpers.
// Included by run.php; the hooks/screen behavior is WordPress's and is
// verified at runtime in wp-env.

use Chronicler\Capabilities;
use Chronicler\Rest\Schemas;
use Chronicler\Rules\AdminPage;
use Chronicler\Store\Rules;

// --- One field-schema source: REST args are pure derivations of it. ---
$fields = Schemas::ruleFieldSchemas();
check(
    'rule field schemas cover exactly the stored config fields',
    array_keys($fields) === array_keys(Rules::DEFAULTS)
);
foreach ($fields as $name => $schema) {
    if (array_key_exists('default', $schema)) {
        check("rule field $name default agrees with the store default", $schema['default'] === Rules::DEFAULTS[$name]);
    }
}
foreach ($fields as $name => $schema) {
    check("rule field $name is a string schema", ($schema['type'] ?? null) === 'string');
}
check('pattern has no default (required on create)', !array_key_exists('default', $fields['pattern']));
check('mode has no default (required on create)', !array_key_exists('default', $fields['mode']));
check('mode enum is exactly RULE_MODES', ($fields['mode']['enum'] ?? null) === Schemas::RULE_MODES);

$create = Schemas::ruleCreateArgs();
check('create args cover exactly the config fields', array_keys($create) === array_keys($fields));
foreach ($fields as $name => $schema) {
    $expected = array_key_exists('default', $schema) ? $schema : $schema + ['required' => true];
    check("create arg $name derives verbatim from the shared field schema", $create[$name] === $expected);
}

$update = Schemas::ruleUpdateArgs();
check('update args are id + the config fields', array_keys($update) === array_merge(['id'], array_keys($fields)));
foreach ($fields as $name => $schema) {
    unset($schema['default']); // PUT is a patch: absent keeps, so no defaults
    check("update arg $name derives verbatim from the shared field schema", $update[$name] === $schema);
}
check('update args require nothing', !array_filter($update, static fn($s) => $s['required'] ?? false));

// --- ruleErrors(): the validator save_post shares with the REST args. ---
$valid = [
    'pattern' => '^#session', 'flags' => 'im', 'mode' => 'start',
    'className' => 'x', 'tagNames' => 'one, two', 'description' => 'Opener',
];
check('a full valid config passes', Schemas::ruleErrors($valid) === []);
check('defaults fill the optional fields', Schemas::ruleErrors(['pattern' => 'x', 'mode' => 'hide']) === []);
check(
    'an empty submission fails on exactly the default-less fields',
    array_keys(Schemas::ruleErrors([])) === ['pattern', 'mode']
);
check('an empty pattern is rejected', isset(Schemas::ruleErrors(['pattern' => '', 'mode' => 'hide'])['pattern']));
check(
    // Parity with the REST schema: minLength counts characters untrimmed.
    'a whitespace pattern passes, exactly as REST minLength does',
    Schemas::ruleErrors(['pattern' => ' ', 'mode' => 'hide']) === []
);
check(
    'an out-of-vocabulary mode is rejected',
    isset(Schemas::ruleErrors(['pattern' => 'x', 'mode' => 'explode'])['mode'])
);
check(
    'the mode error names the vocabulary',
    str_contains(Schemas::ruleErrors(['pattern' => 'x', 'mode' => 'explode'])['mode'], 'wp-tag')
);
check(
    'a non-string field is rejected',
    isset(Schemas::ruleErrors(['pattern' => 'x', 'mode' => 'hide', 'flags' => ['i']])['flags'])
);
check(
    'unknown fields are ignored, matching normalize()',
    Schemas::ruleErrors($valid + ['enabled' => true, 'id' => 'uuid-1']) === []
);

// --- CPT capability collapse: one flat capability, matching the REST rule
// --- WRITE tier (#159) — rule editing is site configuration, not drafting.
$caps = Rules::adminCapabilities();
check(
    'every rule CPT capability is chronicler_manage',
    array_unique(array_values($caps)) === [Capabilities::MANAGE]
);
foreach (['edit_post', 'read_post', 'delete_post', 'edit_posts', 'create_posts', 'delete_posts', 'publish_posts'] as $cap) {
    check("rule CPT maps $cap", isset($caps[$cap]));
}

// --- AdminPage vocabulary: the select can only say what REST accepts. ---
check('mode options cover exactly RULE_MODES', array_keys(AdminPage::modeOptions()) === Schemas::RULE_MODES);
check('mode details cover exactly RULE_MODES', array_keys(AdminPage::modeDetails()) === Schemas::RULE_MODES);
check(
    'treatment options are exactly the MESSAGE_VARIANTS vocabulary',
    array_keys(AdminPage::treatmentOptions()) === ['ooc', 'important']
);
check('selectedTreatment picks a plain stored variant', AdminPage::selectedTreatment('ooc') === 'ooc');
check('selectedTreatment collapses a legacy multi-value to the first variant', AdminPage::selectedTreatment('important, ooc') === 'ooc');
check('selectedTreatment ignores unknown tokens', AdminPage::selectedTreatment('bogus') === '');
check('selectedTreatment of blank is none', AdminPage::selectedTreatment('') === '');
check(
    'field problems read as sentences',
    AdminPage::fieldProblem('pattern', 'is required') === 'Pattern is required.'
);

// --- Attached-session counts from raw rule_ids JSON. ---
check(
    'countByRule tallies each session once per rule',
    AdminPage::countByRule(['[1,2]', '[2,2,"3"]', '[]']) === [1 => 1, 2 => 2, 3 => 1]
);
check(
    'countByRule survives corrupt or junk rows',
    AdminPage::countByRule(['{oops', null, '["x",4]']) === [4 => 1]
);
check('countByRule of nothing is nothing', AdminPage::countByRule([]) === []);
