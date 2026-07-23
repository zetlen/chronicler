/**
 * Block serializer: a ProcessedSession → the flat, visible-only list of
 * chronicler/message block attributes the transcript block stores (and the
 * PHP render callbacks later paint). A thin tail on the shared engine — it
 * pulls in `sessionMessageAttributes` and nothing preview-related, so a bundle
 * that imports only this serializer keeps `renderDocument` tree-shaken out.
 *
 * This is the output the block editor bakes into a post at generation time,
 * with the current rules applied — the same rule pass the preview used.
 *
 * Runs client-side — the transform's sanitizer needs a DOM.
 */

import { sessionMessageAttributes } from "@/lib/wordpress/renderBlocks";
import type { ProcessedSession } from "@/lib/session/SessionProcessor";

export function toMessageAttributes(
  processed: ProcessedSession,
): Record<string, unknown>[] {
  return sessionMessageAttributes(processed.threads, processed.ctx);
}
