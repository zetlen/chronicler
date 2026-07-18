import { describe, it, expect } from "vitest";
import {
  toRichTextSafeHtml,
  isRichTextSafeHtml,
  RICH_TEXT_SAFE_TAGS,
} from "@/lib/transform/richTextSafe";

describe("toRichTextSafeHtml", () => {
  it("converts blockquote to an inline slk-quote span", () => {
    expect(toRichTextSafeHtml("<blockquote>quoted<br></blockquote>after")).toBe(
      '<span class="slk-quote">quoted<br></span>after',
    );
  });

  it("converts pre>code to an inline slk-pre span with <br> line breaks", () => {
    expect(toRichTextSafeHtml("<pre><code>line1\nline2</code></pre>")).toBe(
      '<span class="slk-pre">line1<br>line2</span>',
    );
  });

  it("converts a bare pre without inner code", () => {
    expect(toRichTextSafeHtml("<pre>a\nb</pre>")).toBe(
      '<span class="slk-pre">a<br>b</span>',
    );
  });

  it("leaves inline-safe markup untouched", () => {
    const html =
      '<strong>b</strong> <em>i</em> <del>s</del> <code>c</code> ' +
      '<a href="https://x.com" target="_blank">l</a><br>' +
      '<span class="s-emoji">😄</span><span class="s-mention s-user">@u</span>' +
      '<img class="slk-emoji" src="https://emoji.example/x.png" alt=":x:" loading="lazy">';
    expect(toRichTextSafeHtml(html)).toBe(html);
  });
});

describe("isRichTextSafeHtml", () => {
  it("accepts the full inline inventory", () => {
    expect(
      isRichTextSafeHtml(
        '<strong>b</strong><span class="slk-pre">x<br>y</span><img class="slk-emoji" src="u">',
      ),
    ).toBe(true);
  });

  it("rejects block-level constructs (bot Block Kit figures, dividers, raw pre)", () => {
    expect(isRichTextSafeHtml('<figure class="slk-image"><img src="u"></figure>')).toBe(false);
    expect(isRichTextSafeHtml('<hr class="slk-divider">')).toBe(false);
    expect(isRichTextSafeHtml("<pre>x</pre>")).toBe(false);
    expect(isRichTextSafeHtml("<div>x</div>")).toBe(false);
  });

  it("accepts empty and plain text", () => {
    expect(isRichTextSafeHtml("")).toBe(true);
    expect(isRichTextSafeHtml("hello")).toBe(true);
  });
});

describe("RICH_TEXT_SAFE_TAGS", () => {
  it("is exactly the inline inventory", () => {
    expect([...RICH_TEXT_SAFE_TAGS].sort()).toEqual(
      ["a", "br", "code", "del", "em", "img", "s", "span", "strong"].sort(),
    );
  });
});
