import { describe, it, expect, beforeAll, vi } from "vitest";
import { readFileSync, writeFileSync } from "node:fs";
import { containerBlock, voidBlock } from "@/lib/wordpress/blockGrammar";
import { pruneEmpty } from "@/lib/wordpress/renderBlocks";
import { dialogueCss } from "@/lib/transform/styles";
import { tagTokens, compileRule } from "@/lib/transform/rules";
import { escapeAttr } from "@/lib/transform/shared";

/**
 * Parity harness for editor-native generation (#102).
 *
 * wordpress-plugin/generate/session-blocks.js is the plugin's PURE ES5 port
 * of the session→blocks mapping the Node publish flow performs via
 * renderConversationBlocks + blockGrammar. This suite evaluates the plugin
 * script in a sandbox (the editorScript.test.ts recipe — no wp.* stubs
 * needed, the module is wp-free by contract) and pins:
 *
 * 1. Serialized parity: for a fixture Session whose messages are exactly the
 *    committed message-render parity attributes (the cross-language fixture
 *    source of truth), serializing the mapping's output through
 *    blockGrammar's own emitters is byte-identical to what today's publish
 *    produces for a flat conversation — wrapper attrs {scheme, density,
 *    baseCss, customCss?} in that order, message attrs pruned by the same
 *    rule. Provenance (sessionId/generatedAt) rides after those keys and is
 *    stripped for the comparison.
 * 2. The vendored base stylesheet (generate/base-css.js) equals dialogueCss
 *    exactly. Run UPDATE_FIXTURES=1 on this file to regenerate it.
 * 3. deriveTags reimplements the wp-tag rule semantics (lib/transform/
 *    rules.ts) over REST-shaped Session/Rule data.
 */

const LIB_PATH = "wordpress-plugin/generate/session-blocks.js";
const MIRROR_PATH = "wordpress-plugin/generate/mirror.js";
const BASE_CSS_PATH = "wordpress-plugin/generate/base-css.js";
const FIXTURE_PATH = "wordpress-plugin/tests/fixtures/message-render.json";

/** Evaluate a plugin global-attaching script against a fresh sandbox. */
function evaluate(
  path: string,
  sandbox: Record<string, unknown> = {},
): Record<string, unknown> {
  // The scripts attach to `self` when defined; provide the sandbox as self.
  new Function("self", readFileSync(path, "utf8"))(sandbox);
  return sandbox;
}

interface BlockNode {
  name: string;
  attributes: Record<string, unknown>;
  innerBlocks: BlockNode[];
}

interface SessionBlocksLib {
  pruneEmpty(a: Record<string, unknown>): Record<string, unknown>;
  transcriptAttributes(
    session: Record<string, unknown>,
    opts: Record<string, unknown>,
  ): Record<string, unknown>;
  sessionToBlocks(
    session: Record<string, unknown>,
    opts: Record<string, unknown>,
  ): BlockNode;
  tagTokens(names: unknown): string[];
  compileRule(rule: { pattern?: string; flags?: string }): RegExp | null;
  messageText(message: Record<string, unknown>): string;
  deriveTags(session: Record<string, unknown>, rules: unknown[]): string[];
  slackUrlFromSrc(src: unknown): string | null;
  collectImageUrls(messages: unknown[]): string[];
  rewriteImageUrls(
    messages: Array<Record<string, unknown>>,
    urlMap: Record<string, string>,
  ): Array<Record<string, unknown>>;
  imageCandidates(messages: unknown[]): Array<{
    src: string;
    slackUrl: string;
    alt: string;
    caption: string;
  }>;
}

interface MirrorLib {
  mirrorImage(slackUrl: string, alt?: string): Promise<{ id: number; url: string }>;
  parentAttachment(attachmentId: number, postId: number): Promise<unknown>;
  mirrorAll(
    urls: string[],
    options?: {
      concurrency?: number;
      onProgress?: (done: number, total: number) => void;
    },
  ): Promise<{ byUrl: Record<string, { id: number; url: string }>; failed: string[] }>;
}

