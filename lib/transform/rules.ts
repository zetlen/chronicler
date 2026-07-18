import type { SlackMessage, ThreadedMessage } from "@/lib/transform/slackTypes";

/**
 * User-defined regex rules, evaluated against each message's raw Slack text.
 *
 * A rule has one of four modes:
 *   - "start"    — the transcript begins at the rule's first matching message:
 *                  everything before it is hidden, the marker itself is kept.
 *   - "end"      — the transcript ends at the rule's first matching message at
 *                  or after the start boundary; everything after is hidden.
 *   - "hide"     — matching messages are hidden.
 *   - "addclass" — matching messages get the rule's CSS class(es), so custom
 *                  CSS can style (or animate, or hide) them.
 *
 * Composition: several "start" rules trim to the *latest* of their first
 * matches (each cut applies); several "end" rules trim to the *earliest*.
 * A start/end rule that never fires trims nothing — a missing marker should
 * not blank the transcript. Rules that are disabled, empty, or fail to
 * compile are inert.
 *
 * Ordering follows the transcript's display order (thread parent, then its
 * replies), so a marker inside a thread reply trims everything rendered above
 * it — including its own parent, whose surviving replies the renderer already
 * promotes to the top level.
 */

export type RuleMode = "start" | "end" | "hide" | "addclass" | "wp-tag";

export interface RegexRule {
  id: string;
  /** Regex source, matched against the message's raw Slack text. */
  pattern: string;
  /** Regex flags; "g"/"y" are stripped (stateful matching would misfire). */
  flags: string;
  mode: RuleMode;
  /** Space-separated CSS class(es); only meaningful for "addclass". */
  className: string;
  /**
   * Comma-separated WordPress tag name(s); only meaningful for "wp-tag".
   * Optional because rules persisted before this field existed lack it.
   */
  tagNames?: string;
  enabled: boolean;
}

/** What the rules decided about one message (keyed by its Slack ts). */
export interface RuleEffect {
  hidden: boolean;
  classes: string[];
}

export interface RuleOutcome {
  effects: ReadonlyMap<string, RuleEffect>;
  /** Messages matched per enabled+valid rule id (informational, for the UI). */
  matchCounts: ReadonlyMap<string, number>;
  /**
   * WordPress tags proposed by "wp-tag" rules whose match survived hiding
   * and start/end trimming. Deduped, in rule order.
   */
  tags: readonly string[];
}

export const EMPTY_RULE_OUTCOME: RuleOutcome = {
  effects: new Map(),
  matchCounts: new Map(),
  tags: [],
};

