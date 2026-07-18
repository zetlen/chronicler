import type { Renderer } from "@/lib/transform/types";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import { bubbleParts, renderAttachments } from "@/lib/transform/shared";

/**
 * Message whose primary payload is a shared link — typically rendered by Slack
 * as a URL plus one or more unfurl "cards" (which arrive as attachments).
 * Renders the message text, then the unfurl cards.
 */
export const renderLink: Renderer = (m, ctx) =>
  bubbleParts(
    {
      modifiers: ["link"],
      authorName: ctx.directory.userName(m.user),
      realName: ctx.directory.realUserName(m.user),
      timestamp: m.ts,
      bodyHtml: mrkdwnToHtml(m.text, ctx),
      extrasHtml: renderAttachments(m.attachments, ctx),
      reactions: m.reactions,
      authorColor: ctx.directory.userColor(m.user),
      avatarUrl: ctx.directory.userAvatar(m.user),
    },
    ctx,
  );
