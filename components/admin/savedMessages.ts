/**
 * Preview for a reopened Session (#96): raw Slack payloads are never stored,
 * so until the next fetch/refresh the preview composes the SAVED message
 * objects — chronicler/message block attributes (Rest\Schemas::messageItem)
 * — back into transcript markup with the same composeBubble the plugin's
 * render_callback mirrors. System messages ride the opaque `html` attribute
 * verbatim; structured messages rebuild the classic bubble.
 */

import type { BubbleParts, MessageImage, TranscriptScheme } from "@/lib/transform/types";
import { composeBubble } from "@/lib/transform/shared";
import { sanitizeFragment } from "@/lib/transform/sanitize";

type SavedMessage = Record<string, unknown>;

function str(value: unknown): string {
  return typeof value === "string" ? value : "";
}

function savedImages(value: unknown): MessageImage[] {
  if (!Array.isArray(value)) return [];
  return value.flatMap((im) => {
    if (!im || typeof im !== "object") return [];
    const { src, alt, caption } = im as Record<string, unknown>;
    if (typeof src !== "string") return [];
    return [
      {
        src,
        alt: str(alt),
        ...(typeof caption === "string" ? { caption } : {}),
      },
    ];
  });
}

/** One saved message back to HTML ("" when the attributes carry nothing). */
export function savedMessageHtml(attrs: SavedMessage): string {
  if (typeof attrs.html === "string") return attrs.html;
  const parts: BubbleParts = {
    kind: "bubble",
    rootClass:
      [str(attrs.rootClass) || "slk-msg", str(attrs.className)]
        .filter(Boolean)
        .join(" "),
    anchorId: str(attrs.anchorId),
    authorName: str(attrs.authorName),
    authorColor: str(attrs.authorColor),
    authorColorDark: str(attrs.authorColorDark) || str(attrs.authorColor),
    avatarHtml: str(attrs.avatarHtml),
    headHtml: str(attrs.headHtml),
    bodyHtml: str(attrs.bodyHtml),
    images: savedImages(attrs.images),
    extrasHtml: str(attrs.extrasHtml),
    reactionsHtml: str(attrs.reactionsHtml),
    variants: Array.isArray(attrs.variants)
      ? (attrs.variants as unknown[]).filter((v): v is string => typeof v === "string")
      : [],
    ...(typeof attrs.realName === "string" ? { realName: attrs.realName } : {}),
  };
  return composeBubble(parts);
}

/**
 * The saved messages as one sanitized `.slack-log` fragment — flat (thread
 * grouping is not stored; the blocks path re-derives it from live data).
 * The dark scheme is baked in exactly as renderConversationFragment does.
 */
export function composeSavedFragment(
  messages: SavedMessage[],
  scheme: TranscriptScheme,
): string {
  const schemeClass = scheme === "dark" ? " slk-dark" : "";
  const body = messages.map(savedMessageHtml).filter(Boolean).join("\n");
  return sanitizeFragment(`<div class="slack-log${schemeClass}">${body}</div>`);
}
