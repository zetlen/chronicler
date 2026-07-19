import { describe, it, expect } from "vitest";
import type { SlackMessage, ThreadedMessage } from "@/lib/transform/slackTypes";
import type { Directory } from "@/lib/transform/directory";
import { createDirectory } from "@/lib/transform/directory";
import type { RenderContext } from "@/lib/transform/types";
import { classify } from "@/lib/transform/classify";
import { applyRules } from "@/lib/transform/rules";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import { renderConversationFragment } from "@/lib/transform/renderDocument";
import { transformMessageHtml } from "@/lib/transform/transformMessage";
import { sanitizeFragment } from "@/lib/transform/sanitize";
import { formatTimestamp, renderAttachments } from "@/lib/transform/shared";
import {
  dialogueCss,
  LIGHT_TOKENS,
  DARK_TOKENS,
  customCssTemplate,
  customSchemeTemplate,
} from "@/lib/transform/styles";

const directory: Directory = {
  userName: (id) =>
    id === "U1" ? "Alice" : id === "U2" ? "Bob" : id ?? "Unknown",
  realUserName: (id) =>
    id === "U1" ? "Alice" : id === "U2" ? "Bob" : id ?? "Unknown",
  channelName: (id) => (id === "C1" ? "general" : id ?? "channel"),
  userColor: () => "#4674b8",
  userAvatar: () => undefined,
};

function makeCtx(overrides: Partial<RenderContext> = {}): RenderContext {
  return {
    directory,
    imageMode: "proxy",
    showAvatars: true,
    showTimestamps: true,
    showReactions: true,
    density: "comfortable",
    scheme: "light",
    hiddenKinds: new Set(),
    customEmoji: {},
    ...overrides,
  };
}

const ctx = makeCtx();

// --- Sample messages, one per category we care about ---------------------

const textMsg: SlackMessage = {
  type: "message",
  ts: "100.000",
  user: "U1",
  text: "Hello *world* <@U2> in <#C1>",
  reactions: [{ name: "thumbsup", count: 3 }],
};

const systemMsg: SlackMessage = {
  type: "message",
  subtype: "channel_join",
  ts: "101.000",
  user: "U2",
  text: "<@U2> has joined the channel",
};

const botMsg: SlackMessage = {
  type: "message",
  subtype: "bot_message",
  ts: "102.000",
  bot_id: "B1",
  username: "Deploybot",
  text: "Deploy finished :rocket:",
};

const imageMsg: SlackMessage = {
  type: "message",
  ts: "103.000",
  user: "U1",
  text: "a screenshot",
  files: [
    {
      mimetype: "image/png",
      name: "shot.png",
      url_private: "https://files.slack.com/shot.png",
      thumb_720: "https://files.slack.com/shot_720.png",
    },
  ],
};

const linkMsg: SlackMessage = {
  type: "message",
  ts: "104.000",
  user: "U2",
  text: "look at <https://example.com>",
  attachments: [
    {
      title: "Example Domain",
      title_link: "https://example.com",
      text: "An illustrative example.",
      service_name: "example.com",
    },
  ],
};

const threadParent: SlackMessage = {
  type: "message",
  ts: "105.000",
  thread_ts: "105.000",
  user: "U1",
  text: "starting a thread",
  reply_count: 2,
};

const humanReply: SlackMessage = {
  type: "message",
  ts: "106.000",
  thread_ts: "105.000",
  user: "U2",
  text: "a human reply",
};

const botReply: SlackMessage = {
  type: "message",
  ts: "107.000",
  thread_ts: "105.000",
  bot_id: "B2",
  username: "Helper",
  text: "a bot reply",
};

const allThreads: ThreadedMessage[] = [
  { parent: textMsg, replies: [] },
  { parent: systemMsg, replies: [] },
  { parent: botMsg, replies: [] },
  { parent: imageMsg, replies: [] },
  { parent: linkMsg, replies: [] },
  { parent: threadParent, replies: [humanReply, botReply] },
];

