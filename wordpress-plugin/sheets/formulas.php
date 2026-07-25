<?php
// Derived-stat formulas (#88): a deliberately tiny subset of Symfony
// ExpressionLanguage for sheet authors — property refs (bracket syntax for
// track/counter parts: harm["current"]), arithmetic, comparisons,
// and/or/not, ternary, and exactly floor/ceil/round/min/max. Formulas are
// plain EL, no translation layer: what an author writes is what the engine
// parses, and stock EL documentation applies. Evaluation runs against
// plain arrays only, so no method-call surface exists; the fence walk
// below rejects any parsed node outside the documented grammar (object
// and method access included), so the author-facing language stays
// exactly this small regardless of what the engine could do.
//
// Division is real division; rounding is explicit (floor() for SWADE-style
// round-down). Derived values are computed on read, never stored.

if (!defined('ABSPATH') && !defined('CHRONICLER_TESTS')) {
    exit;
}

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\ArgumentsNode;
use Symfony\Component\ExpressionLanguage\Node\BinaryNode;
use Symfony\Component\ExpressionLanguage\Node\ConditionalNode;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\UnaryNode;
use Symfony\Component\ExpressionLanguage\SyntaxError;

const CHRONICLER_FORMULA_FUNCTIONS = ['floor', 'ceil', 'round', 'min', 'max'];
const CHRONICLER_FORMULA_BINARY_OPS = [
    '+', '-', '*', '/', '%',
    '==', '!=', '<', '>', '<=', '>=',
    'and', 'or', '&&', '||',
];
const CHRONICLER_FORMULA_UNARY_OPS = ['-', '+', 'not', '!'];

/** Property types a formula may reference, and how they appear in context. */
const CHRONICLER_FORMULA_REF_TYPES = ['number', 'track', 'counter', 'toggle', 'select', 'checklist', 'text'];

/**
 * The type of the synthetic `entry` member chronicler_sheets_formula_entry_scope()
 * plants (2026-07-25 Phase B: a weapon's dice add the weapon's own harm).
 * Internal by construction: CHRONICLER_SHEETS_TYPES never admits it, so no
 * author can declare a property of this type, and the property id `entry`
 * has been reserved at strict save since Phase A — the namespace cannot be
 * forged or shadowed from a template.
 */
const CHRONICLER_FORMULA_ENTRY_TYPE = 'list entry';

/** Whether the bundled expression engine is present (vendor/ built). */
function chronicler_sheets_formula_available(): bool {
    return class_exists(ExpressionLanguage::class);
}

/** The shared engine with exactly the documented functions registered. */
function chronicler_sheets_formula_engine(): ExpressionLanguage {
    static $el = null;
    if ($el === null) {
        $el = new ExpressionLanguage();
        $el->register('floor', fn ($x) => "floor($x)", fn ($args, $x) => floor($x));
        $el->register('ceil', fn ($x) => "ceil($x)", fn ($args, $x) => ceil($x));
        $el->register('round', fn ($x) => "round($x)", fn ($args, $x) => round($x));
        $el->register('min', fn (...$a) => 'min(' . implode(',', $a) . ')', fn ($args, ...$a) => min($a));
        $el->register('max', fn (...$a) => 'max(' . implode(',', $a) . ')', fn ($args, ...$a) => max($a));
    }
    return $el;
}

/**
 * The context keys (and ["sub"] keys) each referencable property contributes.
 * A checklist's parts are its OPTION IDS — moves["read_about_this"] — which is
 * the same bracket idiom track/counter use, so a formula can ask whether a
 * character has a given move (2026-07-25 §4b).
 */
function chronicler_sheets_formula_subkeys(array $property): ?array {
    switch ($property['type']) {
        case 'track':
            return ['current', 'max'];
        case 'counter':
            return isset($property['max']) ? ['current', 'max'] : ['current'];
        case 'checklist':
            return array_column($property['options'] ?? [], 'id');
        case CHRONICLER_FORMULA_ENTRY_TYPE:
            // The synthetic `entry` member: its parts are the referencable
            // field ids of the list entry a character-carried roll rides.
            return $property['fields'];
        default:
            return null; // scalar — referenced bare, no dotting
    }
}

/**
 * Evaluation context from a template + current values: scalars by id;
 * track/counter as {current, max}; a checklist as a 0/1 map keyed by option
 * id. Non-referencable types (list, longtext, opinions) are omitted, so
 * referencing one reads as an unknown name.
 */
