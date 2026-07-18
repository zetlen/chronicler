import type { Renderer } from "@/lib/transform/types";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import {
  bubbleParts,
  renderAttachments,
  renderFiles,
  nonImageFiles,
} from "@/lib/transform/shared";

/**
 * Plain human message — the default chat bubble.
 *
 * Edit this to change how ordinary text messages look (spacing, author
 * styling, etc.). Other renderers reuse the same {@link bubbleParts} chrome.
 */
export const renderText: Renderer = (m, ctx) =>
  bubbleParts(
    {
      modifiers: ["text"],
      authorName: ctx.directory.userName(m.user),
      realName: ctx.directory.realUserName(m.user),
      timestamp: m.ts,
      bodyHtml: mrkdwnToHtml(m.text, ctx),
      extrasHtml:
        renderFiles(nonImageFiles(m.files), ctx) +
        renderAttachments(m.attachments, ctx),
      reactions: m.reactions,
      authorColor: ctx.directory.userColor(m.user),
      avatarUrl: ctx.directory.userAvatar(m.user),
    },
    ctx,
  );
