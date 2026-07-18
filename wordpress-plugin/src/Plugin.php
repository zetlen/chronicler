<?php

namespace Chronicler;

/**
 * Namespace root for the Chronicler-in-WordPress port.
 *
 * Real classes (Slack client, REST routes) land here in later milestones;
 * for now this placeholder gives the PSR-4 autoloader something to resolve
 * so the Composer scaffolding can be sanity-checked end to end.
 */
final class Plugin
{
    /** True when Composer's autoloader resolved this class. */
    public static function autoloaded(): bool
    {
        return true;
    }
}
