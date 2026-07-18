import { describe, expect, it } from "vitest";
import { renderMarkdown } from "./markdown-lite";

describe("renderMarkdown", () => {
  it("escapes HTML so schema docs can never inject markup", () => {
    expect(renderMarkdown('use <script>alert("x")</script>')).not.toContain(
      "<script>",
    );
    expect(renderMarkdown("a < b & c > d")).toContain("a &lt; b &amp; c &gt; d");
  });

  it("renders backtick spans as <code>", () => {
    expect(renderMarkdown("bounded by `min`/`max`")).toBe(
      "bounded by <code>min</code>/<code>max</code>",
    );
  });

  it("escapes HTML inside code spans", () => {
    expect(renderMarkdown("compare with `a < b`")).toBe(
      "compare with <code>a &lt; b</code>",
    );
  });

  it("renders **bold** and *emphasis*", () => {
    expect(renderMarkdown("**Harm** is *bad*")).toBe(
      "<strong>Harm</strong> is <em>bad</em>",
    );
  });

  it("keeps plain text unchanged", () => {
    expect(renderMarkdown("Shown beside the portrait.")).toBe(
      "Shown beside the portrait.",
    );
  });

  it("block mode wraps paragraphs; inline mode does not", () => {
    expect(renderMarkdown("one\n\ntwo", false)).toBe("<p>one</p><p>two</p>");
    expect(renderMarkdown("one\n\ntwo")).toBe("one two");
  });

  it("block mode turns single newlines into line breaks", () => {
    expect(renderMarkdown("one\ntwo", false)).toBe("<p>one<br>two</p>");
  });

  it("tolerates unbalanced markers as literal text", () => {
    expect(renderMarkdown("a ** b")).toBe("a ** b");
    expect(renderMarkdown("a ` b")).toBe("a ` b");
  });
});
