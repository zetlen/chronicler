<?php

namespace Chronicler\Rest;

use Chronicler\Store\Rules;
use Chronicler\Store\Settings;

/**
 * Migration import (#101): POST chronicler/v1/import accepts the Node app's
 * GET /api/export payload — {presets, settings, libraryRules} read from its
 * sqlite stores — and lands it in WordPress:
 *
 *  - libraryRules            -> Rule posts (Store\Rules)
 *  - presets (per channel)   -> chronicler_channel_defaults entries; each
 *                               preset's embedded rules also become Rule
 *                               posts, and the ENABLED ones are referenced
 *                               by the channel's rule_ids
 *  - settings                -> chronicler_setting_* options
 *
 * Idempotency: rules are keyed by the Node app's rule id (stored as
 * chronicler_library_id meta) — re-importing updates in place instead of
 * duplicating; channel defaults and settings are keyed overwrites already.
 *
 * plan() is pure (payload in, work order out) so tests/run.php can pin the
 * dedupe/idempotency logic without WordPress; apply() does the writes.
 */
final class Import
{
    /**
     * Compute the work order. Pure.
     *
     * Returns:
     *  - rules:    libraryId => normalized rule config (deduped across the
     *              library and every preset; blank patterns dropped)
     *  - channels: channelId => {userOverrides, scheme, customCss,
     *              controls?, ruleLibraryIds} — rule references still by
     *              library id (apply() resolves them to WP post ids)
     *  - settings: key => value (invalid keys dropped)
     */
    public static function plan(array $payload): array
    {
        $rules = [];
        $collect = static function ($rule) use (&$rules): ?string {
            if (!is_array($rule)) {
                return null;
            }
            $libraryId = $rule['id'] ?? null;
            $pattern = $rule['pattern'] ?? '';
            if (!is_string($libraryId) || $libraryId === ''
                || !is_string($pattern) || trim($pattern) === '') {
                return null;
            }
            // First occurrence wins; the library and presets share one rule
            // id space, so a channel copy of a library rule is the same rule.
            if (!isset($rules[$libraryId])) {
                $rules[$libraryId] = Rules::normalize($rule);
            }
            return $libraryId;
        };

        $libraryRules = is_array($payload['libraryRules'] ?? null) ? $payload['libraryRules'] : [];
        foreach ($libraryRules as $rule) {
            $collect($rule);
        }

        $channels = [];
        $presets = is_array($payload['presets'] ?? null) ? $payload['presets'] : [];
        foreach ($presets as $channelId => $preset) {
            if (!is_array($preset) || !is_string($channelId) || $channelId === '') {
                continue;
            }
            $attached = [];
            $presetRules = is_array($preset['rules'] ?? null) ? $preset['rules'] : [];
            foreach ($presetRules as $rule) {
                $libraryId = $collect($rule);
                // `enabled` is the per-channel attachment: disabled rules
                // still enter the shared library, but the channel's defaults
                // only reference the enabled ones.
                if ($libraryId !== null && ($rule['enabled'] ?? true)) {
                    $attached[] = $libraryId;
                }
            }
            $channel = Settings::normalizeChannelDefault($preset);
            unset($channel['rule_ids']);
            $channel['ruleLibraryIds'] = $attached;
            $channels[$channelId] = $channel;
        }

        $settings = [];
        $incoming = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        foreach ($incoming as $key => $value) {
            if (is_string($key) && Settings::isValidKey($key) && is_scalar($value)) {
                $settings[$key] = (string) $value;
            }
        }

        return ['rules' => $rules, 'channels' => $channels, 'settings' => $settings];
    }

    /** Execute a plan(); returns the counts the REST response reports. */
    public static function apply(array $plan): array
    {
        $created = 0;
        $updated = 0;
        $idMap = []; // libraryId => WP post id
        foreach ($plan['rules'] as $libraryId => $config) {
            $existing = Rules::findByLibraryId($libraryId);
            if ($existing !== null) {
                Rules::update($existing, $config);
                $idMap[$libraryId] = $existing;
                $updated++;
                continue;
            }
            $rule = Rules::create($config, $libraryId);
            if ($rule !== null) {
                $idMap[$libraryId] = $rule['id'];
                $created++;
            }
        }

        $defaults = Settings::channelDefaults();
        foreach ($plan['channels'] as $channelId => $channel) {
            $libraryIds = $channel['ruleLibraryIds'];
            unset($channel['ruleLibraryIds']);
            $channel['rule_ids'] = array_values(array_filter(array_map(
                static fn ($libraryId) => $idMap[$libraryId] ?? null,
                $libraryIds
            ), 'is_int'));
            $defaults[$channelId] = $channel;
        }
        Settings::saveChannelDefaults($defaults);

        foreach ($plan['settings'] as $key => $value) {
            Settings::set($key, $value);
        }

        return [
            'rules' => ['created' => $created, 'updated' => $updated],
            'channelDefaults' => count($plan['channels']),
            'settings' => count($plan['settings']),
        ];
    }
}
