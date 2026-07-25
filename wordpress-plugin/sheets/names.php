<?php
// Pure name-resolution helpers, shared by the profile picker (admin.php) and
// the character-names REST route (rest.php). No WordPress calls, no add_action:
// the standalone test runner requires this file directly.

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

/** A character's log name: its "goes by" nickname, or the full title when unset. */
function chronicler_sheets_display_name_for(string $goes_by, string $title): string {
    $goes_by = trim($goes_by);
    return $goes_by !== '' ? $goes_by : $title;
}

/**
 * Resolve a typed name to one character id, case-insensitively, against a
 * [postId => display name] map (the display name being what
 * chronicler_sheets_display_name_for() returns). Whole-name matching only —
 * a prefix must not silently pick a character, since the reply hands out an
 * edit link. Ties go to the first candidate, so the caller's ordering (the
 * character index order) decides. Pure; unit-tested in tests/run.php.
 */
function chronicler_sheets_match_display_name(string $query, array $candidates): ?int {
    $needle = trim($query);
    if ($needle === '') {
        return null;
    }
    foreach ($candidates as $id => $name) {
        if (is_string($name) && strcasecmp(trim($name), $needle) === 0) {
            return (int) $id;
        }
    }
    return null;
}

/**
 * Map a Slack users.list `members` array to [id => display name], preferring
 * display_name, then real_name, then the handle, then the id. Deleted users,
 * bots, and Slackbot are dropped — none of them play characters.
 */
function chronicler_sheets_parse_slack_users(array $members): array {
    $map = [];
    foreach ($members as $m) {
        if (!is_array($m)) {
            continue;
        }
        $id = $m['id'] ?? '';
        if (!is_string($id) || $id === '' || $id === 'USLACKBOT') {
            continue;
        }
        if (!empty($m['deleted']) || !empty($m['is_bot'])) {
            continue;
        }
        $profile = is_array($m['profile'] ?? null) ? $m['profile'] : [];
        $name = trim((string) ($profile['display_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($profile['real_name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string) ($m['name'] ?? ''));
        }
        $map[$id] = $name !== '' ? $name : $id;
    }
    return $map;
}
