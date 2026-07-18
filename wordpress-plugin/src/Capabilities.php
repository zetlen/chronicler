<?php

namespace Chronicler;

/**
 * Plugin-wide capabilities for the Chronicler-in-WordPress port, beyond the
 * sheets module's post-type caps (CHRONICLER_CHARACTER_CAPS in
 * sheets/post-types.php).
 *
 * Three tiers since #159 (one coarse capability before that): granting
 * COMPOSE alone hands out session drafting and nothing else, so it is safe
 * to delegate to a lower-privilege role (`wp user add-cap <user>
 * chronicler_compose`, per docs/rest-api.md).
 */
final class Capabilities
{
    /** Session drafting: the session editor admin page, session/image REST
     *  routes, and the read routes the editor depends on (rules, settings). */
    public const COMPOSE = 'chronicler_compose';

    /** Site configuration: settings/rules/import writes (REST and the
     *  wp-admin Rules + Settings screens, bot token included). */
    public const MANAGE = 'chronicler_manage';

    /** The Slack proxy — it reads whatever the bot token can see, so it is
     *  its own grant rather than a compose side effect. */
    public const SLACK_READ = 'chronicler_slack_read';

    /** Everything grant() bestows, in one place for grant loops and tests. */
    public const ALL = [self::COMPOSE, self::MANAGE, self::SLACK_READ];

    /**
     * Option recording the plugin version whose grants have run. Activation
     * hooks do NOT fire on plugin updates, so already-active installs would
     * otherwise never receive capabilities added in a newer version — the
     * exact path a zip upload over an existing install takes.
     */
    public const GRANTED_OPTION = 'chronicler_caps_version';

    /**
     * Activation-time grant, mirroring chronicler_sheets_activate():
     * administrators get every tier out of the box; anyone else gets
     * capabilities explicitly, tier by tier.
     */
    public static function grant(): void
    {
        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::ALL as $capability) {
                $admin->add_cap($capability);
            }
        }
        update_option(self::GRANTED_OPTION, CHRONICLER_VERSION);
    }

    /**
     * Update-safe re-grant, hooked on `init` (not `admin_init` — REST
     * requests never fire that, and chronicler/v1 permissions check these
     * capabilities). Costs one autoloaded option read per request; re-runs
     * grant() only when the plugin version changes, and grant() is
     * idempotent.
     */
    public static function ensure(): void
    {
        if (get_option(self::GRANTED_OPTION) !== CHRONICLER_VERSION) {
            self::grant();
        }
    }
}
