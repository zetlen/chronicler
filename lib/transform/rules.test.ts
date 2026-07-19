import { describe, it, expect } from "vitest";
import type { SlackMessage, ThreadedMessage } from "@/lib/transform/slackTypes";
import {
  type RegexRule,
  compileRule,
  ruleError,
  classTokens,
  treatmentTokens,
  injectRootClasses,
  applyRules,
} from "@/lib/transform/rules";

// --- Helpers --------------------------------------------------------------

function msg(ts: string, text: string, thread_ts?: string): SlackMessage {
  return { type: "message", ts, user: "U1", text, ...(thread_ts ? { thread_ts } : {}) };
}

/** A thread of just one top-level message. */
const solo = (ts: string, text: string): ThreadedMessage => ({
  parent: msg(ts, text),
  replies: [],
});

function rule(overrides: Partial<RegexRule>): RegexRule {
  return {
    id: "r1",
    pattern: "",
    flags: "i",
    mode: "hide",
    className: "",
    enabled: true,
    ...overrides,
  };
}

const CHAT: ThreadedMessage[] = [
  solo("1", "good morning"),
  solo("2", "Start of session"),
  solo("3", "let's begin"),
  solo("4", "wrapping up now"),
  solo("5", "End of session"),
  solo("6", "see you tomorrow"),
];

function hiddenTs(threads: ThreadedMessage[], rules: RegexRule[]): string[] {
  const { effects } = applyRules(threads, rules);
  const out: string[] = [];
  for (const t of threads) {
    for (const m of [t.parent, ...t.replies]) {
      if (effects.get(m.ts)?.hidden) out.push(m.ts);
    }
  }
  return out;
}

// --- compileRule ----------------------------------------------------------

describe("compileRule", () => {
  it("compiles a valid pattern with its flags", () => {
    const re = compileRule(rule({ pattern: "hello", flags: "i" }));
    expect(re).toBeInstanceOf(RegExp);
    expect(re!.test("Oh HELLO there")).toBe(true);
  });

  it("returns null for an invalid pattern", () => {
    expect(compileRule(rule({ pattern: "(" }))).toBeNull();
  });

  it("returns null for an empty or whitespace-only pattern", () => {
    expect(compileRule(rule({ pattern: "" }))).toBeNull();
    expect(compileRule(rule({ pattern: "   " }))).toBeNull();
  });

  it("returns null for invalid flags", () => {
    expect(compileRule(rule({ pattern: "x", flags: "zz" }))).toBeNull();
  });

  it("strips stateful global/sticky flags so repeated tests are reliable", () => {
    const re = compileRule(rule({ pattern: "hi", flags: "gi" }));
    expect(re!.test("hi")).toBe(true);
    expect(re!.test("hi")).toBe(true); // a global regex would fail here (lastIndex)
  });
});

// --- ruleError --------------------------------------------------------------

describe("ruleError", () => {
  it("is null for valid or blank rules", () => {
    expect(ruleError(rule({ pattern: "hello" }))).toBeNull();
    expect(ruleError(rule({ pattern: "" }))).toBeNull();
  });

  it("describes an invalid pattern", () => {
    expect(ruleError(rule({ pattern: "(" }))).toMatch(/regular expression/i);
  });
});

// --- classTokens ----------------------------------------------------------

describe("classTokens", () => {
  it("splits on whitespace", () => {
    expect(classTokens("foo  bar")).toEqual(["foo", "bar"]);
  });

  it("strips characters that are unsafe in class attributes", () => {
    expect(classTokens('b@d"<x>')).toEqual(["bdx"]);
  });

  it("drops tokens that sanitize to nothing", () => {
    expect(classTokens('"" <> highlight')).toEqual(["highlight"]);
  });

  it("returns [] for empty input", () => {
    expect(classTokens("")).toEqual([]);
    expect(classTokens("   ")).toEqual([]);
  });
});

// --- injectRootClasses ------------------------------------------------------

describe("injectRootClasses", () => {
  it("appends classes to the root element's class attribute only", () => {
    const html = '<div class="slk-msg"><span class="slk-inner">x</span></div>';
    expect(injectRootClasses(html, ["hot", "loud"])).toBe(
      '<div class="slk-msg hot loud"><span class="slk-inner">x</span></div>',
    );
  });

  it("adds a class attribute when the root has none", () => {
    expect(injectRootClasses("<div><b>x</b></div>", ["hot"])).toBe(
      '<div class="hot"><b>x</b></div>',
    );
  });

  it("returns the html unchanged for an empty class list", () => {
    const html = '<div class="slk-msg">x</div>';
    expect(injectRootClasses(html, [])).toBe(html);
  });
});

// --- applyRules: hide -------------------------------------------------------

