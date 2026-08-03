<?php
// Player login landing (GitHub #1): a player-shaped user who logs in with no
// explicit destination lands on the public page of their character sheet —
// the gameplay surface (live stat edits, admin-bar Edit) — instead of the
// wp-admin dashboard, carrying a one-time chr_welcome arg the sheet renderer
// turns into an arrival banner (sheets/render.php).

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether a requested redirect is "default-shaped": empty (wp-login.php with
 * no redirect_to), or the dashboard root auth_redirect() produces when
 * someone just types /wp-admin/ — with or without a trailing slash or
 * index.php. Anything else is a deliberate deep link and is honored.
 */
function chronicler_sheets_is_default_login_target(string $requested, string $admin_root): bool {
    $requested = trim($requested);
    if ($requested === '') {
        return true;
    }
    $normalize = static function (string $url): string {
        return rtrim((string) preg_replace('#index\.php$#', '', $url), '/');
    };
    return $normalize($requested) === $normalize($admin_root);
}

/**
 * Player-shaped, capability- not role-slug-shaped, mirroring
 * chronicler_sheets_media_scoped() (caps.php): their only claim to
 * characters is the own-sheet grant. GMs (edit_others_chr_characters) and
 * broader core editors keep their default landing. user_can(), not
 * current_user_can(): at login_redirect time the current user is not set.
 */
function chronicler_sheets_user_is_player_shaped($user): bool {
    return $user instanceof WP_User
        && user_can($user, 'edit_chr_characters')
        && !user_can($user, 'edit_others_chr_characters')
        && !user_can($user, 'edit_others_posts');
}

/**
 * login_redirect filter: send a player-shaped login to the most recently
 * modified published character they author (the one-active-character proxy
 * until GitHub #17). Published-only matches the player's own caps and the
 * Slack lookup's rule. No sheet, a failed login (WP_Error), or a deep link
 * all keep WordPress's default behavior.
 */
function chronicler_sheets_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (!chronicler_sheets_user_is_player_shaped($user)) {
        return $redirect_to;
    }
    if (!chronicler_sheets_is_default_login_target((string) $requested_redirect_to, admin_url())) {
        return $redirect_to;
    }
    $ids = get_posts([
        'post_type' => 'chr_character',
        'post_status' => 'publish',
        'author' => (int) $user->ID,
        'numberposts' => 1,
        'orderby' => 'modified',
        'order' => 'DESC',
        'fields' => 'ids',
    ]);
    if ($ids === []) {
        return $redirect_to;
    }
    return add_query_arg('chr_welcome', '1', get_permalink((int) $ids[0]));
}

// Not during uninstall: uninstall.php loads sheets files for constants only
// (same rule as caps.php).
if (!defined('WP_UNINSTALL_PLUGIN')) {
    add_filter('login_redirect', 'chronicler_sheets_login_redirect', 10, 3);
}
