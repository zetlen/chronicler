<?php

namespace Chronicler\Store;

/**
 * App settings + per-channel Session defaults, stored as WP options (#101).
 *
 * App settings mirror lib/settingsStore.ts one-to-one: each key becomes its
 * own prefixed option (`chronicler_setting_<key>`), values are opaque strings
 * and each caller owns its validation — exactly the Node store's contract.
 * Options cannot be enumerated by prefix without raw SQL, so a small index
 * option tracks which keys have been written.
 *
 * Channel defaults live in one option (`chronicler_channel_defaults`): a map
 * of channel id → {userOverrides, scheme, customCss, controls?, rule_ids}
 * that the session editor applies when creating a new Session for that
 * channel. The map is written wholesale; merge semantics for REST PUT
 * patches live in the pure mergeChannelDefaults() so tests can pin them.
 */
final class Settings
{
    public const OPTION_PREFIX = 'chronicler_setting_';
    public const KEYS_OPTION = 'chronicler_settings_keys';
    public const CHANNEL_DEFAULTS_OPTION = 'chronicler_channel_defaults';

    /**
     * Allowed setting keys — starts with a letter, then word chars or
     * dashes, max 64. Keys land inside option names, so this is a safety
     * boundary, not a style preference.
     */
    public const KEY_PATTERN = '^[A-Za-z][A-Za-z0-9_-]{0,63}$';

    public static function isValidKey(string $key): bool
    {
        return preg_match('/' . self::KEY_PATTERN . '/', $key) === 1;
    }

    /** One setting, or null when never written (the Node store's contract). */
    public static function get(string $key): ?string
    {
        if (!self::isValidKey($key)) {
            return null;
        }
        $value = get_option(self::OPTION_PREFIX . $key, null);
        return is_string($value) ? $value : null;
    }

    /** Write one setting; invalid keys are refused (REST validation should
     *  have caught them, this is the storage-side backstop). */
    public static function set(string $key, string $value): bool
    {
        if (!self::isValidKey($key)) {
            return false;
        }
        update_option(self::OPTION_PREFIX . $key, $value, false);
        $keys = self::keys();
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            update_option(self::KEYS_OPTION, $keys, false);
        }
        return true;
    }

    /** Every written setting, keyed by settings-store key. */
    public static function all(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $value = self::get($key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** The key index (keys ever written). */
    private static function keys(): array
    {
        $keys = get_option(self::KEYS_OPTION, []);
        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** The per-channel defaults map (channel id => default set). */
    public static function channelDefaults(): array
    {
        $map = get_option(self::CHANNEL_DEFAULTS_OPTION, []);
        return is_array($map) ? $map : [];
    }

    public static function saveChannelDefaults(array $map): void
    {
        update_option(self::CHANNEL_DEFAULTS_OPTION, $map, false);
    }

    /**
     * Merge a REST patch into the current defaults map. Pure. Per channel:
     * null deletes the entry, an object replaces it wholesale (normalized).
     * Channels absent from the patch are untouched.
     */
    public static function mergeChannelDefaults(array $current, array $patch): array
    {
        foreach ($patch as $channelId => $value) {
            if ($value === null) {
                unset($current[$channelId]);
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $current[(string) $channelId] = self::normalizeChannelDefault($value);
        }
        return $current;
    }

    /**
     * Coerce one channel-default entry to the stored shape. Pure. Unknown
     * fields are dropped; `controls` (an imported preset's display toggles)
     * is kept only when present, passed through opaquely.
     */
    public static function normalizeChannelDefault(array $value): array
    {
        $scheme = $value['scheme'] ?? '';
        $out = [
            'userOverrides' => is_array($value['userOverrides'] ?? null) ? $value['userOverrides'] : [],
            'scheme' => in_array($scheme, \Chronicler\Rest\Schemas::SCHEMES, true) ? $scheme : 'light',
            'customCss' => is_string($value['customCss'] ?? null) ? $value['customCss'] : '',
            'rule_ids' => array_values(array_map(
                'intval',
                array_filter((array) ($value['rule_ids'] ?? []), 'is_numeric')
            )),
        ];
        if (is_array($value['controls'] ?? null)) {
            $out['controls'] = $value['controls'];
        }
        return $out;
    }
}