let lib: SessionBlocksLib;
let baseCss: string;

beforeAll(() => {
  lib = evaluate(LIB_PATH).chroniclerSessionBlocks as SessionBlocksLib;
  baseCss = evaluate(BASE_CSS_PATH).chroniclerTranscriptBaseCss as string;
});

if (process.env.UPDATE_FIXTURES) {
  describe("base-css regeneration", () => {
    it("regenerates generate/base-css.js from lib/transform/styles.ts", () => {
      const banner = `// GENERATED FILE — do not edit by hand.
// Pinned copy of dialogueCss from lib/transform/styles.ts: the base transcript
// stylesheet today's publish flow bakes into every chronicler/transcript
// block's baseCss attribute. Editor-native generation (#102) must emit the
// byte-identical stylesheet, and this plugin is no-build ES5, so the string is
// vendored here. lib/wordpress/generateBlocks.test.ts enforces exact equality
// with the TypeScript source; regenerate after a styles.ts change with:
//   UPDATE_FIXTURES=1 npx vitest run lib/wordpress/generateBlocks.test.ts
(function (root) {
  root.chroniclerTranscriptBaseCss = `;
      writeFileSync(
        BASE_CSS_PATH,
        `${banner}${JSON.stringify(dialogueCss)};\n})(typeof self !== "undefined" ? self : this);\n`,
      );
      expect(dialogueCss.length).toBeGreaterThan(0);
    });
  });
}

/** The committed cross-language parity attributes — the fixture source. */
function fixtureMessages(): Array<Record<string, unknown>> {
  const fixtures = JSON.parse(readFileSync(FIXTURE_PATH, "utf8")) as {
    parity: Array<{ attributes: Record<string, unknown> }>;
  };
  return fixtures.parity.map((c) => c.attributes);
}

function fixtureSession(
  editorState: Record<string, unknown> = { scheme: "light" },
): Record<string, unknown> {
  return {
    id: 7,
    integration: "slack",
    channel: { id: "C0700", name: "dargon-kween" },
    start: "2026-07-10T19:00",
    end: "2026-07-11T02:00",
    rule_ids: [],
    editorState,
    messages: fixtureMessages(),
    messageCount: fixtureMessages().length,
  };
}

const GENERATED_AT = "2026-07-11T12:00:00.000Z";

