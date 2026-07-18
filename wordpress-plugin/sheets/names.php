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
