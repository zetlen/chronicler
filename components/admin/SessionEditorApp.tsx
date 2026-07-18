import { useSyncExternalStore } from "react";
import {
  getServerSessionRouteSnapshot,
  getSessionRouteSnapshot,
  subscribeSessionRoute,
} from "@/components/admin/sessionRoute";
import { SessionList } from "@/components/admin/SessionList";
import { SessionCreate } from "@/components/admin/SessionCreate";
import { SessionEditor } from "@/components/admin/SessionEditor";

/**
 * The wp-admin session editor (#96), complete: a Session is
 * {integration: slack, channel, start, end, attached rules, editor state,
 * transformed messages}, and this app owns its whole lifecycle in the
 * browser — list, create (channel picker + validated time range), fetch
 * through the stateless Slack proxy (#99), transform via lib/transform,
 * save through the sessions routes (#101), refresh, and the draft handoff.
 *
 * Views ride the `session` query arg (sessionRoute.ts): absent → the list,
 * "new" → the create form, an id → the editor, so every view deep-links and
 * the browser's back button walks the history.
 */
export function SessionEditorApp() {
  const route = useSyncExternalStore(
    subscribeSessionRoute,
    getSessionRouteSnapshot,
    getServerSessionRouteSnapshot,
  );

  let view: React.ReactNode;
  if (route === null) {
    view = <SessionList />;
  } else if (route === "new") {
    view = <SessionCreate />;
  } else {
    const id = Number(route);
    // key remounts the editor per session so no state bleeds across ids.
    view =
      Number.isInteger(id) && id > 0 ? (
        <SessionEditor key={route} sessionId={id} />
      ) : (
        <SessionList />
      );
  }

  return <div className="flex flex-col gap-4 py-4 pr-4 text-zinc-900">{view}</div>;
}
