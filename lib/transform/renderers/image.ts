import type { Renderer } from "@/lib/transform/types";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import {
  bubbleParts,
  structuredImages,
  renderImages,
  renderFiles,
  renderAttachments,
  imageFiles,
  nonImageFiles,
} from "@/lib/transform/shared";

/** Message carrying image files; see renderImages for the proxy/link modes. */
export const renderImage: Renderer = (m, ctx) => {
  const proxy = ctx.imageMode === "proxy";
  const imgs = imageFiles(m.files);
  return bubbleParts(
    {
      modifiers: ["image"],
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
