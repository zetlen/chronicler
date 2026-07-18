import { describe, it, expect } from "vitest";
import {
  serializeBlockAttributes,
  voidBlock,
  containerBlock,
  parseChroniclerBlocks,
  hasChroniclerBlocks,
  mapMessageAttributes,
  collectMessageStrings,
  BLOCKS_VERSION,
} from "@/lib/wordpress/blockGrammar";

describe("serializeBlockAttributes", () => {
  it("unicode-escapes every comment-breaking sequence", () => {
    const out = serializeBlockAttributes({ html: '<img src="x?a=1&b=2"> -- done' });
    expect(out).not.toMatch(/--/);
    expect(out).not.toMatch(/[<>]/);
    expect(out).not.toMatch(/&/);
    expect(JSON.parse(out).html).toBe('<img src="x?a=1&b=2"> -- done');
  });

  it("round-trips values that end in a backslash", () => {
    // JSON.stringify emits `...\\"` (escaped backslash + closing quote) here;
    // the quote-escaping pass must not mistake the pair's second backslash for
    // an escaped quote and destroy the terminator. Reachable via a Slack
    // display name or custom CSS ending in "\".
    const attrs = { authorName: "raccoon\\", customCss: ".x{}\\" };
    const out = serializeBlockAttributes(attrs);
    expect(() => JSON.parse(out)).not.toThrow();
    expect(JSON.parse(out)).toEqual(attrs);
  });

  it("round-trips a backslash immediately before an embedded quote", () => {
    const attrs = { html: 'a\\"b', trailing: "c\\\\" };
    const out = serializeBlockAttributes(attrs);
    expect(JSON.parse(out)).toEqual(attrs);
  });

  it("survives the full grammar round-trip for a trailing-backslash attribute", () => {
    const block = voidBlock("message", { authorName: "path\\", bodyHtml: "<p>hi</p>" });
    const [parsed] = parseChroniclerBlocks(block);
    expect(parsed.attributes.authorName).toBe("path\\");
    expect(parsed.attributes.bodyHtml).toBe("<p>hi</p>");
  });
});

describe("parse and round-trip", () => {
  it("parses attribute-less container blocks", () => {
    const content = containerBlock("thread", {}, [voidBlock("message", { html: "<p>hi</p>" })]);
    const blocks = parseChroniclerBlocks(content);
    expect(blocks.map((b) => b.name)).toEqual(["thread", "message"]);
    expect(blocks[0].attributes).toEqual({});
  });

  it("detects chronicler blocks", () => {
    expect(hasChroniclerBlocks(voidBlock("message", { html: "x" }))).toBe(true);
    expect(hasChroniclerBlocks("<div>plain html</div>")).toBe(false);
  });
});

describe("mapMessageAttributes", () => {
  const v3 = voidBlock("message", {
    authorName: "D",
    bodyHtml: '<img src="/api/slack-image?url=a">',
    avatarHtml: '<img src="/api/slack-image?url=b">',
    images: [{ src: "/api/slack-image?url=c", alt: "x" }],
  });
  const v2 = voidBlock("message", { html: '<img src="/api/slack-image?url=d">' });

  it("visits every opaque html attribute and every images[].src", () => {
    const seen: string[] = [];
    mapMessageAttributes(v3 + "\n" + v2, {
      onHtml: (h) => { seen.push(h); return h; },
      onImageSrc: (s) => { seen.push(s); return s; },
    });
    expect(seen).toHaveLength(4); // bodyHtml, avatarHtml, images[0].src, html
  });

  it("rewrites and re-escapes grammar-safely", () => {
    const out = mapMessageAttributes(v3, {
      onHtml: (h) => h.replace("slack-image?url=", "media/"),
      onImageSrc: () => "https://wp.example/img.png",
    });
    const [block] = parseChroniclerBlocks(out);
    expect(block.attributes.bodyHtml).toContain("/api/media/a");
    expect((block.attributes.images as Array<{ src: string }>)[0].src).toBe(
      "https://wp.example/img.png",
    );
  });

  it("leaves non-message blocks untouched", () => {
    const content = containerBlock("transcript", { customCss: ".x { color: red; }" }, []);
    expect(
      mapMessageAttributes(content, { onHtml: () => "REPLACED", onImageSrc: () => "REPLACED" }),
    ).toBe(content);
  });
});

describe("collectMessageStrings", () => {
  it("separates html strings from raw srcs", () => {
    const v3 = voidBlock("message", {
      bodyHtml: "<em>x</em>",
      images: [{ src: "/api/slack-image?url=c", alt: "x" }],
    });
    const { htmlStrings, imageSrcs } = collectMessageStrings(v3);
    expect(htmlStrings).toEqual(["<em>x</em>"]);
    expect(imageSrcs).toEqual(["/api/slack-image?url=c"]);
  });
});

describe("blocks v4", () => {
  it("emitter is at schema version 4", () => {
    expect(BLOCKS_VERSION).toBe(4);
  });
  it("mapMessageAttributes leaves variants and realName untouched", () => {
    const src = voidBlock("message", {
      authorName: "Graz the Bold",
      realName: "jess",
      variants: ["ooc", "important"],
      bodyHtml: "hi",
    });
    const out = mapMessageAttributes(src, {
      onHtml: (h) => h.toUpperCase(), // proves realName is NOT treated as HTML
      onImageSrc: (s) => s,
    });
    const attrs = parseChroniclerBlocks(out)[0].attributes;
    expect(attrs.realName).toBe("jess");
    expect(attrs.variants).toEqual(["ooc", "important"]);
  });
});
