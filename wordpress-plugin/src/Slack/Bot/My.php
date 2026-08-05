<?php

namespace Chronicler\Slack\Bot;

/**
 * `/game my [thing]` — any top-level trait of the caller's linked character,
 * rendered in Slack (design 2026-07-25 §2, §3, §5).
 *
 * The bot has NO vocabulary of its own: "stats", "moves" and "gear" are not
 * bot concepts, they are whatever the game system's author wrote, and
 * resolve() matches the player's word against the template's own ids, labels
 * and section headings. It guesses nothing — an ambiguous prefix comes back as
 * a disambiguation, never a pick.
 *
 * resolve() and reply() are PURE (sheet array in, resolution / Slack body
 * out), so the whole precedence contract is unit-tested without WordPress.
 * handle() is the WordPress shell, and it is the security-bearing half: it
 * resolves the Slack caller to a WP user, calls wp_set_current_user(), and
 * then reads the sheet through chronicler_sheets_sheet_for_viewer() — the ONE
 * sanctioned audience-filtered reader. chronicler_sheets_get_value() filters
 * nothing and must never be called from here.
 *
 * Replies are ephemeral: a sheet can carry owner_only fields and is addressed
 * to the asker. (`/game roll` is the in_channel one — dice are social.)
 */
final class My
{
    /** The words that mean "the whole thing". */
    public const WHOLE_SHEET = ['all', 'sheet', 'everything'];

    // -- The WordPress shell --------------------------------------------------

    public static function handle(array $params): array
    {
        [, $query] = Commands::tokenize(is_string($params['text'] ?? null) ? $params['text'] : '');
        $slackId = is_string($params['user_id'] ?? null) ? $params['user_id'] : '';
        $viewer = self::viewer($slackId);
        if (isset($viewer['reply'])) {
            return $viewer['reply'];
        }
        return self::reply(self::resolve($query, $viewer['sheet']), $viewer['sheet'], $viewer['url']);
    }

    /**
     * Resolve a Slack caller to a character AND to the WordPress identity the
     * visibility gates will be asked about, then read the sheet through the
     * authority. Shared with Roll, so there is exactly one copy of this.
     *
     * Returns ['character' =>, 'sheet' =>, 'url' =>] or ['reply' =>] with the
     * refusal to send back. Reachability is this function's own duty:
     * sheet_for_viewer() deliberately does not gate on post status or password
     * (those answer "may you ask about this character at all", which only the
     * caller knows how to refuse). Status is already covered — the Slack-id
     * lookup returns published characters only — so the password gate is what
     * is added here, matching the REST route's rule.
     */
    public static function viewer(string $slackId): array
    {
        $character = chronicler_sheets_character_for_slack_id($slackId);
        if ($character === null) {
            return ['reply' => self::unlinked()];
        }
        self::become($slackId, $character);
        $id = (int) $character->ID;
        if (post_password_required($character) && !current_user_can('edit_post', $id)) {
            return ['reply' => self::plain(
                'That character sheet is password-protected, so I can\'t read it out here.'
            )];
        }
        $sheet = chronicler_sheets_sheet_for_viewer($id);
        if ($sheet === null) {
            return ['reply' => self::plain(
                'That character has no sheet template yet, so there is nothing to show.'
            )];
        }
        return ['character' => $character, 'sheet' => $sheet, 'url' => (string) get_permalink($character)];
    }

    /**
     * Become the WordPress user this Slack caller is, so current_user_can()
     * inside the visibility authority means the right thing. The user-meta
     * link wins; failing that the linked character's author is who the caller
     * is, which is how a player linked only on their own sheet still sees
     * their owner_only fields.
     *
     * This GRANTS nothing on its own — it supplies the identity the existing
     * gates already check. Slack asserted the user id in the signature-verified
     * payload, and Inbound refuses everything unverified before we get here.
     *
     * Public because /game effect needs the identity WITHOUT the sheet read
     * around it: a game master applying an effect to somebody else may have no
     * character of their own, and $character null then falls back to user 0 —
     * nobody, holding no capability, which is the right answer for a Slack id
     * this site has never heard of.
     */
    public static function become(string $slackId, $character = null): void
    {
        $users = $slackId === '' ? [] : get_users([
            'meta_key' => 'chronicler_slack_user_id',
            'meta_value' => $slackId,
            'number' => 1,
            'fields' => 'ID',
        ]);
        wp_set_current_user($users ? (int) $users[0] : (int) ($character->post_author ?? 0));
    }

    // -- Resolution (pure) ----------------------------------------------------

