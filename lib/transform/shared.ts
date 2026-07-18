import { toHTML } from "slack-markdown";
import type { SlackFile, SlackAttachment, SlackReaction } from "@/lib/transform/slackTypes";
import type { RenderContext } from "@/lib/transform/types";
import type { BubbleParts, MessageImage } from "@/lib/transform/types";
import { colorForName, darkVariantOf } from "@/lib/transform/color";
// Circular with mrkdwn.ts (which imports escapeHtml); benign because both
// modules only call across the cycle at render time, never during evaluation.
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import { variantClasses, isOoc } from "@/lib/transform/variants";

/* ------------------------------------------------------------------ *
 * String / HTML safety
 * ------------------------------------------------------------------ */

/** Escape text that may contain HTML metacharacters for safe interpolation. */
export function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

/** Escape a value destined for a double-quoted HTML attribute. */
export function escapeAttr(value: string): string {
  return escapeHtml(value).replace(/"/g, "&quot;");
}

/* ------------------------------------------------------------------ *
 * Image proxying
 * ------------------------------------------------------------------ */

/** The Node app's authenticated streaming proxy — the historical default. */
export const DEFAULT_IMAGE_PROXY_BASE = "/api/slack-image?url=";

/**
 * A Slack-hosted (auth-required) image URL routed through whichever image
 * proxy the render context names: the raw URL is encoded and appended to the
 * base. RenderContext.imageProxyBase overrides the default so the wp-admin
 * bundle can point at the plugin's media mirror instead of the Node app.
 */
export function proxiedImageUrl(rawUrl: string, base?: string): string {
  return `${base ?? DEFAULT_IMAGE_PROXY_BASE}${encodeURIComponent(rawUrl)}`;
}

/**
 * Allow only safe URL schemes for href/src. Blocks javascript:/data:/etc.
 * Relative URLs (the image proxy) and http(s)/mailto pass; anything else
 * collapses to "#". The DOMPurify pass is the real backstop — this just keeps
 * the renderers self-defensive and the intent explicit.
 */
export function safeUrl(url: string | undefined): string {
  if (!url) return "#";
  // A single leading slash is an app-relative path (the image proxy); a double
  // slash ("//host") is a protocol-relative URL to an external host — not ours.
  if (/^(https?:|mailto:)/i.test(url) || (url.startsWith("/") && !url.startsWith("//"))) return url;
  return "#";
}

/* ------------------------------------------------------------------ *
 * Timestamps
 * ------------------------------------------------------------------ */

/**
 * Format a Slack "ts" (e.g. "1623351600.000400") as a human-readable time.
 * Defaults to the viewer's locale (rendering always happens in the browser);
 * `locale` exists so tests can pin a deterministic one.
 */
export function formatTimestamp(ts: string, locale?: string): string {
  const seconds = Number.parseFloat(ts);
  if (!Number.isFinite(seconds)) return ts;
  const date = new Date(seconds * 1000);
  return date.toLocaleString(locale, {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

/* ------------------------------------------------------------------ *
 * Avatars
 * ------------------------------------------------------------------ */

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * The author's avatar: the real profile image (proxied through
 * /api/slack-image, whose allowlist covers Slack's avatar hosts) when a URL
 * is known, else a small colored square showing the author's initials. The
 * square's colors come from the stylesheet (`--slk-id-active`), which
 * resolves to the light or dark identity color set on the enclosing
 * `.slk-msg`; the <img> carries the initials as alt text, so a failed load
 * degrades to roughly the initials look.
 */
export function avatarHtml(
  name: string,
  avatarUrl?: string,
  proxyBase?: string,
): string {
  const inits = escapeHtml(initials(name));
  if (!avatarUrl) return `<span class="slk-avatar">${inits}</span>`;
  const src = proxiedImageUrl(avatarUrl, proxyBase);
  return `<span class="slk-avatar"><img class="slk-avatar__img" src="${escapeAttr(
    src,
  )}" alt="${inits}" loading="lazy"></span>`;
}

/* ------------------------------------------------------------------ *
 * Files & attachments
 * ------------------------------------------------------------------ */

export function imageFiles(files: SlackFile[] | undefined): SlackFile[] {
  return (files ?? []).filter((f) => f.mimetype?.startsWith("image/"));
}

export function nonImageFiles(files: SlackFile[] | undefined): SlackFile[] {
  return (files ?? []).filter((f) => !f.mimetype?.startsWith("image/"));
}

function humanSize(bytes?: number): string {
  if (!bytes) return "";
  const units = ["B", "KB", "MB", "GB"];
  let n = bytes;
  let i = 0;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${units[i]}`;
}

/** Structured image entries matching renderImages' proxy-mode labels/srcs. */
export function structuredImages(
  files: SlackFile[],
  proxyBase?: string,
): MessageImage[] {
  return files.map((f) => {
    const label = f.title || f.name || "image";
    const source = f.thumb_720 || f.url_private || "";
    return {
      src: proxiedImageUrl(source, proxyBase),
      alt: label,
      caption: label,
    };
  });
}

/** Render image files either as inline (proxied) figures or as portable links. */
export function renderImages(files: SlackFile[], ctx: RenderContext): string {
  if (files.length === 0) return "";
  if (ctx.imageMode === "proxy") {
    return figuresHtml(structuredImages(files, ctx.imageProxyBase));
  }
  const items = files
    .map((f) => {
      const label = f.title || f.name || "image";
      const href = safeUrl(f.permalink || f.url_private);
      return `<a class="slk-file slk-file--image" href="${escapeAttr(
        href,
      )}" target="_blank" rel="noopener noreferrer"><span class="slk-file__icon">🖼️</span><span class="slk-file__name">${escapeHtml(
        label,
      )}</span></a>`;
    })
    .join("");
  return `<div class="slk-images">${items}</div>`;
}

/** Render non-image file attachments as compact cards. */
export function renderFiles(files: SlackFile[], _ctx: RenderContext): string {
  if (files.length === 0) return "";
  const items = files
    .map((f) => {
      const href = safeUrl(f.permalink || f.url_private);
      const meta = [f.pretty_type, humanSize(f.size)].filter(Boolean).join(" · ");
      return `<a class="slk-file" href="${escapeAttr(
        href,
      )}" target="_blank" rel="noopener noreferrer"><span class="slk-file__icon">📄</span><span class="slk-file__name">${escapeHtml(
        f.title || f.name || "file",
      )}</span>${meta ? `<span class="slk-file__meta">${escapeHtml(meta)}</span>` : ""}</a>`;
    })
    .join("");
  return `<div class="slk-files">${items}</div>`;
}

/** Render legacy attachments / link unfurls as bordered cards. */
export function renderAttachments(
  attachments: SlackAttachment[] | undefined,
  ctx: RenderContext,
): string {
  if (!attachments?.length) return "";
  return attachments
    .map((a) => {
      const accent = a.color ? (a.color.startsWith("#") ? a.color : `#${a.color}`) : "#ddd";
      const title = a.title
        ? a.title_link
          ? `<a class="slk-card__title" href="${escapeAttr(
              safeUrl(a.title_link),
            )}" target="_blank" rel="noopener noreferrer">${escapeHtml(a.title)}</a>`
          : `<span class="slk-card__title">${escapeHtml(a.title)}</span>`
        : "";
      const author = a.author_name
        ? `<div class="slk-card__author">${escapeHtml(a.author_name)}</div>`
        : "";
      const service = a.service_name
        ? `<div class="slk-card__service">${escapeHtml(a.service_name)}</div>`
        : "";
      const body = a.text ? `<div class="slk-card__text">${mrkdwnToHtml(a.text, ctx)}</div>` : "";
      const image = a.image_url
        ? `<img class="slk-card__image" src="${escapeAttr(
            safeUrl(a.image_url),
          )}" alt="" loading="lazy">`
        : "";
      const fields = a.fields?.length
        ? `<div class="slk-card__fields">${a.fields
            .map(
              (f) =>
                `<div class="slk-card__field"><div class="slk-card__field-title">${escapeHtml(
                  f.title || "",
                )}</div><div class="slk-card__field-value">${mrkdwnToHtml(f.value, ctx)}</div></div>`,
            )
            .join("")}</div>`
        : "";
      return `<div class="slk-card" style="border-left-color:${escapeAttr(
        accent,
      )}">${service}${author}${title}${body}${fields}${image}</div>`;
    })
    .join("");
}

