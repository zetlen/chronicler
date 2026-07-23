/**
 * Block-editor generation engine (#3).
 *
 * Computes a session's transcript message attributes from its STORED RAW
 * payload + attached rules at Generate time — the same SessionProcessor pass
 * the editor preview runs — so a draft bakes exactly what the editor showed,
 * with the current rules applied. Previously the placeholder mapped the stored
 * baked messages[] 1:1 and applied no rules, so a rule added after the fetch
 * was ignored.
 *
 * Two entry points on window.chroniclerSessionEngine:
 * - messagesFor(): the whole list synchronously (the sidebar's tag/featured-
 *   image actions, which don't need progress).
 * - prepareGeneration(): the placeholder's flow — a `total` plus a batched
 *   async generator that renders the transform in time-budgeted slices and
 *   yields to the event loop between them, so the tab stays responsive and the
 *   progress indicator keeps moving even on large sessions.
 *
 * Returns null when the session has no stored raw (a pre-4.22.0 session not
 * refreshed since): the placeholder surfaces "Refresh from Slack first" rather
 * than baking a stale, pre-rule transcript.
 *
 * Bundled by scripts/build-admin-bundle.mjs into
 * wordpress-plugin/admin/dist/chronicler-session-engine.js.
 */

import { SessionProcessor } from "@/lib/session/SessionProcessor";
import {
  flattenVisibleMessages,
  messageBlockAttributes,
} from "@/lib/wordpress/renderBlocks";
import { sessionImageProxyBase } from "@/components/admin/imageUrls";
import {
  resolveRegexRules,
  sessionRenderOptions,
} from "@/components/admin/sessionRender";
import type { NameMaps } from "@/lib/transform/directory";
import type { RenderContext } from "@/lib/transform/types";
import type { SlackMessage, ThreadedMessage } from "@/lib/transform/slackTypes";
import type { SessionEditorState, WpRule } from "@/components/admin/sessionApi";

interface EngineSessionRaw {
  threads?: ThreadedMessage[];
  names?: NameMaps;
  customEmoji?: Record<string, string>;
}

interface EngineSession {
  rule_ids?: number[];
  editorState?: SessionEditorState;
  raw?: EngineSessionRaw | null;
}

interface Prepared {
  ctx: RenderContext;
  /** Visible messages in emitter order — the cheap flatten; the per-message
   *  transform (messageBlockAttributes) is what's expensive and batched. */
  visible: SlackMessage[];
}

/**
 * The rendering context + flattened visible-message list for a session, or
 * null when it carries no usable raw payload (caller prompts a Refresh). An
 * empty-but-present raw (a fetch that found no messages) is valid — visible is
 * just empty.
 */
function prepare(
  session: EngineSession,
  rules: WpRule[],
  opts: { imageProxyBase?: string },
): Prepared | null {
  const raw = session.raw;
  if (!raw || !Array.isArray(raw.threads) || !raw.names) {
    return null;
  }
  const regexRules = resolveRegexRules(rules, session.rule_ids ?? []);
  const options = sessionRenderOptions(session.editorState ?? {}, opts);
  const processed = SessionProcessor.init(
    { threads: raw.threads, names: raw.names, customEmoji: raw.customEmoji },
    regexRules,
    options,
  ).process();
  return {
    ctx: processed.ctx,
    visible: flattenVisibleMessages(processed.threads, processed.ctx),
  };
}

/** The whole rule-applied block-attribute list synchronously, or null when the
 *  session has no stored raw. Used by the sidebar (tags / featured image). */
export function messagesFor(
  session: EngineSession,
  rules: WpRule[],
  opts: { imageProxyBase?: string } = {},
): Record<string, unknown>[] | null {
  const p = prepare(session, rules, opts);
  return p ? p.visible.map((m) => messageBlockAttributes(m, p.ctx)) : null;
}

export interface RenderBatch {
  batch: Record<string, unknown>[];
  done: number;
  total: number;
}

export interface PreparedGeneration {
  total: number;
  batches: (budgetMs?: number) => AsyncGenerator<RenderBatch>;
}

/**
 * A macrotask yield. Unlike setTimeout it has no ~4ms clamp, and unlike
 * requestAnimationFrame it keeps firing in a backgrounded tab — so generation
 * completes even if the author tabs away to wait out a large session. All
 * evergreen browsers.
 */
function createYielder(): () => Promise<void> {
  const channel = new MessageChannel();
  let resume: () => void = () => {};
  channel.port1.onmessage = () => resume();
  return () =>
    new Promise<void>((r) => {
      resume = r;
      channel.port2.postMessage(0);
    });
}

/**
 * Render the visible messages to block attributes in time-budgeted slices,
 * yielding to the event loop between them (~budgetMs of transform per slice),
 * so the tab stays responsive and the progress bar keeps moving. The transform
 * work is byte-identical to messagesFor; only WHEN it runs differs.
 */
export async function* renderBatches(
  prepared: Prepared,
  budgetMs = 6,
): AsyncGenerator<RenderBatch> {
  const { visible, ctx } = prepared;
  const total = visible.length;
  const breathe = createYielder();
  let i = 0;
  while (i < total) {
    const start = performance.now();
    const batch: Record<string, unknown>[] = [];
    // do/while: always render at least one message per slice, so progress
    // advances even at a zero/tiny budget (a bare while could spin forever).
    do {
      batch.push(messageBlockAttributes(visible[i], ctx));
      i++;
    } while (i < total && performance.now() - start < budgetMs);
    yield { batch, done: i, total };
    await breathe();
  }
}

/** The placeholder's batched flow: the total (for the label) plus a generator
 *  factory, or null when the session has no stored raw. */
export function prepareGeneration(
  session: EngineSession,
  rules: WpRule[],
  opts: { imageProxyBase?: string } = {},
): PreparedGeneration | null {
  const p = prepare(session, rules, opts);
  if (p === null) {
    return null;
  }
  return { total: p.visible.length, batches: (budgetMs) => renderBatches(p, budgetMs) };
}

declare global {
  interface Window {
    chroniclerGenerateBoot?: { apiBase?: string };
    chroniclerSessionEngine?: {
      messagesFor: (
        session: EngineSession,
        rules: WpRule[],
      ) => Record<string, unknown>[] | null;
      prepareGeneration: (
        session: EngineSession,
        rules: WpRule[],
      ) => PreparedGeneration | null;
    };
  }
}

// The persisted image-proxy base — the same root-relative <rest>/image?url=
// form the editor bakes, so the placeholder's collectImageUrls recovers each
// Slack URL to mirror. Read from the localized boot LAZILY (at Generate time),
// since the localized data prints after this script loads as a dependency.
function imageProxyBaseFromBoot(): string | undefined {
  const boot = typeof window !== "undefined" ? window.chroniclerGenerateBoot : undefined;
  return boot && typeof boot.apiBase === "string"
    ? sessionImageProxyBase({ apiBase: boot.apiBase })
    : undefined;
}

if (typeof window !== "undefined") {
  window.chroniclerSessionEngine = {
    messagesFor: (session, rules) =>
      messagesFor(session, rules, { imageProxyBase: imageProxyBaseFromBoot() }),
    prepareGeneration: (session, rules) =>
      prepareGeneration(session, rules, { imageProxyBase: imageProxyBaseFromBoot() }),
  };
}
