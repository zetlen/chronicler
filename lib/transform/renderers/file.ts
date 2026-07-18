import type { Renderer } from "@/lib/transform/types";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import { bubbleParts, renderFiles, renderAttachments } from "@/lib/transform/shared";

/**
 * Message carrying non-image file attachments (PDFs, snippets, archives, …).
 * Renders any caption text followed by compact file cards and any attachment
 * cards (link unfurls).
 */
export const renderFile: Renderer = (m, ctx) =>
  bubbleParts(
    {
      modifiers: ["file"],
      authorName: ctx.directory.userName(m.user),
      realName: ctx.directory.realUserName(m.user),
      timestamp: m.ts,
      bodyHtml: mrkdwnToHtml(m.text, ctx),
      extrasHtml:
        renderFiles(m.files ?? [], ctx) + renderAttachments(m.attachments, ctx),
      reactions: m.reactions,
      authorColor: ctx.directory.userColor(m.user),
      avatarUrl: ctx.directory.userAvatar(m.user),
    },
    ctx,
  );
