import { describe, it, expect } from "vitest";
import type { ThreadedMessage } from "@/lib/transform/slackTypes";
import type { RenderContext } from "@/lib/transform/types";
import { createDirectory } from "@/lib/transform/directory";
import { applyRules, type RegexRule } from "@/lib/transform/rules";
import { sessionMessageAttributes } from "@/lib/wordpress/renderBlocks";

/**
 * The Session messages[] payload must validate against the plugin's message
 * schema (src/Rest/Schemas.php messageItem(), additionalProperties:false —
 * any unknown attribute is a 400 on PUT /sessions/{id}). This suite pins the
 * JS side of that contract: every emitted key is in the schema's property
 * list, image entries stay within {src, alt, caption}, and pruning keeps
 * empty values out entirely.
 */

/** EXACTLY Schemas::messageItem()'s properties — update BOTH together. */
const SCHEMA_KEYS = new Set([
  "html",
  "rootClass",
  "anchorId",
  "authorName",
  "authorColor",
  "authorColorDark",
  "avatarHtml",
  "headHtml",
  "bodyHtml",
  "images",
  "extrasHtml",
  "reactionsHtml",
  "className",
  "variants",
  "realName",
]);

const IMAGE_KEYS = new Set(["src", "alt", "caption"]);

const IMAGE_PROXY_BASE = "/wp-json/chronicler/v1/image?url=";

const THREADS: ThreadedMessage[] = [
  {
    parent: {
      type: "message",
      subtype: "channel_join",
      ts: "1783480000.000100",
      user: "U2",
      text: "<@U2> has joined the channel",
    },
    replies: [],
  },
  {
    parent: {
      type: "message",
      ts: "1783480010.000200",
      user: "U1",
      text: "The bell rings *thirteen* times. [scene]",
      reactions: [{ name: "thumbsup", count: 2 }],
    },
    replies: [],
  },
  {
    parent: {
      type: "message",
      ts: "1783480020.000300",
      thread_ts: "1783480020.000300",
      user: "U2",
      text: "Rolling to follow…",
      reply_count: 1,
    },
    replies: [
      {
        type: "message",
        ts: "1783480030.000400",
        thread_ts: "1783480020.000300",
        user: "U1",
        text: "((ooc: brb))",
      },
    ],
  },
  {
    parent: {
      type: "message",
      ts: "1783480040.000500",
      user: "U2",
      text: "map attached",
      files: [
        {
          mimetype: "image/png",
          title: "The Harbor",
          url_private: "https://files.slack.com/files-pri/T1-F1/harbor.png",
        },
      ],
    },
    replies: [],
  },
];

function makeCtx(rules: RegexRule[] = []): RenderContext {
  const outcome = applyRules(THREADS, rules);
  return {
    directory: createDirectory(
      {
        users: { U1: "Alice", U2: "Bob" },
        channels: {},
        avatars: { U2: "https://avatars.slack-edge.com/bob-72.png" },
      },
      { U1: { name: "The Chronicler" } },
    ),
    imageMode: "proxy",
    imageProxyBase: IMAGE_PROXY_BASE,
    density: "comfortable",
    scheme: "light",
    showAvatars: true,
    showTimestamps: true,
    showReactions: true,
    hiddenKinds: new Set(),
    ruleEffects: outcome.effects,
    customEmoji: {},
  };
}

describe("sessionMessageAttributes (the PUT /sessions/{id} messages[] shape)", () => {
  it("emits only schema-listed keys, with empties pruned", () => {
    const messages = sessionMessageAttributes(THREADS, makeCtx());
    expect(messages.length).toBe(5); // 4 parents + 1 reply, flat
    for (const message of messages) {
      for (const [key, value] of Object.entries(message)) {
        expect(SCHEMA_KEYS.has(key), `unknown message attribute '${key}'`).toBe(true);
        expect(value, `empty value survived pruning for '${key}'`).not.toBe("");
        if (Array.isArray(value)) expect(value.length).toBeGreaterThan(0);
      }
    }
  });

  it("system messages ride the opaque html attribute alone", () => {
    const [join] = sessionMessageAttributes(THREADS, makeCtx());
    expect(Object.keys(join)).toEqual(["html"]);
    expect(join.html).toContain("slk-system");
  });

  it("image entries stay within {src, alt, caption} and route through the mirror", () => {
    const messages = sessionMessageAttributes(THREADS, makeCtx());
    const withImages = messages.find((m) => Array.isArray(m.images))!;
    expect(withImages).toBeDefined();
    for (const image of withImages.images as Array<Record<string, unknown>>) {
      for (const key of Object.keys(image)) {
        expect(IMAGE_KEYS.has(key), `unknown image key '${key}'`).toBe(true);
      }
      expect(String(image.src).startsWith(IMAGE_PROXY_BASE)).toBe(true);
      expect(String(image.src)).toContain(
        encodeURIComponent("https://files.slack.com/files-pri/T1-F1/harbor.png"),
      );
    }
  });

  it("avatars route through the mirror base too", () => {
    const messages = sessionMessageAttributes(THREADS, makeCtx());
    const bob = messages.find(
      (m) => m.authorName === "Bob" && typeof m.avatarHtml === "string",
    )!;
    expect(String(bob.avatarHtml)).toContain(
      `${IMAGE_PROXY_BASE}${encodeURIComponent("https://avatars.slack-edge.com/bob-72.png")}`,
    );
  });

  it("keeps realName only when a rename override differs from the author", () => {
    const messages = sessionMessageAttributes(THREADS, makeCtx());
    const renamed = messages.filter((m) => m.authorName === "The Chronicler");
    expect(renamed.length).toBeGreaterThan(0);
    for (const m of renamed) expect(m.realName).toBe("Alice");
    const bob = messages.find((m) => m.authorName === "Bob")!;
    expect("realName" in bob).toBe(false);
  });

  it("applies rule verdicts: hidden messages drop out, addclass lands on rootClass", () => {
    const rules: RegexRule[] = [
      { id: "r1", pattern: "\\(\\(ooc", flags: "i", mode: "hide", className: "", enabled: true },
      { id: "r2", pattern: "\\[scene\\]", flags: "i", mode: "addclass", className: "scene-marker", enabled: true },
    ];
    const messages = sessionMessageAttributes(THREADS, makeCtx(rules));
    expect(messages.length).toBe(4); // the ooc reply is gone
    const marked = messages.find((m) => String(m.rootClass ?? "").includes("scene-marker"));
    expect(marked).toBeDefined();
  });

  it("promotes the surviving replies of a hidden parent, preserving order", () => {
    const rules: RegexRule[] = [
      { id: "r1", pattern: "Rolling to follow", flags: "i", mode: "hide", className: "", enabled: true },
    ];
    const messages = sessionMessageAttributes(THREADS, makeCtx(rules));
    // Parent hidden, its reply survives at the parent's position.
    const bodies = messages.map((m) => String(m.bodyHtml ?? m.html ?? ""));
    expect(bodies.some((b) => b.includes("ooc: brb"))).toBe(true);
    expect(bodies.some((b) => b.includes("Rolling to follow"))).toBe(false);
  });
});
