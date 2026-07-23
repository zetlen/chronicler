/**
 * Preview serializer: a ProcessedSession → the transcript HTML fragment the
 * session editor shows. A thin tail on the shared engine — it pulls in
 * `renderConversationFragment` (thread nesting + omitted-reply notes) and
 * nothing block-related, so a bundle that imports only this serializer keeps
 * the block-attribute path (`renderBlocks`/`blockGrammar`) tree-shaken out.
 *
 * Runs client-side — the transform's sanitizer needs a DOM.
 */

import { renderConversationFragment } from "@/lib/transform/renderDocument";
import type { ProcessedSession } from "@/lib/session/SessionProcessor";

export function toPreviewHtml(processed: ProcessedSession): string {
  return renderConversationFragment(processed.threads, processed.ctx);
}
