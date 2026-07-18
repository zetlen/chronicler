/**
 * Minimal Gutenberg block-grammar helpers for the `chronicler/*` blocks.
 *
 * Published block content is a sequence of HTML comments carrying JSON
 * attributes (`<!-- wp:chronicler/message {...} /-->`). The blocks are
 * DYNAMIC: all content lives in attributes and the site plugin's
 * render_callback produces the markup, so there is no innerHTML for the
 * editor to validate — reordering, deleting, and re-saving in Gutenberg
 * cannot corrupt a transcript.
 */

/**
 * Version of the block schema the emitter produces. The site plugin reports
 * the schema it understands via the `chronicler.styles` XML-RPC method, and
 * the publish flow only emits blocks when the versions are compatible.
 *
 * v2: the transcript block carries its own `baseCss` — styles are per-post
 * data, not site-global state. A v1 plugin would ignore the attribute and
 * render unstyled, so v2 grammar is never sent to it (HTML fallback).
 * v3: message content is structured editable attributes; a v2 plugin can't
 * render them, so v3 grammar is never sent to it.
 */
export const BLOCKS_VERSION = 4;

export type ChroniclerBlockName = "transcript" | "thread" | "replies" | "message";

export interface ParsedChroniclerBlock {
  name: ChroniclerBlockName;
  attributes: Record<string, unknown>;
}

/**
 * Serialize attributes the way Gutenberg's serializeAttributes does: `--`,
 * `<`, `>`, `&`, and embedded quotes are unicode-escaped because any of them
 * would corrupt the wrapping HTML comment (or get chewed by wpautop). The
 * escapes are plain JSON, so JSON.parse round-trips them untouched.
 *
 * Backslash pairs are escaped to `\` BEFORE the quote pass. Without this,
 * a value ending in a backslash stringifies to `...\\"` (escaped backslash +
 * closing quote); the `\\"`→`"` replace would then match the pair's second
 * backslash and the structural quote, rewriting the terminator and producing
 * JSON that JSON.parse rejects. Escaping `\\` first leaves the closing quote
 * unambiguous. (Gutenberg's own serializer has the same latent gap, but our
 * attributes carry arbitrary Slack HTML and user CSS, so it is far more
 * reachable here.)
 */
export function serializeBlockAttributes(
  attributes: Record<string, unknown>,
): string {
  return JSON.stringify(attributes)
    .replace(/--/g, "\\u002d\\u002d")
    .replace(/</g, "\\u003c")
    .replace(/>/g, "\\u003e")
    .replace(/&/g, "\\u0026")
    .replace(/\\\\/g, "\\u005c")
    .replace(/\\"/g, "\\u0022");
}

/** A leaf block with no inner content, e.g. chronicler/message. */
export function voidBlock(
  name: ChroniclerBlockName,
  attributes: Record<string, unknown>,
): string {
  return `<!-- wp:chronicler/${name} ${serializeBlockAttributes(attributes)} /-->`;
}

/** A container block wrapping serialized inner blocks. */
export function containerBlock(
  name: ChroniclerBlockName,
  attributes: Record<string, unknown>,
  inner: string[],
): string {
  const attrs =
    Object.keys(attributes).length > 0
      ? `${serializeBlockAttributes(attributes)} `
      : "";
  return `<!-- wp:chronicler/${name} ${attrs}-->\n${inner.join("\n")}\n<!-- /wp:chronicler/${name} -->`;
}

// Lazy `{...}` is safe: a literal "-->" can never appear inside the JSON
// (dashes are unicode-escaped), so the first `} -->` / `} /-->` really is
// the comment terminator.
const BLOCK_RE =
  /<!-- wp:chronicler\/(transcript|thread|replies|message)(?: ({[\s\S]*?}))? \/?-->/g;

/** Every chronicler block in the content, opening-order, with parsed attributes. */
export function parseChroniclerBlocks(content: string): ParsedChroniclerBlock[] {
  const out: ParsedChroniclerBlock[] = [];
  for (const match of content.matchAll(BLOCK_RE)) {
    out.push({
      name: match[1] as ChroniclerBlockName,
      attributes: match[2] ? (JSON.parse(match[2]) as Record<string, unknown>) : {},
    });
  }
  return out;
}

/** True when the content contains at least one chronicler block. */
export function hasChroniclerBlocks(content: string): boolean {
  return /<!-- wp:chronicler\//.test(content);
}

const MESSAGE_RE = /<!-- wp:chronicler\/message ({[\s\S]*?}) \/-->/g;

const MESSAGE_HTML_KEYS = [
  "html", "avatarHtml", "headHtml", "bodyHtml", "extrasHtml", "reactionsHtml",
] as const;

export interface MessageStringVisitors {
  /** Applied to every opaque-HTML string attribute of each message block. */
  onHtml: (html: string) => string;
  /** Applied to every structured images[].src. */
  onImageSrc: (src: string) => string;
}

/**
 * Rewrite every message block's opaque-HTML attributes and structured image
 * srcs, preserving the surrounding grammar and re-applying comment-safe
 * escaping. The publish flow uses this to swap proxied srcs for Media
 * Library URLs across both the v2 (html) and v3 (structured) shapes.
 */
export function mapMessageAttributes(
  content: string,
  v: MessageStringVisitors,
): string {
  return content.replace(MESSAGE_RE, (full, json: string) => {
    const attributes = JSON.parse(json) as Record<string, unknown>;
    for (const key of MESSAGE_HTML_KEYS) {
      if (typeof attributes[key] === "string") {
        attributes[key] = v.onHtml(attributes[key] as string);
      }
    }
    if (Array.isArray(attributes.images)) {
      attributes.images = (attributes.images as Array<Record<string, unknown>>).map(
        (im) =>
          im && typeof im.src === "string" ? { ...im, src: v.onImageSrc(im.src) } : im,
      );
    }
    return `<!-- wp:chronicler/message ${serializeBlockAttributes(attributes)} /-->`;
  });
}

/**
 * Every opaque-HTML string and structured src, for the image-mirroring scan.
 */
export function collectMessageStrings(content: string): {
  htmlStrings: string[];
  imageSrcs: string[];
} {
  const htmlStrings: string[] = [];
  const imageSrcs: string[] = [];
  mapMessageAttributes(content, {
    onHtml: (h) => { htmlStrings.push(h); return h; },
    onImageSrc: (s) => { imageSrcs.push(s); return s; },
  });
  return { htmlStrings, imageSrcs };
}
