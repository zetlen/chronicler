import { describe, it, expect } from "vitest";
import { bubbleParts, composeBubble, figuresHtml } from "@/lib/transform/shared";
import type { RenderContext } from "@/lib/transform/types";
import type { SlackMessage } from "@/lib/transform/slackTypes";
import { transformMessage, transformMessageHtml } from "@/lib/transform/transformMessage";

const ctx = {
  showAvatars: true,
  showTimestamps: false, // deterministic: formatTimestamp is locale-dependent
  showReactions: true,
  customEmoji: {},
} as unknown as RenderContext;

describe("bubbleParts/composeBubble", () => {
  it("splits chrome into parts and composes the exact bubble markup", () => {
    const parts = bubbleParts(
      {
        modifiers: ["text"],
        authorName: "Daisy <3",
        timestamp: "1623351600.000400",
        bodyHtml: "<strong>hi</strong>",
        authorColor: "#3d6bbf",
      },
      ctx,
    );
    expect(parts.kind).toBe("bubble");
    expect(parts.rootClass).toBe("slk-msg slk-msg--text");
    expect(parts.anchorId).toBe("msg-1623351600000400"); // deep-link anchor (#75)
    expect(parts.authorName).toBe("Daisy <3");
    expect(parts.authorColor).toBe("#3d6bbf");
    expect(parts.authorColorDark).toBe("#6a93d8"); // index-matched dark palette
    // timestamps off, no badge → headHtml is just the copy-link anchor, whose
    // chain-link icon is painted in CSS (see .slk-msg__permalink in styles.ts).
    expect(parts.headHtml).toBe(
      '<a class="slk-msg__permalink" href="#msg-1623351600000400"' +
        ' aria-label="Copy link to this message"></a>',
    );
    expect(parts.avatarHtml).toBe(
      '<div class="slk-msg__avatar"><span class="slk-avatar">D&lt;</span></div>',
    );
    expect(composeBubble(parts)).toBe(
      `<div class="slk-msg slk-msg--text" id="msg-1623351600000400" style="--slk-id:#3d6bbf;--slk-id-dark:#6a93d8">
  <div class="slk-msg__avatar"><span class="slk-avatar">D&lt;</span></div>
  <div class="slk-msg__main">
    <div class="slk-msg__head"><span class="slk-msg__author">Daisy &lt;3</span><a class="slk-msg__permalink" href="#msg-1623351600000400" aria-label="Copy link to this message"></a></div>
    <div class="slk-msg__body"><strong>hi</strong></div>
    ${""}
  </div>
</div>`,
    );
  });

  it("renders structured images exactly like renderImages' proxy branch", () => {
    expect(
      figuresHtml([{ src: "/api/slack-image?url=x", alt: "pic", caption: "pic" }]),
    ).toBe(
      '<div class="slk-images"><figure class="slk-image">' +
        '<img src="/api/slack-image?url=x" alt="pic" loading="lazy">' +
        "<figcaption>pic</figcaption></figure></div>",
    );
    expect(figuresHtml([])).toBe("");
  });

  it("places images and extras inside the body div, after the text", () => {
    const parts = bubbleParts(
      {
        modifiers: ["image"],
        authorName: "A",
        timestamp: "1",
        bodyHtml: "cap",
        images: [{ src: "/api/slack-image?url=x", alt: "p", caption: "p" }],
        extrasHtml: '<div class="slk-files">F</div>',
        authorColor: "#4674b8",
      },
      ctx,
    );
    const html = composeBubble(parts);
    expect(html).toContain(
      '<div class="slk-msg__body">cap<div class="slk-images">',
    );
    expect(html).toContain('</figure></div><div class="slk-files">F</div></div>');
  });
});

describe("permalink affordance (timestamps hidden)", () => {
  const opts = {
    modifiers: ["text"],
    authorName: "Daisy",
    timestamp: "1623351600.000400",
    bodyHtml: "hi",
    authorColor: "#4674b8",
  };

  it("adds a copy-link anchor to headHtml when timestamps are hidden", () => {
    // ctx.showTimestamps is false: with no visible time, the anchor is the only
    // affordance for grabbing a message's #msg-... permalink. It reuses the
    // anchor id and is empty — the icon is painted in CSS.
    const parts = bubbleParts(opts, ctx);
    expect(parts.headHtml).toBe(
      '<a class="slk-msg__permalink" href="#msg-1623351600000400"' +
        ' aria-label="Copy link to this message"></a>',
    );
  });

  it("omits the permalink when timestamps are shown (the time is the affordance)", () => {
    const shown = { ...ctx, showTimestamps: true } as RenderContext;
    const parts = bubbleParts(opts, shown);
    expect(parts.headHtml).toContain('class="slk-msg__time"');
    expect(parts.headHtml).not.toContain("slk-msg__permalink");
  });

  it("still carries the permalink after a badge when one is present", () => {
    const parts = bubbleParts({ ...opts, badge: "APP" }, ctx);
    expect(parts.headHtml).toBe(
      '<span class="slk-badge">APP</span>' +
        '<a class="slk-msg__permalink" href="#msg-1623351600000400"' +
        ' aria-label="Copy link to this message"></a>',
    );
  });
});

