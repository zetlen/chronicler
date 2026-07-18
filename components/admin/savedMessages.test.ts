import { describe, it, expect } from "vitest";
import { composeSavedFragment, savedMessageHtml } from "@/components/admin/savedMessages";
import { sessionMessageAttributes } from "@/lib/wordpress/renderBlocks";
import { createDirectory } from "@/lib/transform/directory";
import type { RenderContext } from "@/lib/transform/types";
import type { ThreadedMessage } from "@/lib/transform/slackTypes";

const THREADS: ThreadedMessage[] = [
  {
    parent: {
      type: "message",
      ts: "1783480010.000200",
      user: "U1",
      text: "The tide *pulls back*.",
    },
    replies: [],
  },
  {
    parent: {
      type: "message",
      subtype: "channel_join",
      ts: "1783480020.000300",
      user: "U2",
      text: "<@U2> has joined the channel",
    },
    replies: [],
  },
];

const CTX: RenderContext = {
  directory: createDirectory({ users: { U1: "Alice", U2: "Bob" }, channels: {} }),
  imageMode: "proxy",
  imageProxyBase: "/wp-json/chronicler/v1/image?url=",
  density: "comfortable",
  scheme: "light",
  showAvatars: true,
  showTimestamps: true,
  showReactions: true,
  hiddenKinds: new Set(),
  customEmoji: {},
};

describe("composeSavedFragment (reopened-session preview)", () => {
  it("recomposes stored bubble attributes into transcript markup", () => {
    const saved = sessionMessageAttributes(THREADS, CTX);
    const fragment = composeSavedFragment(saved, "light");
    expect(fragment).toContain('class="slack-log"');
    expect(fragment).toContain("Alice");
    expect(fragment).toContain("<strong>pulls back</strong>");
    // The opaque system message rides through verbatim.
    expect(fragment).toContain("slk-system");
  });

  it("bakes the dark scheme class in, exactly like the live renderer", () => {
    const saved = sessionMessageAttributes(THREADS, CTX);
    expect(composeSavedFragment(saved, "dark")).toContain('class="slack-log slk-dark"');
    expect(composeSavedFragment(saved, "custom-dark")).toContain('class="slack-log"');
  });

  it("appends an editor-applied className to the bubble root", () => {
    const html = savedMessageHtml({
      rootClass: "slk-msg slk-msg--text",
      authorName: "Alice",
      bodyHtml: "hello",
      className: "is-special",
    });
    expect(html).toContain('class="slk-msg slk-msg--text is-special"');
  });

  it("renders variants and the OOC real name like the block render path", () => {
    const html = savedMessageHtml({
      rootClass: "slk-msg slk-msg--text",
      authorName: "The Chronicler",
      realName: "Alice",
      variants: ["ooc"],
      bodyHtml: "brb",
    });
    expect(html).toContain("slk-msg--ooc");
    expect(html).toContain("Alice"); // OOC reveals the pre-override name
  });

  it("tolerates empty attribute objects without throwing", () => {
    const fragment = composeSavedFragment([{}], "light");
    expect(fragment).toContain("slack-log");
  });
});