/* ------------------------------------------------------------------ *
 * Reactions
 * ------------------------------------------------------------------ */

/**
 * The registered `chronicler/emoji-image` RichText object format declares
 * `attributes: { src, alt, loading }` (editor.js); WordPress's RichText
 * serializer re-emits an object format's declared attributes in that
 * declaration order with the format's `className` LAST — verified against
 * this repo's @wordpress/rich-text via create()/toHTMLString(). Emitting in
 * any other order here would make the real editor's round-trip lossy even
 * though this function's own output stays valid HTML.
 */
function customEmojiImg(name: string, url: string): string {
  return `<img src="${escapeAttr(safeUrl(url))}" alt=":${escapeAttr(
    name,
  )}:" loading="lazy" class="slk-emoji">`;
}

/**
 * One emoji as HTML: a workspace-custom image, a Unicode character via
 * slack-markdown's dataset (the same one that renders emoji in message text),
 * or the literal :shortcode: when unknown. Skin-tone suffixes like
 * "wave::skin-tone-3" resolve through the standard path.
 */
export function emojiHtml(name: string, ctx: RenderContext): string {
  const base = name.split("::")[0];
  const custom = ctx.customEmoji[base];
  if (custom) return customEmojiImg(base, custom);
  const rendered = toHTML(`:${name}:`, { noExtraEmojiSpanTags: true });
  return rendered !== `:${name}:` ? rendered : escapeHtml(`:${base}:`);
}

