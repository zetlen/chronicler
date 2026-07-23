<?php

namespace Chronicler\Store;

/**
 * Session storage (#101): one custom table, {$prefix}chronicler_sessions.
 *
 * A Session is {integration, channel {id, name}, start/end, attached Rule
 * ids, editor state, and an array of post-transform message objects in the
 * chronicler/message block-attribute schema (Rest\Schemas::messageItem())}.
 *
 * It ALSO keeps the last fetched raw Slack payload (`raw`, the client's
 * SessionRawData: threads + names + emoji). Rules are lossy — hiding and the
 * start/end window drop messages out of `messages[]` entirely — so a stored
 * transcript cannot be re-filtered against a changed rule set from `messages`
 * alone. Persisting `raw` lets the editor rehydrate on load and re-bake
 * `messages[]` whenever rules/filters change WITHOUT re-hitting Slack (#3);
 * "Refresh from Slack" remains the only path that re-fetches. The raw blob is
 * stored verbatim (never kses-sanitized): rules match the raw Slack text, and
 * the transform sanitizes every rendered fragment downstream — PHP never
 * echoes `raw`, only the baked `messages[]`.
 *
 * Why a table and not a CPT: message payloads run to 1–2 MB of JSON. In a
 * CPT that blob would sit in post_content (kses-filtered on save for users
 * without unfiltered_html — it corrupts stored HTML-in-JSON) or in postmeta
 * (where any get_post_meta() call primes the cache with EVERY meta row, so
 * even a "light" listing drags the payloads in). A dedicated table gives
 * the list query an explicit column set — the payload columns are simply
 * never selected — and needs no kses/revision/autoload opt-outs. Sessions
 * also get no native wp-admin UI (the React session editor is their UI),
 * so the CPT's list-table affordances buy nothing here. Rules, which DO
 * want a native admin screen later (#109), are the CPT (Store\Rules).
 *
 * Timestamps (created/updated) are UTC 'Y-m-d H:i:s'. start/end are opaque
 * client-owned strings (the editor writes ISO-8601 from its datetime-local
 * inputs); the store orders by updated_at, never by start/end.
 */
final class Sessions
{
    /** Unprefixed table name; tableName() adds $wpdb->prefix. */
    public const TABLE = 'chronicler_sessions';

