import { describe, it, expect } from "vitest";
import { sanitizeCss, sanitizeFragment } from "@/lib/transform/sanitize";

describe("sanitizeCss", () => {
  it("passes ordinary CSS through untouched", () => {
    const css = ".slack-log { color: teal; }\n.slk-msg--ooc { display: none; }";
    expect(sanitizeCss(css)).toBe(css);
  });

  it("strips every '<', so a </style> breakout is unwritable", () => {
    const out = sanitizeCss("x{}</style><script>alert(1)</script>");
    expect(out).not.toContain("<");
    expect(out).toContain("x{}");
  });

  // The PHP transcript guard (chronicler_transcript_css, exercised by
  // wordpress-plugin/tests/sanitize.test.php) applies the same rule to the
  // same payload; both sides must keep agreeing byte for byte.
  it("mirrors the PHP guard on the shared payload", () => {
    expect(sanitizeCss("a</style>b")).toBe("a/style>b");
  });
});

describe("sanitizeFragment", () => {
  it("drops script elements from a message fragment", () => {
    expect(
      sanitizeFragment('<strong>hi</strong><script>steal()</script>'),
    ).toBe("<strong>hi</strong>");
  });
});
