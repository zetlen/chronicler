<?php

namespace Chronicler\Rest;

/**
 * Pure-data JSON Schemas for the chronicler/v1 write routes (#101).
 *
 * Everything here is a plain array constant-in-spirit: no WordPress calls, so
 * tests/run.php can assert on the shapes standalone. The schemas are wired
 * into register_rest_route() 'args', where WordPress validates each request
 * parameter with rest_validate_value_from_schema() before any handler runs.
 */
final class Schemas
{
    /** TranscriptScheme in lib/transform/types.ts. */
    public const SCHEMES = ['light', 'dark', 'custom-light', 'custom-dark'];

    /** RuleMode in lib/transform/rules.ts. */
    public const RULE_MODES = ['start', 'end', 'hide', 'addclass', 'wp-tag'];

    /**
     * Message-object schema: EXACTLY the attribute schema the
     * chronicler/message block registers in blocks.php (v3/v4 vocabulary).
     * A Session stores post-transform message objects in this shape so
     * transcript generation (#102) is a 1:1 mapping onto block grammar.
     * additionalProperties is false on purpose: anything the block cannot
     * carry does not belong in a stored message. tests/store.test.php pins
     * this property list against a mirror of the block registration.
     */
    public static function messageItem(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                // v2 opaque form; still the system-message path.
                'html' => ['type' => 'string'],
                'rootClass' => ['type' => 'string'],
                'anchorId' => ['type' => 'string'],
                'authorName' => ['type' => 'string'],
                'authorColor' => ['type' => 'string'],
                'authorColorDark' => ['type' => 'string'],
                'avatarHtml' => ['type' => 'string'],
                'headHtml' => ['type' => 'string'],
                'bodyHtml' => ['type' => 'string'],
                'images' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'src' => ['type' => 'string'],
                            'alt' => ['type' => 'string'],
                            'caption' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'extrasHtml' => ['type' => 'string'],
                'reactionsHtml' => ['type' => 'string'],
                'className' => ['type' => 'string'],
                // blocks.php registers a plain array; the render callback
                // drops entries outside its vocabulary, so items stay loose
                // strings here rather than an enum.
                'variants' => ['type' => 'array', 'items' => ['type' => 'string']],
                'realName' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** {id, name} channel reference carried by a Session. */
    public static function channel(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'minLength' => 1],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Editor state is client-owned: the documented fields are what the
     * session editor round-trips today (per-user display-name/color
     * overrides, scheme, custom CSS); additionalProperties stays true so the
     * editor can grow state without a plugin schema bump.
     */
    public static function editorState(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'userOverrides' => self::userOverrides(),
                'scheme' => ['type' => 'string', 'enum' => self::SCHEMES],
                'customCss' => ['type' => 'string'],
            ],
            'additionalProperties' => true,
        ];
    }

    /** UserOverride map from lib/transform/directory.ts, keyed by user id. */
    public static function userOverrides(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'color' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * POST /slack/{method} — only the URL param gets a schema here. The
     * pattern is anchored, case-sensitive defense-in-depth (WordPress
     * matches the ROUTE regex case-insensitively, so this is the first
     * layer that actually rejects `SLACK/BADMETHOD` or `a..b`); the
     * authoritative gate stays Slack\Proxy's exact allowlist, and the
     * per-method body validation lives there too — JSON Schema cannot
     * express "these args for this method".
     */
    public static function slackProxyArgs(): array
    {
        return [
            'method' => ['type' => 'string', 'pattern' => '^[a-z]+(\.[a-z]+)+$'],
        ];
    }

    /**
     * The one sanitize_callback the payload-bearing write args share (#159):
     * Chronicler\Sanitize::tree kses-filters stored-HTML members and strips
     * markup out of customCss, wherever they sit in the JSON tree. Attached
     * at the arg level (not inside the reusable schemas) so response/OPTIONS
     * schemas stay pure data. Schema validation still runs first —
     * WordPress's default validate_callback is untouched.
     */
    private static function sanitized(array $schema): array
    {
        return $schema + ['sanitize_callback' => [\Chronicler\Sanitize::class, 'tree']];
    }

    /**
     * GET /sessions — WP-core-style pagination (#164). Defaults keep the
     * pre-pagination client working for the first DEFAULT_PER_PAGE sessions;
     * totals travel in X-WP-Total/X-WP-TotalPages, as core does it.
     */
    public static function sessionListArgs(): array
    {
        return [
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 200,
                'default' => \Chronicler\Store\Sessions::DEFAULT_PER_PAGE,
            ],
        ];
    }

    /** POST /sessions — the minimal draft body plus optional payload fields. */
    public static function sessionCreateArgs(): array
    {
        return [
            'integration' => ['type' => 'string', 'enum' => ['slack'], 'required' => true],
            'channel' => array_merge(self::channel(), ['required' => true]),
            'start' => ['type' => 'string', 'minLength' => 1, 'required' => true],
            'end' => ['type' => 'string', 'minLength' => 1, 'required' => true],
            'rule_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'default' => []],
            'editorState' => self::sanitized(self::editorState()),
            'messages' => self::sanitized(['type' => 'array', 'items' => self::messageItem()]),
        ];
    }

    /** PUT /sessions/{id} — any subset of the mutable fields (absent = keep). */
    public static function sessionUpdateArgs(): array
    {
        return [
            'id' => ['type' => 'integer'],
            'channel' => self::channel(),
            'start' => ['type' => 'string', 'minLength' => 1],
            'end' => ['type' => 'string', 'minLength' => 1],
            'rule_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'editorState' => self::sanitized(self::editorState()),
            'messages' => self::sanitized(['type' => 'array', 'items' => self::messageItem()]),
        ];
    }

    /**
     * Per-field JSON Schemas for the Rule config — today's rule config
     * (minus per-channel `enabled`) + description. The single source of
     * truth for Rule validation: both REST arg sets derive from it, and
     * ruleErrors() interprets it for the #109 wp-admin form's save path,
     * so the two write surfaces cannot drift. A field WITHOUT a 'default'
     * is required on a full-object write (create/admin form).
     */
    public static function ruleFieldSchemas(): array
    {
        return [
            'pattern' => ['type' => 'string', 'minLength' => 1],
            'flags' => ['type' => 'string', 'default' => 'i'],
            'mode' => ['type' => 'string', 'enum' => self::RULE_MODES],
            'className' => ['type' => 'string', 'default' => ''],
            'tagNames' => ['type' => 'string', 'default' => ''],
            'description' => ['type' => 'string', 'default' => ''],
        ];
    }

    /** POST /rules — full-object create: the default-less fields are required. */
    public static function ruleCreateArgs(): array
    {
        $args = [];
        foreach (self::ruleFieldSchemas() as $field => $schema) {
            $args[$field] = array_key_exists('default', $schema)
                ? $schema
                : $schema + ['required' => true];
        }
        return $args;
    }

    /** PUT /rules/{id} — any subset (absent = keep, so no defaults either). */
    public static function ruleUpdateArgs(): array
    {
        $args = ['id' => ['type' => 'integer']];
        foreach (self::ruleFieldSchemas() as $field => $schema) {
            unset($schema['default']);
            $args[$field] = $schema;
        }
        return $args;
    }

    /**
     * Validate a full Rule config against ruleFieldSchemas() — the exact
     * rules WordPress enforces on POST /rules via the derived args: absent
     * default-less fields are required, strings only, minLength counts
     * characters untrimmed (parity: REST accepts a whitespace pattern),
     * enum membership is strict. Returns [field => problem]; empty means
     * valid. Pure — the #109 admin save path shares it.
     */
    public static function ruleErrors(array $input): array
    {
        $errors = [];
        foreach (self::ruleFieldSchemas() as $field => $schema) {
            $value = array_key_exists($field, $input)
                ? $input[$field]
                : ($schema['default'] ?? null);
            if ($value === null) {
                $errors[$field] = 'is required';
            } elseif (!is_string($value)) {
                $errors[$field] = 'must be a string';
            } elseif (mb_strlen($value) < ($schema['minLength'] ?? 0)) {
                $errors[$field] = 'must not be empty';
            } elseif (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
                $errors[$field] = 'must be one of: ' . implode(', ', $schema['enum']);
            }
        }
        return $errors;
    }

    /**
     * One channel's defaults, applied by the client when creating a new
     * Session for that channel. `null` in a PUT patch removes the entry.
     * `controls` is carried so an imported preset's display toggles survive
     * (lib/presets.ts Controls); the store passes it through opaquely.
     */
    public static function channelDefault(): array
    {
        return [
            'type' => ['object', 'null'],
            'properties' => [
                'userOverrides' => self::userOverrides(),
                'scheme' => ['type' => 'string', 'enum' => self::SCHEMES],
                'customCss' => ['type' => 'string'],
                'controls' => ['type' => 'object'],
                'rule_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * PUT /settings — partial merge. `settings` keys mirror
     * lib/settingsStore.ts (opaque string values, e.g. navView);
     * `channelDefaults` patches per channel id, null deleting.
     */
    public static function settingsPutArgs(): array
    {
        return [
            'settings' => [
                'type' => 'object',
                'patternProperties' => [
                    \Chronicler\Store\Settings::KEY_PATTERN => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
            'channelDefaults' => self::sanitized([
                'type' => 'object',
                'additionalProperties' => self::channelDefault(),
            ]),
        ];
    }

    /**
     * GET /image — the Slack image URL to mirror (#103). format:uri routes
     * the value through wp_http_validate_url before the handler runs; the
     * handler then applies the strict Slack host allowlist (Media\Mirror).
     * `alt` seeds the attachment's alt text on the first (mirroring) fetch.
     * `format` picks the response shape (#102): the default 302 redirect
     * for <img> consumers, or `json` for a 200 {id, url} body when the
     * caller needs the attachment id (featured_media).
     */
    public static function imageArgs(): array
    {
        return [
            'url' => ['type' => 'string', 'format' => 'uri', 'required' => true],
            'alt' => ['type' => 'string', 'default' => ''],
            'format' => ['type' => 'string', 'enum' => ['redirect', 'json'], 'default' => 'redirect'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Response schemas (#112) — same pure-data discipline as the args.
     * Carried per-operation in Routes::definitions() so the route table is
     * a complete API description: tests and openapi.yaml key off it.
     * ------------------------------------------------------------------ */

    /**
     * The light Session (GET /sessions rows). Property list is pinned
     * against Store\Sessions::lightFromRow() in tests/routes.test.php, so
     * the schema cannot drift from what the store actually emits.
     */
    public static function sessionLightResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'integration' => ['type' => 'string'],
                'channel' => self::channel(),
                'start' => ['type' => 'string'],
                'end' => ['type' => 'string'],
                'rule_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'messageCount' => ['type' => 'integer'],
                'created' => ['type' => 'string'],
                'updated' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** The full Session (single-session responses): light + payload fields. */
    public static function sessionFullResponse(): array
    {
        $schema = self::sessionLightResponse();
        $schema['properties']['editorState'] = self::editorState();
        $schema['properties']['messages'] = ['type' => 'array', 'items' => self::messageItem()];
        return $schema;
    }

    /** A stored Rule: id + exactly the writable fields, read back. */
    public static function ruleResponse(): array
    {
        $properties = ['id' => ['type' => 'integer']];
        foreach (self::ruleFieldSchemas() as $field => $schema) {
            unset($schema['default']);
            $properties[$field] = $schema;
        }
        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }

    /** DELETE acknowledgements. */
    public static function deletedResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'deleted' => ['type' => 'boolean'],
                'id' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** GET and PUT /settings both answer with the whole settings document. */
    public static function settingsResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'settings' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'channelDefaults' => ['type' => 'object', 'additionalProperties' => self::channelDefault()],
            ],
            'additionalProperties' => false,
        ];
    }

    /** POST /import summary counts. */
    public static function importResultResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rules' => [
                    'type' => 'object',
                    'properties' => [
                        'created' => ['type' => 'integer'],
                        'updated' => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                'channelDefaults' => ['type' => 'integer'],
                'settings' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** GET /image&format=json body (the default 302 answer has no body). */
    public static function imageJsonResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'url' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ];
    }

    /** POST /slack/{method} — Slack's own JSON body, passed through verbatim. */
    public static function slackProxyResponse(): array
    {
        return ['type' => 'object', 'additionalProperties' => true];
    }

    /**
     * The WP_Error wire shape every chronicler/v1 error uses. Documentation
     * (openapi.yaml), not registered: WordPress owns error serialization.
     * The one departure is the Slack proxy's 429, a plain WP_REST_Response
     * whose body is {code, retry_after} — retry_after (seconds) at the TOP
     * level, no data member at all (Slack\Proxy::handle; openapi.yaml's
     * RateLimited schema).
     */
    public static function errorResponse(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'object'],
            ],
        ];
    }

    /**
     * POST /import — the Node app's GET /api/export payload. Kept
     * deliberately loose (the exporter is our own code and the import
     * planner normalizes/drops anything malformed); only the structural
     * top level is enforced.
     */
    public static function importArgs(): array
    {
        return [
            'presets' => self::sanitized([
                'type' => 'object',
                'additionalProperties' => ['type' => 'object'],
                'default' => [],
            ]),
            'settings' => [
                'type' => 'object',
                'patternProperties' => [
                    \Chronicler\Store\Settings::KEY_PATTERN => ['type' => 'string'],
                ],
                'additionalProperties' => false,
                'default' => [],
            ],
            'libraryRules' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'minLength' => 1],
                        'pattern' => ['type' => 'string'],
                        'flags' => ['type' => 'string'],
                        'mode' => ['type' => 'string'],
                        'className' => ['type' => 'string'],
                        'tagNames' => ['type' => 'string'],
                    ],
                    'required' => ['id'],
                ],
                'default' => [],
            ],
        ];
    }
}