describe("classify", () => {
  it("categorizes each message kind", () => {
    expect(classify(textMsg)).toBe("text");
    expect(classify(systemMsg)).toBe("system");
    expect(classify(botMsg)).toBe("bot_message");
    expect(classify(imageMsg)).toBe("image");
    expect(classify(linkMsg)).toBe("link");
    expect(classify(humanReply)).toBe("reply");
    expect(classify(botReply)).toBe("bot_reply");
  });
});

describe("mrkdwnToHtml", () => {
  it("renders emphasis", () => {
    const html = mrkdwnToHtml("a *bold* and _italic_ word", ctx);
    expect(html).toContain("<strong>bold</strong>");
    expect(html).toContain("<em>italic</em>");
  });

  it("resolves mentions via the directory", () => {
    const html = mrkdwnToHtml("hi <@U2> in <#C1>", ctx);
    expect(html).toContain("@Bob");
    expect(html).toContain("#general");
  });

  it("reverses Slack's entity escaping", () => {
    expect(mrkdwnToHtml("a &amp; b", ctx)).toContain("a &amp; b");
  });

  it("prefers the directory's current name over a stale embedded name", () => {
    expect(mrkdwnToHtml("hi <@U2|oldbob>", ctx)).toContain("@Bob");
  });

  it("falls back to the embedded name when the directory can't resolve the ID", () => {
    expect(mrkdwnToHtml("hi <@U9|legacy>", ctx)).toContain("@legacy");
  });
});

describe("renderAttachments", () => {
  it("resolves user mentions in attachment text (bot cards)", () => {
    const html = renderAttachments([{ text: "<@U2> rolled *15*" }], ctx);
    expect(html).toContain("@Bob");
    expect(html).not.toContain("&lt;@U2&gt;");
  });

  it("renders attachment text as mrkdwn, not plain text", () => {
    const html = renderAttachments([{ text: "<@U2> rolled *15*" }], ctx);
    expect(html).toContain("<strong>15</strong>");
  });

  it("resolves user mentions in attachment field values", () => {
    const html = renderAttachments(
      [{ fields: [{ title: "Winner", value: "<@U1>", short: true }] }],
      ctx,
    );
    expect(html).toContain("@Alice");
    expect(html).not.toContain("&lt;@U1&gt;");
  });
});

describe("renderConversationFragment", () => {
  const html = renderConversationFragment(allThreads, ctx);

  it("wraps output in the scoped container", () => {
    expect(html).toContain('class="slack-log"');
  });

  it("renders authors and resolved mentions", () => {
    expect(html).toContain("Alice");
    expect(html).toContain("@Bob");
  });

  it("flags bot messages with a badge", () => {
    expect(html).toContain("Deploybot");
    expect(html).toContain("slk-badge");
  });

  it("renders images through the authenticated proxy in proxy mode", () => {
    expect(html).toContain("/api/slack-image?url=");
  });

  it("renders link unfurl cards", () => {
    expect(html).toContain("slk-card");
    expect(html).toContain("Example Domain");
  });

  it("renders system events as muted lines, not bubbles", () => {
    expect(html).toContain("slk-system");
  });

  it("leaves no target=_blank link without rel=noopener", () => {
    const threads: ThreadedMessage[] = [
      { parent: { ...textMsg, text: "see <https://example.com|Example>" }, replies: [] },
      { parent: linkMsg, replies: [] },
    ];
    const linkHtml = renderConversationFragment(threads, ctx);
    expect(linkHtml).toContain('href="https://example.com"');
    // No anchor may carry target=_blank without a rel attribute in the same tag.
    expect(linkHtml).not.toMatch(/target="_blank"(?![^>]*\brel=)/);
  });

  it("nests replies and distinguishes human vs bot replies", () => {
    expect(html).toContain("slk-thread__replies");
    expect(html).toContain("slk-msg--reply");
    expect(html).toContain("slk-msg--bot slk-msg--reply");
  });
});

