import { toHTML } from "slack-markdown";
import type { SlackBlock } from "@/lib/transform/slackTypes";
import type { RenderContext } from "@/lib/transform/types";
import {
  escapeHtml,
  escapeAttr,
  safeUrl,
  insertCustomEmoji,
  proxiedImageUrl,
} from "@/lib/transform/shared";
import { toRichTextSafeHtml } from "@/lib/transform/richTextSafe";

/*
 * Slack "mrkdwn" parsing is delegated to the `slack-markdown` library (a fork
 * of the well-tested discord-markdown engine). It handles emphasis, code,
 * blockquotes, links, emoji, and the <@U>/<#C>/<!subteam> entity syntax, with
 * callbacks so we can resolve IDs to names via our Directory.
 *
 * One wrinkle: Slack's API delivers text with &, <, > pre-escaped to &amp;,
 * &lt;, &gt;. `slack-markdown` expects *raw* text and escapes the output
 * itself, so we reverse Slack's escaping first (the standard practice from
 * Slack's own docs). The lone tradeoff — a user who literally types escaped
 * mention syntax could see it parsed as a mention — is negligible here.
 */

function unescapeSlack(text: string): string {
  return text
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&amp;/g, "&");
}

export function mrkdwnToHtml(
  raw: string | undefined,
  ctx: RenderContext,
): string {
  if (!raw) return "";
  try {
    // Normalized to the RichText-safe inline inventory so published bodies
    // survive WordPress rich-text editing; see richTextSafe.ts.
    return toRichTextSafeHtml(
      insertCustomEmoji(
        toHTML(unescapeSlack(raw), {
          hrefTarget: "_blank",
          slackCallbacks: {
            // Prefer the live directory over the embedded legacy name (<@U1|old>),
            // which goes stale after renames; fall back to it only when the
            // directory can't resolve the ID (userName returns the raw ID then).
            user: ({ id, name }) => {
              const resolved = ctx.directory.userName(id);
              return `@${escapeHtml(resolved === id && name ? name : resolved)}`;
            },
            channel: ({ id, name }) =>
              `#${escapeHtml(name || ctx.directory.channelName(id))}`,
            usergroup: ({ name }) => `@${escapeHtml(name || "group")}`,
          },
        }),
        ctx.customEmoji,
      ),
    );
  } catch {
    // slack-markdown's recursive emphasis parser overflows the call stack on a
    // long run of `*`/`_`/`~` (a few thousand chars, well under Slack's 40k
    // message limit). This runs during React render with no error boundary
    // above it, so an uncaught throw white-screens the whole transcript and the
    // draft preview. Degrade the one offending message to escaped plaintext
    // instead — the mention/emoji sugar is lost, but the content survives and
    // the render keeps going.
    return escapeHtml(unescapeSlack(raw));
  }
}

/**
 * Best-effort flattening of Block Kit blocks into HTML so bot messages that
 * only populate `blocks` (no top-level `text`) still render. There's no
 * mature library for this, and it's a small/niche need, so we keep it
 * hand-rolled. Text-bearing blocks go through the mrkdwn pipeline; image and
 * divider blocks render directly; anything else leaves a visible placeholder
 * so omissions are never silent.
 */
export function blocksToHtml(
  blocks: SlackBlock[] | undefined,
  ctx: RenderContext,
): string {
  if (!blocks?.length) return "";
  const parts: string[] = [];

  for (const block of blocks) {
    switch (block.type) {
      case "header": {
        const t = textOf(block.text);
        if (t) parts.push(mrkdwnToHtml(`*${t}*`, ctx));
        break;
      }
      case "section": {
        const t = textOf(block.text);
        if (t) parts.push(mrkdwnToHtml(t, ctx));
        const fields = (block.fields as Array<{ text?: string }> | undefined) ?? [];
        for (const f of fields) if (f.text) parts.push(mrkdwnToHtml(f.text, ctx));
        break;
      }
      case "context": {
        const elements = (block.elements as Array<{ text?: string }> | undefined) ?? [];
        const line = elements.map((e) => e.text).filter(Boolean).join("  ");
        if (line) parts.push(mrkdwnToHtml(line, ctx));
        break;
      }
      case "rich_text": {
        parts.push(mrkdwnToHtml(flattenRichText(block.elements), ctx));
        break;
      }
      case "image": {
        const external = typeof block.image_url === "string" ? block.image_url : undefined;
        const slackFile = block.slack_file as { url_private?: string } | undefined;
        const src = external
          ? safeUrl(external)
          : slackFile?.url_private
            ? proxiedImageUrl(slackFile.url_private, ctx.imageProxyBase)
            : "#";
        if (src === "#") break;
        const alt = typeof block.alt_text === "string" ? block.alt_text : "";
        const title = textOf(block.title as SlackBlock["text"]);
        parts.push(
          `<figure class="slk-image"><img src="${escapeAttr(src)}" alt="${escapeAttr(
            alt,
          )}" loading="lazy">${title ? `<figcaption>${escapeHtml(title)}</figcaption>` : ""}</figure>`,
        );
        break;
      }
      case "divider":
        parts.push('<hr class="slk-divider">');
        break;
      default:
        parts.push(
          `<span class="slk-unsupported">[unsupported block: ${escapeHtml(
            block.type ?? "unknown",
          )}]</span>`,
        );
        break;
    }
  }

  return parts.filter(Boolean).join("\n");
}

function textOf(text: SlackBlock["text"]): string {
  if (!text) return "";
  return typeof text === "string" ? text : text.text ?? "";
}

function flattenRichText(elements: unknown): string {
  if (!Array.isArray(elements)) return "";
  let out = "";
  for (const el of elements as Array<Record<string, unknown>>) {
    if (typeof el.text === "string") out += el.text;
    if (Array.isArray(el.elements)) out += flattenRichText(el.elements);
  }
  return out;
}
