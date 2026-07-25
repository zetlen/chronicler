<?php

namespace Chronicler\Slack\Bot;

/**
 * `/game roll [name]` — roll one of the rolls this character has, with their
 * own values substituted. A roll belongs to whatever declares it (design
 * 2026-07-25, "a move carries its own roll"): the system declares the rolls
 * every character has in its `rolls:` table, and the character declares its
 * own by writing dice on a list entry (a playbook move, a weapon). respond()
 * serves the union — system rolls first, then each contributing list's
 * section, via chronicler_sheets_character_rolls().
 *
 * Resolution follows the same discipline as `/game my`: roll id → roll label →
 * unique prefix, and an ambiguous word comes back as a disambiguation rather
 * than a pick — including a sheet entry named after a system roll, which is
 * ambiguous by decision, never an override. With no argument it lists the
 * union, grouped by section, declaration order, sectionless rolls last.
 *
 * respond() is the whole command minus the viewer resolution — template and
 * viewer-filtered sheet in, Slack body out, randomizer injected — so every
 * branch below, including the security refusal, is unit-tested.
 *
 * THE SECURITY RULE. chronicler_sheets_roll_dice() reads an unresolved
 * placeholder as 0, silently. So a roll whose placeholder references a
 * property the VIEWER-FILTERED sheet does not carry must refuse outright: roll
 * it and a gm_only stat leaks through the arithmetic (subtract the visible
 * terms from the total and you have the secret), and the player is handed a
 * wrong number besides. values() checks every reference against the filtered
 * sheet before a single die is thrown, and the refusal never names the
 * property — naming it would leak its existence.
 *
 * The RESULT posts in_channel: dice are social, and a roll nobody else sees is
 * worthless at a table. Menus and refusals stay ephemeral — a channel does not
 * need everyone's typos.
 */
final class Roll
{
    // -- The WordPress shell --------------------------------------------------

    public static function handle(array $params): array
    {
        [, $query] = Commands::tokenize(is_string($params['text'] ?? null) ? $params['text'] : '');
        $slackId = is_string($params['user_id'] ?? null) ? $params['user_id'] : '';
        // One copy of the viewer resolution, shared with /game my: it maps the
        // Slack caller to a WordPress user, calls wp_set_current_user(), owns
        // the reachability rule, and reads the sheet through the visibility
        // authority. Nothing here reads chronicler_sheets_get_value().
        $viewer = My::viewer($slackId);
        if (isset($viewer['reply'])) {
            return $viewer['reply'];
        }
        // The sheet the authority returns carries no `rolls` — it is a view of
        // one character's values, and the roll table belongs to the system —
        // so the template is read separately here. It is used ONLY for roll
        // definitions and the formula context's property shapes; every VALUE
        // still comes from the filtered sheet.
        $template = chronicler_sheets_template_for_character((int) $viewer['character']->ID);
        if (!is_array($template)) {
            return self::plain('That character has no sheet template yet, so there is nothing to roll.');
        }
        return self::respond($query, $template, $viewer['sheet'], $viewer['url']);
    }

    // -- The command (pure) ---------------------------------------------------

    /**
     * @param array         $template The parsed template (roll definitions).
     * @param array         $sheet    The VIEWER-FILTERED sheet — the only
     *                                source of values, by design.
     * @param ?callable     $rng      fn(int $min, int $max): int; production
     *                                omits it and gets wp_rand().
     */
    public static function respond(
        string $query,
        array $template,
        array $sheet,
        ?string $url = null,
        ?callable $rng = null
    ): array {
        $declared = is_array($template['rolls'] ?? null) ? $template['rolls'] : [];
        // The union everything below sees: the system's rolls (id-keyed,
        // every character has all of them) first, then the sheet's own
        // contributions (2026-07-25: a move carries its own roll) — list
        // entries with dice written on them, id-less by design, keyed
        // "sheet:N" so the union can't collide with the id namespace.
        // Resolution runs over the union, so a sheet roll is rollable
        // exactly like a declared one.
        $rolls = $declared;
        foreach (\chronicler_sheets_character_rolls($sheet) as $i => $contributed) {
            $rolls['sheet:' . $i] = $contributed;
        }
        if ($rolls === []) {
            return self::plain(
                '*' . Commands::escape((string) ($template['system'] ?? 'This system'))
                . '* declares no rolls yet, and your sheet carries none — a game system lists '
                . 'rolls in its `rolls:` table, and a move or gear entry with dice written on '
                . 'it shows up here too.'
            );
        }
        if (trim($query) === '') {
            return self::listing($rolls, $url);
        }

        $resolution = self::resolve($query, $rolls);
        if ($resolution['kind'] === 'ambiguous') {
            $names = array_map(
                static fn($c) => '*' . Commands::escape((string) $c) . '*',
                $resolution['candidates']
            );
            return self::plain('Which one? ' . implode(' or ', $names) . '.');
        }
        if ($resolution['kind'] === 'none') {
            return self::listing($rolls, $url, "I don't know that roll. This system offers:");
        }
        $roll = $resolution['roll'];

        $resolved = self::values($roll, $template, $sheet);
        if (isset($resolved['hidden'])) {
            // Deliberately vague: naming the property would leak the very
            // thing the refusal exists to protect.
            return self::plain(
                '*' . Commands::escape((string) $roll['label']) . '* needs a stat you can\'t see, '
                . 'so I won\'t roll it. Your game master can.'
            );
        }
        if (isset($resolved['error'])) {
            return self::plain(
                '*' . Commands::escape((string) $roll['label']) . "* didn't work out: "
                . Commands::escape($resolved['error'])
            );
        }

        return self::reply(
            (string) ($sheet['title'] ?? 'Someone'),
            $roll,
            chronicler_sheets_roll_dice($roll['parsed'], $resolved['values'], $rng),
            $url
        );
    }

