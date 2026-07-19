<?php
// Route-table checks for src/Rest/Routes.php (#97, #101). Included by run.php.
// The table is pure data; registration/permission behavior is WordPress's
// and is verified at runtime in wp-env, not here.

use Chronicler\Capabilities;
use Chronicler\Rest\Routes;

check('REST namespace is chronicler/v1', Routes::API_NAMESPACE === 'chronicler/v1');
check('compose capability is chronicler_compose', Capabilities::COMPOSE === 'chronicler_compose');

$defs = Routes::definitions();

// One entry per planned surface, no strays, no draft route (gone in v3 —
// post creation is editor-native, #102). All routes are implemented
// (#99, #101, #103) and list their operations.
$expected = [
    '/slack/(?P<method>[a-z.]+)' => ['methods' => ['POST'], 'handlers' => [
        'POST' => 'slackProxy',
    ]],
    '/sessions' => ['methods' => ['GET', 'POST'], 'handlers' => [
        'GET' => 'listSessions', 'POST' => 'createSession',
    ]],
    '/sessions/(?P<id>\d+)' => ['methods' => ['GET', 'PUT', 'DELETE'], 'handlers' => [
        'GET' => 'getSession', 'PUT' => 'updateSession', 'DELETE' => 'deleteSession',
    ]],
    '/rules' => ['methods' => ['GET', 'POST'], 'handlers' => [
        'GET' => 'listRules', 'POST' => 'createRule',
    ]],
    '/rules/(?P<id>\d+)' => ['methods' => ['GET', 'PUT', 'DELETE'], 'handlers' => [
        'GET' => 'getRule', 'PUT' => 'updateRule', 'DELETE' => 'deleteRule',
    ]],
    '/image' => ['methods' => ['GET'], 'handlers' => [
        'GET' => 'image',
    ]],
    '/settings' => ['methods' => ['GET', 'PUT'], 'handlers' => [
        'GET' => 'getSettings', 'PUT' => 'putSettings',
    ]],
    '/import' => ['methods' => ['POST'], 'handlers' => [
        'POST' => 'import',
    ]],
];
check('route table covers exactly the planned routes', array_keys($defs) === array_keys($expected));

foreach ($expected as $route => $want) {
    $def = $defs[$route] ?? null;
    check("$route allows exactly " . implode('|', $want['methods']), is_array($def) && ($def['methods'] ?? null) === $want['methods']);
    check("$route methods are unique", is_array($def) && count(array_unique($def['methods'])) === count($def['methods']));

    if (isset($want['todo'])) {
        // Still a stub: names its implementing issue, has no operations.
        check("$route stub names issue {$want['todo']}", is_array($def) && ($def['todo'] ?? null) === $want['todo']);
        check("$route todo looks like an issue reference", is_array($def) && preg_match('#^\d+(/\d+)*$#', $def['todo'] ?? '') === 1);
        check("$route has no operations while stubbed", !isset($def['operations']));
        continue;
    }

    // Implemented: one operation per allowed method, each handler a real
    // Routes method, JSON-Schema args on every write operation, and a
    // response schema on EVERY operation (#112 — openapi.yaml keys off it).
    $operations = $def['operations'] ?? null;
    check("$route operations cover exactly its methods", is_array($operations) && array_keys($operations) === $want['methods']);
    foreach ($want['handlers'] as $method => $handler) {
        $operation = $operations[$method] ?? null;
        check("$route $method is handled by $handler", is_array($operation) && ($operation['handler'] ?? null) === $handler);
        check("$route $method handler exists on Routes", method_exists(Routes::class, $handler));
        if (in_array($method, ['POST', 'PUT'], true)) {
            check(
                "$route $method validates its body via JSON-Schema args",
                is_array($operation) && is_array($operation['args'] ?? null) && $operation['args'] !== []
            );
        }
        $response = $operation['response'] ?? null;
        check(
            "$route $method documents its response schema",
            is_array($response) && is_string($response['type'] ?? null)
        );
    }
}

// --- Response schemas mirror the store shapes (#112) --------------------------
// The schemas are hand-maintained pure data; these pins make drifting from
// the actual store output a test failure rather than a stale spec.

use Chronicler\Rest\Schemas;
use Chronicler\Store\Sessions;

check(
    'sessionLightResponse mirrors Store\Sessions::lightFromRow exactly',
    array_keys(Schemas::sessionLightResponse()['properties']) === array_keys(Sessions::lightFromRow([]))
);
check(
    'sessionFullResponse mirrors Store\Sessions::fromRow exactly',
    array_keys(Schemas::sessionFullResponse()['properties']) === array_keys(Sessions::fromRow([]))
);
check(
    'ruleResponse is id + exactly the writable rule fields',
    array_keys(Schemas::ruleResponse()['properties']) === array_merge(['id'], array_keys(Schemas::ruleFieldSchemas()))
);
check(
    'resource routes carry an OPTIONS item schema',
    isset($defs['/sessions']['schema'], $defs['/sessions/(?P<id>\d+)']['schema'], $defs['/rules']['schema'], $defs['/rules/(?P<id>\d+)']['schema'], $defs['/settings']['schema'])
);

