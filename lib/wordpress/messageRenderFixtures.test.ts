import { describe, it, expect } from "vitest";
import { readFileSync, writeFileSync } from "node:fs";
import { composeBubble } from "@/lib/transform/shared";
import type { BubbleParts } from "@/lib/transform/types";

const FIXTURE_PATH = "wordpress-plugin/tests/fixtures/message-render.json";

/** Parity-case attributes are exactly the serialized BubbleParts fields. */
function attributesToParts(a: Record<string, unknown>): BubbleParts {
  return {
    kind: "bubble",
    rootClass: (a.rootClass as string) ?? "slk-msg slk-msg--text",
    anchorId: (a.anchorId as string) ?? "",
    authorName: (a.authorName as string) ?? "",
    authorColor: (a.authorColor as string) ?? "",
    authorColorDark: (a.authorColorDark as string) ?? "",
    avatarHtml: (a.avatarHtml as string) ?? "",
    headHtml: (a.headHtml as string) ?? "",
    bodyHtml: (a.bodyHtml as string) ?? "",
    images: (a.images as BubbleParts["images"]) ?? [],
    extrasHtml: (a.extrasHtml as string) ?? "",
    reactionsHtml: (a.reactionsHtml as string) ?? "",
    variants: (a.variants as string[]) ?? [],
    realName: a.realName as string | undefined,
  };
}

// The canonical parity inputs. Add cases here; run UPDATE_FIXTURES=1 to bake.
const PARITY_CASES: Array<{ name: string; attributes: Record<string, unknown> }> = [
  {
    name: "full bubble",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      authorName: 'Daisy "D" <3 & co',
      authorColor: "#4674b8",
      authorColorDark: "#5e86c2",
      avatarHtml: '<div class="slk-msg__avatar"><span class="slk-avatar">DC</span></div>',
      headHtml: '<span class="slk-msg__time">Jun 10, 2021, 3:00 PM</span>',
      bodyHtml: '<strong>hi</strong> <span class="slk-quote">q<br></span>',
      images: [{ src: "https://wp.example/a.png", alt: "a & b", caption: "a & b" }],
      extrasHtml: '<div class="slk-files">F</div>',
      reactionsHtml: '<div class="slk-reactions">R</div>',
    },
  },
  {
    name: "minimal bubble (pruned attributes)",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      authorName: "A",
      authorColor: "#1a865b",
      authorColorDark: "#1f9d6b",
    },
  },
  {
    name: "rule classes on root",
    attributes: {
      rootClass: "slk-msg slk-msg--text harm-taken",
      authorName: "A",
      authorColor: "#c0392b",
      authorColorDark: "#d85d50",
      bodyHtml: "ouch",
    },
  },
  {
    name: "deep-link anchor id",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      anchorId: "msg-1783480170612229",
      authorName: "A",
      authorColor: "#4674b8",
      authorColorDark: "#5e86c2",
      bodyHtml: "anchor me",
    },
  },
  {
    // Timestamps hidden: the head carries the copy-link anchor (not a time), and
    // the id still rides the root div. Proves the PHP emitter reproduces both.
    name: "permalink affordance (timestamps hidden)",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      anchorId: "msg-1783480170612229",
      authorName: "A",
      authorColor: "#4674b8",
      authorColorDark: "#5e86c2",
      headHtml:
        '<a class="slk-msg__permalink" href="#msg-1783480170612229" aria-label="Copy link to this message"></a>',
      bodyHtml: "anchor me",
    },
  },
  {
    name: "variant: important",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      authorName: "A",
      authorColor: "#4674b8",
      authorColorDark: "#5e86c2",
      variants: ["important"],
      bodyHtml: "look here",
    },
  },
  {
    name: "variant: ooc swaps author to realName",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      authorName: "Graz the Bold",
      realName: "jess",
      authorColor: "#4674b8",
      authorColorDark: "#5e86c2",
      variants: ["ooc"],
      bodyHtml: "5 xp each",
    },
  },
  {
    name: "variant: ooc without realName keeps the character name",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      authorName: "Graz the Bold",
      authorColor: "#4674b8",
      authorColorDark: "#5e86c2",
      variants: ["ooc"],
      bodyHtml: "still in character",
    },
  },
  {
    name: "variants: ooc + important, unknown ignored",
    attributes: {
      rootClass: "slk-msg slk-msg--text",
      authorName: "Graz the Bold",
      realName: "jess",
      authorColor: "#4674b8",
      authorColorDark: "#5e86c2",
      variants: ["important", "bogus", "ooc"],
      bodyHtml: "wrap-up",
    },
  },
];

describe("message-render parity fixtures", () => {
  const fixtures = JSON.parse(readFileSync(FIXTURE_PATH, "utf8")) as {
    parity: Array<{ name: string; attributes: Record<string, unknown>; expected: string }>;
    defensive?: unknown[];
  };

  if (process.env.UPDATE_FIXTURES) {
    it("regenerates parity fixtures", () => {
      const parity = PARITY_CASES.map((c) => ({
        ...c,
        expected: composeBubble(attributesToParts(c.attributes)),
      }));
      writeFileSync(
        FIXTURE_PATH,
        JSON.stringify({ parity, defensive: fixtures.defensive ?? [] }, null, 2) + "\n",
      );
      expect(parity.length).toBe(PARITY_CASES.length);
    });
    return;
  }

  it("covers every parity case", () => {
    expect(fixtures.parity.map((f) => f.name)).toEqual(PARITY_CASES.map((c) => c.name));
  });

  for (const c of PARITY_CASES) {
    it(`composeBubble matches committed fixture: ${c.name}`, () => {
      const fixture = fixtures.parity.find((f) => f.name === c.name);
      expect(composeBubble(attributesToParts(c.attributes))).toBe(fixture?.expected);
    });
  }
});
