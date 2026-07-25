<?php

namespace Chronicler\Slack\Bot;

/**
 * Block Kit builders for `/game my` (design 2026-07-25 §3): one renderer per
 * property type, mirroring what the HTML sheet shows as closely as Slack's
 * blocks allow — a boxed signed modifier for `number`, filled/empty circles
 * for `track`, an option's label (never its id) for `select`.
 *
 * Every builder is PURE: it takes a property already serialized by
 * chronicler_sheets_sheet_for_viewer() — the definition plus `value`,
 * `display` and `detail` — and returns blocks. It performs NO visibility
 * checks of its own and must never be handed a property the authority already
 * withheld; deciding audience is that function's job, and duplicating the
 * decision here is exactly the second filter loop the authority exists to
 * prevent.
 *
 * The schema-level helpers it does call (display_value, when_holds,
 * filter_placeholder_entries) are the same ones sheets/render.php uses, so
 * Slack and the web page agree on what a value looks like.
 */
final class BlockKit
{
    /** Slack renders at most 50 blocks per message; the 51st is dropped
     * silently, which reads as "that's all you have". cap() refuses to. */
    public const MAX_BLOCKS = 50;

    /** Prose in a chat message earns its keep by being short (design
     * decision 3): flatten the rich text, cut here, link the rest. */
    public const LONGTEXT_MAX = 500;

    /** A `list` entry's field values are one line among many — clip hard. */
    private const FIELD_MAX = 120;

