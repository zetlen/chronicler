<?php

namespace Chronicler\Slack\Bot;

/**
 * `/game effect` — the GM-applied modifiers of the 2026-08-04 effects design,
 * handed out and taken away from Slack.
 *
 *   /game effect                                     what I'm carrying
 *   /game effect <character>                         what they're carrying
 *   /game effect add <character> <effect> [amount] [-- note]
 *   /game effect add <character> "<Label>" <±number> [target] [-- note]
 *   /game effect clear <character> <effect|"Label"|all>
 *
 * ANYONE may look: effects are public by design — they print on the sheet and
 * inside every roll they touch, which is the entire expiry mechanism, and a
 * modifier nobody can see would defeat it. Only a game master may apply or
 * clear one (the same edit_others_chr_characters capability the sheet's GM-only
 * gate uses), and those mutations post in_channel: applying an effect is a
 * thing that happened to the table, exactly like a roll. Listings and refusals
 * stay ephemeral.
 *
 * Two vocabularies meet here. The template declares effects — id, label, what
 * they do — and the GM names one; a NAMED instance stores which effect and how
 * much, never what it does, so editing the definition fixes every instance of
 * it at once. Anything the system never heard of is a ONE-OFF, told apart by
 * its quoted label, carrying its own signed number and optional target word.
 *
 * respond() is the whole command minus the WordPress lookups: query, template,
 * who it addresses and what they carry in, Slack body out — so every branch
 * below is unit-tested. Its one impurity is deliberate: a mutation calls the
 * store (sheets/effects.php) itself, because the confirmation must not
 * announce a write that didn't land, and the store's read-back is the only
 * thing that knows.
 *
 * Character addressing reuses `/game link`'s roster — published player
 * characters under the goes-by names transcripts already use — matched whole,
 * never by prefix: this command writes to somebody's sheet, and guessing which
 * somebody is not a thing to be clever about.
 */
final class Effect
{
    /** The word that clears everything at once. */
    public const ALL = 'all';

    // -- The WordPress shell --------------------------------------------------

    public static function handle(array $params): array
    {
        [, $query] = Commands::tokenize(is_string($params['text'] ?? null) ? $params['text'] : '');
        $slackId = is_string($params['user_id'] ?? null) ? $params['user_id'] : '';
        $roster = self::roster();
        $command = self::parse($query, $roster);
        if ($command['kind'] === 'usage') {
            return self::usage($command['why']);
        }

        // Who this addresses is settled BEFORE who is asking: a game master
        // with no character of their own still runs this command, and a player
        // who typo'd a name deserves to hear about the name.
        $own = chronicler_sheets_character_for_slack_id($slackId);
        $character = $command['character'];
        if ($character === null && $command['who'] === '' && $own !== null) {
            $character = (int) $own->ID;
        }
        if ($character === null) {
            return self::stranger($command['who'], $roster);
        }

        // Now become the caller, so current_user_can() means them. Unlike
        // /game my this survives a caller with no character — become() falls
        // back to nobody, which holds no capability, which is the right answer.
        My::become($slackId, $own);
        $post = get_post($character);
        // The reachability rule /game my owns: a password-protected sheet is
        // not read out in Slack, and effects are part of that sheet.
        if ($post && post_password_required($post) && !current_user_can('edit_post', $character)) {
            return self::plain('That character sheet is password-protected, so I can\'t read it out here.');
        }
        $template = chronicler_sheets_template_for_character($character);
        if (!is_array($template)) {
            return self::plain('That character has no sheet template yet, so an effect has nothing to attach to.');
        }
        $instances = chronicler_sheets_effects_get($character);
        return self::respond(
            $query,
            $template,
            ['id' => $character, 'name' => $roster[$character] ?? get_the_title($character)],
            ['id' => get_current_user_id(), 'is_gm' => current_user_can('edit_others_chr_characters')],
            $instances,
            $roster,
            self::appliers($instances)
        );
    }

