<?php
// Pure-logic checks for the #101 storage layer: REST schemas, settings/
// channel-defaults merge, rule normalization, session row decoding, and the
// import planner. Included by run.php; everything exercised here is
// WordPress-free (the wpdb/options/post calls live behind these helpers and
// are verified at runtime in wp-env).

use Chronicler\Rest\Import;
use Chronicler\Rest\Schemas;
use Chronicler\Store\Rules;
use Chronicler\Store\Sessions;
use Chronicler\Store\Settings;

// --- Message schema: EXACTLY the chronicler/message block attributes. ---
// This list mirrors the register_block_type('chronicler/message') call in
// blocks.php (which can't be included here — it registers hooks at load).
// If a block attribute is added there, this check forces the Session
// message schema (and the schema version) to move in lockstep.
$blockAttributes = [
    'html', 'rootClass', 'anchorId', 'authorName', 'authorColor',
    'authorColorDark', 'avatarHtml', 'headHtml', 'bodyHtml', 'images',
    'extrasHtml', 'reactionsHtml', 'className', 'variants', 'realName',
];
$message = Schemas::messageItem();
check('message schema is an object schema', ($message['type'] ?? null) === 'object');
check(
    'message schema carries exactly the chronicler/message block attributes',
    array_keys($message['properties']) === $blockAttributes,
    implode(',', array_diff($blockAttributes, array_keys($message['properties'])))
        . ' missing; ' . implode(',', array_diff(array_keys($message['properties']), $blockAttributes)) . ' extra'
);
check('message schema rejects unknown attributes', ($message['additionalProperties'] ?? null) === false);
check(
    'message images items are {src, alt, caption} and nothing else',
    array_keys($message['properties']['images']['items']['properties']) === ['src', 'alt', 'caption']
        && ($message['properties']['images']['items']['additionalProperties'] ?? null) === false
);
check('message variants items are strings', ($message['properties']['variants']['items']['type'] ?? null) === 'string');

// --- Schema vocabulary mirrors the app's. ---
check('schemes mirror TranscriptScheme', Schemas::SCHEMES === ['light', 'dark', 'custom-light', 'custom-dark']);
check('rule modes mirror RuleMode', Schemas::RULE_MODES === ['start', 'end', 'hide', 'addclass', 'wp-tag', 'treatment']);

// --- Write-route args: the minimal-create contract. ---
$create = Schemas::sessionCreateArgs();
foreach (['integration', 'channel', 'start', 'end'] as $field) {
    check("POST sessions requires $field", ($create[$field]['required'] ?? false) === true);
}
foreach (['rule_ids', 'editorState', 'raw'] as $field) {
    check("POST sessions leaves $field optional", ($create[$field]['required'] ?? false) === false);
}
// Arg-level required is `true`; an object schema's `required` LIST (like
// channel's ['id']) is a different, draft-4 construct and stays allowed.
$update = Schemas::sessionUpdateArgs();
foreach ($update as $field => $schema) {
    check("PUT sessions/{id} $field is optional", ($schema['required'] ?? false) !== true);
}
$ruleCreate = Schemas::ruleCreateArgs();
check('rule create requires pattern and mode', $ruleCreate['pattern']['required'] === true && $ruleCreate['mode']['required'] === true);

// --- Settings keys: option-name safety boundary. ---
check('navView is a valid settings key', Settings::isValidKey('navView'));
check('dashed keys are valid', Settings::isValidKey('nav-view_2'));
check('empty key is invalid', !Settings::isValidKey(''));
check('leading digit is invalid', !Settings::isValidKey('9lives'));
check('path characters are invalid', !Settings::isValidKey('a/b'));
check('spaces are invalid', !Settings::isValidKey('nav view'));
check('overlong key is invalid', !Settings::isValidKey(str_repeat('a', 65)));