    /**
     * Match one query against the sheet. First hit wins and the ORDER IS THE
     * CONTRACT (design §2): property id → section id → property label →
     * section heading → the whole-sheet words → unique prefix → nothing.
     *
     * A property id outranking a section id is the one deliberate collision:
     * the narrower answer is the right default, and an author who wants
     * otherwise renames one, since they own both namespaces.
     *
     * Returns one of:
     *   ['kind' => 'overview']                                  (empty query)
     *   ['kind' => 'property',  'property' => …]
     *   ['kind' => 'section',   'id' =>, 'section' =>, 'properties' => […]]
     *   ['kind' => 'all']
     *   ['kind' => 'ambiguous', 'candidates' => […labels…]]
     *   ['kind' => 'none']
     */
    public static function resolve(string $query, array $sheet): array
    {
        $q = self::normalize($query);
        if ($q === '') {
            return ['kind' => 'overview'];
        }
        // Ids are written with underscores but spoken with spaces; "moves gear"
        // and "moves_gear" are the same request.
        $qid = str_replace(' ', '_', $q);
        $sections = self::sections($sheet);
        $properties = [];
        foreach ($sheet['properties'] as $property) {
            $properties[$property['id']] = $property;
        }

        foreach ($properties as $id => $property) {
            if ($id === $qid) {
                return ['kind' => 'property', 'property' => $property];
            }
        }
        foreach ($sections as $section) {
            if (($section['id'] ?? '') === $qid) {
                return self::sectionResolution($section, $properties);
            }
        }
        foreach ($properties as $property) {
            if (self::normalize((string) $property['label']) === $q) {
                return ['kind' => 'property', 'property' => $property];
            }
        }
        foreach ($sections as $section) {
            if (self::normalize((string) $section['section']) === $q) {
                return self::sectionResolution($section, $properties);
            }
        }
        if (in_array($q, self::WHOLE_SHEET, true)) {
            return ['kind' => 'all'];
        }

        // Unique prefix. Candidates are keyed by target, so a word that is a
        // prefix of both a property's id AND its label counts once.
        $candidates = [];
        foreach ($properties as $id => $property) {
            if (self::startsWith($id, $qid) || self::startsWith(self::normalize((string) $property['label']), $q)) {
                $candidates['property:' . $id] = [
                    'label' => (string) $property['label'],
                    'resolution' => ['kind' => 'property', 'property' => $property],
                ];
            }
        }
        foreach ($sections as $section) {
            $sid = (string) ($section['id'] ?? '');
            if (!self::startsWith($sid, $qid) && !self::startsWith(self::normalize((string) $section['section']), $q)) {
                continue;
            }
            // First declaration wins the key, which is what makes an AUTHORED
            // section beat the synthetic trailing one when both answer to the
            // same id (see sections()).
            $candidates['section:' . $sid] ??= [
                'label' => (string) $section['section'],
                'resolution' => self::sectionResolution($section, $properties),
            ];
        }
        if (count($candidates) === 1) {
            return reset($candidates)['resolution'];
        }
        if (count($candidates) > 1) {
            return ['kind' => 'ambiguous', 'candidates' => array_values(array_map(
                static fn(array $c): string => $c['label'],
                $candidates
            ))];
        }
        return ['kind' => 'none'];
    }

    /**
     * The sheet's sections in display order, with every property the layout
     * never placed collected into a trailing synthetic "Other" — the same
     * grouping chronicler_sheets_layout_sections() gives the HTML sheet.
     * (sheet_for_viewer returns the filtered LAYOUT, which has no such
     * section, so this is where the two surfaces are reconciled.)
     *
     * A template that authors its own `other` section keeps it: resolution
     * scans in order and this appends, so the authored one is found first.
     */
    public static function sections(array $sheet): array
    {
        $sections = [];
        $placed = [];
        foreach (($sheet['layout'] ?? []) as $section) {
            $sections[] = $section;
            foreach ($section['properties'] as $pid) {
                $placed[$pid] = true;
            }
        }
        $rest = [];
        foreach ($sheet['properties'] as $property) {
            if (!isset($placed[$property['id']])) {
                $rest[] = $property['id'];
            }
        }
        if ($rest !== []) {
            $sections[] = ['id' => 'other', 'section' => 'Other', 'properties' => $rest, 'masthead' => false];
        }
        return $sections;
    }

    // -- Rendering (pure) -----------------------------------------------------