/** Serialize a mapping descriptor tree through blockGrammar's own emitters. */
function serializeNode(node: BlockNode): string {
  const short = node.name.replace(/^chronicler\//, "") as
    | "transcript"
    | "thread"
    | "replies"
    | "message";
  if (node.innerBlocks.length === 0) {
    return voidBlock(short, node.attributes);
  }
  return containerBlock(short, node.attributes, node.innerBlocks.map(serializeNode));
}

describe("session-blocks vendored base stylesheet", () => {
  it("is byte-identical to dialogueCss", () => {
    expect(baseCss).toBe(dialogueCss);
  });
});

describe("session-blocks mapping parity with blockGrammar", () => {
  it("serializes to exactly today's publish output for the fixture session", () => {
    // What today's publish emits for this conversation, straight from the
    // real emitters: renderConversationBlocks' wrapper attributes (a flat
    // conversation — the Session stores flat, pre-filtered messages) around
    // one pruned message block per fixture case.
    const expected = containerBlock(
      "transcript",
      { scheme: "light", density: "comfortable", baseCss: dialogueCss },
      fixtureMessages().map((m) => voidBlock("message", pruneEmpty(m))),
    );

    const tree = lib.sessionToBlocks(fixtureSession(), {
      generatedAt: GENERATED_AT,
      baseCss,
    });
    expect(tree.name).toBe("chronicler/transcript");
    expect(tree.innerBlocks.map((b) => b.name)).toEqual(
      fixtureMessages().map(() => "chronicler/message"),
    );

    // Provenance is inert extra data appended after the publish-parity keys;
    // strip it and the serialization must be byte-identical.
    const { sessionId, generatedAt, ...parityAttributes } = tree.attributes;
    expect(sessionId).toBe(7);
    expect(generatedAt).toBe(GENERATED_AT);
    const actual = serializeNode({ ...tree, attributes: parityAttributes });
    expect(actual).toBe(expected);
  });

  it("prunes message attributes exactly like renderConversationBlocks", () => {
    for (const attributes of [
      ...fixtureMessages(),
      { html: "", bodyHtml: "x", images: [], variants: ["ooc"], anchorId: "" },
      { authorName: "A", images: [{ src: "s" }], extrasHtml: "" },
    ]) {
      expect(lib.pruneEmpty(attributes)).toEqual(pruneEmpty(attributes));
    }
  });

  it("carries trimmed customCss for custom schemes only (activeCustomCss parity)", () => {
    const custom = lib.transcriptAttributes(
      fixtureSession({ scheme: "custom-dark", customCss: "  .slack-log{color:red}  " }),
      { generatedAt: GENERATED_AT, baseCss },
    );
    expect(custom.scheme).toBe("custom-dark");
    expect(custom.customCss).toBe(".slack-log{color:red}");
    // Key order pins the serialized comment: publish keys first, provenance last.
    expect(Object.keys(custom)).toEqual([
      "scheme", "density", "baseCss", "customCss", "sessionId", "generatedAt",
    ]);

    const light = lib.transcriptAttributes(
      fixtureSession({ scheme: "light", customCss: ".slack-log{color:red}" }),
      { generatedAt: GENERATED_AT, baseCss },
    );
    expect(light).not.toHaveProperty("customCss");
    expect(Object.keys(light)).toEqual([
      "scheme", "density", "baseCss", "sessionId", "generatedAt",
    ]);
  });

  it("defaults unknown schemes/densities to today's publish values", () => {
    const attributes = lib.transcriptAttributes(
      fixtureSession({ scheme: "mauve", density: "cozy" }),
      { generatedAt: GENERATED_AT, baseCss },
    );
    expect(attributes.scheme).toBe("light");
    expect(attributes.density).toBe("comfortable");
    expect(attributes.baseCss).toBe(dialogueCss);
  });
});

describe("session-blocks tag derivation (wp-tag rule semantics)", () => {
  const rules = [
    { id: 11, pattern: "harm", flags: "i", mode: "wp-tag", className: "", tagNames: "combat, injuries" },
    { id: 12, pattern: "xp", flags: "i", mode: "wp-tag", className: "", tagNames: "combat, advancement" },
    { id: 13, pattern: ".*", flags: "i", mode: "hide", className: "", tagNames: "never" },
    { id: 14, pattern: "(", flags: "i", mode: "wp-tag", className: "", tagNames: "broken" },
    { id: 15, pattern: "anchor", flags: "i", mode: "wp-tag", className: "", tagNames: "  " },
    { id: 16, pattern: "nowhere-to-be-found", flags: "i", mode: "wp-tag", className: "", tagNames: "silent" },
  ];

  it("proposes matching rules' tags in attachment order, deduped", () => {
    const session = {
      ...fixtureSession(),
      rule_ids: [12, 11, 13, 14, 15, 16],
    };
    // Fixture bodies include "5 xp each" and "ouch" (harm-taken class body
    // is "ouch" — matched via the 'harm' pattern? no: pattern matches text,
    // and "ouch" doesn't contain "harm") — so pin against explicit bodies:
    const messages = [
      { authorName: "A", bodyHtml: "took <strong>harm</strong> today" },
      { authorName: "B", bodyHtml: "5 xp each" },
    ];
    expect(lib.deriveTags({ ...session, messages }, rules)).toEqual([
      "combat", "advancement", "injuries",
    ]);
  });

  it("ignores rules that are not attached to the session", () => {
    const messages = [{ authorName: "A", bodyHtml: "harm and xp" }];
    const session = { ...fixtureSession(), rule_ids: [11], messages };
    expect(lib.deriveTags(session, rules)).toEqual(["combat", "injuries"]);
  });

  it("is inert for unmatched, invalid, empty-tag and non-wp-tag rules", () => {
    const messages = [{ authorName: "A", bodyHtml: "anchor me" }];
    const session = { ...fixtureSession(), rule_ids: [13, 14, 15, 16], messages };
    expect(lib.deriveTags(session, rules)).toEqual([]);
  });

  it("matches against entity-decoded, tag-stripped body text", () => {
    const messages = [
      { authorName: "A", bodyHtml: '<span class="slk-quote">D &amp; D night<br></span>' },
    ];
    const session = {
      ...fixtureSession(),
      rule_ids: [21],
      messages,
    };
    const ampRule = [
      { id: 21, pattern: "D & D", flags: "", mode: "wp-tag", className: "", tagNames: "dnd" },
    ];
    expect(lib.deriveTags(session, ampRule)).toEqual(["dnd"]);
    expect(lib.messageText(messages[0])).toBe("D & D night\n");
  });

  it("tagTokens and compileRule match lib/transform/rules.ts", () => {
    for (const names of ["", " a ,b,, c ", "one", undefined]) {
      expect(lib.tagTokens(names)).toEqual(tagTokens(names));
    }
    for (const rule of [
      { pattern: "a+b", flags: "ig" },
      { pattern: "  ", flags: "i" },
      { pattern: "(", flags: "" },
      { pattern: "x", flags: "gyu" },
    ]) {
      const ours = lib.compileRule(rule);
      const theirs = compileRule(rule);
      expect(ours === null).toBe(theirs === null);
      if (ours && theirs) {
        expect(ours.source).toBe(theirs.source);
        expect(ours.flags).toBe(theirs.flags);
      }
    }
  });
});

describe("session-blocks featured-image candidates", () => {
  it("recovers Slack URLs from every stored src shape and dedupes", () => {
    const slack = "https://files.slack.com/files-pri/T1-F1/tide.png";
    const messages = [
      { images: [{ src: `/api/slack-image?url=${encodeURIComponent(slack)}`, alt: "tide", caption: "the tide" }] },
      { images: [{ src: `https://wp.example/wp-json/chronicler/v1/image?url=${encodeURIComponent(slack)}&alt=x` }] },
      { images: [{ src: "https://avatars.slack-edge.com/a.png", alt: "avatar" }] },
      { images: [{ src: "https://evil.example/a.png", alt: "nope" }] },
      { images: [{ src: "https://files.slack.com.evil.com/a.png", alt: "nope" }] },
      { images: [{ src: "https://wp.example/wp-content/uploads/2026/07/local.png" }] },
      { bodyHtml: "no images here" },
    ];
    expect(lib.imageCandidates(messages)).toEqual([
      {
        src: `/api/slack-image?url=${encodeURIComponent(slack)}`,
        slackUrl: slack,
        alt: "tide",
        caption: "the tide",
      },
      {
        src: "https://avatars.slack-edge.com/a.png",
        slackUrl: "https://avatars.slack-edge.com/a.png",
        alt: "avatar",
        caption: "",
      },
    ]);
  });

  it("rejects credential and lookalike hosts exactly like Media\\Mirror", () => {
    expect(lib.slackUrlFromSrc("https://files.slack.com@evil.com/a.png")).toBeNull();
    expect(lib.slackUrlFromSrc("http://files.slack.com/a.png")).toBeNull();
    expect(lib.slackUrlFromSrc("https://files.slack.com/a.png")).toBe(
      "https://files.slack.com/a.png",
    );
  });
});

/* ------------------------------------------------------------------ *
 * Publish-time image rewriting: stored messages carry capability-gated
 * chronicler/v1/image srcs; the Generate flow mirrors them and rewrites
 * every occurrence to local uploads URLs (the prepareContent.ts semantics
 * ported to the plugin's URL shape).
 * ------------------------------------------------------------------ */

const SLACK_FILE = "https://files.slack.com/files-pri/T1-F1/tide.png";
const SLACK_AVATAR = "https://avatars.slack-edge.com/2026-07/U1_72.png";
// The two persisted mirror-route forms (components/admin/imageUrls.ts):
// pretty permalinks chain the url arg with `?`, plain permalinks with `&`.
// Neither ever carries a nonce — that is render-time-only.
const PRETTY_SRC = `/wp-json/chronicler/v1/image?url=${encodeURIComponent(SLACK_FILE)}`;
const PLAIN_SRC = `/?rest_route=/chronicler/v1/image&url=${encodeURIComponent(SLACK_AVATAR)}`;

/** Messages shaped like the transform's output: HTML fields are attr-escaped. */
function proxiedMessages(): Array<Record<string, unknown>> {
  return [
    {
      rootClass: "slk-msg slk-msg--text",
      authorName: "Daisy",
      avatarHtml: `<div class="slk-msg__avatar"><span class="slk-avatar"><img class="slk-avatar__img" src="${escapeAttr(PLAIN_SRC)}" alt="D" loading="lazy"></span></div>`,
      bodyHtml: `the tide <figure class="slk-image"><img src="${escapeAttr(PRETTY_SRC)}" alt="tide" loading="lazy"></figure>`,
      images: [{ src: PRETTY_SRC, alt: "tide", caption: "The tide rises" }],
    },
    {
      rootClass: "slk-msg slk-msg--text",
      authorName: "GM",
      bodyHtml: `<img class="slk-card__image" src="https://elsewhere.example/pic.png" alt=""> and <img src="${escapeAttr(PRETTY_SRC)}" alt="again">`,
      // The Node app's legacy proxied form resolves to the same Slack URL.
      images: [{ src: `/api/slack-image?url=${encodeURIComponent(SLACK_FILE)}` }],
    },
  ];
}

describe("session-blocks collectImageUrls", () => {
  it("collects every distinct mirrorable URL across HTML fields and images[]", () => {
    // Deduped on the underlying Slack URL, in message order; the
    // non-allowlisted elsewhere.example src never qualifies.
    expect(lib.collectImageUrls(proxiedMessages())).toEqual([SLACK_AVATAR, SLACK_FILE]);
  });

  it("covers every opaque-HTML attribute in blockGrammar's vocabulary", () => {
    const src = (url: string) => `<img src="${escapeAttr(`/wp-json/chronicler/v1/image?url=${encodeURIComponent(url)}`)}">`;
    const message = {
      html: src("https://files.slack.com/h.png"),
      avatarHtml: src("https://avatars.slack-edge.com/a.png"),
      headHtml: src("https://a.slack-edge.com/head.png"),
      bodyHtml: src("https://files.slack.com/b.png"),
      extrasHtml: src("https://secure.gravatar.com/e.png"),
      reactionsHtml: src("https://a.slack-edge.com/r.png"),
    };
    expect(lib.collectImageUrls([message])).toEqual([
      "https://files.slack.com/h.png",
      "https://avatars.slack-edge.com/a.png",
      "https://a.slack-edge.com/head.png",
      "https://files.slack.com/b.png",
      "https://secure.gravatar.com/e.png",
      "https://a.slack-edge.com/r.png",
    ]);
  });

  it("skips srcs the mirror endpoint could not accept", () => {
    const messages = [{
      bodyHtml: `<img src="${escapeAttr(`/wp-json/chronicler/v1/image?url=${encodeURIComponent("https://emoji.slack-edge.com/T1/party/abc.gif")}`)}">`,
      images: [
        { src: "https://evil.example/a.png" },
        { src: "https://blog.example/wp-content/uploads/2026/07/local.png" },
      ],
    }];
    expect(lib.collectImageUrls(messages)).toEqual([]);
  });

  it("handles fixture messages (no mirrorable srcs) and junk input", () => {
    expect(lib.collectImageUrls(fixtureMessages())).toEqual([]);
    expect(lib.collectImageUrls([])).toEqual([]);
    expect(lib.collectImageUrls([null as unknown as Record<string, unknown>])).toEqual([]);
  });
});

describe("session-blocks rewriteImageUrls", () => {
  // A local URL carrying & proves the attribute re-escaping (replaceImageSrcs
  // parity: HTML gets &amp;, structured srcs stay raw).
  const LOCAL_FILE = "https://blog.example/wp-content/uploads/2026/07/tide.png?a=1&b=2";
  const LOCAL_AVATAR = "https://blog.example/wp-content/uploads/2026/07/U1_72.png";

  it("rewrites every occurrence, attr-escaped in HTML and raw in images[]", () => {
    const input = proxiedMessages();
    const out = lib.rewriteImageUrls(input, {
      [SLACK_FILE]: LOCAL_FILE,
      [SLACK_AVATAR]: LOCAL_AVATAR,
    });
    expect(out[0].avatarHtml).toContain(`src="${escapeAttr(LOCAL_AVATAR)}"`);
    expect(out[0].bodyHtml).toContain(`src="${escapeAttr(LOCAL_FILE)}"`);
    expect(out[1].bodyHtml).toContain(`src="${escapeAttr(LOCAL_FILE)}"`);
    expect((out[0].images as Array<{ src: string }>)[0].src).toBe(LOCAL_FILE);
    // The legacy /api/slack-image form resolves to the same Slack URL.
    expect((out[1].images as Array<{ src: string }>)[0].src).toBe(LOCAL_FILE);
    // Nothing gated or proxied survives; unrelated srcs do, verbatim.
    for (const message of out) {
      const flat = JSON.stringify(message);
      expect(flat).not.toMatch(/chronicler\/v1\/image|slack-image|rest_route/);
    }
    expect(out[1].bodyHtml).toContain('src="https://elsewhere.example/pic.png"');
    // Non-src fields and image alt/caption ride along untouched.
    expect(out[0].authorName).toBe("Daisy");
    expect((out[0].images as Array<{ caption: string }>)[0].caption).toBe("The tide rises");
  });

  it("leaves unmapped (failed-mirror) srcs verbatim and never mutates input", () => {
    const input = proxiedMessages();
    const out = lib.rewriteImageUrls(input, { [SLACK_FILE]: LOCAL_FILE });
    // SLACK_AVATAR was not mirrored: its src stays exactly as stored.
    expect(out[0].avatarHtml).toBe(input[0].avatarHtml);
    expect(out[0].bodyHtml).toContain(`src="${escapeAttr(LOCAL_FILE)}"`);
    // The input array and its message objects are untouched.
    expect((input[0].images as Array<{ src: string }>)[0].src).toBe(PRETTY_SRC);
    expect(input[0].bodyHtml).toContain(escapeAttr(PRETTY_SRC));
    // An empty map is the identity.
    expect(lib.rewriteImageUrls(input, {})).toEqual(input);
  });

  it("parity: rewritten messages serialize exactly like blockGrammar's emission", () => {
    const messages = [...fixtureMessages(), ...proxiedMessages()];
    const rewritten = lib.rewriteImageUrls(messages, {
      [SLACK_FILE]: LOCAL_FILE,
      [SLACK_AVATAR]: LOCAL_AVATAR,
    });
    // What today's publish emitters produce for the SAME rewritten inputs.
    const expected = containerBlock(
      "transcript",
      { scheme: "light", density: "comfortable", baseCss: dialogueCss },
      rewritten.map((m) => voidBlock("message", pruneEmpty(m))),
    );
    const session = { ...fixtureSession(), messages: rewritten };
    const tree = lib.sessionToBlocks(session, { generatedAt: GENERATED_AT, baseCss });
    const { sessionId, generatedAt, ...parityAttributes } = tree.attributes;
    expect(sessionId).toBe(7);
    expect(generatedAt).toBe(GENERATED_AT);
    const actual = serializeNode({ ...tree, attributes: parityAttributes });
    expect(actual).toBe(expected);
    expect(actual).not.toMatch(/chronicler\/v1\/image|slack-image|rest_route/);
    expect(actual).toContain("wp-content/uploads");
  });
});

/* ------------------------------------------------------------------ *
 * generate/mirror.js — the wp.apiFetch plumbing around the pure halves.
 * Evaluated with a sandboxed fake `wp`, the module's one seam.
 * ------------------------------------------------------------------ */

describe("generate/mirror.js", () => {
  type ApiFetch = (options: Record<string, unknown>) => Promise<unknown>;

  function mirrorWith(apiFetch: ApiFetch, warn = vi.fn()): {
    mirror: MirrorLib;
    warn: ReturnType<typeof vi.fn>;
  } {
    const sandbox = evaluate(MIRROR_PATH, {
      wp: { apiFetch },
      console: { warn },
    });
    return { mirror: sandbox.chroniclerMirror as MirrorLib, warn };
  }

  it("mirrorImage hits the json-format image route (alt only when given)", async () => {
    const calls: Array<Record<string, unknown>> = [];
    const { mirror } = mirrorWith(async (options) => {
      calls.push(options);
      return { id: 12, url: "https://blog.example/wp-content/uploads/a.png" };
    });
    await mirror.mirrorImage(SLACK_FILE, "the tide");
    await mirror.mirrorImage(SLACK_FILE);
    expect(calls[0].path).toBe(
      `/chronicler/v1/image?url=${encodeURIComponent(SLACK_FILE)}&alt=${encodeURIComponent("the tide")}&format=json`,
    );
    expect(calls[1].path).toBe(
      `/chronicler/v1/image?url=${encodeURIComponent(SLACK_FILE)}&format=json`,
    );
  });

  it("parentAttachment POSTs the Mirror consumer obligation", async () => {
    const calls: Array<Record<string, unknown>> = [];
    const { mirror } = mirrorWith(async (options) => {
      calls.push(options);
      return {};
    });
    await mirror.parentAttachment(12, 7);
    expect(calls[0]).toEqual({
      path: "/wp/v2/media/12",
      method: "POST",
      data: { post: 7 },
    });
  });

  it("mirrorAll maps every URL, reports progress, and caps concurrency", async () => {
    let active = 0;
    let peak = 0;
    let nextId = 0;
    const { mirror } = mirrorWith(async () => {
      active++;
      peak = Math.max(peak, active);
      await new Promise((resolve) => setTimeout(resolve, 1));
      active--;
      nextId++;
      return { id: nextId, url: `https://blog.example/u/${nextId}.png` };
    });
    const progress: Array<[number, number]> = [];
    const urls = ["u1", "u2", "u3", "u4", "u5", "u6"];
    const result = await mirror.mirrorAll(urls, {
      concurrency: 2,
      onProgress: (done, total) => progress.push([done, total]),
    });
    expect(Object.keys(result.byUrl).sort()).toEqual([...urls].sort());
    expect(result.failed).toEqual([]);
    expect(peak).toBe(2);
    expect(progress).toEqual([[1, 6], [2, 6], [3, 6], [4, 6], [5, 6], [6, 6]]);
  });

  it("a failed mirror warns and is skipped — generation is never blocked", async () => {
    const { mirror, warn } = mirrorWith(async (options) => {
      if (String(options.path).includes(encodeURIComponent("bad"))) {
        throw new Error("upstream 502");
      }
      return { id: 1, url: "https://blog.example/u/ok.png" };
    });
    const result = await mirror.mirrorAll(["https://files.slack.com/ok.png", "https://files.slack.com/bad.png"]);
    expect(Object.keys(result.byUrl)).toEqual(["https://files.slack.com/ok.png"]);
    expect(result.failed).toEqual(["https://files.slack.com/bad.png"]);
    expect(warn).toHaveBeenCalledTimes(1);
  });

  it("resolves an empty list without touching the network", async () => {
    const apiFetch = vi.fn();
    const { mirror } = mirrorWith(apiFetch as unknown as ApiFetch);
    const progress = vi.fn();
    const result = await mirror.mirrorAll([], { onProgress: progress });
    expect(result).toEqual({ byUrl: {}, failed: [] });
    expect(apiFetch).not.toHaveBeenCalled();
    expect(progress).not.toHaveBeenCalled();
  });
});
