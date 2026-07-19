import { useEffect, useState } from "react";
import {
  listSessions,
  SESSIONS_PER_PAGE,
  type SessionLight,
} from "@/components/admin/sessionApi";
import { navigateToSession } from "@/components/admin/sessionRoute";
import { formatRange, formatUtcTimestamp } from "@/components/admin/datetime";
import {
  ERROR_NOTICE_CLS,
  PRIMARY_BUTTON_CLS,
  SMALL_BUTTON_CLS,
} from "@/components/admin/ui";

/**
 * The session editor's home view (#96): stored Sessions in light form
 * (GET /sessions — channel, range, message count, last activity), with Edit
 * per row and "New session" up top. Paged since #164: newest
 * SESSIONS_PER_PAGE first, "Load more" appends the next page. A full page
 * is the "maybe more" signal — the boundary case costs one extra fetch.
 */

type ListState =
  | { kind: "loading" }
  | { kind: "error"; message: string }
  | {
      kind: "ready";
      sessions: SessionLight[];
      hasMore: boolean;
      loadingMore: boolean;
      /** A failed APPEND keeps the loaded rows; only the initial load owns `error`. */
      loadMoreError: string | null;
    };

export function SessionList() {
  const [state, setState] = useState<ListState>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    listSessions(1, controller.signal)
      .then((sessions) => {
        if (!cancelled) {
          setState({
            kind: "ready",
            sessions,
            hasMore: sessions.length === SESSIONS_PER_PAGE,
            loadingMore: false,
            loadMoreError: null,
          });
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setState({
          kind: "error",
          message: err instanceof Error ? err.message : "Couldn't load sessions.",
        });
      });
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, []);

  function loadMore() {
    if (state.kind !== "ready" || state.loadingMore) return;
    const loaded = state.sessions;
    setState({ ...state, loadingMore: true, loadMoreError: null });
    listSessions(Math.floor(loaded.length / SESSIONS_PER_PAGE) + 1)
      .then((next) => {
        // Offset paging drifts when sessions are created/updated between
        // clicks; deduping by id stops the visible symptom (repeated rows).
        // A shifted-out row stays missed until a reload — inherent to
        // offset pagination, acceptable for an admin list.
        const seen = new Set(loaded.map((s) => s.id));
        setState({
          kind: "ready",
          sessions: [...loaded, ...next.filter((s) => !seen.has(s.id))],
          hasMore: next.length === SESSIONS_PER_PAGE,
          loadingMore: false,
          loadMoreError: null,
        });
      })
      .catch((err: unknown) => {
        // A transient blip on an append must not discard rows the user
        // already has; surface it at the button instead.
        setState({
          kind: "ready",
          sessions: loaded,
          hasMore: true,
          loadingMore: false,
          loadMoreError:
            err instanceof Error ? err.message : "Couldn't load more sessions.",
        });
      });
  }

  return (
    <div className="flex max-w-4xl flex-col gap-4">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="font-serif text-2xl tracking-tight">Chronicler sessions</h1>
          <p className="text-xs text-zinc-500">
            A session is a channel and a time range.
          </p>
        </div>
        <button
          type="button"
          className={PRIMARY_BUTTON_CLS}
          onClick={() => navigateToSession("new")}
        >
          New session
        </button>
      </header>

      {state.kind === "loading" && (
        <p className="text-sm text-zinc-500">Loading sessions…</p>
      )}

      {state.kind === "error" && (
        <div className={ERROR_NOTICE_CLS}>
          Couldn&rsquo;t load sessions: {state.message}
        </div>
      )}

      {state.kind === "ready" && state.sessions.length === 0 && (
        <div className="rounded-lg border border-dashed border-zinc-300 bg-white p-8 text-center text-sm text-zinc-500">
          No sessions yet. Create one to turn a channel&rsquo;s messages into
          a transcript.
        </div>
      )}

      {state.kind === "ready" && state.sessions.length > 0 && (
        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500">
                <th className="px-3 py-2 font-semibold">Channel</th>
                <th className="px-3 py-2 font-semibold">Range</th>
                <th className="px-3 py-2 font-semibold">Messages</th>
                <th className="px-3 py-2 font-semibold">Updated</th>
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {state.sessions.map((s) => (
                <tr key={s.id} className="border-b border-zinc-100 last:border-b-0">
                  <td className="px-3 py-2 font-medium">#{s.channel.name || s.channel.id}</td>
                  <td className="px-3 py-2 text-zinc-600">{formatRange(s.start, s.end)}</td>
                  <td className="px-3 py-2 tabular-nums text-zinc-600">{s.messageCount}</td>
                  <td className="px-3 py-2 text-zinc-600">{formatUtcTimestamp(s.updated)}</td>
                  <td className="px-3 py-2 text-right">
                    <button
                      type="button"
                      className={SMALL_BUTTON_CLS}
                      onClick={() => navigateToSession(String(s.id))}
                    >
                      Edit
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {state.kind === "ready" && state.hasMore && (
        <div className="flex flex-col items-center gap-2">
          {state.loadMoreError !== null && (
            <div className={ERROR_NOTICE_CLS}>
              Couldn&rsquo;t load more sessions: {state.loadMoreError}
            </div>
          )}
          <button
            type="button"
            className={SMALL_BUTTON_CLS}
            disabled={state.loadingMore}
            onClick={loadMore}
          >
            {state.loadingMore
              ? "Loading…"
              : state.loadMoreError !== null
                ? "Retry"
                : "Load more"}
          </button>
        </div>
      )}
    </div>
  );
}
