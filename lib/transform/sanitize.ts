import DOMPurify from "dompurify";

// Reverse-tabnabbing guard: every target=_blank anchor must carry
// rel=noopener noreferrer. The hand-rolled renderers set it themselves, but
// slack-markdown emits target (via hrefTarget) with no rel, so it is enforced
// here — the one pass every fragment goes through. Registered lazily on the
// first sanitize: at module scope it would also run during SSR prerendering,
// where dompurify has no DOM and no addHook.
let relHookRegistered = false;
function ensureRelHook(): void {
  if (relHookRegistered) return;
  relHookRegistered = true;
  DOMPurify.addHook("afterSanitizeAttributes", (node) => {
    if (node.tagName === "A" && node.getAttribute("target") === "_blank") {
      node.setAttribute("rel", "noopener noreferrer");
    }
  });
}

/**
 * Sanitize a rendered HTML fragment before it is shown in the preview or copied
 * out as a snippet.
 *
 * Rendering happens in the browser (driven by the controls panel), so this uses
 * the browser-native DOMPurify. The renderers already escape text and guard URL
 * schemes; this is defense-in-depth — the output is injected via
 * dangerouslySetInnerHTML and exported for pasting elsewhere, and the per-type
 * renderers are meant to be edited, so a sanitizer guards mistakes too.
 *
 * `target` is allow-listed so our `target="_blank"` links survive; `class` and
 * inline `style` (avatar colors, card accents) are kept by default.
 */
export function sanitizeFragment(html: string): string {
  ensureRelHook();
  return DOMPurify.sanitize(html, { ADD_ATTR: ["target"] });
}

/**
 * Stylesheet text safe to inject into a <style> element: CSS has no
 * legitimate use for '<', so every occurrence is removed — a stored
 * `</style><script>` payload cannot close the element. Mirrors
 * Chronicler\Sanitize::css() in the plugin, which guards the same stored
 * value at publish render; the two must keep agreeing byte for byte.
 */
export function sanitizeCss(css: string): string {
  return css.replace(/</g, "");
}
