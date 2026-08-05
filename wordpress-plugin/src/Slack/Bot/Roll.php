<?php

namespace Chronicler\Slack\Bot;

/**
 * `/game roll [name]` — roll one of the rolls this character has, with their
 * own values substituted. A roll belongs to whatever declares it (design
 * 2026-07-25, "a move carries its own roll"): the system declares the rolls
 * every character has in its `rolls:` table, and the character declares its
 * own by writing dice on a list entry (a playbook move, a weapon) or by
 * carrying a dice pool (2026-08-04 — a `dice` property is a roll under its
 * own name). respond() serves the union — see union() below.
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
 * Dice pools (2026-08-04) add no carve-out: `{gut}` is checked against the
 * filtered sheet exactly like `{cool}`, and an invisible one refuses in the
 * same unrevealing words. The one refusal that DOES name a property — a pool
 * holding something that isn't dice — names it for the opposite reason: that
 * check runs only after the pool was found on the viewer's own sheet.
 *
 * Effects (2026-08-04) add none either. An effect is public — it prints on the
 * sheet and in every roll it touches, and a refusal may name it — but its
 * modifier can be a FORMULA, and a formula reading a gm_only stat would leak
 * it through the total just as a placeholder would. So effects() fences every
 * expression modifier against the same filtered sheet and refuses in the same
 * unrevealing words, before a single die is thrown.
 *
 * The rule has exactly one carve-out (2026-07-25 Phase B): a reference named
 * `entry` on a character-carried roll. The entry's values ride a list the
 * viewer already sees — the visibility authority filtered the sheet BEFORE
 * the collector ran, so a contribution from an invisible list cannot exist
 * here — and the values arrive on the roll itself, not through the sheet
 * lookup. Every other reference keeps the rule unchanged.
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
        // The character's applied effects (2026-08-04) are READ here and never
        // written: nothing expires by itself, so a roll changes no state. They
        // arrive as a parameter for the same reason the randomizer does —
        // respond() stays a pure function of what it was handed.
        return self::respond(
            $query,
            $template,
            $viewer['sheet'],
            $viewer['url'],
            null,
            chronicler_sheets_effects_get((int) $viewer['character']->ID)
        );
    }

    // -- The command (pure) ---------------------------------------------------

    /**
     * @param array         $template The parsed template (roll definitions).
     * @param array         $sheet    The VIEWER-FILTERED sheet — the only
     *                                source of values, by design.
     * @param ?callable     $rng      fn(int $min, int $max): int; production
     *                                omits it and gets wp_rand().
     * @param array         $effects  The character's applied effect instances
     *                                (sheets/effects.php). Handed in, never
     *                                read from meta here, so this stays pure.
     */
    public static function respond(
        string $query,
        array $template,
        array $sheet,
        ?string $url = null,
        ?callable $rng = null,
        array $effects = []
    ): array {
        $rolls = self::union($template, $sheet);
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
            return self::hidden($roll);
        }
        if (isset($resolved['pool'])) {
            // The opposite of the refusal above: this pool is the roller's own
            // visible property, so naming it (and what the sheet holds) leaks
            // nothing and is the only way they'd know what to fix.
            $written = $resolved['pool']['value'] === ''
                ? 'the sheet has it blank'
                : 'the sheet has "' . Commands::escape($resolved['pool']['value']) . '"';
            return self::plain(
                '*' . Commands::escape((string) $roll['label']) . '* needs *'
                . Commands::escape($resolved['pool']['label'])
                . '* written as dice (like 2d6+1d4) — ' . $written . '.'
            );
        }
        if (isset($resolved['error'])) {
            return self::plain(
                '*' . Commands::escape((string) $roll['label']) . "* didn't work out: "
                . Commands::escape($resolved['error'])
            );
        }

        // What the character's applied effects do to THIS roll, resolved
        // against the same context the placeholders just ran against.
        $applied = self::effects($effects, $roll, $template, $sheet, $resolved['context']);
        if (isset($applied['hidden'])) {
            return self::hidden($roll);
        }
        if ($applied['error'] !== null) {
            // The effect's LABEL and nothing else. Effects are public — they
            // print on the sheet and in every roll they touch — so naming one
            // leaks nothing, and the roller needs to know which to clear.
            return self::plain(
                '*' . Commands::escape((string) $roll['label']) . '* can\'t roll: the *'
                . Commands::escape($applied['error']) . '* effect didn\'t work out.'
            );
        }

        return self::reply(
            (string) ($sheet['title'] ?? 'Someone'),
            $roll,
            chronicler_sheets_roll_dice($resolved['parsed'], $resolved['values'], $rng),
            $url,
            $applied['terms']
        );
    }

    /**
     * The effect terms for one roll: ['terms' => [['label','value'], …],
     * 'error' => ?string], or ['hidden' => [property ids]] when an effect's
     * expression reaches for something the VIEWER-FILTERED sheet doesn't
     * carry.
     *
     * That last case is the security rule (class docblock) reaching the one
     * place values() cannot see: an effect's modifier may be a formula, and a
     * formula reading a gm_only stat leaks it through the total exactly like a
     * placeholder would. So every expression modifier goes through the same
     * fence, the same visible-sheet membership test, and the same unrevealing
     * refusal. Effects themselves are public and stay nameable; the PROPERTY
     * an expression reached for does not.
     *
     * Sugar modifiers read nothing off the sheet — they are a constant and a
     * target word — so only expressions are checked.
     */
    public static function effects(array $instances, array $roll, array $template, array $sheet, array $context): array
    {
        if ($instances === []) {
            return ['terms' => [], 'error' => null];
        }
        $definitions = is_array($template['effects'] ?? null) ? $template['effects'] : [];
        $scope = chronicler_sheets_formula_effect_scope(
            $template,
            chronicler_sheets_template_traits($template)
        );
        $visible = self::visible($sheet);
        $hidden = [];
        foreach ($instances as $instance) {
            $effect = $instance['effect'] ?? null;
            $definition = $effect === null ? null : ($definitions[$effect] ?? null);
            // A one-off's modifier is always a number (the store refuses
            // anything else), and an instance of a dropped definition is
            // skipped by the evaluator — neither reads a property.
            if (!is_array($definition) || !is_string($definition['modifier'])) {
                continue;
            }
            $checked = chronicler_sheets_formula_check($definition['modifier'], $scope);
            if (is_wp_error($checked)) {
                return ['terms' => [], 'error' => (string) $definition['label']];
            }
            foreach ($checked['refs'] as $ref) {
                // `roll` and `amount` are the effect scope's synthetic names
                // (formulas.php), not properties anybody could hide.
                if ($ref === 'roll' || $ref === 'amount') {
                    continue;
                }
                if (!array_key_exists($ref, $visible)) {
                    $hidden[$ref] = true;
                }
            }
        }
        if ($hidden !== []) {
            return ['hidden' => array_keys($hidden)];
        }
        return chronicler_sheets_effects_for_roll($instances, $template, $roll, $context);
    }

    /**
     * Everything this character can roll, in menu order: the system's rolls
     * (id-keyed, every character has all of them) first, then the character's
     * own — dice pools keyed "pool:<id>" (2026-08-04) and list entries with
     * dice written on them keyed "sheet:N" (2026-07-25: a move carries its
     * own roll). Both prefixes keep the union's keys out of the id namespace,
     * so a system roll named after one of them is AMBIGUOUS at resolution,
     * never silently overridden. Resolution runs over the union, so every
     * kind is rollable exactly like a declared one.
     *
     * Every roll leaves here carrying the two keys effect evaluation reads:
     * `traits` (what the roll IS — declared, defaulting to none) and `uses`
     * (what its dice reach, pools included). They ride the union rather than
     * the execution path because the effects that read them are per-roll, and
     * a roll's identity shouldn't depend on who asked for it.
     */
    public static function union(array $template, array $sheet): array
    {
        $rolls = is_array($template['rolls'] ?? null) ? $template['rolls'] : [];
        foreach (\chronicler_sheets_dice_property_rolls($sheet) as $id => $pool) {
            $rolls['pool:' . $id] = $pool;
        }
        foreach (\chronicler_sheets_character_rolls($sheet) as $i => $contributed) {
            $rolls['sheet:' . $i] = $contributed;
        }
        foreach ($rolls as $key => $roll) {
            $roll['traits'] = is_array($roll['traits'] ?? null) ? $roll['traits'] : [];
            $roll['uses'] = \chronicler_sheets_roll_uses($roll, $template);
            $rolls[$key] = $roll;
        }
        return $rolls;
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
     * Resolve every {…} placeholder against the VIEWER-FILTERED sheet, and
     * splice every dice pool the roll reaches for.
     *
     * Returns ['values' => [expression => number], 'parsed' => the roll's
     * terms with pools expanded, 'context' => the character's formula context]
     * keyed the way chronicler_sheets_roll_dice() expects — the context rides
     * along so effect expressions evaluate against exactly what the
     * placeholders did, rather than a second copy that could drift — or
     * ['hidden' => [property ids]] when the roll reaches for
     * something this viewer cannot see (see the class docblock — this is the
     * security rule), or ['pool' => [label, value]] when a pool the viewer CAN
     * see holds nothing rollable, or ['error' => message] when a placeholder
     * fails to evaluate, or evaluates to something dice cannot add —
     * roll_dice() would quietly read either as 0, and a wrong total posted
     * in_channel is the failure a dice bot exists to prevent.
     *
     * A character-carried roll brings its own 'entry' map (the collector's
     * contribution shape), so its placeholders are fenced against the
     * entry-augmented scope and entry["…"] resolves the entry's own fields;
     * refs named `entry` skip the hidden-property check (the class docblock's
     * carve-out). A system roll carries no entry, keeps the plain template,
     * and `entry` stays an unknown name there.
     *
     * The reference list comes from the same fence template SAVE uses
     * (chronicler_sheets_formula_check), so "what does this expression touch"
     * has exactly one answer in the codebase.
     */
    public static function values(array $roll, array $template, array $sheet): array
    {
        $visible = self::visible($sheet);
        $labels = [];
        foreach (($sheet['properties'] ?? []) as $property) {
            $labels[$property['id']] = (string) ($property['label'] ?? $property['id']);
        }

        $entry = is_array($roll['entry'] ?? null) ? $roll['entry'] : null;
        $scope = $entry === null
            ? $template
            : chronicler_sheets_formula_entry_scope($template, array_keys($entry));

        // Pools first (2026-08-04): a placeholder that is exactly a dice
        // property's id contributes DICE, not a number, so it is spliced out
        // of the roll before anything tries to evaluate it. The security rule
        // reaches it unchanged — a pool the filtered sheet doesn't carry is
        // hidden like any other reference, refused in the same words — but a
        // pool that IS visible and holds junk gets named: it is the roller's
        // own property, and the sheet is where they fix it.
        $pools = [];
        $hidden = [];
        foreach (chronicler_sheets_dice_placeholders($roll['parsed']) as $expression) {
            $pool = chronicler_sheets_formula_pool_ref($expression, $template);
            if ($pool === null) {
                continue;
            }
            if (!array_key_exists($pool, $visible)) {
                $hidden[$pool] = true;
                continue;
            }
            $pools[$expression] = (string) $visible[$pool];
        }
        if ($hidden !== []) {
            return ['hidden' => array_keys($hidden)];
        }
        $parsed = chronicler_sheets_expand_dice_pools($roll['parsed'], $pools);
        if (is_wp_error($parsed)) {
            if ($parsed->get_error_code() === 'chronicler_invalid_pool') {
                $data = (array) $parsed->get_error_data();
                return ['pool' => [
                    'label' => $labels[$data['pool']] ?? (string) $data['pool'],
                    'value' => (string) ($data['value'] ?? ''),
                ]];
            }
            // Post-expansion term limits, in the parser's own words.
            return ['error' => $parsed->get_error_message()];
        }

        // Everything left is arithmetic — including a pool spelled any way but
        // bare, which the fence below refuses rather than reading as 0.
        $placeholders = chronicler_sheets_dice_placeholders($parsed);
        foreach ($placeholders as $expression) {
            $checked = chronicler_sheets_formula_check($expression, $scope);
            if (is_wp_error($checked)) {
                return ['error' => $checked->get_error_message()];
            }
            foreach ($checked['refs'] as $ref) {
                if ($entry !== null && $ref === 'entry') {
                    continue;
                }
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
        if ($entry !== null) {
            $context['entry'] = $entry;
        }
        $values = [];
        foreach ($placeholders as $expression) {
            $result = chronicler_sheets_formula_evaluate($expression, $context);
            if (is_wp_error($result)) {
                return ['error' => $result->get_error_message()];
            }
            if (!is_numeric($result)) {
                return ['error' => '{' . $expression . '} adds up to something that isn\'t a number.'];
            }
            $values[$expression] = $result;
        }
        return ['values' => $values, 'parsed' => $parsed, 'context' => $context];
    }

    /**
     * [property id => value] as the VIEWER-FILTERED sheet carries them. Both
     * membership tests the security rule is written against — placeholders in
     * values(), effect expressions in effects() — ask this one map, so "what
     * can this viewer see" has a single answer.
     */
    private static function visible(array $sheet): array
    {
        $visible = [];
        foreach (($sheet['properties'] ?? []) as $property) {
            $visible[$property['id']] = $property['value'] ?? null;
        }
        return $visible;
    }

    // -- Rendering (pure) -----------------------------------------------------

    /**
     * The roll result. Individual dice are ALWAYS shown — a total with no dice
     * behind it invites exactly the suspicion a dice bot exists to prevent —
     * and a die a kh/kl term dropped is struck through rather than omitted, so
     * the arithmetic can be checked from the message alone.
     *
     * $effects are this roll's nonzero effect terms, which ride the faces line
     * as labeled constants and are added into the total. Labeling them there
     * is the whole lifecycle (2026-08-04): nothing expires, so a stale effect
     * is caught by being visible in front of the table at the exact moment it
     * distorts a roll.
     */
    public static function reply(string $name, array $roll, array $result, ?string $url = null, array $effects = []): array
    {
        $who = Commands::escape($name);
        $linked = $url !== null && $url !== '' ? '<' . $url . '|' . $who . '>' : $who;
        $label = Commands::escape((string) $roll['label']);
        $notation = Commands::escape((string) $roll['dice']);
        $head = "🎲 *$linked* rolls *$label* — `$notation`";
        $total = $result['total'];
        $plain = [];
        foreach ($effects as $effect) {
            $total += (int) $effect['value'];
            $plain[] = sprintf('%+d %s', (int) $effect['value'], (string) $effect['label']);
        }
        $line = self::faces($result, $effects) . '  =  *' . $total . '*';

        $blocks = [BlockKit::text($head . "\n" . $line)];
        $detail = trim((string) ($roll['detail'] ?? ''));
        if ($detail !== '') {
            $blocks[] = BlockKit::context('_' . Commands::escape($detail) . '_');
        }
        // The fallback is what a notification and a screen reader get, so the
        // effects belong in it too — a total nothing explains is the failure
        // the faces line exists to prevent, in a quieter font.
        $fallback = "$name rolls {$roll['label']} — {$roll['dice']} = $total"
            . ($plain === [] ? '' : ' (' . implode(', ', $plain) . ')');
        return [
            'response_type' => 'in_channel',
            'text' => Commands::escape($fallback),
            'blocks' => BlockKit::cap($blocks, $url),
        ];
    }

    /**
     * `[4] [3]  +2  -1 (Queasy)` — kept dice bracketed, dropped dice struck
     * through, and each effect term wearing its label so the arithmetic stays
     * checkable from the message alone.
     */
    private static function faces(array $result, array $effects = []): string
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
        foreach ($effects as $effect) {
            $parts[] = sprintf('%+d (%s)', (int) $effect['value'], Commands::escape((string) $effect['label']));
        }
        return implode('  ', $parts);
    }

    /**
     * The refusal for a roll that reaches something this viewer cannot see.
     * Deliberately vague, and shared by both reaches — a placeholder's and an
     * effect expression's: naming the property would leak the very thing the
     * refusal exists to protect.
     */
    private static function hidden(array $roll): array
    {
        return self::plain(
            '*' . Commands::escape((string) $roll['label']) . '* needs a stat you can\'t see, '
            . 'so I won\'t roll it. Your game master can.'
        );
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