describe("attachment cards on media messages", () => {
  const unfurl = {
    title: "Shared Doc",
    title_link: "https://example.com/doc",
    text: "A shared document.",
    service_name: "example.com",
  };

  it("renders unfurl cards on image messages", () => {
    const msg: SlackMessage = {
      ...imageMsg,
      text: "a screenshot of <https://example.com/doc>",
      attachments: [unfurl],
    };
    const html = renderConversationFragment([{ parent: msg, replies: [] }], ctx);
    expect(html).toContain("slk-card");
    expect(html).toContain("Shared Doc");
  });

  it("renders unfurl cards on file messages", () => {
    const msg: SlackMessage = {
      type: "message",
      ts: "108.000",
      user: "U1",
      text: "the report and <https://example.com/doc>",
      files: [
        {
          mimetype: "application/pdf",
          name: "report.pdf",
          url_private: "https://files.slack.com/report.pdf",
        },
      ],
      attachments: [unfurl],
    };
    const html = renderConversationFragment([{ parent: msg, replies: [] }], ctx);
    expect(html).toContain("slk-card");
    expect(html).toContain("Shared Doc");
  });
});

describe("blocksToHtml (Block Kit flattening)", () => {
  const blockMsg: SlackMessage = {
    type: "message",
    subtype: "bot_message",
    ts: "110.000",
    bot_id: "B1",
    username: "Blockbot",
    blocks: [
      { type: "header", text: { type: "plain_text", text: "Weekly report" } },
      { type: "section", text: { type: "mrkdwn", text: "All *good*" } },
      { type: "divider" },
      {
        type: "image",
        image_url: "https://cdn.example.com/chart.png",
        alt_text: "a chart",
        title: { type: "plain_text", text: "Chart" },
      },
      { type: "actions", elements: [] },
    ],
  };
  const html = () =>
    renderConversationFragment([{ parent: blockMsg, replies: [] }], ctx);

  it("renders header and section text through mrkdwn", () => {
    expect(html()).toContain("<strong>Weekly report</strong>");
    expect(html()).toContain("<strong>good</strong>");
  });

  it("renders image blocks as figures with alt and caption", () => {
    expect(html()).toContain('src="https://cdn.example.com/chart.png"');
    expect(html()).toContain('alt="a chart"');
    expect(html()).toContain("<figcaption>Chart</figcaption>");
  });

  it("renders divider blocks as rules", () => {
    expect(html()).toContain('<hr class="slk-divider">');
  });

  it("renders a visible placeholder for unsupported block types", () => {
    expect(html()).toContain('class="slk-unsupported"');
    expect(html()).toContain("[unsupported block: actions]");
  });

  it("proxies Slack-hosted block images", () => {
    const msg: SlackMessage = {
      ...blockMsg,
      blocks: [
        {
          type: "image",
          slack_file: { url_private: "https://files.slack.com/x.png" },
          alt_text: "x",
        },
      ],
    };
    const out = renderConversationFragment([{ parent: msg, replies: [] }], ctx);
    expect(out).toContain("/api/slack-image?url=");
  });
});

describe("reactions", () => {
  it("shows reaction chips (as emoji) when enabled", () => {
    const html = renderConversationFragment(allThreads, makeCtx({ showReactions: true }));
    expect(html).toContain("slk-reaction");
    expect(html).toContain("👍"); // thumbsup mapped to emoji
  });

  it("omits reactions when disabled", () => {
    const html = renderConversationFragment(allThreads, makeCtx({ showReactions: false }));
    expect(html).not.toContain("slk-reaction");
  });
});