// --- Channel-defaults merge: object replaces, null deletes, absent keeps. ---
$current = [
    'C1' => ['userOverrides' => [], 'scheme' => 'dark', 'customCss' => '', 'rule_ids' => [3]],
    'C2' => ['userOverrides' => [], 'scheme' => 'light', 'customCss' => '', 'rule_ids' => []],
];
$merged = Settings::mergeChannelDefaults($current, [
    'C1' => ['scheme' => 'custom-dark', 'rule_ids' => [5, '7', 'x'], 'customCss' => '.a{}'],
    'C2' => null,
    'C3' => ['userOverrides' => ['U1' => ['name' => 'Kira']], 'controls' => ['hideBots' => true]],
]);
$merged2 = Settings::mergeChannelDefaults($merged, ['C4' => ['scheme' => 'light']]);
check('merge replaces a patched channel wholesale', $merged['C1']['scheme'] === 'custom-dark' && $merged['C1']['customCss'] === '.a{}');
check('merge normalizes rule_ids to ints and drops junk', $merged['C1']['rule_ids'] === [5, 7]);
check('merge deletes a channel on null', !isset($merged['C2']));
check('merge adds new channels', isset($merged['C3']) && $merged['C3']['userOverrides'] === ['U1' => ['name' => 'Kira']]);
check('merge keeps controls only when supplied', isset($merged['C3']['controls']) && !isset($merged['C1']['controls']));
check('merge leaves untouched channels intact', $merged2['C1']['scheme'] === 'custom-dark' && isset($merged2['C3']));
check('normalize falls back to light on a junk scheme', Settings::normalizeChannelDefault(['scheme' => 'sepia'])['scheme'] === 'light');

// --- Rule normalization: config-only, mode vocabulary enforced. ---
$normalized = Rules::normalize([
    'id' => 'uuid-1', 'enabled' => true, // app-side fields, dropped
    'pattern' => '^#session', 'flags' => 'im', 'mode' => 'start',
    'className' => 'x', 'tagNames' => 'one, two', 'treatments' => 'ooc', 'description' => 'Session opener',
]);
check('normalize keeps exactly the config fields', array_keys($normalized) === array_keys(Rules::DEFAULTS));
check('normalize drops app-side id/enabled', !isset($normalized['id']) && !isset($normalized['enabled']));
check('normalize preserves values', $normalized['pattern'] === '^#session' && $normalized['flags'] === 'im' && $normalized['tagNames'] === 'one, two' && $normalized['treatments'] === 'ooc');
check('normalize defaults missing fields', Rules::normalize(['pattern' => 'x', 'mode' => 'hide'])['flags'] === 'i');
check('normalize refuses an unknown mode', Rules::normalize(['mode' => 'explode'])['mode'] === 'hide');
check(
    'normalize merges a patch over a base config',
    Rules::normalize(['description' => 'new'], $normalized)['pattern'] === '^#session'
        && Rules::normalize(['description' => 'new'], $normalized)['description'] === 'new'
);
check('adminTitle prefers the description', Rules::adminTitle($normalized) === 'Session opener');
check('adminTitle falls back to mode: pattern', Rules::adminTitle(['mode' => 'hide', 'pattern' => 'ooc', 'description' => '']) === 'hide: ooc');
check('configOf strips id/libraryId', array_keys(Rules::configOf(['id' => 4, 'libraryId' => 'u'] + $normalized)) === array_keys(Rules::DEFAULTS));

// --- Session row decoding (the SELECT * -> REST shape path). ---
$row = [
    'id' => '12', 'integration' => 'slack',
    'channel_id' => 'C42', 'channel_name' => 'general',
    'start_at' => '2026-07-01T19:00', 'end_at' => '2026-07-02T01:00',
    'rule_ids' => '[3,5]',
    'editor_state' => '{"scheme":"dark","customCss":"","userOverrides":{}}',
    'raw' => '{"threads":[{"parent":{"user":"U1"},"replies":[{"user":"U2"}]}],"names":{"users":{}}}',
    'message_count' => '2',
    'created_at' => '2026-07-11 20:00:00', 'updated_at' => '2026-07-11 20:05:00',
];
$full = Sessions::fromRow($row);
check('fromRow types the id', $full['id'] === 12);
check('fromRow builds the channel object', $full['channel'] === ['id' => 'C42', 'name' => 'general']);
check('fromRow decodes rule ids as ints', $full['rule_ids'] === [3, 5]);
check('fromRow decodes editor state', $full['editorState']['scheme'] === 'dark');
check('fromRow has no baked messages field', !array_key_exists('messages', $full));
check('fromRow decodes raw payload', $full['raw']['threads'][0]['parent']['user'] === 'U1');
check('fromRow keeps start/end verbatim', $full['start'] === '2026-07-01T19:00' && $full['end'] === '2026-07-02T01:00');
$light = Sessions::lightFromRow($row);
check('light shape has no payload fields', !isset($light['editorState']) && !isset($light['raw']));
check('light shape keeps the message count', $light['messageCount'] === 2);
check('absent raw column is null, not []', Sessions::fromRow(array_diff_key($row, ['raw' => 1]))['raw'] === null);
check('corrupt raw JSON degrades to null', Sessions::fromRow(['raw' => '{oops'] + $row)['raw'] === null);
// message_count derives from the raw payload (parents + replies), 0 when absent.
check('countRawMessages sums parents and replies', Sessions::countRawMessages(json_decode($row['raw'], true)) === 2);
check('countRawMessages of null is 0', Sessions::countRawMessages(null) === 0);
check('countRawMessages of malformed is 0', Sessions::countRawMessages(['threads' => 'nope']) === 0);
check('normalizeRuleIds cleans junk', Sessions::normalizeRuleIds(['3', 5, 'x', null]) === [3, 5]);
check('normalizeRuleIds tolerates non-arrays', Sessions::normalizeRuleIds('nope') === []);