describe("regression: image-bearing thread replies", () => {
  // classify() routes threaded messages to "reply" before it ever checks for
  // image files (see lib/transform/classify.ts: isReply() wins over
  // hasImage()), so a reply carrying an image file must still render it —
  // exactly like the "image" kind's proxy-mode fork.
  const replyCtx = {
    directory: {
      userName: () => "Bob",
      realUserName: () => "Bob",
      channelName: (id?: string) => id ?? "channel",
      userColor: () => "#4674b8",
      userAvatar: () => undefined,
    },
    imageMode: "proxy",
    showAvatars: true,
    showTimestamps: false,
    showReactions: true,
    density: "comfortable",
    scheme: "light",
    hiddenKinds: new Set(),
    customEmoji: {},
  } as unknown as RenderContext;

  const imageReplyMsg: SlackMessage = {
    type: "message",
    ts: "200.000",
    thread_ts: "199.000", // differs from ts, so classify() treats this as a reply
    user: "U2",
    text: "check this out",
    files: [
      {
        mimetype: "image/png",
        url_private: "https://files.slack.com/pic.png",
        title: "pic",
      },
    ],
  };

  it("keeps the reply bubble kind and modifier", () => {
    const parts = transformMessage(imageReplyMsg, replyCtx);
    expect(parts.kind).toBe("bubble");
    if (parts.kind !== "bubble") throw new Error("expected a bubble");
    expect(parts.rootClass).toContain("slk-msg--reply");
  });

  it("carries the image through parts.images in proxy mode", () => {
    const parts = transformMessage(imageReplyMsg, replyCtx);
    if (parts.kind !== "bubble") throw new Error("expected a bubble");
    expect(parts.images).toHaveLength(1);
    expect(parts.images[0].src).toMatch(/^\/api\/slack-image\?url=/);
  });

  it("renders the image figure in the composed HTML", () => {
    const html = transformMessageHtml(imageReplyMsg, replyCtx);
    expect(html).toContain('<figure class="slk-image">');
  });
});

describe("treatment rules feed compose-time variants (#154)", () => {
  // A directory where the display name is a character override, so the OOC
  // byline swap has a real name to reveal.
  const ruleCtx = (
    effects: Map<string, { hidden: boolean; classes: string[]; variants: string[] }>,
  ) =>
    ({
      directory: {
        userName: () => "Marisol the Bold",
        realUserName: () => "Alice",
        channelName: (id?: string) => id ?? "channel",
        userColor: () => "#4674b8",
        userAvatar: () => undefined,
      },
      imageMode: "proxy",
      showAvatars: false,
      showTimestamps: false,
      showReactions: false,
      density: "comfortable",
      scheme: "light",
      hiddenKinds: new Set(),
      customEmoji: {},
      ruleEffects: effects,
    }) as unknown as RenderContext;

  const textMsg: SlackMessage = {
    type: "message",
    ts: "300.000",
    user: "U1",
    text: "(brb, dog needs out)",
  };

  it("merges rule variants into bubble parts", () => {
    const ctx = ruleCtx(
      new Map([["300.000", { hidden: false, classes: [], variants: ["ooc"] }]]),
    );
    const parts = transformMessage(textMsg, ctx);
    if (parts.kind !== "bubble") throw new Error("expected a bubble");
    expect(parts.variants).toEqual(["ooc"]);
  });

  it("swaps the byline to the real name in composed HTML when ooc is set", () => {
    const ctx = ruleCtx(
      new Map([["300.000", { hidden: false, classes: [], variants: ["ooc"] }]]),
    );
    const html = transformMessageHtml(textMsg, ctx);
    expect(html).toContain('<span class="slk-msg__author">Alice</span>');
    expect(html).toContain("slk-msg--ooc");
  });

  it("keeps the character byline when no treatment applies", () => {
    const ctx = ruleCtx(new Map());
    const html = transformMessageHtml(textMsg, ctx);
    expect(html).toContain('<span class="slk-msg__author">Marisol the Bold</span>');
    expect(html).not.toContain("slk-msg--ooc");
  });

  it("combines rule classes and variants on one message", () => {
    const ctx = ruleCtx(
      new Map([
        ["300.000", { hidden: false, classes: ["marker"], variants: ["important"] }],
      ]),
    );
    const html = transformMessageHtml(textMsg, ctx);
    expect(html).toContain("slk-msg--important");
    expect(html).toContain("marker");
  });

  it("degrades to class injection for raw (system) messages", () => {
    const sysMsg: SlackMessage = {
      type: "message",
      subtype: "channel_join",
      ts: "301.000",
      user: "U1",
      text: "<@U1> has joined the channel",
    };
    const ctx = ruleCtx(
      new Map([["301.000", { hidden: false, classes: [], variants: ["ooc"] }]]),
    );
    const parts = transformMessage(sysMsg, ctx);
    if (parts.kind !== "raw") throw new Error("expected raw parts");
    expect(parts.rawHtml).toContain("slk-msg--ooc");
  });
});
