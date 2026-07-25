<?php

namespace Chronicler\Slack\Bot;

/**
 * `/game link [name]` — the signpost half of identity linking. The bot
 * NEVER writes the mapping: it reports the caller's Slack member id (which
 * Slack itself asserts in the signature-verified payload) and links to the
 * character's sheet editor, where the Slack member box saves it. WordPress's
 * own per-character edit capability is the entire authorization check, so
 * there is no token, no confirmation, and no inbound consent surface here.
 *
 * reply() is pure so tests/run.php covers the wording; handle() is the thin
 * WordPress shell that resolves the name and builds the edit URL.
 */
final class Link
{
    /** Build the ephemeral reply. See the class docblock for the split. */
    public static function reply(string $slackId, ?string $name, ?array $found, array $roster): array
    {
        $id = $slackId !== '' ? '`' . $slackId . '`' : '(unknown — Slack sent no user id)';

        if ($found !== null) {
            $text = "*{$found['name']}* — <{$found['editUrl']}|open the character sheet>.\n"
                . "Your Slack member id is $id.\n"
                . 'Paste it into the *Slack member* box on that sheet and hit Update. '
                . "That's the whole link — you can only edit your own sheet, so nothing else needs approving.";
            return ['response_type' => 'ephemeral', 'text' => $text];
        }

        $lines = [];
        if ($name !== null && trim($name) !== '') {
            $lines[] = 'No character goes by `' . Commands::escape(trim($name)) . '`.';
        } else {
            $lines[] = 'Tell me which character is yours: `' . Commands::COMMAND . ' link <name>`.';
        }
        if ($roster !== []) {
            $lines[] = 'Characters: ' . implode(', ', array_map(
                static fn($n) => '*' . Commands::escape((string) $n) . '*',
                $roster
            )) . '.';
        }
        $lines[] = "Your Slack member id is $id.";
        return ['response_type' => 'ephemeral', 'text' => implode("\n", $lines)];
    }

    /**
     * Resolve the named character and hand reply() what it needs. The roster
     * is every published player character's log name, in index order — the
     * same vocabulary transcripts use.
     *
     * The sheet URL is assembled here rather than by get_edit_post_link(),
     * which capability-checks the CURRENT user and so returns null for every
     * slash command — a Slack request carries no WordPress session, and the
     * deep link is the entire point of the reply. Publishing the URL leaks
     * nothing: wp-admin re-checks on load, so a non-owner following it gets
     * refused exactly as the design assumes.
     */
    public static function handle(array $params): array
    {
        [, $name] = Commands::tokenize(is_string($params['text'] ?? null) ? $params['text'] : '');
        $slackId = is_string($params['user_id'] ?? null) ? $params['user_id'] : '';

        $roster = [];
        foreach (chronicler_sheets_player_characters() as $id) {
            $roster[$id] = chronicler_sheets_display_name_for(
                (string) get_post_meta($id, 'chr_goes_by', true),
                get_the_title($id)
            );
        }

        $matched = $name === '' ? null : chronicler_sheets_match_display_name($name, $roster);
        $found = $matched === null ? null : [
            'name' => $roster[$matched],
            'editUrl' => admin_url('post.php?post=' . $matched . '&action=edit'),
        ];

        return self::reply($slackId, $name === '' ? null : $name, $found, array_values($roster));
    }
}
