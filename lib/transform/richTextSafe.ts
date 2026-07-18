/**
 * Normalizes mrkdwn-rendered HTML to the inline inventory that WordPress
 * RichText can round-trip without loss (see the blocks-v3 spec). Gutenberg's
 * rich-text value model is inline-only: block-level children get flattened
 * or dropped on edit. Blockquotes and code blocks are therefore re-expressed
 * as styled inline spans; everything else mrkdwn emits is already inline.
 */

export const RICH_TEXT_SAFE_TAGS: ReadonlySet<string> = new Set([
  "strong", "em", "s", "del", "a", "code", "br", "span", "img",
]);

/**
 * The custom rich-text formats the editor must register, ordered. Task-coupled
 * to editor.js via lib/wordpress/editorScript.test.ts.
 */
export const RICH_TEXT_FORMAT_MARKUP: ReadonlyArray<{ tag: string; className: string }> = [
  { tag: "span", className: "s-mention" },
  { tag: "span", className: "s-emoji" },
  { tag: "img", className: "slk-emoji" },
  { tag: "span", className: "slk-quote" },
  { tag: "span", className: "slk-pre" },
];

/** Re-express block-level mrkdwn constructs as styled inline spans. */
export function toRichTextSafeHtml(html: string): string {
  return html
    .replace(
      /<pre>(?:<code>)?([\s\S]*?)(?:<\/code>)?<\/pre>/g,
      (_, inner: string) => `<span class="slk-pre">${inner.replace(/\n/g, "<br>")}</span>`,
    )
    .replace(/<blockquote>/g, '<span class="slk-quote">')
    .replace(/<\/blockquote>/g, "</span>");
}

const TAG_RE = /<\/?([a-zA-Z][a-zA-Z0-9]*)/g;

/** True when every tag in the fragment belongs to the inline inventory. */
export function isRichTextSafeHtml(html: string): boolean {
  for (const match of html.matchAll(TAG_RE)) {
    if (!RICH_TEXT_SAFE_TAGS.has(match[1].toLowerCase())) return false;
  }
  return true;
}