function chronicler_sheets_formula_context(array $template, array $values): array {
    $context = [];
    foreach ($template['properties'] as $id => $property) {
        if ($property['type'] === CHRONICLER_FORMULA_ENTRY_TYPE) {
            // The synthetic `entry` member: the caller passes the value map
            // ready-made (chronicler_sheets_formula_entry_values). An absent
            // map still registers the name, which is all the fence's
            // name-check needs.
            $context[$id] = (array) ($values[$id] ?? []);
            continue;
        }
        if (!in_array($property['type'], CHRONICLER_FORMULA_REF_TYPES, true)) {
            continue;
        }
        $value = $values[$id] ?? chronicler_sheets_default_value($property);
        $subkeys = chronicler_sheets_formula_subkeys($property);
        if ($subkeys === null) {
            $context[$id] = $value;
            continue;
        }
        if ($property['type'] === 'checklist') {
            // 0/1 rather than true/false: a move you have is worth adding up
            // (`moves["a"] + moves["b"]` counts them), and it still reads as
            // a boolean everywhere PHP treats 0 as false.
            $checked = (array) $value;
            $entry = [];
            foreach ($subkeys as $option_id) {
                $entry[$option_id] = in_array($option_id, $checked, true) ? 1 : 0;
            }
            $context[$id] = $entry;
            continue;
        }
        $entry = ['current' => (int) $value];
        if (in_array('max', $subkeys, true)) {
            $entry['max'] = (int) ($property['type'] === 'track' ? $property['length'] : $property['max']);
        }
        $context[$id] = $entry;
    }
    return $context;
}

/**
 * Parse + fence one formula against the template's properties. Returns
 * ['refs' => [property ids]] on success, WP_Error naming the problem (with
 * the engine's positional detail where it has one) otherwise. Pure.
 */
function chronicler_sheets_formula_check(string $expr, array $template) {
    if (!chronicler_sheets_formula_available()) {
        return new WP_Error(
            'chronicler_formula_engine',
            'Formula support needs the plugin\'s bundled dependencies (run "composer install" in wordpress-plugin/ on a source checkout).'
        );
    }
    $names = array_keys(chronicler_sheets_formula_context($template, []));
    try {
        $parsed = chronicler_sheets_formula_engine()->parse($expr, $names);
    } catch (SyntaxError $e) {
        return new WP_Error('chronicler_formula_syntax', $e->getMessage());
    }
    $refs = [];
    $error = chronicler_sheets_formula_walk($parsed->getNodes(), $template, $refs, null);
    if (is_wp_error($error)) {
        return $error;
    }
    return ['refs' => array_values(array_unique($refs))];
}

/**
 * The fence: allow exactly the documented grammar, collect referenced
 * property ids, and require track/counter/checklist refs to name a part (bare
 * `harm` is ambiguous; `harm["current"]` is not). Returns WP_Error or null.
 */
