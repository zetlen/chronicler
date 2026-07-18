import type { MessageKind, Renderer } from "@/lib/transform/types";
import { renderText } from "@/lib/transform/renderers/text";
import { renderReply } from "@/lib/transform/renderers/reply";
import { renderBotMessage } from "@/lib/transform/renderers/botMessage";
import { renderBotReply } from "@/lib/transform/renderers/botReply";
import { renderImage } from "@/lib/transform/renderers/image";
import { renderFile } from "@/lib/transform/renderers/file";
import { renderLink } from "@/lib/transform/renderers/link";
import { renderSystem } from "@/lib/transform/renderers/system";

/**
 * The strategy registry: each {@link MessageKind} maps to the renderer that
 * knows how to display it. This is the companion to `classify()` — together
 * they form the switch/strategy structure. To customize a category's
 * appearance, edit its renderer module; to add a category, add a `MessageKind`,
 * a branch in `classify()`, and an entry here.
 */
export const renderers: Record<MessageKind, Renderer> = {
  system: renderSystem,
  bot_message: renderBotMessage,
  bot_reply: renderBotReply,
  reply: renderReply,
  image: renderImage,
  file: renderFile,
  link: renderLink,
  text: renderText,
};
