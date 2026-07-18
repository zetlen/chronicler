import type { ThreadedMessage, SlackMessage } from "@/lib/transform/slackTypes";
import type { RenderContext } from "@/lib/transform/types";
import { transformMessage } from "@/lib/transform/transformMessage";
import { sanitizeFragment } from "@/lib/transform/sanitize";
import {
  isVisible,
  CONTEXT_NOTE,
  beforeNoteText,
  afterNoteText,
} from "@/lib/transform/renderDocument";
import { dialogueCss } from "@/lib/transform/styles";
import { containerBlock, voidBlock } from "@/lib/wordpress/blockGrammar";

/**
 * Drop empty strings/arrays so the serialized grammar stays lean. Exported
 * so generateBlocks.test.ts can pin the plugin's ES5 port
 * (wordpress-plugin/generate/session-blocks.js) to the same pruning.
 */
export function pruneEmpty(o: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(o).filter(
      ([, v]) => v !== "" && !(Array.isArray(v) && v.length === 0),
    ),
  );
}

/**
 * One message's chronicler/message block attributes — the canonical
 * transform-output → block-attribute derivation, shared by the publish
 * emitter below and by the wp-admin session editor (whose PUT
 * /sessions/{id} payload must match the plugin's message schema, which is
 * EXACTLY this attribute vocabulary — Rest\Schemas::messageItem()).
 *
 * Runs client-side (sanitizeFragment needs a DOM).
 */
export function messageBlockAttributes(
  message: SlackMessage,
  ctx: RenderContext,
): Record<string, unknown> {
  const parts = transformMessage(message, ctx);
  if (parts.kind === "raw") {
    return { html: sanitizeFragment(parts.rawHtml) };
  }
  return pruneEmpty({
    rootClass: parts.rootClass,
    anchorId: parts.anchorId,
    authorName: parts.authorName,
    realName:
      parts.realName && parts.realName !== parts.authorName ? parts.realName : "",
    authorColor: parts.authorColor,
    authorColorDark: parts.authorColorDark,
    avatarHtml: sanitizeFragment(parts.avatarHtml),
    headHtml: sanitizeFragment(parts.headHtml),
    bodyHtml: sanitizeFragment(parts.bodyHtml),
    images: parts.images,
    extrasHtml: sanitizeFragment(parts.extrasHtml),
    reactionsHtml: sanitizeFragment(parts.reactionsHtml),
  });
}

/**
 * The Session `messages[]` payload (#96/#101): every VISIBLE message's block
 * attributes, flattened in the exact order the block emitter would publish
 * them — parent then surviving replies, with the replies of a filtered-out
 * parent promoted in place. Thread grouping and the omitted-reply notes are
 * not representable in the stored message schema (additionalProperties is
 * false); they are display artifacts the block emitter re-derives at
 * publish/preview time from live data.
 */
export function sessionMessageAttributes(
  threads: ThreadedMessage[],
  ctx: RenderContext,
): Record<string, unknown>[] {
  const out: Record<string, unknown>[] = [];
  for (const thread of threads) {
    const visibleReplies = thread.replies.filter((r) => isVisible(r, ctx));
    if (isVisible(thread.parent, ctx)) {
      out.push(messageBlockAttributes(thread.parent, ctx));
    }
    for (const reply of visibleReplies) {
      out.push(messageBlockAttributes(reply, ctx));
    }
  }
  return out;
}

/**
 * Render the conversation as Gutenberg block grammar — the block-plugin
 * sibling of renderConversationFragment. Layout semantics (visibility,
 * thread nesting, promoted replies, omitted-reply notes) are identical;
 * only the serialization differs: every message's rendered HTML rides in a
 * dynamic block's attributes, and the site plugin's render_callbacks
 * reproduce the exact markup the HTML path would have published.
 *
 * Runs client-side, like the fragment renderer (sanitizeFragment needs a
 * DOM).
 */
export function renderConversationBlocks(
  threads: ThreadedMessage[],
  ctx: RenderContext,
  opts?: { customCss?: string },
): string {
  const messageBlock = (message: SlackMessage) =>
    voidBlock("message", messageBlockAttributes(message, ctx));

  const inner: string[] = [];
  for (const thread of threads) {
    const parentVisible = isVisible(thread.parent, ctx);
    const visibleReplies = thread.replies.filter((r) => isVisible(r, ctx));

    if (!parentVisible) {
      // Parent filtered out — promote surviving replies to the top level.
      for (const reply of visibleReplies) inner.push(messageBlock(reply));
      continue;
    }

    const hasNotes = Boolean(
      thread.parentBeforeWindow || thread.omittedBefore || thread.omittedAfter,
    );
    if (visibleReplies.length === 0 && !hasNotes) {
      inner.push(messageBlock(thread.parent));
      continue;
    }

    const threadAttributes = thread.parentBeforeWindow
      ? { context: true, contextNote: CONTEXT_NOTE }
      : {};
    const repliesAttributes = {
      ...(thread.omittedBefore
        ? { beforeNote: beforeNoteText(thread.omittedBefore) }
        : {}),
      ...(thread.omittedAfter
        ? { afterNote: afterNoteText(thread.omittedAfter) }
        : {}),
    };
    inner.push(
      containerBlock("thread", threadAttributes, [
        messageBlock(thread.parent),
        containerBlock(
          "replies",
          repliesAttributes,
          visibleReplies.map((reply) => messageBlock(reply)),
        ),
      ]),
    );
  }

  const customCss = opts?.customCss?.trim();
  return containerBlock(
    "transcript",
    {
      // Scheme and density are always explicit so the PHP renderer never
      // guesses defaults. The base stylesheet travels with the post — a
      // transcript is a finished artifact and keeps the look it was
      // published with; later stylesheet changes never rewrite history.
      scheme: ctx.scheme,
      density: ctx.density,
      baseCss: dialogueCss,
      ...(customCss ? { customCss } : {}),
    },
    inner,
  );
}