function chronicler_sheets_formula_walk(Node $node, array $template, array &$refs, ?string $inSubscriptOf) {
    $bad = fn (string $m) => new WP_Error('chronicler_formula_grammar', $m);

    if ($node instanceof NameNode) {
        $name = $node->attributes['name'];
        $refs[] = $name;
        $property = $template['properties'][$name] ?? null;
        if ($property !== null
            && chronicler_sheets_formula_subkeys($property) !== null
            && $inSubscriptOf !== $name) {
            $keys = implode('"] / ["', chronicler_sheets_formula_subkeys($property));
            return $bad("\"$name\" is a {$property['type']} — reference {$name}[\"{$keys}\"].");
        }
        return null;
    }
    if ($node instanceof GetAttrNode) {
        if ($node->attributes['type'] !== GetAttrNode::ARRAY_CALL) {
            // The instinctive `harm.current` parses as object access, which
            // formulas don't have — point at the bracket spelling instead.
            $target = $node->nodes['node'] ?? null;
            $attribute = $node->nodes['attribute'] ?? null;
            if ($target instanceof NameNode && $attribute instanceof ConstantNode) {
                $name = $target->attributes['name'];
                $key = $attribute->attributes['value'];
                return $bad("Don't use dots; get properties of things with brackets and quotes: {$name}[\"{$key}\"].");
            }
            return $bad('Object and method access are not part of the formula language.');
        }
        $target = $node->nodes['node'];
        $attribute = $node->nodes['attribute'];
        if (!$target instanceof NameNode || !$attribute instanceof ConstantNode) {
            return $bad('Only one level of lookup is supported (e.g. harm["current"]).');
        }
        $name = $target->attributes['name'];
        $key = $attribute->attributes['value'];
        $property = $template['properties'][$name] ?? null;
        $subkeys = $property !== null ? chronicler_sheets_formula_subkeys($property) : null;
        if ($subkeys === null) {
            return $bad("\"$name\" has no [\"$key\"] — only tracks, counters and checklists have parts.");
        }
        if (!in_array($key, $subkeys, true)) {
            return $bad("\"$name\" has no [\"$key\"] — it has [\"" . implode('"] / ["', $subkeys) . '"].');
        }
        return chronicler_sheets_formula_walk($target, $template, $refs, $name);
    }
    if ($node instanceof BinaryNode) {
        if (!in_array($node->attributes['operator'], CHRONICLER_FORMULA_BINARY_OPS, true)) {
            return $bad("The \"{$node->attributes['operator']}\" operator is not part of the formula language.");
        }
    } elseif ($node instanceof UnaryNode) {
        if (!in_array($node->attributes['operator'], CHRONICLER_FORMULA_UNARY_OPS, true)) {
            return $bad("The \"{$node->attributes['operator']}\" operator is not part of the formula language.");
        }
    } elseif ($node instanceof FunctionNode) {
        if (!in_array($node->attributes['name'], CHRONICLER_FORMULA_FUNCTIONS, true)) {
            return $bad("Unknown function \"{$node->attributes['name']}\" — formulas know " . implode(', ', CHRONICLER_FORMULA_FUNCTIONS) . '.');
        }
    } elseif (!$node instanceof ConstantNode
        && !$node instanceof ConditionalNode
        && !$node instanceof ArgumentsNode
        && get_class($node) !== Node::class) {
        return $bad('That construct is not part of the formula language.');
    }

    foreach ($node->nodes as $child) {
        $error = chronicler_sheets_formula_walk($child, $template, $refs, null);
        if (is_wp_error($error)) {
            return $error;
        }
    }
    return null;
}

/**
 * Evaluate one checked formula against a context. Runtime failures (e.g.
 * division by zero) come back as WP_Error — readers fail soft to the
 * property default rather than breaking the sheet.
 */
function chronicler_sheets_formula_evaluate(string $expr, array $context) {
    if (!chronicler_sheets_formula_available()) {
        return new WP_Error('chronicler_formula_engine', 'Formula engine unavailable.');
    }
    try {
        return chronicler_sheets_formula_engine()->evaluate($expr, $context);
    } catch (\Throwable $e) {
        return new WP_Error('chronicler_formula_eval', $e->getMessage());
    }
}

/** Field types inside a list entry that a `when` expression may reference. */
const CHRONICLER_FORMULA_ENTRY_REF_TYPES = ['number', 'toggle', 'select', 'text'];

/**
 * A pseudo-template whose "properties" are one list property's entry fields,
 * so field-scoped `when` expressions reuse the same fence and context
 * machinery as top-level derived formulas. Fields are already property-shaped
 * (id/label/type/min/max/options); track and counter cannot be field types,
 * so no bracket parts exist at this scope.
 */
function chronicler_sheets_formula_entry_template(array $property): array {
    $fields = [];
    foreach ($property['fields'] as $field) {
        if (in_array($field['type'], CHRONICLER_FORMULA_ENTRY_REF_TYPES, true)) {
            $fields[$field['id']] = $field;
        }
    }
    return ['properties' => $fields];
}

/**
 * Whether a list field's `when` expression holds for one entry. Missing entry
 * values fall back to field defaults; evaluation failures fail soft to hidden,
 * matching derived's fail-soft posture. No `when` means always shown.
 */
function chronicler_sheets_when_holds(array $property, array $field, array $entry): bool {
    $expr = $field['when'] ?? null;
    if (!is_string($expr)) {
        return true;
    }
    $context = chronicler_sheets_formula_context(chronicler_sheets_formula_entry_template($property), $entry);
    $result = chronicler_sheets_formula_evaluate($expr, $context);
    return is_wp_error($result) ? false : (bool) $result;
}

