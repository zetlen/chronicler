/**
 * Image-mirror URL plumbing (#96/#103).
 *
 * Transform output routes Slack-hosted images through the plugin's mirror:
 * GET <rest base>/image?url=<encoded slack url> (302 to the media-library
 * copy). Two forms of that endpoint exist here:
 *
 * - The PERSISTED form (`sessionImageProxyBase`) carries no auth and is what
 *   lands in stored messages[] and in the transform's fragment — root-
 *   relative, so it survives http/https and host changes. The #102 drafter
 *   reads the `url` query arg back out of it when mirroring for publish.
 * - The PREVIEW form adds `_wpnonce=<rest nonce>`: the mirror route is
 *   capability-gated cookie auth, and WordPress treats a cookie request
 *   without a nonce as logged-out, so a bare <img> would 401. The rewrite
 *   happens at render time only — nonces expire and must never be stored.
 */

import type { ChroniclerBoot } from "@/components/admin/apiFetch";
import { escapeAttr } from "@/lib/transform/shared";

/** apiBase reduced to a root-relative path (keeps ?rest_route= forms). */
function apiBasePath(apiBase: string): string {
  try {
    const url = new URL(apiBase, window.location.origin);
    // A bare-root path (the ?rest_route= form) keeps its leading slash.
    return `${url.pathname.replace(/\/+$/, "") || "/"}${url.search}`;
  } catch {
    return apiBase.replace(/\/+$/, "");
  }
}

/**
 * The imageProxyBase handed to the transform (RenderContext.imageProxyBase):
 * ends in `url=` so the raw Slack URL is appended encoded. Pretty
 * permalinks: `/wp-json/chronicler/v1/image?url=`; plain permalinks:
 * `/?rest_route=/chronicler/v1/image&url=`.
 */
export function sessionImageProxyBase(boot: ChroniclerBoot): string {
  const base = apiBasePath(boot.apiBase);
  // A querying base is the plain-permalink ?rest_route= form: the endpoint
  // path extends the rest_route value and further args chain with `&`.
  return base.includes("?") ? `${base}/image&url=` : `${base}/image?url=`;
}

/**
 * Rewrite the persisted image endpoint to its nonce-carrying preview form
 * inside a rendered HTML fragment. Operates on the attribute-escaped text
 * (escapeAttr escapes `&`), covering avatars, figures, and block images.
 */
export function authorizePreviewImages(html: string, boot: ChroniclerBoot): string {
  const persisted = sessionImageProxyBase(boot);
  const authorized = persisted.replace(
    /url=$/,
    `_wpnonce=${encodeURIComponent(boot.nonce)}&url=`,
  );
  return html.split(escapeAttr(persisted)).join(escapeAttr(authorized));
}
