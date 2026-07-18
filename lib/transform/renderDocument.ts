import type { ThreadedMessage, SlackMessage } from "@/lib/transform/slackTypes";
import type { RenderContext } from "@/lib/transform/types";
import { classify } from "@/lib/transform/classify";
import { transformMessageHtml } from "@/lib/transform/transformMessage";
import { sanitizeFragment } from "@/lib/transform/sanitize";

/** A message is shown unless its kind is filtered or a regex rule hides it. */
export function isVisible(message: SlackMessage, ctx: RenderContext): boolean {
  if (ctx.hiddenKinds.has(classify(message))) return false;
  return !ctx.ruleEffects?.get(message.ts)?.hidden;
}

/** A muted marker line for replies the time window clipped away. */
const note = (text: string) => `<div class="slk-thread__note">${text}</div>`;

/** Shared with the block emitter so both outputs word notes identically. */
export const CONTEXT_NOTE =
  "Thread started before the selected range — parent shown for context.";
export const replyNoun = (n: number) => (n === 1 ? "reply" : "replies");
export const beforeNoteText = (n: number) =>
  `${n} earlier ${replyNoun(n)} not shown.`;
export const afterNoteText = (n: number) =>
  `Thread continues past the selected range — ${n} later ${replyNoun(n)} not shown.`;

/**
 * Render the full conversation as an HTML *fragment* (no <style>).
 *
 * Layout concerns (thread nesting, filtering, density) live here; per-message
 * appearance lives in the individual renderers. A visible thread parent renders
 * with its visible replies nested beneath it; if the parent is filtered out,
 * its surviving replies are promoted to the top level so their content isn't
 * lost.
 */
export function renderConversationFragment(
  threads: ThreadedMessage[],
  ctx: RenderContext,
): string {
  const blocks: string[] = [];

  for (const thread of threads) {
    const parentVisible = isVisible(thread.parent, ctx);
    const visibleReplies = thread.replies.filter((r) => isVisible(r, ctx));

    if (!parentVisible) {
      // Parent filtered out — promote any surviving replies to the top level.
      for (const reply of visibleReplies) blocks.push(transformMessageHtml(reply, ctx));
      continue;
    }

    const parent = transformMessageHtml(thread.parent, ctx);
    const contextNote = thread.parentBeforeWindow ? note(CONTEXT_NOTE) : "";
    const beforeNote = thread.omittedBefore
      ? note(beforeNoteText(thread.omittedBefore))
      : "";
    const afterNote = thread.omittedAfter
      ? note(afterNoteText(thread.omittedAfter))
      : "";

    if (visibleReplies.length === 0 && !contextNote && !beforeNote && !afterNote) {
      blocks.push(parent);
      continue;
    }

    const replies = visibleReplies
      .map((reply) => transformMessageHtml(reply, ctx))
      .join("\n");
    const threadClass = thread.parentBeforeWindow
      ? "slk-thread slk-thread--context"
      : "slk-thread";
    blocks.push(
      `<div class="${threadClass}">${contextNote}${parent}<div class="slk-thread__replies">${beforeNote}${replies}${afterNote}</div></div>`,
    );
  }

  // The dark preset is baked into the markup so the exported fragment carries
  // its scheme anywhere it's pasted. Light and custom render the bare base.
  const schemeClass = ctx.scheme === "dark" ? " slk-dark" : "";
  const densityClass =
    ctx.density === "compact" ? " slk-density-compact" : "";
  return sanitizeFragment(
    `<div class="slack-log${schemeClass}${densityClass}">${blocks.join("\n")}</div>`,
  );
}

