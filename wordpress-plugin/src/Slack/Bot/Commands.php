<?php

namespace Chronicler\Slack\Bot;

/**
 * The /game slash command: tokenize the text into a subcommand and
 * dispatch. Everything here is pure (arrays in, Slack in-body message
 * arrays out) so tests/run.php covers the whole router; the only
 * WordPress in the chain is Inbound's transport shell.
 *
 * help, unknown, link, my, roll and effect ship today. Later phases add match
 * arms — stat (writes) — each implemented in its own class and dispatched
 * from here, the way Link, My, Roll and Effect are.
 */
final class Commands
{
    /**
     * The slash command players type. Named here because every other
     * mention derives from it — help text, the unknown-subcommand hint,
     * and the Settings self-check's synthetic payload. The one copy this
     * constant CANNOT reach is slack-app-manifest.yml (a static file
     * Slack parses, not PHP), so slack-inbound.test.php pins the two
     * together the way the repo pins its other cross-file literals.
     *
     * It is deliberately generic — players think "game", not the name of
     * the plugin publishing their transcripts. Renaming it is this line
     * plus the manifest, and costs every workspace one manifest re-paste.
     */
    public const COMMAND = '/game';

    /** [name, help blurb], in help's display order. Advertising a
     * subcommand before it ships is deliberate: the manifest usage_hint
     * names them, so help must explain rather than 404. */
    public const SUBCOMMANDS = [
        ['help', 'this summary'],
        ['link', 'connect your Slack account to your character'],
        ['my', 'show your character — a stat, a section, or everything'],
        ['roll', "roll one of your system's rolls"],
        ['effect', 'see what a character is carrying (game masters apply and clear)'],
    ];

    /** Route a verified slash-command payload to a response body. */
    public static function dispatch(array $params): array
    {
        [$sub] = self::tokenize(is_string($params['text'] ?? null) ? $params['text'] : '');
        return match ($sub) {
            '', 'help' => self::help(),
            'link' => Link::handle($params),
            'my' => My::handle($params),
            'roll' => Roll::handle($params),
            'effect' => Effect::handle($params),
            default => self::unknown($sub),
        };
    }

    /** First word (lowercased) and the remainder (original case, single
     * split — inner spacing of the remainder survives for later parsers). */
    public static function tokenize(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $trimmed, 2);
        return [strtolower($parts[0]), $parts[1] ?? ''];
    }

    /** Slack mrkdwn's three control characters, per Slack's escaping rules. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private static function help(): array
    {
        $lines = ['*Chronicler* — campaign tools for this workspace.'];
        foreach (self::SUBCOMMANDS as [$name, $blurb]) {
            $lines[] = '• `' . self::COMMAND . " $name` — $blurb";
        }
        return ['response_type' => 'ephemeral', 'text' => implode("\n", $lines)];
    }

    private static function unknown(string $sub): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => 'Unknown subcommand `' . self::escape($sub) . '`. Try `' . self::COMMAND . ' help`.',
        ];
    }
}