// crypto.randomUUID needs a secure context, which plain-HTTP LAN dev lacks.
function newRuleId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `r${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/** A fresh, inert rule ready for the editor. */
export function createRule(mode: RuleMode = "hide"): RegexRule {
  return {
    id: newRuleId(),
    pattern: "",
    flags: "i",
    mode,
    className: "",
    enabled: true,
  };
}

/**
 * Compile a rule's pattern, or null if it's empty or invalid — the caller
 * treats null as "inert" (and the editor as "show the error state").
 */
export function compileRule(
  rule: Pick<RegexRule, "pattern" | "flags">,
): RegExp | null {
  const source = rule.pattern.trim();
  if (!source) return null;
  try {
    return new RegExp(source, rule.flags.replace(/[gy]/g, ""));
  } catch {
    return null;
  }
}

/**
 * The compile error for the editor's inline message, or null when the rule
 * compiles — or is blank, which is "unfinished", not wrong.
 */
export function ruleError(
  rule: Pick<RegexRule, "pattern" | "flags">,
): string | null {
  const source = rule.pattern.trim();
  if (!source) return null;
  try {
    new RegExp(source, rule.flags.replace(/[gy]/g, ""));
    return null;
  } catch (err) {
    return err instanceof Error ? err.message : "Invalid regular expression";
  }
}

/** Split a comma-separated tag-names field into trimmed, non-empty names. */
export function tagTokens(tagNames: string | undefined): string[] {
  return (tagNames ?? "")
    .split(",")
    .map((name) => name.trim())
    .filter(Boolean);
}

/** Split a class-name field into attribute-safe tokens. */
export function classTokens(className: string): string[] {
  return className
    .split(/\s+/)
    .map((token) => token.replace(/[^A-Za-z0-9_-]/g, ""))
    .filter(Boolean);
}

/** Append classes to an HTML fragment's root element. */
export function injectRootClasses(html: string, classes: string[]): string {
  if (classes.length === 0) return html;
  const rootTag = /^<[a-zA-Z][^>]*>/.exec(html)?.[0];
  if (!rootTag) return html;
  const addition = classes.join(" ");
  const updated = rootTag.includes('class="')
    ? rootTag.replace(/class="([^"]*)"/, (_, existing) => `class="${existing} ${addition}"`)
    : rootTag.replace(/>$/, ` class="${addition}">`);
  return updated + html.slice(rootTag.length);
}

/** Evaluate every rule against every message; see the module doc for semantics. */
export function applyRules(
  threads: ThreadedMessage[],
  rules: readonly RegexRule[],
): RuleOutcome {
  const effects = new Map<string, RuleEffect>();
  const matchCounts = new Map<string, number>();
  const tags: string[] = [];
  const outcome: RuleOutcome = { effects, matchCounts, tags };

  const active = rules.flatMap((rule) => {
    if (!rule.enabled) return [];
    const regex = compileRule(rule);
    return regex ? [{ rule, regex }] : [];
  });
  if (active.length === 0) return outcome;

  // Display order: thread parent, then its replies.
  const messages: SlackMessage[] = threads.flatMap((t) => [t.parent, ...t.replies]);

  const effectFor = (ts: string): RuleEffect => {
    let effect = effects.get(ts);
    if (!effect) {
      effect = { hidden: false, classes: [] };
      effects.set(ts, effect);
    }
    return effect;
  };

  let startBoundary = 0;
  const endCandidates: { regex: RegExp; matches: number[] }[] = [];
  const tagCandidates: { names: string[]; matches: number[] }[] = [];

  for (const { rule, regex } of active) {
    const matches: number[] = [];
    messages.forEach((m, i) => {
      if (regex.test(m.text ?? "")) matches.push(i);
    });
    matchCounts.set(rule.id, matches.length);

    switch (rule.mode) {
      case "hide":
        for (const i of matches) effectFor(messages[i].ts).hidden = true;
        break;
      case "addclass": {
        const tokens = classTokens(rule.className);
        for (const i of matches) {
          const effect = effectFor(messages[i].ts);
          for (const token of tokens) {
            if (!effect.classes.includes(token)) effect.classes.push(token);
          }
        }
        break;
      }
      case "start":
        if (matches.length > 0) startBoundary = Math.max(startBoundary, matches[0]);
        break;
      case "end":
        endCandidates.push({ regex, matches });
        break;
      case "wp-tag": {
        const names = tagTokens(rule.tagNames);
        if (names.length > 0) tagCandidates.push({ names, matches });
        break;
      }
    }
  }

  // "end" fires at its first match inside the surviving transcript, so stale
  // markers from before the start boundary are ignored.
  let endBoundary = messages.length - 1;
  for (const { matches } of endCandidates) {
    const idx = matches.find((i) => i >= startBoundary);
    if (idx !== undefined) endBoundary = Math.min(endBoundary, idx);
  }

  for (let i = 0; i < messages.length; i++) {
    if (i < startBoundary || i > endBoundary) effectFor(messages[i].ts).hidden = true;
  }

  // Tags resolve last, once hiding and trimming are final: a wp-tag rule
  // whose only matches are hidden proposes nothing.
  for (const { names, matches } of tagCandidates) {
    const visible = matches.some((i) => !effects.get(messages[i].ts)?.hidden);
    if (!visible) continue;
    for (const name of names) {
      if (!tags.includes(name)) tags.push(name);
    }
  }

  return outcome;
}
