import type { Renderer } from "@/lib/transform/types";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import {
  bubbleParts,
  structuredImages,
  renderImages,
  renderAttachments,
  renderFiles,
  imageFiles,
  nonImageFiles,
} from "@/lib/transform/shared";

/**
 * Human message posted inside a thread.
 *
 * Structurally identical to a plain message, but tagged with the `reply`
 * modifier so the document layer / CSS can indent it under its parent. Edit
 * here to change how human thread replies specifically appear.
 *
 * classify() routes threaded messages here before it ever checks for image
 * files, so a reply can carry images just like the `image` kind — hence the
 * same proxy/link fork as renderImage.
 */
export const renderReply: Renderer = (m, ctx) => {
  const proxy = ctx.imageMode === "proxy";
  const imgs = imageFiles(m.files);
  return bubbleParts(
    {
      modifiers: ["reply"],
      authorName: ctx.directory.userName(m.user),
      realName: ctx.directory.realUserName(m.user),
      timestamp: m.ts,
      bodyHtml: mrkdwnToHtml(m.text, ctx),
      images: proxy ? structuredImages(imgs, ctx.imageProxyBase) : [],
      extrasHtml:
        (proxy ? "" : renderImages(imgs, ctx)) +
        renderFiles(nonImageFiles(m.files), ctx) +
        renderAttachments(m.attachments, ctx),
      reactions: m.reactions,
      authorColor: ctx.directory.userColor(m.user),
      avatarUrl: ctx.directory.userAvatar(m.user),
    },
    ctx,
  );
};
