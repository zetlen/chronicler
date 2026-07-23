import { describe, it, expect } from "vitest";
import {
  authorizePreviewImages,
  sessionImageProxyBase,
} from "@/components/admin/imageUrls";
import { escapeAttr } from "@/lib/transform/shared";

const PRETTY = { apiBase: "https://blog.example/wp-json/chronicler/v1/", nonce: "n0nce" };
const PLAIN = { apiBase: "https://blog.example/?rest_route=/chronicler/v1", nonce: "n0nce" };

describe("sessionImageProxyBase", () => {
  it("derives a root-relative base from a pretty-permalink rest_url", () => {
    expect(sessionImageProxyBase(PRETTY)).toBe("/wp-json/chronicler/v1/image?url=");
  });

  it("handles the plain-permalink ?rest_route= form", () => {
    expect(sessionImageProxyBase(PLAIN)).toBe("/?rest_route=/chronicler/v1/image&url=");
  });

  it("handles a root-relative apiBase (no origin)", () => {
    expect(sessionImageProxyBase({ apiBase: "/wp-json/chronicler/v1" })).toBe(
      "/wp-json/chronicler/v1/image?url=",
    );
  });
});

describe("authorizePreviewImages", () => {
  it("injects the rest nonce into persisted mirror URLs (attr-escaped html)", () => {
    const persisted = sessionImageProxyBase(PRETTY); // ends in ?url=
    const slack = encodeURIComponent("https://files.slack.com/a.png");
    const html = `<img src="${escapeAttr(`${persisted}${slack}`)}" alt="a">`;
    const authorized = authorizePreviewImages(html, PRETTY);
    expect(authorized).toContain(
      escapeAttr(`/wp-json/chronicler/v1/image?_wpnonce=n0nce&url=${slack}`),
    );
    // The persisted (nonce-free) form is fully rewritten.
    expect(authorized).not.toContain(escapeAttr(`${persisted}${slack}`));
  });

  it("chains with & on the plain-permalink form", () => {
    const persisted = sessionImageProxyBase(PLAIN); // ends in &url=
    const html = `<img src="${escapeAttr(`${persisted}x`)}">`;
    expect(authorizePreviewImages(html, PLAIN)).toContain(
      escapeAttr("/?rest_route=/chronicler/v1/image&_wpnonce=n0nce&url=x"),
    );
  });

  it("leaves unrelated markup alone", () => {
    const html = `<img src="https://elsewhere.example/pic.png">`;
    expect(authorizePreviewImages(html, PRETTY)).toBe(html);
  });
});