// GET /sessions is paginated (#164): WP-core-style page/per_page, bounded,
// with the default page size mirroring the store's LIMIT fallback.
$listArgs = $defs['/sessions']['operations']['GET']['args'] ?? [];
check('/sessions GET pages from 1', ($listArgs['page']['default'] ?? null) === 1 && ($listArgs['page']['minimum'] ?? null) === 1);
check('/sessions GET per_page is bounded', ($listArgs['per_page']['minimum'] ?? null) === 1 && ($listArgs['per_page']['maximum'] ?? null) === 200);
check('/sessions GET per_page default mirrors the store', ($listArgs['per_page']['default'] ?? null) === Sessions::DEFAULT_PER_PAGE);

// GET /image (#103) validates its query args like the write routes do:
// url is required (and format:uri pre-screened), alt optional.
$imageArgs = $defs['/image']['operations']['GET']['args'] ?? [];
check('/image GET requires url', ($imageArgs['url']['required'] ?? false) === true);
check('/image GET pre-screens url as a uri', ($imageArgs['url']['format'] ?? null) === 'uri');
check('/image GET alt is optional', ($imageArgs['alt']['required'] ?? false) === false);


// --- Capability tiers (#159) --------------------------------------------
// The single compose gate is split per route group so delegating
// chronicler_compose hands out session drafting only: settings/rules/import
// WRITES need the manage capability, and the Slack proxy (which reads any
// channel the bot token can see) needs the slack-read capability. Reads the
// session editor depends on (rules list, settings) stay compose-tier.
// WordPress's dispatch of permission_callback is runtime behavior verified
// in wp-env; the table below pins which capability each operation names.

check('manage capability is chronicler_manage', Capabilities::MANAGE === 'chronicler_manage');
check('slack-read capability is chronicler_slack_read', Capabilities::SLACK_READ === 'chronicler_slack_read');
check(
    'activation grants all three capabilities',
    Capabilities::ALL === [Capabilities::COMPOSE, Capabilities::MANAGE, Capabilities::SLACK_READ]
);

$permissions = [];
foreach ($defs as $route => $def) {
    foreach ($def['operations'] ?? [] as $method => $operation) {
        $permissions["$method $route"] = $operation['permission'] ?? null;
    }
}
$expectedPermissions = [
    'POST /slack/(?P<method>[a-z.]+)' => Capabilities::SLACK_READ,
    'GET /sessions' => Capabilities::COMPOSE,
    'POST /sessions' => Capabilities::COMPOSE,
    'GET /sessions/(?P<id>\d+)' => Capabilities::COMPOSE,
    'PUT /sessions/(?P<id>\d+)' => Capabilities::COMPOSE,
    'DELETE /sessions/(?P<id>\d+)' => Capabilities::COMPOSE,
    'GET /rules' => Capabilities::COMPOSE,
    'POST /rules' => Capabilities::MANAGE,
    'GET /rules/(?P<id>\d+)' => Capabilities::COMPOSE,
    'PUT /rules/(?P<id>\d+)' => Capabilities::MANAGE,
    'DELETE /rules/(?P<id>\d+)' => Capabilities::MANAGE,
    'GET /image' => Capabilities::COMPOSE,
    'GET /settings' => Capabilities::COMPOSE,
    'PUT /settings' => Capabilities::MANAGE,
    'POST /import' => Capabilities::MANAGE,
];
check(
    'every operation names its capability tier (#159)',
    $permissions === $expectedPermissions,
    var_export(array_diff_assoc($expectedPermissions, $permissions), true)
        . ' expected; strays: ' . var_export(array_diff_key($permissions, $expectedPermissions), true)
);

// The slack proxy route pattern is DOCUMENTATION, not a gate: WordPress
// matches route regexes case-insensitively, so uppercase and double-dot
// URLs still reach the handler (recorded on #99). The layers that actually
// reject are Schemas::slackProxyArgs()'s anchored case-sensitive pattern
// and, authoritatively, Slack\Proxy's exact allowlist (tests/slack.test.php).
$methodSchema = Chronicler\Rest\Schemas::slackProxyArgs()['method'] ?? null;
check('slack method param carries a pattern schema', is_array($methodSchema) && is_string($methodSchema['pattern'] ?? null));
$schemaPattern = '#' . str_replace('#', '\#', $methodSchema['pattern']) . '#u'; // as rest_validate_json_schema_pattern compiles it
check('method schema admits conversations.history', preg_match($schemaPattern, 'conversations.history') === 1);
check('method schema admits auth.test', preg_match($schemaPattern, 'auth.test') === 1);
check('method schema rejects uppercase', preg_match($schemaPattern, 'BADMETHOD') === 0);
check('method schema rejects mixed case', preg_match($schemaPattern, 'Conversations.History') === 0);
check('method schema rejects double dots', preg_match($schemaPattern, 'conversations..list') === 0);
check('method schema rejects dotless names', preg_match($schemaPattern, 'badmethod') === 0);
check('method schema rejects embedded paths', preg_match($schemaPattern, 'a.b/c.d') === 0);
