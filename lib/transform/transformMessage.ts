import type { SlackMessage } from "@/lib/transform/slackTypes";
import type { RenderContext, MessageParts } from "@/lib/transform/types";
import { classify } from "@/lib/transform/classify";
import { injectRootClasses } from "@/lib/transform/rules";
import { composeBubble } from "@/lib/transform/shared";
import { renderers } from "@/lib/transform/renderers";
import { variantClasses } from "@/lib/transform/variants";

/**
 * Transform a single Slack message into its structured parts. Strategy is
 * unchanged: classify() picks the kind, the registry picks the renderer.
 * "addclass" rules land on the root class (or on the raw html's root tag);
 * "treatment" rules land on `variants` — compose INPUT, so composeBubble
 * (and the PHP renderer) derive the slk-msg--<v> classes and the OOC
 * byline swap from them.
 */
export function transformMessage(
  message: SlackMessage,
  ctx: RenderContext,
): MessageParts {
  const kind = classify(message);
  const render = renderers[kind] ?? renderers.text;
  const parts = render(message, ctx);
  const effect = ctx.ruleEffects?.get(message.ts);
  const classes = effect?.classes ?? [];
  const variants = effect?.variants ?? [];
  if (classes.length === 0 && variants.length === 0) return parts;
  if (parts.kind === "raw") {
    // Raw parts never reach composeBubble, so treatments degrade to their
    // classes — CSS and the OOC toggle key off slk-msg--<v> in the DOM.
    return {
      ...parts,
      rawHtml: injectRootClasses(parts.rawHtml, [...classes, ...variantClasses(variants)]),
    };
  }
  const existing = parts.variants ?? [];
  return {
    ...parts,
    rootClass: classes.length ? `${parts.rootClass} ${classes.join(" ")}` : parts.rootClass,
    variants: [...existing, ...variants.filter((v) => !existing.includes(v))],
  };
}

/** The classic flat-HTML transform — parts composed back to a string. */
export function transformMessageHtml(
  message: SlackMessage,
  ctx: RenderContext,
): string {
  const parts = transformMessage(message, ctx);
  return parts.kind === "raw" ? parts.rawHtml : composeBubble(parts);
}