describe("emoji", () => {
  const PARROT = "https://emoji.slack-edge.com/T0/party_parrot/a.gif";

  it("renders any standard reaction shortcode via the full dataset", () => {
    const msg: SlackMessage = { ...textMsg, reactions: [{ name: "wave", count: 1 }] };
    const html = renderConversationFragment([{ parent: msg, replies: [] }], ctx);
    expect(html).toContain("👋"); // not in the old hand-rolled map
  });

  it("renders skin-tone reaction variants", () => {
    const msg: SlackMessage = {
      ...textMsg,
      reactions: [{ name: "thumbsup::skin-tone-3", count: 1 }],
    };
    const html = renderConversationFragment([{ parent: msg, replies: [] }], ctx);
    expect(html).toContain("👍🏼");
  });

  it("renders custom workspace emoji reactions as images", () => {
    const msg: SlackMessage = {
      ...textMsg,
      reactions: [{ name: "party_parrot", count: 2 }],
    };
    const html = renderConversationFragment(
      [{ parent: msg, replies: [] }],
      makeCtx({ customEmoji: { party_parrot: PARROT } }),
    );
    expect(html).toContain('class="slk-emoji"');
    expect(html).toContain(PARROT);
  });

  it("falls back to :shortcode: text for unknown emoji", () => {
    const msg: SlackMessage = { ...textMsg, reactions: [{ name: "mystery_meat", count: 1 }] };
    const html = renderConversationFragment([{ parent: msg, replies: [] }], ctx);
    expect(html).toContain(":mystery_meat:");
  });

  it("substitutes custom emoji shortcodes in message text", () => {
    const msg: SlackMessage = { ...textMsg, text: "ship it :party_parrot:" };
    const html = renderConversationFragment(
      [{ parent: msg, replies: [] }],
      makeCtx({ customEmoji: { party_parrot: PARROT } }),
    );
    expect(html).toContain(PARROT);
  });

  it("leaves shortcodes inside code spans literal", () => {
    const msg: SlackMessage = { ...textMsg, text: "use `:party_parrot:` here" };
    const html = renderConversationFragment(
      [{ parent: msg, replies: [] }],
      makeCtx({ customEmoji: { party_parrot: PARROT } }),
    );
    expect(html).not.toContain(PARROT);
    expect(html).toContain(":party_parrot:");
  });
});

describe("filters (hiddenKinds)", () => {
  it("hides system events", () => {
    const html = renderConversationFragment(allThreads, makeCtx({ hiddenKinds: new Set(["system"]) }));
    expect(html).not.toContain("slk-system");
  });

  it("hides bot messages and bot replies", () => {
    const html = renderConversationFragment(
      allThreads,
      makeCtx({ hiddenKinds: new Set(["bot_message", "bot_reply"]) }),
    );
    expect(html).not.toContain("Deploybot");
    expect(html).not.toContain("slk-msg--bot");
  });

  it("promotes surviving replies when the thread parent is filtered out", () => {
    // Parent is a bot_message with a human reply; hiding bots keeps the reply.
    const threads: ThreadedMessage[] = [{ parent: botMsg, replies: [humanReply] }];
    const html = renderConversationFragment(
      threads,
      makeCtx({ hiddenKinds: new Set(["bot_message", "bot_reply"]) }),
    );
    expect(html).not.toContain("Deploybot");
    expect(html).toContain("a human reply");
    expect(html).not.toContain("slk-thread__replies"); // promoted, not nested
  });
});

describe("regex rule effects (ruleEffects)", () => {
  it("hides messages the rules marked hidden", () => {
    const ruleEffects = new Map([["102.000", { hidden: true, classes: [], variants: [] }]]);
    const html = renderConversationFragment(allThreads, makeCtx({ ruleEffects }));
    expect(html).not.toContain("Deploybot");
  });

  it("promotes surviving replies when a rule hides the thread parent", () => {
    const ruleEffects = new Map([["105.000", { hidden: true, classes: [], variants: [] }]]);
    const html = renderConversationFragment(allThreads, makeCtx({ ruleEffects }));
    expect(html).not.toContain("starting a thread");
    expect(html).toContain("a human reply");
    expect(html).not.toContain("slk-thread__replies"); // promoted, not nested
  });

  it("injects rule classes into the message root and they survive sanitization", () => {
    const ruleEffects = new Map([
      ["100.000", { hidden: false, classes: ["session-note"], variants: [] }],
    ]);
    const html = renderConversationFragment(allThreads, makeCtx({ ruleEffects }));
    expect(html).toContain('class="slk-msg slk-msg--text session-note"');
  });

  it("plugs applyRules output straight into the renderer", () => {
    const { effects } = applyRules(allThreads, [
      {
        id: "r",
        pattern: "deploy finished",
        flags: "i",
        mode: "hide",
        className: "",
        enabled: true,
      },
    ]);
    const html = renderConversationFragment(allThreads, makeCtx({ ruleEffects: effects }));
    expect(html).not.toContain("Deploy finished");
  });
});

