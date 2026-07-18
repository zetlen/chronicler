import type { SlackMessage } from "@/lib/transform/slackTypes";
import type { RenderContext, MessageParts } from "@/lib/transform/types";
import { classify } from "@/lib/transform/classify";
import { injectRootClasses } from "@/lib/transform/rules";
import { composeBubble } from "@/lib/transform/shared";
import { renderers } from "@/lib/transform/renderers";

/**
 * Transform a single Slack message into its structured parts. Strategy is
 * unchanged: classify() picks the kind, the registry picks the renderer.
 * "addclass" rules land on the root class (or on the raw html's root tag).
 */
export function transformMessage(
  message: SlackMessage,
  ctx: RenderContext,
): MessageParts {
  const kind = classify(message);
  const render = renderers[kind] ?? renderers.text;
  const parts = render(message, ctx);
  const classes = ctx.ruleEffects?.get(message.ts)?.classes;
  if (!classes?.length) return parts;
  if (parts.kind === "raw") {
    return { ...parts, rawHtml: injectRootClasses(parts.rawHtml, classes) };
  }
  return { ...parts, rootClass: `${parts.rootClass} ${classes.join(" ")}` };
}

/** The classic flat-HTML transform — parts composed back to a string. */
export function transformMessageHtml(
  message: SlackMessage,
  ctx: RenderContext,
): string {
  const parts = transformMessage(message, ctx);
  return parts.kind === "raw" ? parts.rawHtml : composeBubble(parts);
}