    /** Columns a list response reads — the payload columns stay unselected. */
    public const LIGHT_COLUMNS = 'id, integration, channel_id, channel_name, start_at, end_at, rule_ids, message_count, created_at, updated_at';

    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create/upgrade the table via dbDelta (idempotent, diffs existing
     * schema). Called by Store\Schema's upgrade routine, never per-request.
     */
    public static function createTable(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::tableName();
        $charset = $wpdb->get_charset_collate();
        // dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            integration varchar(32) NOT NULL DEFAULT 'slack',
            channel_id varchar(64) NOT NULL DEFAULT '',
            channel_name varchar(191) NOT NULL DEFAULT '',
            start_at varchar(64) NOT NULL DEFAULT '',
            end_at varchar(64) NOT NULL DEFAULT '',
            rule_ids longtext,
            editor_state longtext,
            raw longtext,
            message_count int(10) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY channel_id (channel_id)
        ) $charset;");
    }

    /** Insert a Session; returns the stored full shape, or null on DB error. */
    public static function create(array $data): ?array
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $raw = $data['raw'] ?? null;
        $inserted = $wpdb->insert(self::tableName(), [
            'integration' => (string) ($data['integration'] ?? 'slack'),
            'channel_id' => (string) ($data['channel']['id'] ?? ''),
            'channel_name' => (string) ($data['channel']['name'] ?? ''),
            'start_at' => (string) ($data['start'] ?? ''),
            'end_at' => (string) ($data['end'] ?? ''),
            'rule_ids' => wp_json_encode(self::normalizeRuleIds($data['rule_ids'] ?? [])),
            'editor_state' => wp_json_encode((object) ($data['editorState'] ?? [])),
            'raw' => self::encodeRaw($raw),
            'message_count' => self::countRawMessages($raw),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === false) {
            return null;
        }
        return self::get((int) $wpdb->insert_id);
    }

    /** The full Session (payload included), or null when the id is unknown. */
    public static function get(int $id): ?array
    {
        global $wpdb;
        $table = self::tableName();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * Apply a partial update (only keys present in $patch change), or null
     * when the id is unknown or the write fails. Replacing `raw` recounts
     * message_count from it.
     */
    public static function update(int $id, array $patch): ?array
    {
        global $wpdb;
        if (self::get($id) === null) {
            return null;
        }
        $row = [];
        if (array_key_exists('channel', $patch) && is_array($patch['channel'])) {
            $row['channel_id'] = (string) ($patch['channel']['id'] ?? '');
            $row['channel_name'] = (string) ($patch['channel']['name'] ?? '');
        }
        if (array_key_exists('start', $patch)) {
            $row['start_at'] = (string) $patch['start'];
        }
        if (array_key_exists('end', $patch)) {
            $row['end_at'] = (string) $patch['end'];
        }
        if (array_key_exists('rule_ids', $patch)) {
            $row['rule_ids'] = wp_json_encode(self::normalizeRuleIds($patch['rule_ids']));
        }
        if (array_key_exists('editorState', $patch)) {
            $row['editor_state'] = wp_json_encode((object) (is_array($patch['editorState']) ? $patch['editorState'] : []));
        }
        if (array_key_exists('raw', $patch)) {
            $row['raw'] = self::encodeRaw($patch['raw']);
            $row['message_count'] = self::countRawMessages($patch['raw']);
        }
        $row['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($wpdb->update(self::tableName(), $row, ['id' => $id]) === false) {
            return null;
        }
        return self::get($id);
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(self::tableName(), ['id' => $id]);
    }

    /**
     * Drop the legacy `messages` column if present (schema v3, #3). Idempotent:
     * a fresh install never created the column, and a re-run finds nothing to
     * drop — the information_schema probe keeps both quiet (no ALTER, no
     * warning). Sessions store raw + config now; the transcript is rebaked from
     * raw on demand, so the baked snapshot is reclaimed.
     */
    public static function dropMessagesColumn(): void
    {
        global $wpdb;
        $table = self::tableName();
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
                    . ' WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                DB_NAME,
                $table,
                'messages'
            )
        );
        if ($exists !== null) {
            $wpdb->query("ALTER TABLE $table DROP COLUMN messages");
        }
    }

    /** GET /sessions default page size; Schemas::sessionListArgs() mirrors it. */
    public const DEFAULT_PER_PAGE = 50;

    /**
     * One page of Sessions in light form (no messages, no editor state),
     * newest activity first. The payload columns are not in the SELECT at
     * all. Bounded since #164 — the query always carries a LIMIT.
     */
    public static function all(int $limit = self::DEFAULT_PER_PAGE, int $offset = 0): array
    {
        global $wpdb;
        $table = self::tableName();
        $columns = self::LIGHT_COLUMNS;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT $columns FROM $table ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
                max(1, $limit),
                max(0, $offset)
            ),
            ARRAY_A
        );
        return array_map([self::class, 'lightFromRow'], is_array($rows) ? $rows : []);
    }

    /** Total stored Sessions — feeds the list response's X-WP-Total headers. */
    public static function count(): int
    {
        global $wpdb;
        $table = self::tableName();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    /* ------------------------------------------------------------------ *
     * Pure row <-> shape helpers (exercised by tests/store.test.php)
     * ------------------------------------------------------------------ */

    /**
     * Full Session shape from a SELECT * row. Pure. `raw` is the stored
     * SessionRawData object (the transcript's source of truth — the client
     * and the block-editor engine rebake messages from it on demand), or null
     * when none has been fetched yet, which both surfaces read as "must
     * Refresh to preview/generate".
     */
    public static function fromRow(array $row): array
    {
        return self::lightFromRow($row) + [
            'editorState' => self::decodeJson($row['editor_state'] ?? null, []),
            'raw' => self::decodeRaw($row['raw'] ?? null),
        ];
    }

    /** Light (list) Session shape from a payload-free row. Pure. */
    public static function lightFromRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'integration' => (string) ($row['integration'] ?? 'slack'),
            'channel' => [
                'id' => (string) ($row['channel_id'] ?? ''),
                'name' => (string) ($row['channel_name'] ?? ''),
            ],
            'start' => (string) ($row['start_at'] ?? ''),
            'end' => (string) ($row['end_at'] ?? ''),
            'rule_ids' => self::normalizeRuleIds(self::decodeJson($row['rule_ids'] ?? null, [])),
            'messageCount' => (int) ($row['message_count'] ?? 0),
            'created' => (string) ($row['created_at'] ?? ''),
            'updated' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** Attached-rule ids as a clean int list. Pure. */
    public static function normalizeRuleIds($ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_map('intval', array_filter($ids, 'is_numeric')));
    }

    private static function decodeJson($json, array $fallback): array
    {
        if (!is_string($json) || $json === '') {
            return $fallback;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    /**
     * Encode the raw payload for the `raw` column: JSON for a present object,
     * SQL NULL when the caller clears it (or hands null). Raw is the client's
     * opaque SessionRawData object, round-tripped verbatim.
     */
    private static function encodeRaw($raw): ?string
    {
        return $raw === null ? null : wp_json_encode($raw);
    }

    /**
     * Total messages captured in a raw payload (parents + replies across every
     * thread) — the list's message_count. Counts everything fetched, not the
     * rule-filtered visible subset (the store never runs the transform); 0 for
     * a null/malformed payload. Pure.
     */
    public static function countRawMessages($raw): int
    {
        if (!is_array($raw) || !isset($raw['threads']) || !is_array($raw['threads'])) {
            return 0;
        }
        $count = 0;
        foreach ($raw['threads'] as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            $count += 1; // the parent
            if (isset($thread['replies']) && is_array($thread['replies'])) {
                $count += count($thread['replies']);
            }
        }
        return $count;
    }

    /**
     * Decode the stored raw payload back to an associative array, or null
     * when the column is empty/NULL (no fetch yet) or corrupt. Distinct from
     * decodeJson's [] fallback: null is a meaningful "nothing stored" signal
     * the client branches on, and an empty [] would masquerade as data.
     */
    private static function decodeRaw($json)
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