    /** Slack's `header` block takes plain_text only, capped at 150. */
    public static function header(string $text): array
    {
        return [
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => self::clip($text, 150), 'emoji' => true],
        ];
    }

    /** A body block. $mrkdwn is ALREADY escaped by its caller. */
    public static function text(string $mrkdwn): array
    {
        return ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $mrkdwn]];
    }

    /** The small grey line under a block — details, counts, truncation notes. */
    public static function context(string $mrkdwn): array
    {
        return ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $mrkdwn]]];
    }

    /**
     * One serialized property as blocks. Empty for `opinions`, which render on
     * NPC pages only and are per-PC private — they have no meaning in a "my
     * character" view, so there is nothing here to leak or to show.
     *
     * $url is the character's public page, used only when a longtext had to be
     * truncated (the reader needs somewhere to finish it).
     */
    public static function property(array $property, ?string $url = null): array
    {
        if (($property['type'] ?? '') === 'opinions') {
            return [];
        }
        $label = Commands::escape((string) ($property['label'] ?? ''));
        $value = $property['value'] ?? null;
        $display = Commands::escape((string) ($property['display'] ?? ''));
        $notes = [];
        $blocks = [];

        switch ($property['type']) {
            case 'number':
                // The sheet's boxed modifier: the number leads, big and bold,
                // and the label sits beside it.
                $blocks[] = self::text("*$display*  $label");
                break;

            case 'track':
                $length = (int) ($property['length'] ?? 0);
                $blocks[] = self::text("*$label*  " . self::circles((int) $value, $length) . "  $display");
                break;

            case 'counter':
                $blocks[] = self::text("*$label*  $display");
                break;

            case 'toggle':
                $blocks[] = self::text(($value ? '✓' : '✗') . "  *$label*");
                break;

            case 'select':
                // display_value already resolved the option's label.
                $blocks[] = self::text("*$label*  $display");
                break;

            case 'checklist':
                $checked = (array) $value;
                $lines = ["*$label*"];
                foreach (($property['options'] ?? []) as $option) {
                    if (in_array($option['id'], $checked, true)) {
                        $lines[] = '• ' . Commands::escape((string) $option['label']);
                    }
                }
                if (count($lines) === 1) {
                    $lines[] = '_Nothing recorded yet._';
                }
                $blocks[] = self::text(implode("\n", $lines));
                $notes[] = $display;
                break;

            case 'list':
                $blocks[] = self::text(implode("\n", array_merge(
                    ["*$label*"],
                    self::entries($property, (array) $value)
                )));
                break;

            case 'longtext':
                $plain = wp_strip_all_tags((string) $value);
                $plain = trim(preg_replace('/\n{3,}/', "\n\n", $plain));
                if ($plain === '') {
                    $blocks[] = self::text("*$label*\n_Nothing recorded yet._");
                    break;
                }
                $clipped = self::clip($plain, self::LONGTEXT_MAX);
                $blocks[] = self::text("*$label*\n" . Commands::escape($clipped));
                if ($clipped !== $plain && $url !== null && $url !== '') {
                    $notes[] = '<' . $url . '|read the rest on the sheet>';
                }
                break;

            default: // text
                $shown = trim((string) $value) === ''
                    ? '_Nothing recorded yet._'
                    : Commands::escape(self::clip((string) $value, self::LONGTEXT_MAX));
                $blocks[] = self::text("*$label*  $shown");
                break;
        }

        $detail = trim((string) ($property['detail'] ?? ''));
        if ($detail !== '') {
            $notes[] = '_' . Commands::escape($detail) . '_';
        }
        if ($notes !== []) {
            $blocks[] = self::context(implode('  ·  ', array_filter($notes)));
        }
        return $blocks;
    }

    /** A heading block followed by each property's blocks, in layout order. */
    public static function section(string $heading, array $properties, ?string $url = null): array
    {
        $blocks = [self::header($heading)];
        foreach ($properties as $property) {
            foreach (self::property($property, $url) as $block) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /**
     * Slack's 50-block ceiling, enforced out loud. Over the cap, the tail is
     * replaced by a context line counting what did not fit and linking the
     * full sheet — a silent truncation would read as "that's all you have".
     */
    public static function cap(array $blocks, ?string $url = null): array
    {
        if (count($blocks) <= self::MAX_BLOCKS) {
            return $blocks;
        }
        $kept = array_slice($blocks, 0, self::MAX_BLOCKS - 1);
        $dropped = count($blocks) - count($kept);
        $note = $dropped . ($dropped === 1 ? ' more block' : ' more blocks') . " didn't fit in one Slack message";
        $note .= $url !== null && $url !== '' ? ' — <' . $url . '|see the full sheet>.' : '.';
        $kept[] = self::context($note);
        return $kept;
    }

    /** ●●●○○○○ — the sheet's fillable boxes, as far as chat can carry them. */
    public static function circles(int $filled, int $length): string
    {
        $length = max(0, min($length, 40));
        $filled = max(0, min($filled, $length));
        return str_repeat('●', $filled) . str_repeat('○', $length - $filled);
    }

    /**
     * One line per list entry: the first field bold as the entry's name, the
     * remaining non-empty fields as `label: value` after an em dash. `when`
     * gated fields that evaluate false are omitted, same as the sheet, and
     * placeholder-only rows are stripped before any of it.
     */
    private static function entries(array $property, array $value): array
    {
        $entries = chronicler_sheets_filter_placeholder_entries($property, $value);
        if ($entries === []) {
            return ['_None yet._'];
        }
        $fields = $property['fields'] ?? [];
        $first = $fields[0] ?? null;
        $lines = [];
        foreach ($entries as $entry) {
            $name = $first === null ? '' : trim((string) ($entry[$first['id']] ?? ''));
            $parts = [];
            foreach (array_slice($fields, 1) as $field) {
                if (!chronicler_sheets_when_holds($property, $field, $entry)) {
                    continue;
                }
                $raw = $entry[$field['id']] ?? chronicler_sheets_default_value($field);
                if ($field['type'] === 'toggle') {
                    if ($raw) {
                        $parts[] = Commands::escape((string) $field['label']);
                    }
                    continue;
                }
                $shown = trim(wp_strip_all_tags((string) chronicler_sheets_display_value($field, $raw)));
                if ($shown === '') {
                    continue;
                }
                $parts[] = Commands::escape((string) $field['label']) . ': '
                    . Commands::escape(self::clip($shown, self::FIELD_MAX));
            }
            $line = '• *' . Commands::escape($name === '' ? 'Untitled' : $name) . '*';
            $lines[] = $parts === [] ? $line : $line . ' — ' . implode(', ', $parts);
        }
        return $lines;
    }

    /** Truncate on characters, not bytes, appending an ellipsis when it bites. */
    private static function clip(string $text, int $max): string
    {
        if (function_exists('mb_strlen') ? mb_strlen($text) <= $max : strlen($text) <= $max) {
            return $text;
        }
        $cut = function_exists('mb_substr') ? mb_substr($text, 0, $max - 1) : substr($text, 0, $max - 1);
        return rtrim($cut) . '…';
    }
}