/**
 * The value map behind entry["…"] (2026-07-25 Phase B): one entry's
 * referencable fields — the same set a field `when` may reference — with
 * field defaults filling what the entry doesn't store, and toggles as 0/1,
 * the checklist convention, so a toggle can ride arithmetic.
 */
function chronicler_sheets_formula_entry_values(array $property, array $entry): array {
    $values = [];
    foreach (chronicler_sheets_formula_entry_template($property)['properties'] as $id => $field) {
        $value = $entry[$id] ?? chronicler_sheets_default_value($field);
        $values[$id] = $field['type'] === 'toggle' ? (empty($value) ? 0 : 1) : $value;
    }
    return $values;
}

/**
 * The template a character-carried dice expression is fenced against: the
 * real properties plus a synthetic `entry` member whose parts are the given
 * field ids. Entry fields are reachable ONLY through the namespace, never
 * merged into property scope — the collision is real (the live MotW shape
 * has a character `harm` track AND a gear-entry `harm` number), and silently
 * shadowing one with the other mid-session is exactly the surprise the
 * namespace exists to prevent.
 */
function chronicler_sheets_formula_entry_scope(array $template, array $field_ids): array {
    $template['properties']['entry'] = [
        'type' => CHRONICLER_FORMULA_ENTRY_TYPE,
        'fields' => array_values($field_ids),
    ];
    return $template;
}

/**
 * Derived properties in dependency order (a formula may reference another
 * derived property). Kahn's algorithm over the checked refs; WP_Error names
 * the cycle members if one exists. Pure.
 */
function chronicler_sheets_derived_order(array $template) {
    $derived = [];
    foreach ($template['properties'] as $id => $property) {
        if (isset($property['derived'])) {
            $checked = chronicler_sheets_formula_check($property['derived'], $template);
            if (is_wp_error($checked)) {
                return $checked;
            }
            $derived[$id] = array_values(array_intersect($checked['refs'], array_keys($template['properties'])));
        }
    }
    $order = [];
    $remaining = $derived;
    while ($remaining !== []) {
        $progressed = false;
        foreach ($remaining as $id => $refs) {
            $blocked = array_intersect($refs, array_keys($remaining));
            if ($blocked === []) {
                $order[] = $id;
                unset($remaining[$id]);
                $progressed = true;
            }
        }
        if (!$progressed) {
            return new WP_Error(
                'chronicler_formula_cycle',
                'Derived formulas reference each other in a cycle: ' . implode(', ', array_keys($remaining)) . '.'
            );
        }
    }
    return $order;
}

/**
 * Compute every derived value from base values, in dependency order, coerced
 * to the property's type (number → numeric, toggle → strict boolean).
 * Evaluation failures fail soft: the property keeps its default. Pure.
 */
function chronicler_sheets_compute_derived(array $template, array $values): array {
    $order = chronicler_sheets_derived_order($template);
    if (is_wp_error($order)) {
        return [];
    }
    $computed = [];
    foreach ($order as $id) {
        $property = $template['properties'][$id];
        $context = chronicler_sheets_formula_context($template, array_merge($values, $computed));
        $result = chronicler_sheets_formula_evaluate($property['derived'], $context);
        if (is_wp_error($result)) {
            $computed[$id] = chronicler_sheets_default_value($property);
            continue;
        }
        if ($property['type'] === 'toggle') {
            $computed[$id] = (bool) $result;
        } else {
            $n = is_numeric($result) ? $result + 0 : chronicler_sheets_default_value($property);
            [$min, $max] = chronicler_sheets_bounds($property);
            if ($min !== null && $n < $min) {
                $n = $min;
            }
            if ($max !== null && $n > $max) {
                $n = $max;
            }
            $computed[$id] = is_float($n) && floor($n) === $n ? (int) $n : $n;
        }
    }
    return $computed;
}

/**
 * Fresh derived values for a character in the reconcile-echo shape the play
 * surfaces consume ([{prop, label, value, display}]).
 */
function chronicler_sheets_derived_echo(int $post_id, array $template): array {
    $echo = [];
    foreach ($template['properties'] as $id => $property) {
        if (!isset($property['derived'])) {
            continue;
        }
        $value = chronicler_sheets_get_value($post_id, $property);
        $echo[] = [
            'prop' => $id,
            'label' => $property['label'],
            'value' => $value,
            'display' => chronicler_sheets_display_value($property, $value),
        ];
    }
    return $echo;
}
