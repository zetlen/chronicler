import { createRoot, type Root } from "react-dom/client";
import { getBoot } from "@/components/admin/apiFetch";
import { SessionEditorApp } from "@/components/admin/SessionEditorApp";

/**
 * Mount contract for the wp-admin bundle (#96): the plugin's admin page (#97)
 * prints `<div id="chronicler-admin-root"></div>` and defines
 * `window.chroniclerBoot = { apiBase, nonce }` before this bundle executes
 * (inline `before` script or wp_localize_script). Either half missing is a
 * console error, never a crash — wp-admin keeps working.
 */

export const ROOT_ID = "chronicler-admin-root";

/**
 * Mount the session editor onto #chronicler-admin-root. Returns the React
 * root, or null when the page isn't set up for it (missing root element or
 * missing/malformed window.chroniclerBoot).
 */
export function mountChroniclerAdmin(): Root | null {
  const container = document.getElementById(ROOT_ID);
  if (!container) {
    console.error(
      `Chronicler admin bundle loaded but #${ROOT_ID} is not in the document — is the admin page printing the root div?`,
    );
    return null;
  }
  if (!getBoot()) {
    console.error(
      "Chronicler admin bundle loaded without window.chroniclerBoot = { apiBase, nonce } — the enqueue must define it before the bundle runs.",
    );
    return null;
  }
  const root = createRoot(container);
  root.render(<SessionEditorApp />);
  return root;
}

/** Mount now if the DOM is ready, else on DOMContentLoaded (header enqueue). */
export function mountWhenReady(): void {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => mountChroniclerAdmin(), {
      once: true,
    });
    return;
  }
  mountChroniclerAdmin();
}