    /** [post id => log name] for every published PC — /game link's roster. */
    private static function roster(): array
    {
        $roster = [];
        foreach (chronicler_sheets_player_characters() as $id) {
            $roster[$id] = chronicler_sheets_display_name_for(
                (string) get_post_meta($id, 'chr_goes_by', true),
                get_the_title($id)
            );
        }
        return $roster;
    }

    /** [user id => display name] for whoever applied what is on this sheet. */
    private static function appliers(array $instances): array
    {
        $names = [];
        foreach ($instances as $instance) {
            $id = (int) ($instance['applied_by'] ?? 0);
            if ($id <= 0 || isset($names[$id])) {
                continue;
            }
            $user = get_userdata($id);
            $name = $user === false ? '' : (string) $user->display_name;
            if ($name !== '') {
                $names[$id] = $name;
            }
        }
        return $names;
    }

    // -- The command (pure, but for the store) --------------------------------

    /**
     * @param array  $character ['id' => int, 'name' => string] — the character
     *                          this command addresses.
     * @param array  $caller    ['id' => WP user id, 'is_gm' => bool] — who is
     *                          asking, and whether they run this game.
     * @param array  $instances What that character is carrying now.
     * @param array  $roster    [post id => log name], for name matching.
     * @param array  $appliers  [user id => display name], for the listing.
     */
    public static function respond(
        string $query,
        array $template,
        array $character,
        array $caller,
        array $instances = [],
        array $roster = [],
        array $appliers = []
    ): array {
        $command = self::parse($query, $roster);
        if ($command['kind'] === 'usage') {
            return self::usage($command['why']);
        }
        if ($command['kind'] === 'list') {
            return self::listing($instances, $template, $character, (bool) ($caller['is_gm'] ?? false), $appliers);
        }
        // Everything past here writes to somebody's sheet.
        if (empty($caller['is_gm'])) {
            return self::plain(
                'Effects are the game master\'s to give and take away, so I won\'t. Anyone can look: `'
                . Commands::COMMAND . ' effect ' . Commands::escape($character['name']) . '`.'
            );
        }
        return $command['kind'] === 'clear'
            ? self::clear($command, $template, $character, $instances)
            : self::apply($command, $template, $character, $caller);
    }