    /**
     * Match a query against the merged roll set: id → label → unique prefix,
     * first STAGE wins. Ids are written with underscores but spoken with
     * spaces, so "act under pressure" finds `act_under_pressure` either way.
     *
     * The merge changed the exact stage's shape (2026-07-25). It used to be
     * two stages, id then label, first hit wins — but an id is its label
     * spoken with underscores for virtually every declared roll, so "id
     * first" would let the system roll silently swallow a same-named sheet
     * entry, the exact override the design rejects. Instead ONE exact stage
     * collects every roll the word names exactly — by id (spoken with spaces
     * or not) or by label — keyed by the caller's array key, so an id hit
     * and a label hit on the SAME roll count once. One candidate wins
     * outright; several are ambiguous. Character rolls carry no id
     * (id === null), and normalized labels keep their spaces, so the
     * underscore spelling (`act_under_pressure`) remains an unambiguous
     * escape hatch into the id namespace.
     *
     * Returns ['kind' => 'roll', 'roll' => …], ['kind' => 'ambiguous',
     * 'candidates' => […labels…]], or ['kind' => 'none'].
     */
    public static function resolve(string $query, array $rolls): array
    {
        $q = self::normalize($query);
        $qid = str_replace(' ', '_', $q);
        if ($q === '') {
            return ['kind' => 'none'];
        }
        $named = [];
        foreach ($rolls as $key => $roll) {
            if (($roll['id'] !== null && (string) $roll['id'] === $qid)
                || self::normalize((string) $roll['label']) === $q) {
                $named[$key] = $roll;
            }
        }
        if (count($named) === 1) {
            return ['kind' => 'roll', 'roll' => reset($named)];
        }
        if (count($named) > 1) {
            return ['kind' => 'ambiguous', 'candidates' => array_values(array_map(
                static fn(array $r): string => (string) $r['label'],
                $named
            ))];
        }
        // Candidates are keyed by the caller's own array key — a character
        // roll has no id to key by — so a word that is a prefix of both a
        // roll's id AND its label still counts once.
        $candidates = [];
        foreach ($rolls as $key => $roll) {
            $id = $roll['id'] === null ? '' : (string) $roll['id'];
            if (($id !== '' && self::startsWith($id, $qid)) || self::startsWith(self::normalize((string) $roll['label']), $q)) {
                $candidates[$key] = $roll;
            }
        }
        if (count($candidates) === 1) {
            return ['kind' => 'roll', 'roll' => reset($candidates)];
        }
        if (count($candidates) > 1) {
            return ['kind' => 'ambiguous', 'candidates' => array_values(array_map(
                static fn(array $r): string => (string) $r['label'],
                $candidates
            ))];
        }
        return ['kind' => 'none'];
    }

