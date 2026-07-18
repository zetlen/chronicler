// Drop-in replacement for codemirror-json-schema's utils/markdown module,
// swapped in at build time (scripts/build-admin-bundle.mjs). The real module
// pulls markdown-it + shiki (~415 kB minified) to render hover/completion
// docs; our schema descriptions only ever use backticks, bold, and emphasis,
// so a hand-sized renderer keeps the Game System bundle honest. The build
// asserts shiki/markdown-it stay out of the module graph.

const escapeHtml = (text: string): string =>
  text
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");

/**
 * Inline markdown subset → HTML string: `code`, **bold**, *em*. Everything is
 * HTML-escaped first, so docs (or a hostile schema) can't inject markup.
 * Unbalanced markers stay literal — the replacements below only fire on
 * complete pairs.
 */
const renderInline = (markdown: string): string =>
  escapeHtml(markdown)
    .replace(/`([^`\n]+)`/g, "<code>$1</code>")
    .replace(/\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>")
    .replace(/\*([^*\n]+)\*/g, "<em>$1</em>");

/**
 * Same signature as the upstream module's export: inline (default) renders a
 * single run of text; block mode (`inline = false`) wraps blank-line-separated
 * paragraphs in <p> and keeps single newlines as <br>.
 */
export function renderMarkdown(markdown: string, inline = true): string {
  if (inline) {
    return renderInline(markdown.replace(/\s*\n\s*/g, " "));
  }
  return markdown
    .split(/\n{2,}/)
    .filter((paragraph) => paragraph.trim() !== "")
    .map((paragraph) => `<p>${renderInline(paragraph).replaceAll("\n", "<br>")}</p>`)
    .join("");
}