    /**
     * The grammar, as data. $roster is [id => log name] because a character's
     * name is where the command splits — "add big marty forward" has to know
     * that Big Marty is two words of name and one of effect, and only the
     * roster knows. Names match whole (chronicler_sheets_match_display_name),
     * longest first, so a name that contains another still resolves.
     *
     * Returns one of:
     *   ['kind' => 'list',  'character' => ?int, 'who' => string]
     *   ['kind' => 'add',   …, 'word' => string, 'amount' => ?int, 'note' => string]
     *   ['kind' => 'one_off', …, 'label' =>, 'modifier' => int, 'target' => ?string, 'note' => string]
     *   ['kind' => 'clear', …, 'word' => string]
     *   ['kind' => 'usage', 'why' => string]
     */
    public static function parse(string $query, array $roster = []): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', trim($query)));
        [$verb, $rest] = array_pad(explode(' ', $text, 2), 2, '');
        $verb = strtolower($verb);
        if ($verb !== 'add' && $verb !== 'clear') {
            // Everything else is a name (or nothing, meaning your own).
            return [
                'kind' => 'list',
                'character' => $text === '' ? null : chronicler_sheets_match_display_name($text, $roster),
                'who' => $text,
            ];
        }
        // The note is free prose and may contain anything the parser below
        // would trip over, so it comes off first.
        $note = '';
        $parts = preg_split('/\s+--(?:\s+|$)/u', $rest, 2);
        if (count($parts) === 2) {
            $rest = $parts[0];
            $note = trim($parts[1]);
        }
        [$addressed, $tail] = self::addressed($rest, $roster);
        if ($verb === 'clear') {
            if ($tail === '') {
                return self::incomplete('Clear what? `' . Commands::COMMAND
                    . ' effect clear <character> <effect|"Label"|all>`.');
            }
            return $addressed + ['kind' => 'clear', 'word' => trim($tail, '"“”')];
        }
        if ($tail === '') {
            return self::incomplete('Apply what? `' . Commands::COMMAND
                . ' effect add <character> <effect> [amount]`.');
        }
        // A quoted label is what tells a one-off from a named effect: the GM
        // is inventing something the system never declared, so it arrives in
        // quotes carrying its own number.
        if (preg_match('/^["“](?<label>[^"”]*)["”]\s*(?<tail>.*)$/u', $tail, $m)) {
            return self::oneOff($addressed, $m['label'], trim($m['tail']), $note);
        }
        $words = explode(' ', $tail);
        $amount = null;
        // A trailing whole number is the amount — "queasy 2" — and never the
        // last word of an effect's name, which is why an author writing one
        // that ends in a digit gets the number read as a number.
        if (count($words) > 1 && preg_match('/^\d+$/', end($words))) {
            $amount = (int) array_pop($words);
        }
        return $addressed + [
            'kind' => 'add',
            'word' => implode(' ', $words),
            'amount' => $amount,
            'note' => $note,
        ];
    }

    /**
     * Split "<character> <the rest>" on the roster, longest name first, as
     * [['character' =>, 'who' =>], the rest]. When nobody answers, the FIRST
     * word is who they meant — enough for the refusal to quote it back.
     */
    private static function addressed(string $text, array $roster): array
    {
        $words = $text === '' ? [] : explode(' ', $text);
        for ($take = count($words); $take > 0; $take--) {
            $name = implode(' ', array_slice($words, 0, $take));
            $id = chronicler_sheets_match_display_name($name, $roster);
            if ($id !== null) {
                return [
                    ['character' => $id, 'who' => $name],
                    implode(' ', array_slice($words, $take)),
                ];
            }
        }
        return [
            ['character' => null, 'who' => $words[0] ?? ''],
            implode(' ', array_slice($words, 1)),
        ];
    }

    /** The one-off form's tail: a signed number, then an optional target word. */
    private static function oneOff(array $addressed, string $label, string $tail, string $note): array
    {
        if (trim($label) === '') {
            return self::incomplete('A one-off needs a name: `' . Commands::COMMAND
                . ' effect add <character> "Acne" -2 [target]`.');
        }
        $words = $tail === '' ? [] : explode(' ', $tail);
        $modifier = array_shift($words);
        if ($modifier === null || !preg_match('/^[+-]?\d+$/', $modifier)) {
            return self::incomplete('A one-off needs a number — how much it adds to a roll: `'
                . Commands::COMMAND . ' effect add <character> "' . Commands::escape(trim($label)) . '" -2`.');
        }
        $target = array_shift($words);
        if ($target !== null && !preg_match(CHRONICLER_SHEETS_ID_PATTERN, $target)) {
            return self::incomplete('`' . Commands::escape($target)
                . '` isn\'t a target — that\'s one word: a roll, a trait, or a stat the dice use.');
        }
        if ($words !== []) {
            return self::incomplete('A one-off takes a name, a number and at most one target — anything else you want to say goes after `--`.');
        }
        return $addressed + [
            'kind' => 'one_off',
            'label' => trim($label),
            'modifier' => (int) $modifier,
            'target' => $target,
            'note' => $note,
        ];
    }

    private static function incomplete(string $why): array
    {
        return ['kind' => 'usage', 'why' => $why, 'character' => null, 'who' => ''];
    }

    /**
     * Match one word against the template's effect vocabulary: id → label →
     * unique prefix, first STAGE wins, an ambiguous word coming back as a
     * disambiguation rather than a pick. The `/game roll` discipline, over the
     * other thing this bot has a vocabulary for.
     *
     * Returns ['kind' => 'effect', 'effect' => …], ['kind' => 'ambiguous',
     * 'candidates' => […labels…]] or ['kind' => 'none'].
     */
    public static function resolve(string $word, array $template): array
    {
        $effects = is_array($template['effects'] ?? null) ? $template['effects'] : [];
        $q = self::normalize($word);
        $qid = str_replace(' ', '_', $q);
        if ($q === '') {
            return ['kind' => 'none'];
        }
        $named = [];
        foreach ($effects as $id => $effect) {
            if ((string) $id === $qid || self::normalize((string) $effect['label']) === $q) {
                $named[$id] = $effect;
            }
        }
        $candidates = $named;
        if ($candidates === []) {
            foreach ($effects as $id => $effect) {
                if (self::startsWith((string) $id, $qid)
                    || self::startsWith(self::normalize((string) $effect['label']), $q)) {
                    $candidates[$id] = $effect;
                }
            }
        }
        if (count($candidates) === 1) {
            return ['kind' => 'effect', 'effect' => reset($candidates)];
        }
        if (count($candidates) > 1) {
            return ['kind' => 'ambiguous', 'candidates' => array_values(array_map(
                static fn(array $e): string => (string) $e['label'],
                $candidates
            ))];
        }
        return ['kind' => 'none'];
    }

    // -- Mutations ------------------------------------------------------------

    /** `add`, both forms: shape the instance, store it, announce it. */
    private static function apply(array $command, array $template, array $character, array $caller): array
    {
        if ($command['kind'] === 'one_off') {
            if ($command['modifier'] === 0) {
                return self::plain(
                    'A one-off worth 0 would print on every roll it touches and change none of them. '
                    . 'Give it a number with a sign: `-2`, `+1`.'
                );
            }
            $draft = [
                'label' => $command['label'],
                'modifier' => $command['modifier'],
                'target' => $command['target'],
                'note' => $command['note'],
            ];
        } else {
            $resolution = self::resolve($command['word'], $template);
            if ($resolution['kind'] === 'ambiguous') {
                return self::plain('Which one? ' . implode(' or ', array_map(
                    static fn($c) => '*' . Commands::escape((string) $c) . '*',
                    $resolution['candidates']
                )) . '.');
            }
            if ($resolution['kind'] === 'none') {
                return self::plain(self::unknown($command['word'], $template)
                    . "\nOr invent one on the spot: `" . Commands::COMMAND . ' effect add '
                    . Commands::escape($character['name']) . ' "' . Commands::escape($command['word']) . '" -1`.');
            }
            if ($command['amount'] !== null && $command['amount'] < 1) {
                return self::plain('An effect applies at least once — `2` means twice as much, `0` means don\'t.');
            }
            $draft = [
                'effect' => $resolution['effect']['id'],
                'amount' => $command['amount'] ?? 1,
                'note' => $command['note'],
            ];
        }
        $draft['applied_by'] = (int) ($caller['id'] ?? 0);
        // Normalize before writing, and render what normalizing produced: the
        // confirmation must describe the instance that is now on the sheet,
        // not the one that was typed.
        $instance = chronicler_sheets_effects_normalize($draft);
        if ($instance === null) {
            return self::plain('I couldn\'t make an effect out of that. `' . Commands::COMMAND
                . ' effect add <character> <effect> [amount]`.');
        }
        if (!chronicler_sheets_effects_add((int) $character['id'], $instance)) {
            return self::plain('That didn\'t save, so I won\'t say it applied. Try again.');
        }
        return self::announce(
            '✨ *' . Commands::escape($character['name']) . '* takes ' . self::line($instance, $template)
        );
    }

    /** `clear`: by effect word, by a one-off's label, or all of it. */
    private static function clear(array $command, array $template, array $character, array $instances): array
    {
        $name = Commands::escape($character['name']);
        if (self::normalize($command['word']) === self::ALL) {
            $removed = chronicler_sheets_effects_clear((int) $character['id'], null);
            if ($removed === 0) {
                return self::plain('*' . $name . '* has no active effects to clear.');
            }
            return self::announce('🧼 *' . $name . '* is clear — ' . self::count($removed) . ' gone.');
        }
        $resolution = self::resolve($command['word'], $template);
        if ($resolution['kind'] === 'ambiguous') {
            return self::plain('Which one? ' . implode(' or ', array_map(
                static fn($c) => '*' . Commands::escape((string) $c) . '*',
                $resolution['candidates']
            )) . '.');
        }
        // A one-off answers to its own label, and an instance of an effect the
        // template has since dropped answers to the id it was applied under —
        // the two things the vocabulary can't resolve, and both must clear or
        // they'd be stuck on the sheet forever.
        $key = $resolution['kind'] === 'effect' ? (string) $resolution['effect']['id'] : $command['word'];
        $label = $resolution['kind'] === 'effect' ? (string) $resolution['effect']['label'] : $command['word'];
        if ($resolution['kind'] === 'none' && !self::carried($command['word'], $instances)) {
            return self::plain(self::unknown($command['word'], $template));
        }
        $removed = chronicler_sheets_effects_clear((int) $character['id'], $key);
        if ($removed === 0) {
            return self::plain('*' . $name . '* isn\'t carrying *' . Commands::escape($label) . '*.');
        }
        return self::announce('🧼 *' . Commands::escape($label) . '* is off *' . $name . '*'
            . ($removed > 1 ? ' — ' . self::count($removed) . ' gone.' : '.'));
    }

    /** Whether this character carries something answering to that word. */
    private static function carried(string $word, array $instances): bool
    {
        $want = self::normalize($word);
        foreach ($instances as $instance) {
            $key = $instance['effect'] ?? $instance['label'];
            if (self::normalize(str_replace('_', ' ', (string) $key)) === str_replace('_', ' ', $want)) {
                return true;
            }
        }
        return false;
    }

    // -- Rendering (pure) -----------------------------------------------------

    /**
     * ONE instance, said the way the sheet says it (sheets/render.php's Active
     * Effects row): label, signed contribution, ×amount when it isn't one,
     * what it applies to, and the note the GM left. An `expr` modifier prints
     * as `expr` because what it contributes depends on the roll — the roll
     * output is where its real number shows up.
     *
     * A named instance whose definition the template no longer declares is
     * flagged rather than dropped: it does nothing to rolls, and the only way
     * anybody knows to clear it is seeing it here under the id it was applied
     * with.
     */
    public static function line(array $instance, array $template): string
    {
        $definitions = is_array($template['effects'] ?? null) ? $template['effects'] : [];
        $effect = $instance['effect'] ?? null;
        $definition = $effect === null ? null : ($definitions[$effect] ?? null);
        if ($effect !== null && !is_array($definition)) {
            return '*' . Commands::escape((string) $effect) . '* (no longer in this system — clear it?)';
        }
        $modifier = $definition === null ? $instance['modifier'] : $definition['modifier'];
        $target = $definition === null ? ($instance['target'] ?? null) : ($definition['applies_to'] ?? null);
        $amount = (int) ($instance['amount'] ?? 1);
        $parts = ['*' . Commands::escape((string) ($definition === null ? $instance['label'] : $definition['label'])) . '*'];
        $parts[] = is_string($modifier) ? '`expr`' : sprintf('%+d', (int) $modifier);
        if ($amount !== 1) {
            $parts[] = '×' . $amount;
        }
        $line = implode(' ', $parts);
        if ($target !== null && $target !== '') {
            $line .= ' — on ' . Commands::escape((string) $target);
        }
        $note = trim((string) ($instance['note'] ?? ''));
        return $note === '' ? $line : $line . ' — _' . Commands::escape($note) . '_';
    }

    /**
     * What a character is carrying. Ephemeral — a menu is not a state change —
     * and readable by anyone, because an effect nobody can see cannot be the
     * thing that catches itself in a roll.
     */
    public static function listing(
        array $instances,
        array $template,
        array $character,
        bool $is_gm = false,
        array $appliers = []
    ): array {
        $name = Commands::escape($character['name']);
        $lines = [];
        if ($instances === []) {
            $lines[] = '*' . $name . '* has no active effects.';
        } else {
            $lines[] = '*' . $name . '* — active effects:';
            foreach ($instances as $instance) {
                $lines[] = '• ' . self::line($instance, $template) . self::attribution($instance, $appliers);
            }
        }
        // The vocabulary and the verbs are the GM's business: they are the only
        // one who can use them, and a player's listing is about their own
        // character, not the system's catalogue.
        if ($is_gm) {
            $vocabulary = array_map(
                static fn(array $e): string => '*' . Commands::escape((string) $e['label']) . '*',
                is_array($template['effects'] ?? null) ? $template['effects'] : []
            );
            if ($vocabulary !== []) {
                $lines[] = 'This system hands out: ' . implode(', ', array_values($vocabulary)) . '.';
            }
            $lines[] = 'Apply one with `' . Commands::COMMAND . ' effect add ' . $name
                . ' <effect>`, clear one with `' . Commands::COMMAND . ' effect clear ' . $name . ' <effect>`.';
        }
        return self::plain(implode("\n", $lines));
    }

    /**
     * `— Alice, Aug 4`. Slack renders the timestamp in the reader's own zone
     * and says "yesterday" when it can (the date_short_pretty token), so the
     * "when" costs nothing to compute and reads right for everyone at the
     * table; the text after the pipe is what a client too old to know the
     * token falls back to.
     */
    private static function attribution(array $instance, array $appliers): string
    {
        $who = $appliers[(int) ($instance['applied_by'] ?? 0)] ?? '';
        $when = (int) ($instance['applied_at'] ?? 0);
        $parts = [];
        if ($who !== '') {
            $parts[] = Commands::escape((string) $who);
        }
        if ($when > 0) {
            $parts[] = '<!date^' . $when . '^{date_short_pretty}|' . gmdate('M j, Y', $when) . '>';
        }
        return $parts === [] ? '' : '  _— ' . implode(', ', $parts) . '_';
    }

    /** "I don't know that effect" plus what this system actually declares. */
    private static function unknown(string $word, array $template): string
    {
        $effects = is_array($template['effects'] ?? null) ? $template['effects'] : [];
        $text = 'I don\'t know an effect called `' . Commands::escape($word) . '`.';
        if ($effects === []) {
            return $text . ' This system declares none — its `effects:` table is where they live.';
        }
        return $text . ' This system hands out: ' . implode(', ', array_map(
            static fn(array $e): string => '*' . Commands::escape((string) $e['label']) . '*',
            array_values($effects)
        )) . '.';
    }

    /** The name nobody answers to — /game link's reply, minus the edit link. */
    public static function stranger(string $who, array $roster): array
    {
        $lines = [];
        $lines[] = trim($who) === ''
            ? 'I don\'t know which character is yours yet — run `' . Commands::COMMAND
                . ' link <name>` and I\'ll show you how to connect it.'
            : 'No character goes by `' . Commands::escape(trim($who)) . '`.';
        if ($roster !== []) {
            $lines[] = 'Characters: ' . implode(', ', array_map(
                static fn($n) => '*' . Commands::escape((string) $n) . '*',
                array_values($roster)
            )) . '.';
        }
        return self::plain(implode("\n", $lines));
    }

    /** The whole grammar, led by whichever part they were mid-sentence on. */
    public static function usage(string $why = ''): array
    {
        $c = Commands::COMMAND;
        $lines = array_filter([
            $why,
            '• `' . $c . ' effect` — what you\'re carrying; `' . $c . ' effect <character>` — what they are.',
            '• `' . $c . ' effect add <character> <effect> [amount] [-- note]` — game master only.',
            '• `' . $c . ' effect add <character> "<Label>" <±number> [target] [-- note]` — a one-off nothing in the system declares.',
            '• `' . $c . ' effect clear <character> <effect|"Label"|all>` — game master only.',
        ]);
        return self::plain(implode("\n", $lines));
    }

    /** A state change is social, exactly like a roll. */
    private static function announce(string $text): array
    {
        return ['response_type' => 'in_channel', 'text' => $text];
    }

    private static function plain(string $text): array
    {
        return ['response_type' => 'ephemeral', 'text' => $text];
    }

    private static function count(int $n): string
    {
        return $n . ($n === 1 ? ' effect' : ' effects');
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
