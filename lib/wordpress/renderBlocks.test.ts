import { describe, it, expect } from "vitest";
import type { ThreadedMessage, SlackMessage } from "@/lib/transform/slackTypes";
import type { RenderContext } from "@/lib/transform/types";
import { createDirectory } from "@/lib/transform/directory";
import { renderConversationBlocks } from "@/lib/wordpress/renderBlocks";
import { parseChroniclerBlocks } from "@/lib/wordpress/blockGrammar";

function msg(ts: string, text: string, thread_ts?: string): SlackMessage {
  return { type: "message", ts, user: "U1", text, ...(thread_ts ? { thread_ts } : {}) };
}

function msgFrom(
  ts: string,
  user: string,
  text: string,
  extra?: Partial<SlackMessage>,
): SlackMessage {
  return { type: "message", ts, user, text, ...extra };
}

const solo = (ts: string, text: string): ThreadedMessage => ({
  parent: msg(ts, text),
  replies: [],
});

function ctx(overrides?: Partial<RenderContext>): RenderContext {
  return {
    directory: createDirectory({ users: { U1: "Ripley", U2: "Daisy" }, channels: {} }),
    imageMode: "proxy",
    showAvatars: false,
    showTimestamps: false,
    showReactions: false,
    density: "comfortable",
    scheme: "light",
    hiddenKinds: new Set(),
    customEmoji: {},
    ...overrides,
  };
}

const threadsWithOnePlainMessage: ThreadedMessage[] = [
  { parent: msgFrom("1", "U2", "hello there"), replies: [] },
];

const threadsWithSystemMessage: ThreadedMessage[] = [
  { parent: msgFrom("1", "U1", "", { subtype: "channel_join" }), replies: [] },
];

const threadsWithImageMessage: ThreadedMessage[] = [
  {
    parent: msgFrom("1", "U2", "check this out", {
      files: [
        {
          mimetype: "image/png",
          name: "shot.png",
          url_private: "https://files.slack.com/shot.png",
          thumb_720: "https://files.slack.com/shot_720.png",
        },
      ],
    }),
    replies: [],
  },
];