/**
 * Replace :shortcode: occurrences that survived mrkdwn parsing (workspace
 * custom emoji, which slack-markdown doesn't know) with <img> tags — in text
 * content only, never inside tags or code/pre blocks.
 */
export function insertCustomEmoji(
  html: string,
  customEmoji: Record<string, string>,
): string {
  if (!html.includes(":") || Object.keys(customEmoji).length === 0) return html;
  let codeDepth = 0;
  return html
    .split(/(<[^>]+>)/)
    .map((part) => {
      if (part.startsWith("<")) {
        if (/^<(code|pre)\b/i.test(part)) codeDepth++;
        else if (/^<\/(code|pre)\b/i.test(part)) codeDepth = Math.max(0, codeDepth - 1);
        return part;
      }
      if (codeDepth > 0) return part;
      return part.replace(/:([a-z0-9_+'-]+):/gi, (match, name: string) =>
        customEmoji[name] ? customEmojiImg(name, customEmoji[name]) : match,
      );
    })
    .join("");
}

/** Render a message's reactions as a row of count chips. */
export function renderReactions(
  reactions: SlackReaction[] | undefined,
  ctx: RenderContext,
): string {
  if (!reactions?.length) return "";
  const chips = reactions
    .map(
      (r) =>
        `<span class="slk-reaction"><span class="slk-reaction__emoji">${emojiHtml(
          r.name,
          ctx,
        )}</span>${r.count ? `<span class="slk-reaction__count">${r.count}</span>` : ""}</span>`,
    )
    .join("");
  return `<div class="slk-reactions">${chips}</div>`;
}

/* ------------------------------------------------------------------ *
 * The shared message "bubble"
 * ------------------------------------------------------------------ */

export interface BubbleOptions {
  /** CSS modifiers appended as `slk-msg--<modifier>` (e.g. ["bot","reply"]). */
  modifiers: string[];
  authorName: string;
  timestamp: string;
  /** The text portion of the body (RichText-safe html). */
  bodyHtml: string;
  /** Structured inline image figures (proxy mode). */
  images?: MessageImage[];
  /** Pre-rendered file cards / attachment cards, appended after images. */
  extrasHtml?: string;
  /** Optional pill rendered next to the author (e.g. "APP"). */
  badge?: string;
  /** Reactions on the message; rendered when ctx.showReactions is on. */
  reactions?: SlackReaction[];
  /** Identity color (hex) for the author; defaults to a hash of the name. */
  authorColor?: string;
  /** Profile image URL (raw Slack URL; avatarHtml proxies it). */
  avatarUrl?: string;
  /** The author's real (un-renamed) name, for the OOC treatment. */
  realName?: string;
}

/** Structured image figures — byte-identical to renderImages' proxy branch. */
export function figuresHtml(images: MessageImage[]): string {
  if (images.length === 0) return "";
  const items = images
    .map(
      (im) =>
        `<figure class="slk-image"><img src="${escapeAttr(im.src)}" alt="${escapeAttr(
          im.alt,
        )}" loading="lazy"><figcaption>${escapeHtml(im.caption ?? im.alt)}</figcaption></figure>`,
    )
    .join("");
  return `<div class="slk-images">${items}</div>`;
}

/**
 * The bubble's pieces, separated so the block publisher can serialize them
 * as v3 attributes. composeBubble() reproduces the classic flat markup.
 */
export function bubbleParts(o: BubbleOptions, ctx: RenderContext): BubbleParts {
  const color = o.authorColor ?? colorForName(o.authorName);
  const badge = o.badge ? `<span class="slk-badge">${escapeHtml(o.badge)}</span>` : "";
  // Stable per-message anchor for deep links (#msg-<ts>). The Slack ts never
  // changes, so the fragment survives re-publishing; the dot is dropped to keep
  // the id CSS-/URL-friendly.
  const anchorId = `msg-${o.timestamp.replace(/\./g, "")}`;
  // Timestamps shown: the visible time is where a reader grabs the permalink.
  // Timestamps hidden: this empty anchor is the only affordance for it — its
  // chain-link icon is painted in CSS and revealed on hover (or tap on touch).
  const headExtra = ctx.showTimestamps
    ? `<span class="slk-msg__time">${escapeHtml(formatTimestamp(o.timestamp))}</span>`
    : `<a class="slk-msg__permalink" href="#${escapeAttr(anchorId)}" aria-label="Copy link to this message"></a>`;
  return {
    kind: "bubble",
    rootClass: ["slk-msg", ...o.modifiers.map((m) => `slk-msg--${m}`)].join(" "),
    anchorId,
    authorName: o.authorName,
    authorColor: color,
    authorColorDark: darkVariantOf(color),
    avatarHtml: ctx.showAvatars
      ? `<div class="slk-msg__avatar">${avatarHtml(o.authorName, o.avatarUrl, ctx.imageProxyBase)}</div>`
      : "",
    headHtml: badge + headExtra,
    bodyHtml: o.bodyHtml,
    images: o.images ?? [],
    extrasHtml: o.extrasHtml ?? "",
    reactionsHtml: ctx.showReactions ? renderReactions(o.reactions, ctx) : "",
    variants: [],
    realName: o.realName,
  };
}

/** The classic flat bubble markup, byte-identical to the pre-parts output. */
export function composeBubble(p: BubbleParts): string {
  const identity = `--slk-id:${escapeAttr(p.authorColor)};--slk-id-dark:${escapeAttr(
    p.authorColorDark,
  )}`;
  const anchor = p.anchorId ? ` id="${escapeAttr(p.anchorId)}"` : "";
  const rootClass = [p.rootClass, ...variantClasses(p.variants ?? [])].join(" ");
  const author = isOoc(p.variants ?? []) && p.realName ? p.realName : p.authorName;
  return `<div class="${rootClass}"${anchor} style="${identity}">
  ${p.avatarHtml}
  <div class="slk-msg__main">
    <div class="slk-msg__head"><span class="slk-msg__author">${escapeHtml(
      author,
    )}</span>${p.headHtml}</div>
    <div class="slk-msg__body">${p.bodyHtml}${figuresHtml(p.images)}${p.extrasHtml}</div>
    ${p.reactionsHtml}
  </div>
</div>`;
}