describe("applyRules hide", () => {
  it("hides matching messages and counts matches", () => {
    const rules = [rule({ pattern: "session", mode: "hide" })];
    expect(hiddenTs(CHAT, rules)).toEqual(["2", "5"]);
    expect(applyRules(CHAT, rules).matchCounts.get("r1")).toBe(2);
  });

  it("ignores disabled rules", () => {
    const rules = [rule({ pattern: "session", mode: "hide", enabled: false })];
    expect(hiddenTs(CHAT, rules)).toEqual([]);
    expect(applyRules(CHAT, rules).matchCounts.has("r1")).toBe(false);
  });

  it("ignores invalid and empty patterns", () => {
    expect(hiddenTs(CHAT, [rule({ pattern: "(" })])).toEqual([]);
    expect(hiddenTs(CHAT, [rule({ pattern: "" })])).toEqual([]);
  });

  it("matches thread replies too", () => {
    const threads: ThreadedMessage[] = [
      {
        parent: msg("1", "parent", "1"),
        replies: [msg("2", "noise: reply", "1"), msg("3", "keep me", "1")],
      },
    ];
    expect(hiddenTs(threads, [rule({ pattern: "^noise:" })])).toEqual(["2"]);
  });
});

// --- applyRules: addclass ---------------------------------------------------

describe("applyRules addclass", () => {
  it("adds sanitized classes to matching messages", () => {
    const rules = [rule({ pattern: "session", mode: "addclass", className: "marker big" })];
    const { effects } = applyRules(CHAT, rules);
    expect(effects.get("2")?.classes).toEqual(["marker", "big"]);
    expect(effects.get("5")?.classes).toEqual(["marker", "big"]);
    expect(effects.get("3")?.classes ?? []).toEqual([]);
    expect(effects.get("2")?.hidden).toBeFalsy();
  });

  it("accumulates classes from multiple rules on one message", () => {
    const rules = [
      rule({ id: "a", pattern: "start", mode: "addclass", className: "one" }),
      rule({ id: "b", pattern: "session", mode: "addclass", className: "two" }),
    ];
    expect(applyRules(CHAT, rules).effects.get("2")?.classes).toEqual(["one", "two"]);
  });

  it("counts matches even when the class name is empty", () => {
    const rules = [rule({ pattern: "session", mode: "addclass", className: "" })];
    const outcome = applyRules(CHAT, rules);
    expect(outcome.matchCounts.get("r1")).toBe(2);
    expect(outcome.effects.get("2")?.classes ?? []).toEqual([]);
  });
});

// --- applyRules: start / end -------------------------------------------------

describe("applyRules start/end markers", () => {
  it("start: hides everything before the first match, keeping the marker", () => {
    const rules = [rule({ pattern: "start of session", mode: "start" })];
    expect(hiddenTs(CHAT, rules)).toEqual(["1"]);
  });

  it("start: a rule that never fires hides nothing", () => {
    expect(hiddenTs(CHAT, [rule({ pattern: "absent", mode: "start" })])).toEqual([]);
  });

  it("end: hides everything after the first match, keeping the marker", () => {
    const rules = [rule({ pattern: "end of session", mode: "end" })];
    expect(hiddenTs(CHAT, rules)).toEqual(["6"]);
  });

  it("start + end trim to the window between the markers", () => {
    const rules = [
      rule({ id: "s", pattern: "start of session", mode: "start" }),
      rule({ id: "e", pattern: "end of session", mode: "end" }),
    ];
    expect(hiddenTs(CHAT, rules)).toEqual(["1", "6"]);
  });

  it("end matches before the start boundary are ignored", () => {
    const threads = [
      solo("1", "End of session"), // stale marker from a previous session
      solo("2", "Start of session"),
      solo("3", "work happens"),
      solo("4", "End of session"),
      solo("5", "afterglow"),
    ];
    const rules = [
      rule({ id: "s", pattern: "start of session", mode: "start" }),
      rule({ id: "e", pattern: "end of session", mode: "end" }),
    ];
    expect(hiddenTs(threads, rules)).toEqual(["1", "5"]);
  });

  it("multiple start rules: the latest first-match wins", () => {
    const rules = [
      rule({ id: "a", pattern: "good morning", mode: "start" }),
      rule({ id: "b", pattern: "let's begin", mode: "start" }),
    ];
    expect(hiddenTs(CHAT, rules)).toEqual(["1", "2"]);
  });

  it("multiple end rules: the earliest first-match wins", () => {
    const rules = [
      rule({ id: "a", pattern: "wrapping up", mode: "end" }),
      rule({ id: "b", pattern: "end of session", mode: "end" }),
    ];
    expect(hiddenTs(CHAT, rules)).toEqual(["5", "6"]);
  });

  it("start/end apply over display order, so a marker in a reply hides its parent", () => {
    const threads: ThreadedMessage[] = [
      {
        parent: msg("1", "thread opener", "1"),
        replies: [msg("2", "Start of session", "1"), msg("3", "in the window", "1")],
      },
      solo("4", "also in the window"),
    ];
    const rules = [rule({ pattern: "start of session", mode: "start" })];
    expect(hiddenTs(threads, rules)).toEqual(["1"]);
  });

  it("start/end rules still report match counts", () => {
    const outcome = applyRules(CHAT, [rule({ pattern: "session", mode: "start" })]);
    expect(outcome.matchCounts.get("r1")).toBe(2);
  });
});