describe("renderConversationBlocks", () => {
  it("wraps messages in a transcript block of void message blocks", () => {
    const out = renderConversationBlocks([solo("1", "hello world")], ctx());
    expect(out).toMatch(/^<!-- wp:chronicler\/transcript {.*} -->/);
    expect(out).toMatch(/<!-- \/wp:chronicler\/transcript -->$/);
    const messages = parseChroniclerBlocks(out).filter((b) => b.name === "message");
    expect(messages).toHaveLength(1);
    expect(messages[0].attributes.bodyHtml).toContain("hello world");
    expect(messages[0].attributes.rootClass).toContain("slk-msg");
    expect(messages[0].attributes.authorName).toBe("Ripley");
  });

  it("carries a stable deep-link anchor id in each message's stored html", () => {
    const out = renderConversationBlocks([solo("1783480170.612229", "anchor me")], ctx());
    const [message] = parseChroniclerBlocks(out).filter((b) => b.name === "message");
    // The anchor must survive the block round-trip so a #msg-… fragment
    // resolves against the published post; in blocks v3 it rides the
    // structured anchorId attribute and render.php emits the id.
    expect(message.attributes.anchorId).toBe("msg-1783480170612229");
  });

  it("carries the copy-link permalink in stored headHtml when timestamps are hidden", () => {
    // ctx() hides timestamps, so the head content is the permalink anchor; it
    // must survive sanitizeFragment and the block round-trip for render.php to
    // emit it into the published post.
    const out = renderConversationBlocks([solo("1783480170.612229", "anchor me")], ctx());
    const [message] = parseChroniclerBlocks(out).filter((b) => b.name === "message");
    expect(String(message.attributes.headHtml)).toContain(
      '<a class="slk-msg__permalink" href="#msg-1783480170612229"',
    );
    expect(String(message.attributes.headHtml)).toContain(
      'aria-label="Copy link to this message"',
    );
  });

  it("escapes comment-breaking sequences in attribute JSON", () => {
    const out = renderConversationBlocks([solo("1", "a -- b <em> & c")], ctx());
    // Raw HTML in an attribute would contain `--`, `<`, `>` and `&`, any of
    // which corrupts the surrounding HTML comment. Gutenberg's grammar
    // requires them unicode-escaped.
    const body = out.replace(/<!-- \/?wp:[a-z/]+/g, "").replace(/-->/g, "");
    expect(body).not.toMatch(/--/);
    expect(body).not.toMatch(/[<>]/);
    // And they round-trip back through JSON.parse.
    const [message] = parseChroniclerBlocks(out).filter((b) => b.name === "message");
    expect(String(message.attributes.bodyHtml)).toContain("&lt;em&gt;");
  });

  it("nests thread replies in thread and replies blocks with note attributes", () => {
    const thread: ThreadedMessage = {
      parent: msg("1", "parent post"),
      replies: [msg("1.1", "first reply", "1")],
      omittedBefore: 2,
      parentBeforeWindow: true,
    };
    const out = renderConversationBlocks([thread], ctx());
    const blocks = parseChroniclerBlocks(out);
    const threadBlock = blocks.find((b) => b.name === "thread");
    const repliesBlock = blocks.find((b) => b.name === "replies");
    expect(threadBlock?.attributes.context).toBe(true);
    expect(String(threadBlock?.attributes.contextNote)).toMatch(/before the selected range/);
    expect(String(repliesBlock?.attributes.beforeNote)).toMatch(/2 earlier replies/);
    expect(blocks.filter((b) => b.name === "message")).toHaveLength(2);
  });

  it("skips hidden messages and promotes replies of hidden parents", () => {
    const thread: ThreadedMessage = {
      parent: msg("1", "hidden parent"),
      replies: [msg("1.1", "surviving reply", "1")],
    };
    const out = renderConversationBlocks([thread], {
      ...ctx(),
      ruleEffects: new Map([["1", { hidden: true, classes: [] }]]),
    });
    const blocks = parseChroniclerBlocks(out);
    expect(blocks.find((b) => b.name === "thread")).toBeUndefined();
    const messages = blocks.filter((b) => b.name === "message");
    expect(messages).toHaveLength(1);
    expect(messages[0].attributes.bodyHtml).toContain("surviving reply");
  });

  it("carries scheme, density, and custom CSS on the transcript block", () => {
    const out = renderConversationBlocks([solo("1", "hi")], ctx({ scheme: "dark", density: "compact" }), {
      customCss: ".slack-log { --slk-accent: red; }",
    });
    const transcript = parseChroniclerBlocks(out).find((b) => b.name === "transcript");
    expect(transcript?.attributes.scheme).toBe("dark");
    expect(transcript?.attributes.density).toBe("compact");
    expect(transcript?.attributes.customCss).toContain("--slk-accent: red");
  });

  it("bakes the base stylesheet into the transcript block", () => {
    const out = renderConversationBlocks([solo("1", "hi")], ctx());
    const transcript = parseChroniclerBlocks(out).find((b) => b.name === "transcript");
    expect(String(transcript?.attributes.baseCss)).toContain(".slack-log {");
  });

  it("applies addclass rule classes inside the message html", () => {
    const out = renderConversationBlocks([solo("1", "tag me")], {
      ...ctx(),
      ruleEffects: new Map([["1", { hidden: false, classes: ["highlight"] }]]),
    });
    const [message] = parseChroniclerBlocks(out).filter((b) => b.name === "message");
    expect(String(message.attributes.rootClass)).toMatch(/\bhighlight\b/);
  });

  it("emits structured v3 attributes for chat messages", () => {
    const content = renderConversationBlocks(threadsWithOnePlainMessage, ctx());
    const blocks = parseChroniclerBlocks(content).filter((b) => b.name === "message");
    expect(blocks).toHaveLength(1);
    const a = blocks[0].attributes;
    expect(a.authorName).toBe("Daisy");
    expect(typeof a.authorColor).toBe("string");
    expect(typeof a.authorColorDark).toBe("string");
    expect(a.rootClass).toBe("slk-msg slk-msg--text");
    expect(a.bodyHtml).toContain("hello");
    expect(a.html).toBeUndefined();
    expect(a).not.toHaveProperty("images"); // pruned when empty
  });

  it("still emits opaque html for system messages", () => {
    const content = renderConversationBlocks(threadsWithSystemMessage, ctx());
    const [block] = parseChroniclerBlocks(content).filter((b) => b.name === "message");
    expect(typeof block.attributes.html).toBe("string");
    expect(block.attributes.authorName).toBeUndefined();
  });

  it("emits structured images with proxied srcs", () => {
    const content = renderConversationBlocks(threadsWithImageMessage, ctx());
    const [block] = parseChroniclerBlocks(content).filter((b) => b.name === "message");
    const images = block.attributes.images as Array<{ src: string; alt: string }>;
    expect(images[0].src).toMatch(/^\/api\/slack-image\?url=/);
    expect(images[0].alt).toBeTruthy();
  });
});

describe("renderBlocks realName", () => {
  it("emits realName when the author was renamed", () => {
    const dir = createDirectory(
      { users: { U1: "Alice" }, channels: {} },
      { U1: { name: "Graz the Bold" } },
    );
    const thread: ThreadedMessage = { parent: msgFrom("1.1", "U1", "hi"), replies: [] };
    const out = renderConversationBlocks([thread], ctx({ directory: dir }));
    expect(out).toContain('"realName":"Alice"');
  });

  it("omits realName when the author was not renamed", () => {
    const dir = createDirectory({ users: { U2: "Bob" }, channels: {} });
    const thread: ThreadedMessage = { parent: msgFrom("1.1", "U2", "hi"), replies: [] };
    const out = renderConversationBlocks([thread], ctx({ directory: dir }));
    expect(out).not.toContain("realName");
  });
});
