import { describe, it, expect } from "vitest";
import type { RenderContext } from "@/lib/transform/types";
import type { Directory } from "@/lib/transform/directory";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";

const directory: Directory = {
  userName: (id) => id ?? "Unknown",
  realUserName: (id) => id ?? "Unknown",
  channelName: (id) => id ?? "channel",
  userColor: () => "#4674b8",
  userAvatar: () => undefined,
};

const ctx: RenderContext = {
  directory,
  imageMode: "proxy",
  showAvatars: true,
  showTimestamps: true,
  showReactions: true,
  density: "comfortable",
  scheme: "light",
  hiddenKinds: new Set(),
  customEmoji: {},
};

describe("mrkdwnToHtml resilience", () => {
  it("degrades instead of throwing on a pathological delimiter run", () => {
    // slack-markdown's recursive emphasis parser overflows the call stack on a
    // long run of * _ ~ (~6k chars, well under Slack's 40k message limit).
    // Unguarded, this throws RangeError mid-render and — with no error boundary
    // in the app — white-screens the whole transcript.
    const evil = "*".repeat(8000);
    let out = "";
    expect(() => {
      out = mrkdwnToHtml(evil, ctx);
    }).not.toThrow();
    expect(out.length).toBeGreaterThan(0);
  });

  it("escapes the degraded fallback so a parser crash can't inject HTML", () => {
    const evil = "_".repeat(8000) + "<script>alert(1)</script>";
    const out = mrkdwnToHtml(evil, ctx);
    expect(out).not.toContain("<script>");
    expect(out).toContain("&lt;script&gt;");
  });

  it("still renders ordinary mrkdwn unchanged", () => {
    expect(mrkdwnToHtml("hello *world*", ctx)).toContain("world");
  });
});
