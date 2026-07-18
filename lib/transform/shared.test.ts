import { describe, it, expect } from "vitest";
import { safeUrl, composeBubble } from "@/lib/transform/shared";
import type { BubbleParts } from "@/lib/transform/types";

describe("safeUrl", () => {
  it("allows http(s), mailto, and app-relative paths", () => {
    expect(safeUrl("https://x.com/a")).toBe("https://x.com/a");
    expect(safeUrl("mailto:a@b.com")).toBe("mailto:a@b.com");
    expect(safeUrl("/api/slack-image?url=x")).toBe("/api/slack-image?url=x");
  });

  it("collapses javascript: and protocol-relative //host to #", () => {
    expect(safeUrl("javascript:alert(1)")).toBe("#");
    // `//evil.com` is a protocol-relative URL to an external host, not an
    // app-relative path — startsWith("/") must not wave it through.
    expect(safeUrl("//evil.com/x")).toBe("#");
  });
});

function baseParts(over: Partial<BubbleParts> = {}): BubbleParts {
  return {
    kind: "bubble",
    rootClass: "slk-msg slk-msg--text",
    anchorId: "",
    authorName: "Graz the Bold",
    authorColor: "#4674b8",
    authorColorDark: "#5e86c2",
    avatarHtml: "",
    headHtml: "",
    bodyHtml: "hi",
    images: [],
    extrasHtml: "",
    reactionsHtml: "",
    ...over,
  };
}

describe("composeBubble variants", () => {
  it("appends variant classes in vocabulary order", () => {
    const html = composeBubble(baseParts({ variants: ["important", "ooc"] }));
    expect(html).toContain('class="slk-msg slk-msg--text slk-msg--ooc slk-msg--important"');
  });
  it("shows realName in the author span when ooc is active", () => {
    const html = composeBubble(baseParts({ variants: ["ooc"], realName: "jess" }));
    expect(html).toContain('<span class="slk-msg__author">jess</span>');
  });
  it("keeps the character name when ooc is set but realName is empty", () => {
    const html = composeBubble(baseParts({ variants: ["ooc"] }));
    expect(html).toContain('<span class="slk-msg__author">Graz the Bold</span>');
  });
  it("keeps the character name when realName is set but not ooc", () => {
    const html = composeBubble(baseParts({ variants: ["important"], realName: "jess" }));
    expect(html).toContain('<span class="slk-msg__author">Graz the Bold</span>');
  });
  it("is byte-identical to the pre-variant output when no variants are set", () => {
    expect(composeBubble(baseParts())).toContain('class="slk-msg slk-msg--text"');
  });
});
