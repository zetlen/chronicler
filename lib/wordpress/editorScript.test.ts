import { describe, it, expect, beforeAll } from "vitest";
import { readFileSync } from "node:fs";
import { RICH_TEXT_FORMAT_MARKUP, isRichTextSafeHtml } from "@/lib/transform/richTextSafe";
import { PALETTE, darkVariantOf } from "@/lib/transform/color";
import { variantClasses as tsVariantClasses, MESSAGE_VARIANTS as TS_VARIANTS } from "@/lib/transform/variants";

/**
 * editor.js is no-build ES5 against wp.* globals. Evaluate it once with a
 * stub wp that records registrations, then check the pieces that must stay
 * in lockstep with the TypeScript side. editor.js itself attaches its
 * test-only internals to wp.chroniclerEditorInternals.
 */
type Registration = { name: string; settings: Record<string, unknown> };
const registeredFormats: Registration[] = [];
const registeredBlocks: Registration[] = [];
// Populated by evaluating editor.js; typed loosely because it's a stub.
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- stub wp global, shape unknown until editor.js populates it
let wp: Record<string, any>;

beforeAll(() => {
  const stubComponent = () => null;
  wp = {
    element: { createElement: () => null, Fragment: stubComponent, RawHTML: stubComponent },
    blockEditor: {
      InnerBlocks: Object.assign(stubComponent, { Content: stubComponent }),
      InspectorControls: stubComponent,
      useBlockProps: (p: unknown) => p,
      RichText: stubComponent,
      MediaUpload: stubComponent,
      MediaUploadCheck: stubComponent,
    },
    components: {
      PanelBody: stubComponent,
      SelectControl: stubComponent,
      TextareaControl: stubComponent,
      TextControl: stubComponent,
      Button: stubComponent,
      ColorPalette: stubComponent,
      ToggleControl: stubComponent,
    },
    blocks: {
      registerBlockType: (name: string, settings: Record<string, unknown>) =>
        registeredBlocks.push({ name, settings }),
    },
    richText: {
      registerFormatType: (name: string, settings: Record<string, unknown>) =>
        registeredFormats.push({ name, settings }),
    },
  };
  const src = readFileSync("wordpress-plugin/editor.js", "utf8");
  new Function("window", src)({ wp });
});

describe("editor.js contract", () => {
  it("registers exactly the RichText format inventory, in order", () => {
    const markup = registeredFormats.map((f) => ({
      tag: f.settings.tagName,
      className: f.settings.className,
    }));
    expect(markup).toEqual(
      RICH_TEXT_FORMAT_MARKUP.map((m) => ({ tag: m.tag, className: m.className })),
    );
  });

  it("registers every block at Block API v3 (#164)", () => {
    expect(registeredBlocks).toHaveLength(4);
    for (const block of registeredBlocks) {
      expect(block.settings.apiVersion, block.name).toBe(3);
    }
  });

  it("registers the message block with a v3 edit surface", () => {
    const message = registeredBlocks.find((b) => b.name === "chronicler/message");
    expect(message).toBeDefined();
    const attrs = message!.settings.attributes as Record<string, unknown>;
    expect(attrs.authorName).toBeDefined();
    expect(attrs.images).toBeDefined();
    expect(attrs.html).toBeDefined(); // v2 fallback stays registered
    expect(attrs.anchorId).toBeDefined(); // deep-link anchor must survive editor re-saves
  });

  it("registers chronicler/emoji-image as an object format with a src/alt/loading attributes map", () => {
    const emojiImage = registeredFormats.find((f) => f.name === "chronicler/emoji-image");
    expect(emojiImage).toBeDefined();
    expect(emojiImage!.settings.object).toBe(true);
    expect(emojiImage!.settings.attributes).toEqual({
      src: "src",
      alt: "alt",
      loading: "loading",
    });
  });
});

describe("editor.js internals parity", () => {
  it("darkVariantOf matches lib/transform/color.ts over the palette and pass-through", () => {
    const port = wp.chroniclerEditorInternals.darkVariantOf as (c: string) => string;
    for (const c of [...PALETTE, "#123456", "not-a-color"]) {
      expect(port(c)).toBe(darkVariantOf(c));
    }
  });

  it("isRichTextSafeHtml matches the TS implementation over a corpus", () => {
    const port = wp.chroniclerEditorInternals.isRichTextSafeHtml as (h: string) => boolean;
    for (const html of [
      "", "plain", "<strong>b</strong>", '<span class="slk-pre">x<br>y</span>',
      "<pre>x</pre>", '<figure class="slk-image"></figure>', "<div>x</div>",
      '<img class="slk-emoji" src="u">',
      // Object.prototype member names must not read as "safe tags": a plain-
      // object lookup returns Object.prototype.constructor (truthy) for these.
      "<constructor>x</constructor>", "<valueOf>", "<hasOwnProperty>",
    ]) {
      expect(port(html)).toBe(isRichTextSafeHtml(html));
    }
  });

  it("decodeRichTextValue decodes the five entities RichText's serializer produces, &amp; last", () => {
    const decode = wp.chroniclerEditorInternals.decodeRichTextValue as (v: string) => string;
    expect(decode("Daisy &amp; co")).toBe("Daisy & co");
    expect(decode("D &lt;3 &quot;q&quot; &#39;s&#39;")).toBe("D <3 \"q\" 's'");
    // Ordering trap: &amp; must decode LAST, or "&amp;lt;" would come back
    // as "<" instead of the literal text "&lt;".
    expect(decode("&amp;lt;")).toBe("&lt;");
  });
});

describe("editor.js variant parity", () => {
  it("registers variants and realName attributes on the message block", () => {
    const message = registeredBlocks.find((b) => b.name === "chronicler/message");
    const attrs = message!.settings.attributes as Record<string, unknown>;
    expect(attrs.variants).toBeDefined();
    expect(attrs.realName).toBeDefined();
  });
  it("exposes the same variant vocabulary as lib/transform/variants.ts", () => {
    expect(wp.chroniclerEditorInternals.MESSAGE_VARIANTS).toEqual([...TS_VARIANTS]);
  });
  it("variantClasses matches the TS implementation", () => {
    const port = wp.chroniclerEditorInternals.variantClasses as (v: string[]) => string[];
    for (const input of [[], ["ooc"], ["important", "ooc"], ["ooc", "bogus"]]) {
      expect(port(input)).toEqual(tsVariantClasses(input));
    }
  });
});

describe("editor.js OOC author preview", () => {
  // authorView mirrors the front-end author swap (message-render.php /
  // composeBubble): OOC reveals the realName, and because realName is not an
  // editable attribute, the field goes read-only when it does.
  const view = () =>
    wp.chroniclerEditorInternals.authorView as (a: {
      authorName?: string;
      realName?: string;
      variants?: string[];
    }) => { name: string; editable: boolean };

  it("reveals the realName read-only when OOC is active", () => {
    expect(view()({ authorName: "WOLFGANG", realName: "jess", variants: ["ooc"] })).toEqual({
      name: "jess",
      editable: false,
    });
  });

  it("keeps the editable character name when not OOC", () => {
    expect(view()({ authorName: "WOLFGANG", realName: "jess", variants: [] })).toEqual({
      name: "WOLFGANG",
      editable: true,
    });
  });

  it("keeps the editable character name when OOC but there is no realName to reveal", () => {
    expect(view()({ authorName: "WOLFGANG", realName: "", variants: ["ooc"] })).toEqual({
      name: "WOLFGANG",
      editable: true,
    });
  });
});
