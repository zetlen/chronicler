import type { Renderer } from "@/lib/transform/types";
import { bubbleParts } from "@/lib/transform/shared";
import {
  botAuthor,
  botAvatar,
  botBodyText,
  botBodyExtras,
} from "@/lib/transform/renderers/_bot";

/**
 * Top-level message from a bot / integration / incoming webhook.
 *
 * Tagged with the `bot` modifier and an "APP" badge. Bot content commonly
 * lives in `blocks`/`attachments` rather than `text` — see {@link botBodyText}.
 */
export const renderBotMessage: Renderer = (m, ctx) => {
  const { images, extrasHtml } = botBodyExtras(m, ctx);
  return bubbleParts(
    {
      modifiers: ["bot"],
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
