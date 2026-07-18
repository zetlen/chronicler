/**
 * This suite runs in the registered-format regime: the beforeAll below
 * registers the same five chronicler/* RichText object/inline formats that
 * wordpress-plugin/editor.js registers on the real editor (same names,
 * tagName, className, object flag, and — for chronicler/emoji-image — the
 * same attributes map). create()/toHTMLString() behave differently once
 * formats are registered (an unregistered object format silently drops its
 * element), so asserting against bare, unregistered @wordpress/rich-text
 * would pass while the real editor still mangled custom emoji. Every corpus
 * case, including customEmoji, must survive create()/toHTMLString()
 * byte-for-byte under this regime.
 */
import { describe, it, expect, beforeAll } from "vitest";
// @wordpress/rich-text create() needs a DOM; vitest runs jsdom here.
import { create, toHTMLString, registerFormatType } from "@wordpress/rich-text";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import { isRichTextSafeHtml } from "@/lib/transform/richTextSafe";
import type { RenderContext } from "@/lib/transform/types";

// Mirrors wordpress-plugin/editor.js's wp.richText.registerFormatType calls
// exactly (names, tagName, className, object flag, attributes map) so this
// suite exercises the same RichText behavior the real editor does.
function noEdit() {
  return null;
}

// The published @wordpress/rich-text WPFormat type declares `interactive`
// and `object` as required and has no `attributes` field at all — stale
// relative to the runtime, which treats both as optional and reads
// `attributes` for object formats (verified empirically: it's how
// chronicler/emoji-image's src/alt/loading survive create()/toHTMLString()).
// This local type documents the shape editor.js actually registers with.
type EditorFormatSettings = {
  title: string;
  tagName: string;
  className: string;
  object?: boolean;
  attributes?: Record<string, string>;
  edit: () => null;
};
function register(name: string, settings: EditorFormatSettings) {
  registerFormatType(name, settings as unknown as Parameters<typeof registerFormatType>[1]);
}

beforeAll(() => {
  register("chronicler/mention", {
    title: "Mention",
    tagName: "span",
    className: "s-mention",
    edit: noEdit,
  });
  register("chronicler/emoji-name", {
    title: "Emoji",
    tagName: "span",
    className: "s-emoji",
    edit: noEdit,
  });
  register("chronicler/emoji-image", {
    title: "Custom emoji",
    tagName: "img",
    className: "slk-emoji",
    object: true,
    attributes: { src: "src", alt: "alt", loading: "loading" },
    edit: noEdit,
  });
  register("chronicler/quote", {
    title: "Quote",
    tagName: "span",
    className: "slk-quote",
    edit: noEdit,
  });
  register("chronicler/pre", {
    title: "Code block",
    tagName: "span",
    className: "slk-pre",
    edit: noEdit,
  });
});

const ctx = {
  directory: {
    userName: (id: string) => (id === "U1" ? "Daisy" : id),
    channelName: (_id: string) => "general",
    userColor: () => undefined,
    userAvatar: () => undefined,
  },
  customEmoji: { partyparrot: "https://emoji.example/partyparrot.gif" },
  showAvatars: true,
  showTimestamps: true,
  showReactions: true,
  imageMode: "proxy",
  scheme: "light",
  density: "comfortable",
} as unknown as RenderContext;

// Every construct mrkdwn can emit, per the spec's inventory.
const CORPUS: Record<string, string> = {
  emphasis: "*bold* _italic_ ~strike~",
  code: "before `x = 1` after",
  codeBlock: "```line1\nline2```",
  quote: "> quoted line\nafter",
  link: "<https://example.com|a label>",
  mention: "<@U1> hello",
  channel: "<#C1|general>",
  unicodeEmoji: "party :smile: time",
  customEmoji: "party :partyparrot: time",
  multiline: "first\nsecond",
};

describe("mrkdwn → RichText round-trip contract", () => {
  for (const [name, mrkdwn] of Object.entries(CORPUS)) {
    it(`emits RichText-safe HTML for ${name}`, () => {
      expect(isRichTextSafeHtml(mrkdwnToHtml(mrkdwn, ctx))).toBe(true);
    });

    it(`survives create()/toHTMLString() byte-for-byte for ${name}`, () => {
      const html = mrkdwnToHtml(mrkdwn, ctx);
      expect(toHTMLString({ value: create({ html }) })).toBe(html);
    });
  }
});
