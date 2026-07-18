<?php

namespace Chronicler\Store;

/**
 * Storage schema versioning for #101, mirroring the Capabilities::ensure()
 * pattern: activation hooks do NOT fire on plugin updates, so an option
 * records the schema version whose upgrade routine has run, and ensure() —
 * hooked on `init` and on activation — re-runs the upgrades whenever the
 * recorded version lags. Costs one autoloaded-option read per request.
 *
 * Upgrade steps are cumulative and idempotent (dbDelta diffs; option writes
 * overwrite): upgrading from any older version replays every later step.
 */
final class Schema
{
    public const OPTION = 'chronicler_schema_version';

    /** Bump when the stored shape changes, and add a step in upgrade(). */
    public const VERSION = 1;

    public static function ensure(): void
    {
        $installed = (int) get_option(self::OPTION, 0);
        if ($installed === self::VERSION) {
            return;
        }
        self::upgrade($installed);
        update_option(self::OPTION, self::VERSION);
    }

    /** Run every upgrade step newer than $from. */
    public static function upgrade(int $from): void
    {
        if ($from < 1) {
            // v1: the Sessions table. Rules (CPT) and Settings (options)
            // need no DDL.
            Sessions::createTable();
        }
    }
}