    /**
     * Resolve every {…} placeholder against the VIEWER-FILTERED sheet.
     *
     * Returns ['values' => [expression => number]] keyed the way
     * chronicler_sheets_roll_dice() expects, or ['hidden' => [property ids]]
     * when the roll reaches for something this viewer cannot see (see the
     * class docblock — this is the security rule), or ['error' => message]
     * when a placeholder fails to evaluate at all.
     *
     * The reference list comes from the same fence template SAVE uses
     * (chronicler_sheets_formula_check), so "what does this expression touch"
     * has exactly one answer in the codebase.
     */
    public static function values(array $roll, array $template, array $sheet): array
    {
        $visible = [];
        foreach (($sheet['properties'] ?? []) as $property) {
            $visible[$property['id']] = $property['value'] ?? null;
        }

        $placeholders = chronicler_sheets_dice_placeholders($roll['parsed']);
        $hidden = [];
        foreach ($placeholders as $expression) {
            $checked = chronicler_sheets_formula_check($expression, $template);
            if (is_wp_error($checked)) {
                return ['error' => $checked->get_error_message()];
            }
            foreach ($checked['refs'] as $ref) {
                if (!array_key_exists($ref, $visible)) {
                    $hidden[$ref] = true;
                }
            }
        }
        if ($hidden !== []) {
            return ['hidden' => array_keys($hidden)];
        }

        // Every reference is visible, so the context's fallback-to-default for
        // withheld properties can no longer affect the result.
        $context = chronicler_sheets_formula_context($template, $visible);
        $values = [];
        foreach ($placeholders as $expression) {
            $result = chronicler_sheets_formula_evaluate($expression, $context);
            if (is_wp_error($result)) {
                return ['error' => $result->get_error_message()];
            }
            $values[$expression] = $result;
        }
        return ['values' => $values];
    }

    // -- Rendering (pure) -----------------------------------------------------

    /**
     * The roll result. Individual dice are ALWAYS shown — a total with no dice
     * behind it invites exactly the suspicion a dice bot exists to prevent —
     * and a die a kh/kl term dropped is struck through rather than omitted, so
     * the arithmetic can be checked from the message alone.
     */
    public static function reply(string $name, array $roll, array $result, ?string $url = null): array
    {
        $who = Commands::escape($name);
        $linked = $url !== null && $url !== '' ? '<' . $url . '|' . $who . '>' : $who;
        $label = Commands::escape((string) $roll['label']);
        $notation = Commands::escape((string) $roll['dice']);
        $head = "🎲 *$linked* rolls *$label* — `$notation`";
        $line = self::faces($result) . '  =  *' . $result['total'] . '*';

        $blocks = [BlockKit::text($head . "\n" . $line)];
        $detail = trim((string) ($roll['detail'] ?? ''));
        if ($detail !== '') {
            $blocks[] = BlockKit::context('_' . Commands::escape($detail) . '_');
        }
        return [
            'response_type' => 'in_channel',
            'text' => Commands::escape("$name rolls {$roll['label']} — {$roll['dice']} = {$result['total']}"),
            'blocks' => BlockKit::cap($blocks, $url),
        ];
    }

    /** `[4] [3]  +2` — kept dice bracketed, dropped dice struck through. */
    private static function faces(array $result): string
    {
        $parts = [];
        foreach ($result['terms'] as $term) {
            if ($term['kind'] !== 'dice') {
                $parts[] = sprintf('%+d', $term['subtotal']);
                continue;
            }
            $dice = [];
            foreach ($term['dice'] as $die) {
                $dice[] = $die['kept'] ? '[' . $die['value'] . ']' : '~[' . $die['value'] . ']~';
            }
            $group = implode(' ', $dice);
            $parts[] = $term['sign'] < 0 ? '- ' . $group : $group;
        }
        return implode('  ', $parts);
    }

    /**
     * What this system offers, grouped by the optional `section` in
     * declaration order, sectionless rolls collected into a trailing "Other".
     * Nothing addresses a roll section, so it needs no id — it only orders
     * output.
     *
     * This lists rolls the caller might be REFUSED (a roll over a gm_only
     * stat): naming the roll leaks nothing, and hiding it would leave the
     * player wondering why the GM keeps mentioning it.
     */
    public static function listing(array $rolls, ?string $url = null, string $lead = ''): array
    {
        $order = [];
        $groups = [];
        $loose = [];
        foreach ($rolls as $roll) {
            $heading = trim((string) ($roll['section'] ?? ''));
            if ($heading === '') {
                $loose[] = $roll;
                continue;
            }
            if (!in_array($heading, $order, true)) {
                $order[] = $heading;
            }
            $groups[$heading][] = $roll;
        }
        if ($loose !== []) {
            $order[] = 'Other';
            $groups['Other'] = $loose;
        }

        $lines = [];
        if ($lead !== '') {
            $lines[] = $lead;
        }
        foreach ($order as $heading) {
            $lines[] = '*' . Commands::escape($heading) . '*';
            foreach ($groups[$heading] as $roll) {
                $line = '• *' . Commands::escape((string) $roll['label']) . '* — `'
                    . Commands::escape((string) $roll['dice']) . '`';
                $detail = trim((string) ($roll['detail'] ?? ''));
                if ($detail !== '') {
                    $line .= ' — _' . Commands::escape($detail) . '_';
                }
                $lines[] = $line;
            }
        }
        $lines[] = 'Roll one with `' . Commands::COMMAND . ' roll <name>`.';
        return self::plain(implode("\n", $lines));
    }

    private static function plain(string $text): array
    {
        return ['response_type' => 'ephemeral', 'text' => $text];
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
