<?php
// The active-character authority (GitHub #17). chr_active is tri-state per
// sheet: absent = never decided, '1' = the player's active character, '0' =
// a tombstone — the GM explicitly said "not this one". The resolver below is
// the ONE answer to "this player's character"; login (sheets/login.php) and
// the Slack user-meta chain (post-types.php) both delegate here.

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The player's active character id, healing lazily as it reads: the flagged
 * sheet wins; else the most recently modified undecided sheet is promoted —
 * written back, so the pick sticks — and a player whose eligible sheets are
 * all tombstoned (or who has none) gets null, on purpose. Lazy healing at
 * read time replaces publish/trash/status-transition tracking: any event
 * that unsets or invalidates the flag simply leaves a state the next
 * resolution repairs. Every caller runs during a POST (login, Slack
 * commands), so the repair write never happens on a public GET. NPCs are
 * never eligible (#176), even if a stale flag survives on one.
 */
function chronicler_sheets_active_character_for(int $user_id): ?int {
    $ids = get_posts([
        'post_type' => 'chr_character',
        'post_status' => 'publish',
        'author' => $user_id,
        'numberposts' => -1,
        'orderby' => 'modified',
        'order' => 'DESC',
        'fields' => 'ids',
    ]);
    if ($ids === []) {
        return null;
    }
    update_meta_cache('post', $ids);
    $undecided = null;
    foreach ($ids as $id) {
        $id = (int) $id;
        if (chronicler_sheets_is_npc($id)) {
            continue;
        }
        $flag = get_post_meta($id, 'chr_active', true);
        if ($flag === '1') {
            return $id;
        }
        if ($flag !== '0' && $undecided === null) {
            $undecided = $id;
        }
    }
    if ($undecided !== null) {
        update_post_meta($undecided, 'chr_active', '1');
    }
    return $undecided;
}
