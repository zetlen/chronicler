/**
 * Query-arg routing for the session editor (#96): the `session` query
 * parameter on the wp-admin page URL selects the view — absent for the
 * session list, "new" for the create form, a numeric id for the editor — so
 * sessions deep-link (`admin.php?page=chronicler&session=12`; the
 * `?chronicler_session` post seed flow will build these URLs too).
 *
 * A useSyncExternalStore-shaped store, matching lib/navView.ts et al. The
 * snapshot is a primitive read straight off location.search (no caching
 * needed); popstate and programmatic navigation notify subscribers. Every
 * other query arg (page=chronicler, etc.) is preserved.
 */

export const SESSION_PARAM = "session";

type Listener = () => void;
const listeners = new Set<Listener>();

function emit(): void {
  for (const listener of listeners) listener();
}

export function subscribeSessionRoute(listener: Listener): () => void {
  if (listeners.size === 0) {
    window.addEventListener("popstate", emit);
  }
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0) {
      window.removeEventListener("popstate", emit);
    }
  };
}

/** The current `session` query-arg value, or null (the list view). */
export function getSessionRouteSnapshot(): string | null {
  return new URLSearchParams(window.location.search).get(SESSION_PARAM);
}

/** SSR never happens in wp-admin, but the store contract wants a form. */
export function getServerSessionRouteSnapshot(): string | null {
  return null;
}

/**
 * Navigate to a view: null → the list, "new" → the create form, an id → the
 * editor. Pushes history (back button walks the views) and preserves the
 * page's other query args.
 */
export function navigateToSession(value: string | null): void {
  const url = new URL(window.location.href);
  if (value === null) {
    url.searchParams.delete(SESSION_PARAM);
  } else {
    url.searchParams.set(SESSION_PARAM, value);
  }
  window.history.pushState(null, "", url);
  emit();
}
