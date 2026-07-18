import { useEffect, useMemo, useState } from "react";
import type { ChannelSummary } from "@/lib/transform/slackTypes";
import { Group } from "@/components/Group";
import { createSlackCall, fetchAllChannels } from "@/components/admin/slackFetch";
import {
  createSession,
  getSettings,
  DEFAULT_SESSION_CONTROLS,
  type ChannelDefault,
} from "@/components/admin/sessionApi";
import { navigateToSession } from "@/components/admin/sessionRoute";
import {
  toLocalInputValue,
  validateSessionRange,
} from "@/components/admin/datetime";
import {
  ERROR_NOTICE_CLS,
  FIELD_CLS,
  LABEL_CLS,
  PRIMARY_BUTTON_CLS,
  SMALL_BUTTON_CLS,
  WARN_NOTICE_CLS,
} from "@/components/admin/ui";

/**
 * The create-session form (#96): integration (slack), a channel picked from
 * the proxied conversations.list (cursor pagination, private channels
 * flagged), and a datetime-local start/end pair whose validation gates the
 * whole flow — non-empty, parseable, start < end (the recorded #96 gap).
 *
 * On create: channel defaults from GET /settings seed editorState/rule_ids,
 * POST /sessions stores the draft, and navigation hands off to the editor,
 * which auto-runs the first fetch for an empty session.
 */

type ChannelsState =
  | { kind: "loading"; waitSeconds: number | null }
  | { kind: "ready"; channels: ChannelSummary[] }
  | { kind: "error"; message: string };

export function SessionCreate() {
  const [channelsState, setChannelsState] = useState<ChannelsState>({
    kind: "loading",
    waitSeconds: null,
  });
  const [channelId, setChannelId] = useState("");
  const [start, setStart] = useState(() =>
    toLocalInputValue(new Date(Date.now() - 24 * 60 * 60 * 1000)),
  );
  const [end, setEnd] = useState(() => toLocalInputValue(new Date()));
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    const call = createSlackCall({
      signal: controller.signal,
      onWait: (seconds) => {
        if (!cancelled) setChannelsState({ kind: "loading", waitSeconds: seconds });
      },
    });
    fetchAllChannels(call)
      .then((channels) => {
        if (!cancelled) setChannelsState({ kind: "ready", channels });
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setChannelsState({
          kind: "error",
          message:
            err instanceof Error ? err.message : "Couldn't load the channel list.",
        });
      });
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, []);

  const channels = channelsState.kind === "ready" ? channelsState.channels : [];
  const range = useMemo(() => validateSessionRange(start, end), [start, end]);
  const canCreate = channelId !== "" && range.ok && !creating;

  async function handleCreate() {
    const channel = channels.find((c) => c.id === channelId);
    if (!channel || !range.ok || creating) return;
    setCreating(true);
    setCreateError(null);
    try {
      // Channel defaults seed the new session's editor state + rules.
      const settings = await getSettings();
      const defaults: ChannelDefault | undefined =
        settings.channelDefaults[channel.id] ?? undefined;
      const session = await createSession({
        integration: "slack",
        channel: { id: channel.id, name: channel.name },
        start: range.startIso,
        end: range.endIso,
        rule_ids: defaults?.rule_ids ?? [],
        editorState: {
          userOverrides: defaults?.userOverrides ?? {},
          scheme: defaults?.scheme ?? "light",
          customCss: defaults?.customCss ?? "",
          controls: { ...DEFAULT_SESSION_CONTROLS, ...defaults?.controls },
        },
      });
      navigateToSession(String(session.id));
    } catch (err) {
      setCreateError(err instanceof Error ? err.message : "Couldn't create the session.");
      setCreating(false);
    }
  }

  return (
    <div className="flex max-w-xl flex-col gap-4">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="font-serif text-2xl tracking-tight">New session</h1>
          <p className="text-xs text-zinc-500">
            Pick a channel and a time range.
          </p>
        </div>
        <button
          type="button"
          className={SMALL_BUTTON_CLS}
          onClick={() => navigateToSession(null)}
        >
          ← All sessions
        </button>
      </header>

      <form
        className="flex flex-col gap-5 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm"
        onSubmit={(e) => {
          e.preventDefault();
          void handleCreate();
        }}
      >
        <Group title="Source">
          <div>
            <label className={LABEL_CLS} htmlFor="chronicler-integration">
              Integration
            </label>
            <select
              id="chronicler-integration"
              className={FIELD_CLS}
              value="slack"
              disabled
            >
              <option value="slack">Slack</option>
            </select>
          </div>

          <div>
            <label className={LABEL_CLS} htmlFor="chronicler-channel">
              Channel
            </label>
            <select
              id="chronicler-channel"
              className={FIELD_CLS}
              value={channelId}
              onChange={(e) => setChannelId(e.target.value)}
              disabled={channelsState.kind !== "ready" || channels.length === 0}
            >
              <option value="">
                {channelsState.kind === "loading"
                  ? channelsState.waitSeconds !== null
                    ? `Waiting on Slack — retrying in ${channelsState.waitSeconds}s…`
                    : "Loading channels…"
                  : channelsState.kind === "ready" && channels.length > 0
                    ? "Select a channel…"
                    : "Channel list unavailable"}
              </option>
              {channels.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.isPrivate ? "🔒 " : "# "}
                  {c.name}
                </option>
              ))}
            </select>
            {channelsState.kind === "error" && (
              <p className="mt-1 text-xs text-red-600">
                Couldn&rsquo;t load channels: {channelsState.message}
              </p>
            )}
          </div>
        </Group>

        <Group title="Time range">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={LABEL_CLS} htmlFor="chronicler-start">
                Start
              </label>
              <input
                id="chronicler-start"
                type="datetime-local"
                className={FIELD_CLS}
                value={start}
                onChange={(e) => setStart(e.target.value)}
              />
            </div>
            <div>
              <label className={LABEL_CLS} htmlFor="chronicler-end">
                End
              </label>
              <input
                id="chronicler-end"
                type="datetime-local"
                className={FIELD_CLS}
                value={end}
                onChange={(e) => setEnd(e.target.value)}
              />
            </div>
          </div>
          <p className="text-xs text-zinc-400">
            Times are in your local timezone.
          </p>
          {!range.ok && <div className={WARN_NOTICE_CLS}>{range.error}</div>}
        </Group>

        {createError && <div className={ERROR_NOTICE_CLS}>{createError}</div>}

        <button
          type="submit"
          disabled={!canCreate}
          className={`w-full ${PRIMARY_BUTTON_CLS}`}
        >
          {creating ? "Creating session…" : "Create session & fetch messages"}
        </button>
      </form>
    </div>
  );
}