    /** Turn a resolution into the Slack response body. */
    public static function reply(array $resolution, array $sheet, ?string $url = null): array
    {
        $name = (string) ($sheet['title'] ?? 'Your character');
        switch ($resolution['kind']) {
            case 'property':
                $property = $resolution['property'];
                $blocks = BlockKit::property($property, $url);
                if ($blocks === []) {
                    // opinions — the one type with nothing to say here.
                    return self::plain(
                        Commands::escape((string) $property['label'])
                        . ' is recorded per character on the sheet page, not here.'
                    );
                }
                return self::blocks(
                    $blocks,
                    $name . ' — ' . $property['label'] . ': ' . $property['display'],
                    $url
                );

            case 'section':
                $shown = self::shown($resolution['properties']);
                if ($shown === []) {
                    return self::plain('Nothing is filled in under *'
                        . Commands::escape((string) $resolution['section']) . '* yet.');
                }
                return self::blocks(
                    BlockKit::section((string) $resolution['section'], $shown, $url),
                    $name . ' — ' . $resolution['section'],
                    $url
                );

            case 'all':
                $blocks = [self::banner($sheet, $url)];
                foreach (self::sections($sheet) as $section) {
                    $shown = self::shown(self::propertiesOf($section, $sheet));
                    if ($shown === []) {
                        continue;
                    }
                    foreach (BlockKit::section((string) $section['section'], $shown, $url) as $block) {
                        $blocks[] = $block;
                    }
                }
                return self::blocks($blocks, $name . ' — the whole sheet', $url);

            case 'ambiguous':
                $names = array_map(
                    static fn($c) => '*' . Commands::escape((string) $c) . '*',
                    $resolution['candidates']
                );
                return self::plain('Which one? ' . implode(' or ', $names) . '.');

            case 'none':
                return self::plain("I don't know that one. You can ask for:\n" . self::vocabulary($sheet));

            case 'overview':
            default:
                $blocks = [self::banner($sheet, $url)];
                foreach (self::sections($sheet) as $section) {
                    if (empty($section['masthead'])) {
                        continue;
                    }
                    foreach (self::shown(self::propertiesOf($section, $sheet)) as $property) {
                        foreach (BlockKit::property($property, $url) as $block) {
                            $blocks[] = $block;
                        }
                    }
                }
                $blocks[] = BlockKit::context('Ask for any of these: ' . self::vocabulary($sheet, ' · '));
                return self::blocks($blocks, $name . ' — ' . ($sheet['system'] ?? ''), $url);
        }
    }

    /** The name/system line every block reply opens with. */
    private static function banner(array $sheet, ?string $url): array
    {
        $name = Commands::escape((string) ($sheet['title'] ?? 'Your character'));
        $linked = $url !== null && $url !== '' ? '<' . $url . '|' . $name . '>' : $name;
        $system = trim((string) ($sheet['system'] ?? ''));
        return BlockKit::text('*' . $linked . '*' . ($system === '' ? '' : '  ·  ' . Commands::escape($system)));
    }

    /** A section's serialized properties, in the order the layout names them. */
    private static function propertiesOf(array $section, array $sheet): array
    {
        $by = [];
        foreach ($sheet['properties'] as $property) {
            $by[$property['id']] = $property;
        }
        $out = [];
        foreach ($section['properties'] as $pid) {
            if (isset($by[$pid])) {
                $out[] = $by[$pid];
            }
        }
        return $out;
    }

    /**
     * Group renders drop unfilled properties unless the template flags them
     * always_show — the HTML sheet's rule, not REST's. A chat message full of
     * blank rows is noise. A single-property query bypasses this and says the
     * property is empty instead, because "you asked, and there's nothing
     * there" is an answer.
     */
    private static function shown(array $properties): array
    {
        return array_values(array_filter($properties, static function (array $property): bool {
            if ($property['type'] === 'opinions') {
                return false;
            }
            return chronicler_sheets_is_always_show($property)
                || !chronicler_sheets_is_unfilled($property, $property['value'] ?? null);
        }));
    }

    /** "What you can ask for": sections by heading, then the labels in each. */
    private static function vocabulary(array $sheet, string $glue = "\n"): string
    {
        $lines = [];
        foreach (self::sections($sheet) as $section) {
            $labels = [];
            foreach (self::propertiesOf($section, $sheet) as $property) {
                if ($property['type'] !== 'opinions') {
                    $labels[] = Commands::escape((string) $property['label']);
                }
            }
            if ($labels === []) {
                continue;
            }
            $lines[] = '*' . Commands::escape((string) $section['section']) . '* — ' . implode(', ', $labels);
        }
        $lines[] = 'or `' . implode('`, `', self::WHOLE_SHEET) . '` for the whole sheet';
        return implode($glue, $lines);
    }

    private static function sectionResolution(array $section, array $properties): array
    {
        $out = [];
        foreach ($section['properties'] as $pid) {
            if (isset($properties[$pid])) {
                $out[] = $properties[$pid];
            }
        }
        return [
            'kind' => 'section',
            'id' => (string) ($section['id'] ?? ''),
            'section' => (string) $section['section'],
            'properties' => $out,
        ];
    }

    /** Slack needs a `text` fallback for notifications and screen readers. */
    private static function blocks(array $blocks, string $fallback, ?string $url): array
    {
        return [
            'response_type' => 'ephemeral',
            'text' => Commands::escape($fallback),
            'blocks' => BlockKit::cap($blocks, $url),
        ];
    }

    private static function plain(string $text): array
    {
        return ['response_type' => 'ephemeral', 'text' => $text];
    }

    private static function unlinked(): array
    {
        return self::plain(
            "I don't know which character is yours yet — run `"
            . Commands::COMMAND . ' link <name>` and I\'ll show you how to connect it.'
        );
    }

    /** Lowercase, trimmed, inner whitespace collapsed to single spaces. */
    private static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', strtolower(trim($text))));
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $haystack !== '' && $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
