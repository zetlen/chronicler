import { describe, it, expect } from "vitest";
import { createDirectory } from "@/lib/transform/directory";
import { transformMessage } from "@/lib/transform/transformMessage";
import type { RenderContext } from "@/lib/transform/types";
import type { SlackMessage } from "@/lib/transform/slackTypes";

function ctx(): RenderContext {
  return {
    directory: createDirectory(
      { users: { U1: "Alice" }, channels: {} },
      { U1: { name: "Graz the Bold" } },
    ),
    imageMode: "proxy",
    showAvatars: false,
    showTimestamps: false,
    showReactions: false,
    density: "comfortable",
    scheme: "light",
    hiddenKinds: new Set(),
    customEmoji: {},
  };
}

describe("human renderers carry realName", () => {
  it("text sets realName to the un-renamed name", () => {
    const m = { type: "message", user: "U1", ts: "1.1", text: "hi" } as SlackMessage;
    const parts = transformMessage(m, ctx());
    expect(parts.kind).toBe("bubble");
    if (parts.kind !== "bubble") return;
    expect(parts.authorName).toBe("Graz the Bold");
    expect(parts.realName).toBe("Alice");
  });
});