// --- Import planner: dedupe + idempotency-by-library-id logic. ---
$export = [
    'libraryRules' => [
        ['id' => 'lib-1', 'pattern' => '^#start', 'flags' => 'i', 'mode' => 'start', 'className' => ''],
        ['id' => 'lib-2', 'pattern' => 'ooc:', 'flags' => 'i', 'mode' => 'hide', 'className' => ''],
        ['id' => 'lib-blank', 'pattern' => '   ', 'flags' => 'i', 'mode' => 'hide', 'className' => ''],
    ],
    'presets' => [
        'C1' => [
            'controls' => ['hideBots' => true, 'includeReplies' => true],
            'userOverrides' => ['U1' => ['name' => 'Kira', 'color' => '#4674b8']],
            'scheme' => 'dark',
            'customCss' => '.slack-log{color:teal}',
            'rules' => [
                // Same id as a library rule: one Rule object, not two.
                ['id' => 'lib-1', 'pattern' => '^#start', 'flags' => 'i', 'mode' => 'start', 'className' => '', 'enabled' => true],
                // Channel-only rule, disabled: enters the library, not the attachments.
                ['id' => 'chan-1', 'pattern' => 'secret', 'flags' => 'i', 'mode' => 'hide', 'className' => '', 'enabled' => false],
                // Enabled channel-only rule.
                ['id' => 'chan-2', 'pattern' => '#end', 'flags' => 'i', 'mode' => 'end', 'className' => '', 'enabled' => true],
            ],
        ],
        'C2' => ['scheme' => 'light', 'rules' => []],
    ],
    'settings' => ['navView' => 'calendar', 'bad key!' => 'x'],
];
$plan = Import::plan($export);
check(
    'plan collects each rule once, keyed by library id',
    array_keys($plan['rules']) === ['lib-1', 'lib-2', 'chan-1', 'chan-2']
);
check('plan drops blank-pattern rules', !isset($plan['rules']['lib-blank']));
check('plan normalizes collected rules', $plan['rules']['chan-2']['mode'] === 'end' && !isset($plan['rules']['chan-2']['enabled']));
check('plan attaches only enabled preset rules', $plan['channels']['C1']['ruleLibraryIds'] === ['lib-1', 'chan-2']);
check('plan carries overrides/scheme/customCss', $plan['channels']['C1']['scheme'] === 'dark'
    && $plan['channels']['C1']['userOverrides'] === ['U1' => ['name' => 'Kira', 'color' => '#4674b8']]
    && $plan['channels']['C1']['customCss'] === '.slack-log{color:teal}');
check('plan passes controls through', ($plan['channels']['C1']['controls']['hideBots'] ?? null) === true);
check('plan handles a rule-less preset', $plan['channels']['C2']['ruleLibraryIds'] === []);
check('plan filters settings keys', $plan['settings'] === ['navView' => 'calendar']);
check('plan is deterministic (re-import plans the same work)', Import::plan($export) === $plan);
check('plan tolerates an empty export', Import::plan([]) === ['rules' => [], 'channels' => [], 'settings' => []]);