describe("appearance", () => {
  it("adds the compact density class", () => {
    const html = renderConversationFragment(allThreads, makeCtx({ density: "compact" }));
    expect(html).toContain("slk-density-compact");
  });

  it("omits avatars and timestamps when disabled", () => {
    const html = renderConversationFragment(
      allThreads,
      makeCtx({ showAvatars: false, showTimestamps: false }),
    );
    expect(html).not.toContain("slk-msg__avatar");
    expect(html).not.toContain("slk-msg__time");
  });
});

describe("createDirectory", () => {
  const maps = {
    users: { U1: "Alice" },
    channels: { C1: "general" },
    avatars: { U1: "https://avatars.slack-edge.com/U1-72.png" },
  };

  it("exposes avatar URLs, undefined when unknown", () => {
    const dir = createDirectory(maps);
    expect(dir.userAvatar("U1")).toBe("https://avatars.slack-edge.com/U1-72.png");
    expect(dir.userAvatar("U9")).toBeUndefined();
    expect(dir.userAvatar(undefined)).toBeUndefined();
  });

  it("resolves names from the maps", () => {
    const dir = createDirectory(maps);
    expect(dir.userName("U1")).toBe("Alice");
    expect(dir.channelName("C1")).toBe("general");
  });

  it("applies user renames, falling back to the resolved name when blank", () => {
    expect(createDirectory(maps, { U1: { name: "Ace" } }).userName("U1")).toBe("Ace");
    expect(createDirectory(maps, { U1: { name: "  " } }).userName("U1")).toBe("Alice");
  });

  it("falls back to the raw ID for unknown users", () => {
    expect(createDirectory(maps).userName("U9")).toBe("U9");
  });

  it("uses a color override, else a stable default", () => {
    expect(createDirectory(maps, { U1: { color: "#ff0000" } }).userColor("U1")).toBe(
      "#ff0000",
    );
    const def = createDirectory(maps).userColor("U1");
    expect(def).toMatch(/^#[0-9a-f]{6}$/i);
    // Stable: same name → same default color.
    expect(createDirectory(maps).userColor("U1")).toBe(def);
  });
});

describe("thread window notes", () => {
  it("notes the clipped tail of an in-window thread", () => {
    const threads: ThreadedMessage[] = [
      { parent: threadParent, replies: [humanReply], omittedAfter: 3 },
    ];
    const html = renderConversationFragment(threads, ctx);
    expect(html).toContain("slk-thread__note");
    expect(html).toContain("3 later replies");
  });

  it("marks orphan-thread parents as context with earlier-reply note", () => {
    const threads: ThreadedMessage[] = [
      {
        parent: threadParent,
        replies: [humanReply],
        parentBeforeWindow: true,
        omittedBefore: 1,
      },
    ];
    const html = renderConversationFragment(threads, ctx);
    expect(html).toContain("slk-thread--context");
    expect(html).toContain("started before the selected range");
    expect(html).toContain("1 earlier reply");
  });

  it("renders no notes for unclipped threads", () => {
    const html = renderConversationFragment(allThreads, ctx);
    expect(html).not.toContain("slk-thread__note");
  });
});

describe("avatars", () => {
  const AVATAR = "https://avatars.slack-edge.com/U1-72.png";
  const dirWithAvatars: Directory = {
    ...directory,
    userAvatar: (id) => (id === "U1" ? AVATAR : undefined),
  };

  it("renders a proxied <img> avatar when the directory has one", () => {
    const html = renderConversationFragment(
      [{ parent: textMsg, replies: [] }],
      makeCtx({ directory: dirWithAvatars }),
    );
    expect(html).toContain('class="slk-avatar__img"');
    expect(html).toContain(encodeURIComponent(AVATAR));
  });

  it("falls back to initials when no avatar URL is known", () => {
    const msg: SlackMessage = { ...linkMsg, ts: "120.000" }; // authored by U2
    const html = renderConversationFragment(
      [{ parent: msg, replies: [] }],
      makeCtx({ directory: dirWithAvatars }),
    );
    expect(html).toMatch(/<span class="slk-avatar">BO<\/span>/); // "Bob" → "BO" initials path
  });

  it("uses bot_profile icons for bot messages", () => {
    const msg: SlackMessage = {
      ...botMsg,
      bot_profile: { name: "Deploybot", icons: { image_72: AVATAR } },
    };
    const html = renderConversationFragment([{ parent: msg, replies: [] }], ctx);
    expect(html).toContain('class="slk-avatar__img"');
    expect(html).toContain(encodeURIComponent(AVATAR));
  });
});

describe("sanitizeFragment", () => {
  it("strips javascript: URLs and inline event handlers", () => {
    const dirty = `<a href="javascript:alert(1)">x</a><img src="y" onerror="alert(1)">`;
    const clean = sanitizeFragment(dirty);
    expect(clean).not.toContain("javascript:");
    expect(clean).not.toContain("onerror");
  });

  it("keeps identity-color custom properties in style attributes", () => {
    const html = `<div class="slk-msg" style="--slk-id:#4674b8;--slk-id-dark:#5e86c2">x</div>`;
    const clean = sanitizeFragment(html);
    expect(clean).toContain("--slk-id:#4674b8");
    expect(clean).toContain("--slk-id-dark:#5e86c2");
  });

  it("forces rel=noopener noreferrer onto target=_blank links", () => {
    const clean = sanitizeFragment('<a href="https://x.test" target="_blank">x</a>');
    expect(clean).toContain('rel="noopener noreferrer"');
  });

  it("keeps safe structure: classes, inline styles, target", () => {
    const ok = `<span class="slk-avatar" style="background:hsl(10 50% 50%)">AB</span><a href="https://ok.com" target="_blank">ok</a>`;
    const clean = sanitizeFragment(ok);
    expect(clean).toContain('class="slk-avatar"');
    expect(clean).toContain("background:hsl(10 50% 50%)");
    expect(clean).toContain('target="_blank"');
    expect(clean).toContain("https://ok.com");
  });
});

describe("message deep-link anchors", () => {
  // A real Slack ts; the id drops the dot so it is CSS-/URL-safe.
  const anchored: SlackMessage = { ...textMsg, ts: "1783480170.612229" };

  it("gives each message a stable id derived from its ts (dot removed)", () => {
    expect(transformMessageHtml(anchored, ctx)).toContain('id="msg-1783480170612229"');
  });

  it("keeps the anchor id through sanitizeFragment (preview/HTML path)", () => {
    const clean = sanitizeFragment(transformMessageHtml(anchored, ctx));
    expect(clean).toContain('id="msg-1783480170612229"');
  });

  it("highlights the :target message with a scheme-aware rule", () => {
    expect(dialogueCss).toContain(".slk-msg:target");
  });
});

describe("message permalink affordance (timestamps hidden)", () => {
  const hidden = makeCtx({ showTimestamps: false });
  const msg: SlackMessage = { ...textMsg, ts: "1783480170.612229" };

  it("renders an empty copy-link anchor pointing at the message's #msg- id", () => {
    expect(transformMessageHtml(msg, hidden)).toContain(
      '<a class="slk-msg__permalink" href="#msg-1783480170612229"' +
        ' aria-label="Copy link to this message"></a>',
    );
  });

  it("keeps the anchor (href + aria-label) through sanitizeFragment", () => {
    // DOMPurify must not strip the empty anchor or its attributes: the whole
    // affordance rides on the href (#msg-...) and the aria-label surviving into
    // the published, sanitized markup.
    const clean = sanitizeFragment(transformMessageHtml(msg, hidden));
    expect(clean).toContain('class="slk-msg__permalink"');
    expect(clean).toContain('href="#msg-1783480170612229"');
    expect(clean).toContain('aria-label="Copy link to this message"');
  });

  it("shows no permalink anchor when timestamps are visible", () => {
    expect(transformMessageHtml(msg, ctx)).not.toContain("slk-msg__permalink");
  });

  it("styles the affordance (hidden, hover/active reveal) in the shared stylesheet", () => {
    expect(dialogueCss).toContain(".slk-msg__permalink");
    // Hidden until the message is hovered or activated (touch tap sets .is-active).
    expect(dialogueCss).toContain(".slk-msg:hover .slk-msg__permalink");
    expect(dialogueCss).toContain(".slk-msg.is-active .slk-msg__permalink");
  });
});

describe("scheme presets", () => {
  const threads: ThreadedMessage[] = [{ parent: textMsg, replies: [] }];

  it("pins slk-dark into the markup only for the dark scheme", () => {
    expect(renderConversationFragment(threads, makeCtx({ scheme: "dark" }))).toContain(
      'class="slack-log slk-dark"',
    );
    expect(
      renderConversationFragment(threads, makeCtx({ scheme: "light" })),
    ).not.toContain("slk-dark");
    // Both custom schemes render the light base and theme via custom CSS.
    expect(
      renderConversationFragment(threads, makeCtx({ scheme: "custom-light" })),
    ).not.toContain("slk-dark");
    expect(
      renderConversationFragment(threads, makeCtx({ scheme: "custom-dark" })),
    ).not.toContain("slk-dark");
  });

});

describe("style tokens", () => {
  it("declares every token in the base stylesheet block", () => {
    for (const [name, value] of Object.entries(LIGHT_TOKENS)) {
      expect.soft(dialogueCss, name).toContain(`${name}: ${value};`);
    }
  });

  it("dark overrides only tokens that exist in the light set", () => {
    for (const name of Object.keys(DARK_TOKENS)) {
      expect.soft(Object.keys(LIGHT_TOKENS), name).toContain(name);
    }
  });

  it("the custom-scheme template lists every supported token", () => {
    const template = customCssTemplate(LIGHT_TOKENS);
    expect(template).toContain(".slack-log {");
    for (const [name, value] of Object.entries(LIGHT_TOKENS)) {
      expect.soft(template, name).toContain(`${name}: ${value};`);
    }
  });

  it("the light custom template seeds the light palette", () => {
    const template = customSchemeTemplate(false);
    for (const [name, value] of Object.entries(LIGHT_TOKENS)) {
      expect.soft(template, name).toContain(`${name}: ${value};`);
    }
    expect(template).not.toContain("--slk-id-dark");
  });

  it("the dark custom template seeds the dark palette and dark identity color", () => {
    const template = customSchemeTemplate(true);
    // Dark color overrides win…
    for (const [name, value] of Object.entries(DARK_TOKENS)) {
      expect.soft(template, name).toContain(`${name}: ${value};`);
    }
    // …while light-only tokens (typography/layout) carry over.
    expect(template).toContain(`--slk-font-size: ${LIGHT_TOKENS["--slk-font-size"]};`);
    // Identity colors flip to their dark variant on the light base.
    expect(template).toContain("--slk-id-active: var(--slk-id-dark);");
  });

  it("includes typography and layout tokens, not just colors", () => {
    for (const name of [
      "--slk-font",
      "--slk-mono",
      "--slk-font-size",
      "--slk-line-height",
      "--slk-radius",
      "--slk-avatar-size",
      "--slk-thread-indent",
      "--slk-image-max",
      "--slk-card-max",
    ]) {
      expect.soft(Object.keys(LIGHT_TOKENS), name).toContain(name);
      expect.soft(dialogueCss, `var(${name})`).toContain(`var(${name})`);
    }
  });
});

describe("formatTimestamp", () => {
  const ts = "1623351600.000400"; // 2021-06-10T19:00:00Z
  const opts = {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  } as const;

  it("follows the viewer's locale by default", () => {
    expect(formatTimestamp(ts)).toBe(
      new Date(1623351600000).toLocaleString(undefined, opts),
    );
  });

  it("honors an explicit locale", () => {
    expect(formatTimestamp(ts, "de-DE")).toBe(
      new Date(1623351600000).toLocaleString("de-DE", opts),
    );
    expect(formatTimestamp(ts, "de-DE")).not.toBe(formatTimestamp(ts, "en-US"));
  });

  it("returns the raw string when unparseable", () => {
    expect(formatTimestamp("nonsense")).toBe("nonsense");
  });
});
