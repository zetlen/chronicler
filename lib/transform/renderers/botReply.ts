import type { Renderer } from "@/lib/transform/types";
import { bubbleParts } from "@/lib/transform/shared";
import {
  botAuthor,
  botAvatar,
  botBodyText,
  botBodyExtras,
} from "@/lib/transform/renderers/_bot";

/**
 * Bot / integration message posted inside a thread (e.g. an app replying to a
 * user in a thread). Carries both `bot` and `reply` modifiers so it reads as a
 * bot message *and* is indented under its thread parent.
 */
export const renderBotReply: Renderer = (m, ctx) => {
  const { images, extrasHtml } = botBodyExtras(m, ctx);
  return bubbleParts(
    {
      modifiers: ["bot", "reply"],
      authorName: botAuthor(m),
      timestamp: m.ts,
      badge: "APP",
      avatarUrl: botAvatar(m),
      bodyHtml: botBodyText(m, ctx),
      images,
      extrasHtml,
      reactions: m.reactions,
    },
    ctx,
  );
};
