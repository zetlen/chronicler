<?php

namespace Chronicler\Rest;

use Chronicler\Capabilities;
use Chronicler\Media\Mirror;
use Chronicler\Slack\Proxy;
use Chronicler\Store\Rules;
use Chronicler\Store\Sessions;
use Chronicler\Store\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The core of the chronicler/v1 REST namespace, fully implemented:
 * session/rule/settings storage (#101), the stateless Slack proxy (#99),
 * and the image mirror (#103). No stubs remain.
 * Same-origin cookie + X-WP-Nonce auth (the boot contract in Admin\Page
 * hands the bundle its nonce), and every operation names the capability it
 * requires (#159): session drafting and its supporting reads take
 * Capabilities::COMPOSE, settings/rules/import writes take MANAGE, and the
 * Slack proxy takes SLACK_READ — so delegating compose to a role hands out
 * drafting only. The plugin has no inbound Slack surface (#104 closed — all
 * Slack traffic is outbound through the slack/* proxy).
 *
 * This table is not the whole namespace: the character-sheet routes
 * (sheets/rest.php) register on their own rest_api_init hook with
 * per-route permission callbacks — including a deliberately PUBLIC sheet
 * read — so the per-operation capabilities named above gate the core
 * routes only. openapi.yaml documents both surfaces (#160).
 *
 * Write routes carry JSON-Schema 'args' (Rest\Schemas): WordPress validates
 * every parameter before a handler runs, so handlers only see vetted input.
 */
final class Routes
{
    public const API_NAMESPACE = 'chronicler/v1';

    /**
     * The route table, as pure data so tests/run.php can check it without
     * WordPress: route pattern => definition.
     *
     * A definition always lists its 'methods'. Implemented routes add
     * 'operations' (method => ['handler' => Routes method, 'permission' =>
     * the capability the operation requires (#159), 'args' => JSON-Schema
     * arg map, 'response' => JSON-Schema of the success body]);
     * still-stubbed routes carry 'todo' — the Gitea issue their 501 body
     * echoes — instead. Resource routes also carry 'schema', the canonical
     * item schema WordPress serves on OPTIONS (#112); openapi.yaml is
     * cross-checked against this table plus the sheets/rest.php
     * registrations by lib/wordpress/openapi.test.ts.
     */
    public static function definitions(): array
    {
        return [
            // Stateless allowlisted Slack Web API proxy (#99). The route
            // pattern is documentation only — WordPress matches route
            // regexes case-INsensitively, so Slack\Proxy's exact allowlist
            // check is the real (and only) method gate.
            '/slack/(?P<method>[a-z.]+)' => [
                'methods' => ['POST'],
                'operations' => [
                    'POST' => [
                        'handler' => 'slackProxy',
                        'permission' => Capabilities::SLACK_READ,
                        'args' => Schemas::slackProxyArgs(),
                        'response' => Schemas::slackProxyResponse(),
                    ],
                ],
            ],
            // Session storage (#101).
            '/sessions' => [
                'methods' => ['GET', 'POST'],
                'schema' => Schemas::sessionFullResponse(),
                'operations' => [
                    'GET' => [
                        'handler' => 'listSessions',
                        'permission' => Capabilities::COMPOSE,
                        'args' => Schemas::sessionListArgs(),
                        'response' => ['type' => 'array', 'items' => Schemas::sessionLightResponse()],
                    ],
                    'POST' => [
                        'handler' => 'createSession',
                        'permission' => Capabilities::COMPOSE,
                        'args' => Schemas::sessionCreateArgs(),
                        'response' => Schemas::sessionFullResponse(),
                    ],
                ],
            ],
            '/sessions/(?P<id>\d+)' => [
                'methods' => ['GET', 'PUT', 'DELETE'],
                'schema' => Schemas::sessionFullResponse(),
                'operations' => [
                    'GET' => [
                        'handler' => 'getSession',
                        'permission' => Capabilities::COMPOSE,
                        'args' => ['id' => ['type' => 'integer']],
                        'response' => Schemas::sessionFullResponse(),
                    ],
                    'PUT' => [
                        'handler' => 'updateSession',
                        'permission' => Capabilities::COMPOSE,
                        'args' => Schemas::sessionUpdateArgs(),
                        'response' => Schemas::sessionFullResponse(),
                    ],
                    'DELETE' => [
                        'handler' => 'deleteSession',
                        'permission' => Capabilities::COMPOSE,
                        'args' => ['id' => ['type' => 'integer']],
                        'response' => Schemas::deletedResponse(),
                    ],
                ],
            ],
            // Rule storage (#101). These routes stay the canonical write
            // path for the session editor; the #109 wp-admin CRUD screen
            // (Rules\AdminPage) shares their validation via
            // Schemas::ruleFieldSchemas()/ruleErrors().
            '/rules' => [
                'methods' => ['GET', 'POST'],
                'schema' => Schemas::ruleResponse(),
                'operations' => [
                    'GET' => [
                        'handler' => 'listRules',
                        'permission' => Capabilities::COMPOSE,
                        'response' => ['type' => 'array', 'items' => Schemas::ruleResponse()],
                    ],
                    'POST' => [
                        'handler' => 'createRule',
                        'permission' => Capabilities::MANAGE,
                        'args' => Schemas::ruleCreateArgs(),
                        'response' => Schemas::ruleResponse(),
                    ],
                ],
            ],
            '/rules/(?P<id>\d+)' => [
                'methods' => ['GET', 'PUT', 'DELETE'],
                'schema' => Schemas::ruleResponse(),
                'operations' => [
                    'GET' => [
                        'handler' => 'getRule',
                        'permission' => Capabilities::COMPOSE,
                        'args' => ['id' => ['type' => 'integer']],
                        'response' => Schemas::ruleResponse(),
                    ],
                    'PUT' => [
                        'handler' => 'updateRule',
                        'permission' => Capabilities::MANAGE,
                        'args' => Schemas::ruleUpdateArgs(),
                        'response' => Schemas::ruleResponse(),
                    ],
                    'DELETE' => [
                        'handler' => 'deleteRule',
                        'permission' => Capabilities::MANAGE,
                        'args' => ['id' => ['type' => 'integer']],
                        'response' => Schemas::deletedResponse(),
                    ],
                ],
            ],
            // Image mirroring (#103): 302 to the media-library copy; the
            // response schema documents the format=json body.
            '/image' => [
                'methods' => ['GET'],
                'operations' => [
                    'GET' => [
                        'handler' => 'image',
                        'permission' => Capabilities::COMPOSE,
                        'args' => Schemas::imageArgs(),
                        'response' => Schemas::imageJsonResponse(),
                    ],
                ],
            ],
            // App settings + per-channel Session defaults (#101).
            '/settings' => [
                'methods' => ['GET', 'PUT'],
                'schema' => Schemas::settingsResponse(),
                'operations' => [
                    'GET' => [
                        'handler' => 'getSettings',
                        'permission' => Capabilities::COMPOSE,
                        'response' => Schemas::settingsResponse(),
                    ],
                    'PUT' => [
                        'handler' => 'putSettings',
                        'permission' => Capabilities::MANAGE,
                        'args' => Schemas::settingsPutArgs(),
                        'response' => Schemas::settingsResponse(),
                    ],
                ],
            ],
            // One-shot migration from the Node app's GET /api/export (#101).
            '/import' => [
                'methods' => ['POST'],
                'operations' => [
                    'POST' => [
                        'handler' => 'import',
                        'permission' => Capabilities::MANAGE,
                        'args' => Schemas::importArgs(),
                        'response' => Schemas::importResultResponse(),
                    ],
                ],
            ],
        ];
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        foreach (self::definitions() as $route => $definition) {
            if (!isset($definition['operations'])) {
                register_rest_route(self::API_NAMESPACE, $route, [
                    'methods' => $definition['methods'],
                    'callback' => static function () use ($definition) {
                        return new WP_REST_Response(['todo' => $definition['todo']], 501);
                    },
                    'permission_callback' => self::permit(Capabilities::COMPOSE),
                ]);
                continue;
            }
            $endpoints = [];
            foreach ($definition['operations'] as $method => $operation) {
                $endpoints[] = [
                    'methods' => $method,
                    'callback' => [$this, $operation['handler']],
                    'permission_callback' => self::permit($operation['permission']),
                    'args' => $operation['args'] ?? [],
                ];
            }
            if (isset($definition['schema'])) {
                // The canonical item schema, served on OPTIONS (#112).
                $endpoints['schema'] = static fn () => $definition['schema'];
            }
            register_rest_route(self::API_NAMESPACE, $route, $endpoints);
        }
    }

    /**
     * The permission callback for one operation: the current user must hold
     * the capability the route table names for it (#159) — COMPOSE for
     * session work, MANAGE for configuration writes, SLACK_READ for the
     * proxy. tests/routes.test.php pins the per-operation map.
     */
    private static function permit(string $capability): callable
    {
        return static fn (): bool => current_user_can($capability);
    }

    /* ------------------------------------------------------------------ *
     * Slack proxy (#99)
     * ------------------------------------------------------------------ */

    /** POST /slack/{method} — one stateless allowlisted Slack call per
     *  request; the whole contract lives on Slack\Proxy. */
    public function slackProxy(WP_REST_Request $request)
    {
        return (new Proxy())->handle($request);
    }

    /* ------------------------------------------------------------------ *
     * Sessions (#101)
     * ------------------------------------------------------------------ */

    /** GET /sessions — light objects only, one page at a time (#164);
     *  payloads stay in the database, totals in the X-WP-* headers. The
     *  clamps mirror the arg schema's minimums: HTTP callers are already
     *  validated, but a direct PHP caller shouldn't reach a division by
     *  zero or a negative OFFSET either. */
    public function listSessions(WP_REST_Request $request)
    {
        $perPage = max(1, (int) $request->get_param('per_page'));
        $page = max(1, (int) $request->get_param('page'));
        $response = rest_ensure_response(Sessions::all($perPage, ($page - 1) * $perPage));
        $total = Sessions::count();
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) (int) ceil($total / $perPage));
        return $response;
    }

    /** POST /sessions — a minimal {integration, channel, start, end} body
     *  creates a draft; rule_ids/editorState/messages are optional. */
    public function createSession(WP_REST_Request $request)
    {
        $session = Sessions::create([
            'integration' => $request['integration'],
            'channel' => $request['channel'],
            'start' => $request['start'],
            'end' => $request['end'],
            'rule_ids' => $request['rule_ids'] ?? [],
            'editorState' => $request['editorState'] ?? [],
            'raw' => $request['raw'] ?? null,
        ]);
        if ($session === null) {
            return new WP_Error('chronicler_db_error', 'Could not save the session.', ['status' => 500]);
        }
        return new WP_REST_Response(self::presentSession($session), 201);
    }

    /** GET /sessions/{id} — the full Session, message payload included. */
    public function getSession(WP_REST_Request $request)
    {
        $session = Sessions::get((int) $request['id']);
        if ($session === null) {
            return self::notFound('session');
        }
        return rest_ensure_response(self::presentSession($session));
    }

    /** PUT /sessions/{id} — partial update: absent fields are untouched. */
    public function updateSession(WP_REST_Request $request)
    {
        $patch = [];
        // raw is only ever sent as an object (a completed fetch), never null,
        // so the shared non-null guard is correct — no path clears it.
        foreach (['channel', 'start', 'end', 'rule_ids', 'editorState', 'raw'] as $key) {
            if ($request->get_param($key) !== null) {
                $patch[$key] = $request->get_param($key);
            }
        }
        $session = Sessions::update((int) $request['id'], $patch);
        if ($session === null) {
            return self::notFound('session');
        }
        return rest_ensure_response(self::presentSession($session));
    }

    public function deleteSession(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        if (!Sessions::delete($id)) {
            return self::notFound('session');
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    /* ------------------------------------------------------------------ *
     * Rules (#101)
     * ------------------------------------------------------------------ */

    public function listRules()
    {
        return rest_ensure_response(Rules::all());
    }

    public function createRule(WP_REST_Request $request)
    {
        // Field list derived from the shared schema, never restated: a field
        // added to ruleFieldSchemas() (validated by the args) must reach the
        // store too — `treatments` was silently dropped here once (#154).
        $config = [];
        foreach (array_keys(Schemas::ruleFieldSchemas()) as $key) {
            $config[$key] = $request[$key];
        }
        $rule = Rules::create($config);
        if ($rule === null) {
            return new WP_Error('chronicler_db_error', 'Could not save the rule.', ['status' => 500]);
        }
        return new WP_REST_Response($rule, 201);
    }

    public function getRule(WP_REST_Request $request)
    {
        $rule = Rules::get((int) $request['id']);
        if ($rule === null) {
            return self::notFound('rule');
        }
        return rest_ensure_response($rule);
    }

    public function updateRule(WP_REST_Request $request)
    {
        $patch = [];
        foreach (array_keys(Schemas::ruleFieldSchemas()) as $key) {
            if ($request->get_param($key) !== null) {
                $patch[$key] = $request->get_param($key);
            }
        }
        $rule = Rules::update((int) $request['id'], $patch);
        if ($rule === null) {
            return self::notFound('rule');
        }
        return rest_ensure_response($rule);
    }

    public function deleteRule(WP_REST_Request $request)
    {
        $id = (int) $request['id'];
        if (!Rules::delete($id)) {
            return self::notFound('rule');
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    /* ------------------------------------------------------------------ *
     * Settings + per-channel defaults (#101)
     * ------------------------------------------------------------------ */

    public function getSettings()
    {
        return rest_ensure_response(self::presentSettings());
    }

    /** PUT /settings — merge semantics: settings per key; channelDefaults
     *  per channel (object replaces, null deletes, absent keeps). */
    public function putSettings(WP_REST_Request $request)
    {
        $settings = $request->get_param('settings');
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                Settings::set((string) $key, (string) $value);
            }
        }
        $patch = $request->get_param('channelDefaults');
        if (is_array($patch)) {
            Settings::saveChannelDefaults(
                Settings::mergeChannelDefaults(Settings::channelDefaults(), $patch)
            );
        }
        return rest_ensure_response(self::presentSettings());
    }

    /* ------------------------------------------------------------------ *
     * Image mirror (#103)
     * ------------------------------------------------------------------ */

    /**
     * GET /image?url=... — the editor's <img> endpoint, replacing the Node
     * app's streaming proxy (/api/slack-image). Mirrors the Slack image
     * into the media library on first fetch (Media\Mirror) and 302s to the
     * local attachment URL. The mirrored URL is stable for a given source
     * URL, so the redirect carries a long Cache-Control (private: the route
     * itself is capability-gated cookie-auth).
     *
     * &format=json opts into a 200 {id, url} body instead of the 302 — for
     * consumers that need the ATTACHMENT ID, e.g. the #102 editor sidebar
     * staging featured_media (which then owes the attachment a parent post;
     * see the Media\Mirror consumer contract).
     *
     * Errors pass the Mirror's WP_Error through: 400 for a URL/redirect-hop
     * off the Slack host allowlist, 502 (with the reason) for download
     * failures, 500 for local storage failures.
     */
    public function image(WP_REST_Request $request)
    {
        $attachment = Mirror::localAttachment((string) $request['url'], (string) $request['alt']);
        if (is_wp_error($attachment)) {
            return $attachment;
        }
        if ($request['format'] === 'json') {
            return rest_ensure_response($attachment);
        }
        $response = new WP_REST_Response(null, 302);
        $response->header('Location', $attachment['url']);
        $response->header('Cache-Control', 'private, max-age=2592000, immutable');
        return $response;
    }

    /* ------------------------------------------------------------------ *
     * Migration import (#101)
     * ------------------------------------------------------------------ */

    /** POST /import — the Node app's export payload; idempotent. */
    public function import(WP_REST_Request $request)
    {
        $result = Import::apply(Import::plan([
            'presets' => $request['presets'] ?? [],
            'settings' => $request['settings'] ?? [],
            'libraryRules' => $request['libraryRules'] ?? [],
        ]));
        return rest_ensure_response($result);
    }

    /* ------------------------------------------------------------------ *
     * Presentation helpers
     * ------------------------------------------------------------------ */

    /** JSON-shape fixups: empty maps must serialize as {}, not []. */
    private static function presentSession(array $session): array
    {
        $session['editorState'] = (object) $session['editorState'];
        return $session;
    }

    private static function presentSettings(): array
    {
        return [
            'settings' => (object) Settings::all(),
            'channelDefaults' => (object) Settings::channelDefaults(),
        ];
    }

    private static function notFound(string $what): WP_Error
    {
        return new WP_Error('chronicler_not_found', "No such $what.", ['status' => 404]);
    }
}