// --- applyRules wp-tag ------------------------------------------------------

describe("applyRules wp-tag", () => {
  it("collects tags from rules that match visible messages, deduped in rule order", () => {
    const { tags } = applyRules(CHAT, [
      rule({ id: "a", pattern: "session", mode: "wp-tag", tagNames: "rpg, session-log" }),
      rule({ id: "b", pattern: "morning", mode: "wp-tag", tagNames: "session-log,greetings" }),
    ]);
    expect(tags).toEqual(["rpg", "session-log", "greetings"]);
  });

  it("counts matches like any other rule", () => {
    const { matchCounts } = applyRules(CHAT, [
      rule({ id: "a", pattern: "session", mode: "wp-tag", tagNames: "rpg" }),
    ]);
    expect(matchCounts.get("a")).toBe(2);
  });

  it("contributes nothing when every match is hidden by a hide rule", () => {
    const { tags } = applyRules(CHAT, [
      rule({ id: "h", pattern: "morning", mode: "hide" }),
      rule({ id: "a", pattern: "morning", mode: "wp-tag", tagNames: "greetings" }),
    ]);
    expect(tags).toEqual([]);
  });

  it("contributes nothing when every match is outside the start/end window", () => {
    const { tags } = applyRules(CHAT, [
      rule({ id: "s", pattern: "Start of session", mode: "start" }),
      rule({ id: "a", pattern: "good morning", mode: "wp-tag", tagNames: "greetings" }),
    ]);
    expect(tags).toEqual([]);
  });

  it("still contributes when at least one match is visible", () => {
    const { tags } = applyRules(CHAT, [
      rule({ id: "s", pattern: "Start of session", mode: "start" }),
      rule({ id: "a", pattern: "session", mode: "wp-tag", tagNames: "rpg" }),
    ]);
    expect(tags).toEqual(["rpg"]); // "End of session" is inside the window
  });

  it("is inert when disabled or when it has no tag names", () => {
    const { tags } = applyRules(CHAT, [
      rule({ id: "a", pattern: "session", mode: "wp-tag", tagNames: "rpg", enabled: false }),
      rule({ id: "b", pattern: "session", mode: "wp-tag", tagNames: "  ,  " }),
    ]);
    expect(tags).toEqual([]);
  });
});

// --- treatmentTokens --------------------------------------------------------

describe("treatmentTokens", () => {
  it("splits on commas and whitespace and keeps only known variants", () => {
    expect(treatmentTokens("ooc, important")).toEqual(["ooc", "important"]);
    expect(treatmentTokens("important ooc")).toEqual(["ooc", "important"]); // vocabulary order
    expect(treatmentTokens("OOC")).toEqual(["ooc"]); // case-insensitive
  });

  it("drops unknown names and returns [] for empty input", () => {
    expect(treatmentTokens("ghost, spooky")).toEqual([]);
    expect(treatmentTokens("")).toEqual([]);
    expect(treatmentTokens(undefined)).toEqual([]);
  });
});

// --- applyRules: treatment --------------------------------------------------

describe("applyRules treatment", () => {
  it("sets variants on matching messages and counts matches", () => {
    const rules = [rule({ pattern: "session", mode: "treatment", treatments: "ooc" })];
    const outcome = applyRules(CHAT, rules);
    expect(outcome.effects.get("2")?.variants).toEqual(["ooc"]);
    expect(outcome.effects.get("5")?.variants).toEqual(["ooc"]);
    expect(outcome.effects.get("3")?.variants ?? []).toEqual([]);
    expect(outcome.effects.get("2")?.hidden).toBeFalsy();
    expect(outcome.matchCounts.get("r1")).toBe(2);
  });

  it("accumulates variants from multiple rules without duplicates", () => {
    const rules = [
      rule({ id: "a", pattern: "start", mode: "treatment", treatments: "ooc" }),
      rule({ id: "b", pattern: "session", mode: "treatment", treatments: "ooc, important" }),
    ];
    expect(applyRules(CHAT, rules).effects.get("2")?.variants).toEqual(["ooc", "important"]);
  });

  it("ignores unknown treatment names but still counts matches", () => {
    const rules = [rule({ pattern: "session", mode: "treatment", treatments: "spooky" })];
    const outcome = applyRules(CHAT, rules);
    expect(outcome.matchCounts.get("r1")).toBe(2);
    expect(outcome.effects.get("2")?.variants ?? []).toEqual([]);
  });

  it("is inert when disabled", () => {
    const rules = [
      rule({ pattern: "session", mode: "treatment", treatments: "ooc", enabled: false }),
    ];
    expect(applyRules(CHAT, rules).effects.get("2")?.variants ?? []).toEqual([]);
  });
});
