import { describe, it, expect } from "vitest";
import type { SlackMessage, ThreadedMessage } from "@/lib/transform/slackTypes";
import type { RenderContext } from "@/lib/transform/types";
import { createDirectory } from "@/lib/transform/directory";
import { applyRules, type RegexRule } from "@/lib/transform/rules";
import { renderConversationFragment } from "@/lib/transform/renderDocument";
import { sessionMessageAttributes } from "@/lib/wordpress/renderBlocks";
import {
  SessionProcessor,
  type SessionRenderOptions,
  type SessionSource,
} from "@/lib/session/SessionProcessor";
import { toPreviewHtml } from "@/lib/session/toPreviewHtml";
import { toMessageAttributes } from "@/lib/session/toMessageAttributes";

function msg(ts: string, user: string, text: string): SlackMessage {
  return { type: "message", ts, user, text };
}

const SOURCE: SessionSource = {
  threads: [
    { parent: msg("1", "U2", "hello there"), replies: [] },
    { parent: msg("2", "U1", "this is a secret aside"), replies: [] },
  ] satisfies ThreadedMessage[],
  names: { users: { U1: "Ripley", U2: "Daisy" }, channels: {} },
  customEmoji: {},
};

const OPTIONS: SessionRenderOptions = {
  scheme: "light",
  density: "comfortable",
  showAvatars: false,
  showTimestamps: false,
  showReactions: false,
  hiddenKinds: new Set(),
  userOverrides: {},
  imageMode: "proxy",
};

const HIDE_SECRET: RegexRule = {
  id: "r1",
  pattern: "secret",
  flags: "i",
  mode: "hide",
  className: "",
  tagNames: "",
  treatments: "",
  enabled: true,
};

/** The RenderContext the facade should assemble — built here independently so
 *  the parity checks pin the facade against a hand-wired direct call. */
function directCtx(
  options: SessionRenderOptions,
  effects: RenderContext["ruleEffects"],
): RenderContext {
  return {
    directory: createDirectory(SOURCE.names, options.userOverrides),
    imageMode: options.imageMode,
    showAvatars: options.showAvatars,
    showTimestamps: options.showTimestamps,
    showReactions: options.showReactions,
    density: options.density,
    scheme: options.scheme,
    hiddenKinds: options.hiddenKinds,
    ruleEffects: effects,
    customEmoji: SOURCE.customEmoji ?? {},
  };
}

describe("SessionProcessor", () => {
  it("both serializers match a direct lib/transform call (no rules)", () => {
    const outcome = applyRules(SOURCE.threads, []);
    const ctx = directCtx(OPTIONS, outcome.effects);
    const processed = SessionProcessor.init(SOURCE, [], OPTIONS).process();

    expect(toMessageAttributes(processed)).toEqual(
      sessionMessageAttributes(SOURCE.threads, ctx),
    );
    expect(toPreviewHtml(processed)).toBe(
      renderConversationFragment(SOURCE.threads, ctx),
    );
  });

  it("applies a hide rule to both outputs and exposes match counts", () => {
    const outcome = applyRules(SOURCE.threads, [HIDE_SECRET]);
    const ctx = directCtx(OPTIONS, outcome.effects);
    const processed = SessionProcessor.init(SOURCE, [HIDE_SECRET], OPTIONS).process();

    // The "secret" message drops out of the stored attributes...
    const attrs = toMessageAttributes(processed);
    expect(attrs).toHaveLength(1);
    expect(attrs[0].bodyHtml).toContain("hello there");
    // ...identically to the direct call, and in the preview HTML too.
    expect(attrs).toEqual(sessionMessageAttributes(SOURCE.threads, ctx));
    expect(toPreviewHtml(processed)).toBe(
      renderConversationFragment(SOURCE.threads, ctx),
    );
    expect(processed.outcome.matchCounts.get("r1")).toBe(1);
  });

  it("update() re-runs the match pass when rules change", () => {
    const proc = SessionProcessor.init(SOURCE, [], OPTIONS);
    expect(toMessageAttributes(proc.process())).toHaveLength(2);

    proc.update([HIDE_SECRET], OPTIONS);
    const afterHide = proc.process();
    expect(toMessageAttributes(afterHide)).toHaveLength(1);
    expect(afterHide.outcome.matchCounts.get("r1")).toBe(1);
  });

  it("an options-only update keeps rule verdicts but re-renders", () => {
    const rules = [HIDE_SECRET];
    const proc = SessionProcessor.init(SOURCE, rules, OPTIONS);
    const before = proc.process();

    // Same rules array reference, avatars flipped on: verdicts unchanged, but
    // the rendered attributes differ (avatar chrome now present).
    proc.update(rules, { ...OPTIONS, showAvatars: true });
    const after = proc.process();

    expect(after.outcome.matchCounts.get("r1")).toBe(
      before.outcome.matchCounts.get("r1"),
    );
    expect(toMessageAttributes(after)).not.toEqual(toMessageAttributes(before));
    expect(toMessageAttributes(after)).toEqual(
      sessionMessageAttributes(
        SOURCE.threads,
        directCtx({ ...OPTIONS, showAvatars: true }, after.outcome.effects),
      ),
    );
  });
});
