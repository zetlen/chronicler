import type { SlackMessage } from "@/lib/transform/slackTypes";
import type { MessageImage, RenderContext } from "@/lib/transform/types";
import { mrkdwnToHtml, blocksToHtml } from "@/lib/transform/mrkdwn";
import {
  structuredImages,
  renderImages,
  renderFiles,
  renderAttachments,
  imageFiles,
  nonImageFiles,
} from "@/lib/transform/shared";

/**
 * Shared helpers for bot/integration messages. Both the top-level and threaded
 * bot renderers compose their body the same way; they differ only in the
 * bubble modifiers/badge, which keeps each renderer file focused on appearance.
 */

export function botAuthor(m: SlackMessage): string {
  return m.username || m.bot_profile?.name || "App";
}

export function botAvatar(m: SlackMessage): string | undefined {
  const icons = m.bot_profile?.icons;
  return icons?.image_72 || icons?.image_48;
}

/**
 * Bots frequently populate `blocks` and/or `attachments` instead of `text`,
 * so we fall back through all of them.
 */
export function botBodyText(m: SlackMessage, ctx: RenderContext): string {
  return m.text ? mrkdwnToHtml(m.text, ctx) : blocksToHtml(m.blocks, ctx);
}

/**
 * The images/extras fork shared with renderImage: proxy mode carries
 * structured figures in `images`, while link mode folds the anchor cards
 * into `extrasHtml` ahead of the file/attachment cards.
 */
export function botBodyExtras(
  m: SlackMessage,
  ctx: RenderContext,
): { images: MessageImage[]; extrasHtml: string } {
  const proxy = ctx.imageMode === "proxy";
  const imgs = imageFiles(m.files);
  return {
    images: proxy ? structuredImages(imgs, ctx.imageProxyBase) : [],
    extrasHtml:
      (proxy ? "" : renderImages(imgs, ctx)) +
      renderFiles(nonImageFiles(m.files), ctx) +
      renderAttachments(m.attachments, ctx),
  };
}
