import { useEffect, useMemo, useRef, useState } from "react";
import type { MessageKind, RenderContext, TranscriptScheme } from "@/lib/transform/types";
import type { UserOverride } from "@/lib/transform/directory";
import { createDirectory } from "@/lib/transform/directory";
import { assignDistinctColors, colorForName } from "@/lib/transform/color";
import { renderConversationFragment } from "@/lib/transform/renderDocument";
import { dialogueCss, customSchemeTemplate } from "@/lib/transform/styles";
import { sanitizeCss } from "@/lib/transform/sanitize";
import {
  applyRules,
  EMPTY_RULE_OUTCOME,
  type RegexRule,
} from "@/lib/transform/rules";
import { sessionMessageAttributes } from "@/lib/wordpress/renderBlocks";
import { Group } from "@/components/Group";
import { CustomCssEditor } from "@/components/CustomCssEditor";
import { getBoot } from "@/components/admin/apiFetch";
import {
  getSession,
  listRules,
  putSession,
  DEFAULT_SESSION_CONTROLS,
  type SessionChannel,
  type SessionControls,
  type SessionEditorState,
  type SessionFull,
  type SessionPatch,
  type WpRule,
} from "@/components/admin/sessionApi";
import {
  fetchSessionData,
  isAbortError,
  type FetchProgress,
  type SessionRawData,
} from "@/components/admin/slackFetch";
import { navigateToSession } from "@/components/admin/sessionRoute";
import { RulesPanel } from "@/components/admin/RulesPanel";
import { composeSavedFragment } from "@/components/admin/savedMessages";
import {
  authorizePreviewImages,
  sessionImageProxyBase,
} from "@/components/admin/imageUrls";
import {
  isoToLocalInputValue,
  validateSessionRange,
} from "@/components/admin/datetime";
import {
  ERROR_NOTICE_CLS,
  FIELD_CLS,
  LABEL_CLS,
  PaneShell,
  PRIMARY_BUTTON_CLS,
  SMALL_BUTTON_CLS,
  Toggle,
  WARN_NOTICE_CLS,
} from "@/components/admin/ui";

/**
 * The session editor proper (#96): loads a stored Session, renders its saved
 * messages immediately, and owns the whole fetch → transform → save cycle:
 *
 * - Fetch/Refresh runs the browser-side pipeline (slackFetch.ts) against the
 *   stateless proxy, with live progress, a visible 429 countdown, and a
 *   Cancel that aborts mid-flight. An empty session fetches automatically
 *   (the create flow lands here).
 * - The transform (lib/transform, exactly as LogFormatterApp wires it) feeds
 *   BOTH the preview and the stored messages[]: whenever fetched data exists,
 *   the derived block-attribute payload is PUT back (debounced), so the
 *   saved session always matches what the preview shows. Refresh therefore
 *   REPLACES messages wholesale; posts/blocks are never touched from here.
 * - editorState (overrides, scheme, custom CSS, display toggles) and
 *   rule_ids persist on the same debounced PUT.
 */

const SAVE_DEBOUNCE_MS = 1_000;

type LoadState =
  | { kind: "loading" }
  | { kind: "error"; message: string }
  | { kind: "ready" };

type FetchUiState =
  | { kind: "idle" }
  | { kind: "running"; progress: FetchProgress }
  | { kind: "error"; message: string };

type SaveState = "idle" | "saving" | "error";

interface PresentUser {
  id: string;
  /** Real Slack name — drives the identity color (stable across renames). */
  name: string;
  /** What the transcript shows by default: character name, else Slack name. */
  displayName: string;
}

function collectPresentUsers(data: SessionRawData): PresentUser[] {
  const ids = new Set<string>();
  for (const thread of data.threads) {
    for (const m of [thread.parent, ...thread.replies]) {
      if (m.user) ids.add(m.user);
    }
  }
  return [...ids]
    .map((id) => ({
      id,
      name: data.names.users[id] ?? id,
      displayName: data.names.characters?.[id] || data.names.users[id] || id,
    }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

const isCustomScheme = (scheme: TranscriptScheme): boolean =>
  scheme === "custom-light" || scheme === "custom-dark";

function hiddenKindsFor(controls: SessionControls): Set<MessageKind> {
  const hidden = new Set<MessageKind>();
  if (controls.hideSystem) hidden.add("system");
  if (controls.hideBots) {
    hidden.add("bot_message");
    hidden.add("bot_reply");
  }
  return hidden;
}

function toRegexRule(rule: WpRule): RegexRule {
  return {
    id: String(rule.id),
    pattern: rule.pattern,
    flags: rule.flags,
    mode: rule.mode,
    className: rule.className,
    tagNames: rule.tagNames,
    enabled: true,
  };
}

const STEP_LABELS: Record<FetchProgress["step"], string> = {
  channels: "Listing channels",
  history: "Fetching messages",
  lookback: "Scanning for threads that started earlier",
  threads: "Loading thread replies",
  directory: "Getting author names",
  emoji: "Loading custom emoji",
};

export function SessionEditor({ sessionId }: { sessionId: number }) {
  const boot = useMemo(() => getBoot(), []);
  const imageProxyBase = useMemo(
    () => (boot ? sessionImageProxyBase(boot) : undefined),
    [boot],
  );

  const [load, setLoad] = useState<LoadState>({ kind: "loading" });
  const [session, setSession] = useState<SessionFull | null>(null);
  const [allRules, setAllRules] = useState<WpRule[] | null>(null);

  // Editable session state (adopted from the stored Session on load).
  const [ruleIds, setRuleIds] = useState<number[]>([]);
  const [userOverrides, setUserOverrides] = useState<Record<string, UserOverride>>({});
  const [scheme, setScheme] = useState<TranscriptScheme>("light");
  const [customCss, setCustomCss] = useState("");
  const [controls, setControls] = useState<SessionControls>(DEFAULT_SESSION_CONTROLS);
  const [start, setStart] = useState("");
  const [end, setEnd] = useState("");

  // In-memory fetch results — never persisted raw (Ground rule 1).
  const [rawData, setRawData] = useState<SessionRawData | null>(null);
  const [fetchState, setFetchState] = useState<FetchUiState>({ kind: "idle" });
  const [saveState, setSaveState] = useState<SaveState>("idle");

  /* ------------------------------------------------------------------ *
   * Debounced PUT machinery (usePresetSync's pattern, session-shaped)
   * ------------------------------------------------------------------ */

  const mountedRef = useRef(true);
  const pendingRef = useRef<SessionPatch>({});
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  async function flushSave() {
    const patch = pendingRef.current;
    if (Object.keys(patch).length === 0) return;
    pendingRef.current = {};
    if (mountedRef.current) setSaveState("saving");
    try {
      const saved = await putSession(sessionId, patch);
      if (!mountedRef.current) return;
      // The header count comes from the stored session; adopt the PUT
      // response so the first fetch's save shows without a reload (#124).
      // Same-count saves keep the previous object to spare the fragment memo.
      setSession((prev) =>
        prev && prev.messageCount !== saved.messageCount
          ? { ...prev, messageCount: saved.messageCount }
          : prev,
      );
      if (Object.keys(pendingRef.current).length > 0) {
        queueSave({}); // edits landed mid-flight; another pass
      } else {
        setSaveState("idle");
      }
    } catch {
      // Newer edits win over the failed patch; the next edit retries.
      pendingRef.current = { ...patch, ...pendingRef.current };
      if (mountedRef.current) setSaveState("error");
    }
  }

  function queueSave(patch: SessionPatch) {
    Object.assign(pendingRef.current, patch);
    clearTimeout(saveTimerRef.current);
    saveTimerRef.current = setTimeout(() => {
      saveTimerRef.current = undefined;
      void flushSave();
    }, SAVE_DEBOUNCE_MS);
  }

  // Latest-closure refs for the load effect / unmount flush.
  const queueSaveRef = useRef(queueSave);
  const flushSaveRef = useRef(flushSave);
  const startFetchRef = useRef<typeof startFetch | null>(null);
  const userOverridesRef = useRef(userOverrides);
  useEffect(() => {
    queueSaveRef.current = queueSave;
    flushSaveRef.current = flushSave;
    startFetchRef.current = startFetch;
    userOverridesRef.current = userOverrides;
  });

  /* ------------------------------------------------------------------ *
   * Fetch pipeline
   * ------------------------------------------------------------------ */

  const fetchAbortRef = useRef<AbortController | null>(null);

  async function startFetch(params: {
    channel: SessionChannel;
    startLocal: string;
    endLocal: string;
    includeReplies: boolean;
  }) {
    const range = validateSessionRange(params.startLocal, params.endLocal);
    if (!range.ok) {
      setFetchState({ kind: "error", message: range.error });
      return;
    }
    fetchAbortRef.current?.abort();
    const controller = new AbortController();
    fetchAbortRef.current = controller;
    setFetchState({
      kind: "running",
      progress: {
        step: "history",
        pagesFetched: 0,
        messagesFetched: 0,
        threadsResolved: 0,
        usersResolved: 0,
        usersTotal: 0,
        waitSeconds: null,
      },
    });
    try {
      const data = await fetchSessionData(
        {
          channelId: params.channel.id,
          oldest: range.startMs / 1000,
          latest: range.endMs / 1000,
          includeReplies: params.includeReplies,
          channelNames: { [params.channel.id]: params.channel.name },
        },
        {
          signal: controller.signal,
          onProgress: (progress) => {
            if (!controller.signal.aborted && mountedRef.current) {
              setFetchState({ kind: "running", progress });
            }
          },
        },
      );
      if (controller.signal.aborted || !mountedRef.current) return;
      setRawData(data);
      autoAssignColors(data);
      setFetchState({ kind: "idle" });
      // The range that actually ran is what the session means now.
      queueSaveRef.current({ start: range.startIso, end: range.endIso });
    } catch (err) {
      if (!mountedRef.current) return;
      if (isAbortError(err)) {
        setFetchState({ kind: "idle" });
        return;
      }
      setFetchState({
        kind: "error",
        message: err instanceof Error ? err.message : "Fetching messages failed.",
      });
    }
  }

  // First sight of the cast pins distinct identity colors (write-once, same
  // as LogFormatterApp): only users without a color get one.
  function autoAssignColors(data: SessionRawData) {
    const assigned = assignDistinctColors(
      collectPresentUsers(data),
      userOverridesRef.current,
    );
    if (Object.keys(assigned).length === 0) return;
    setUserOverrides((prev) => {
      const next = { ...prev };
      for (const [id, color] of Object.entries(assigned)) {
        if (!next[id]?.color) next[id] = { ...next[id], color };
      }
      return next;
    });
  }

  /* ------------------------------------------------------------------ *
   * Load + adopt
   * ------------------------------------------------------------------ */

  const editorStateBaselineRef = useRef<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    Promise.all([getSession(sessionId, controller.signal), listRules(controller.signal)])
      .then(([loaded, rules]) => {
        if (cancelled) return;
        setSession(loaded);
        setAllRules(rules);
        setRuleIds(loaded.rule_ids);
        const es: SessionEditorState = loaded.editorState ?? {};
        const adoptedControls = { ...DEFAULT_SESSION_CONTROLS, ...es.controls };
        setUserOverrides(es.userOverrides ?? {});
        setScheme(es.scheme ?? "light");
        setCustomCss(es.customCss ?? "");
        setControls(adoptedControls);
        const startLocal = isoToLocalInputValue(loaded.start);
        const endLocal = isoToLocalInputValue(loaded.end);
        setStart(startLocal);
        setEnd(endLocal);
        editorStateBaselineRef.current = JSON.stringify({
          userOverrides: es.userOverrides ?? {},
          scheme: es.scheme ?? "light",
          customCss: es.customCss ?? "",
          controls: adoptedControls,
        } satisfies SessionEditorState);
        setLoad({ kind: "ready" });
        // A session without messages (fresh from the create flow) runs its
        // first fetch automatically.
        if (loaded.messages.length === 0) {
          void startFetchRef.current?.({
            channel: loaded.channel,
            startLocal,
            endLocal,
            includeReplies: adoptedControls.includeReplies,
          });
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        setLoad({
          kind: "error",
          message: err instanceof Error ? err.message : "Couldn't load the session.",
        });
      });
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [sessionId]);

  // Unmount: flush a pending save, abort a running fetch.
  useEffect(() => {
    return () => {
      mountedRef.current = false;
      fetchAbortRef.current?.abort();
      if (saveTimerRef.current !== undefined) {
        clearTimeout(saveTimerRef.current);
        void flushSaveRef.current();
      }
    };
  }, []);

  /* ------------------------------------------------------------------ *
   * Transform: preview + messages payload from one context
   * ------------------------------------------------------------------ */

  const regexRules = useMemo<RegexRule[]>(() => {
    if (!allRules) return [];
    const byId = new Map(allRules.map((r) => [r.id, r]));
    return ruleIds.flatMap((id) => {
      const rule = byId.get(id);
      return rule ? [toRegexRule(rule)] : [];
    });
  }, [allRules, ruleIds]);

  const ruleOutcome = useMemo(
    () => (rawData ? applyRules(rawData.threads, regexRules) : EMPTY_RULE_OUTCOME),
    [rawData, regexRules],
  );

  const renderCtx = useMemo<RenderContext | null>(() => {
    if (!rawData) return null;
    return {
      directory: createDirectory(rawData.names, userOverrides),
      imageMode: "proxy",
      imageProxyBase,
      density: "comfortable",
      scheme,
      showAvatars: controls.showAvatars,
      showTimestamps: controls.showTimestamps,
      showReactions: controls.showReactions,
      hiddenKinds: hiddenKindsFor(controls),
      ruleEffects: ruleOutcome.effects,
      customEmoji: rawData.customEmoji ?? {},
    };
  }, [rawData, userOverrides, imageProxyBase, scheme, controls, ruleOutcome]);

  // Fetched data renders live; a reopened session renders its saved
  // messages until the next refresh.
  const fragment = useMemo(() => {
    if (rawData && renderCtx) {
      return renderConversationFragment(rawData.threads, renderCtx);
    }
    if (session && session.messages.length > 0) {
      return composeSavedFragment(session.messages, scheme);
    }
    return null;
  }, [rawData, renderCtx, session, scheme]);

  // The persisted (nonce-free) image endpoints get their preview auth here.
  const previewHtml = useMemo(
    () => (fragment && boot ? authorizePreviewImages(fragment, boot) : fragment),
    [fragment, boot],
  );

  const messagesPayload = useMemo(
    () =>
      rawData && renderCtx ? sessionMessageAttributes(rawData.threads, renderCtx) : null,
    [rawData, renderCtx],
  );

  // Persist the transform output whenever it changes (fetch completion,
  // rule/override/toggle edits over live data) — messages are REPLACED.
  const lastMessagesRef = useRef<string | null>(null);
  useEffect(() => {
    if (!messagesPayload) return;
    const serialized = JSON.stringify(messagesPayload);
    if (serialized === lastMessagesRef.current) return;
    lastMessagesRef.current = serialized;
    queueSaveRef.current({ messages: messagesPayload });
  }, [messagesPayload]);

  // Persist editorState (debounced) once it drifts from the adopted baseline.
  const editorState = useMemo<SessionEditorState>(
    () => ({ userOverrides, scheme, customCss, controls }),
    [userOverrides, scheme, customCss, controls],
  );
  useEffect(() => {
    if (editorStateBaselineRef.current === null) return; // not adopted yet
    const serialized = JSON.stringify(editorState);
    if (serialized === editorStateBaselineRef.current) return;
    editorStateBaselineRef.current = serialized;
    queueSaveRef.current({ editorState });
  }, [editorState]);

  /* ------------------------------------------------------------------ *
   * Handlers
   * ------------------------------------------------------------------ */

  const presentUsers = useMemo<PresentUser[]>(
    () => (rawData ? collectPresentUsers(rawData) : []),
    [rawData],
  );

  function handleRefresh() {
    if (!session) return;
    void startFetch({
      channel: session.channel,
      startLocal: start,
      endLocal: end,
      includeReplies: controls.includeReplies,
    });
  }

  function handleCancelFetch() {
    fetchAbortRef.current?.abort();
  }

  function updateControl<K extends keyof SessionControls>(
    key: K,
    value: SessionControls[K],
  ) {
    setControls((c) => ({ ...c, [key]: value }));
  }

  // Include-replies is fetch-time: with data on screen, toggling re-fetches
  // (today's UX in LogFormatterApp).
  function setIncludeReplies(value: boolean) {
    updateControl("includeReplies", value);
    if (rawData && session) {
      void startFetch({
        channel: session.channel,
        startLocal: start,
        endLocal: end,
        includeReplies: value,
      });
    }
  }

  function setUserOverride(id: string, patch: Partial<UserOverride>) {
    setUserOverrides((prev) => ({ ...prev, [id]: { ...prev[id], ...patch } }));
  }

  function updateScheme(value: TranscriptScheme) {
    setScheme(value);
    if (!isCustomScheme(value)) return;
    // Seed an empty editor, and keep a *pristine* template in step with the
    // scheme — picking Custom (dark) over an untouched light template must
    // yield dark styles, not stale light ones (#92). CSS the user has
    // edited is never replaced ("Clear custom CSS" regenerates on demand).
    const current = customCss.trim();
    const pristine =
      current === "" ||
      current === customSchemeTemplate(false).trim() ||
      current === customSchemeTemplate(true).trim();
    if (pristine) {
      setCustomCss(customSchemeTemplate(value === "custom-dark"));
    }
  }

  function attachRule(id: number) {
    setRuleIds((prev) => {
      if (prev.includes(id)) return prev;
      const next = [...prev, id];
      queueSaveRef.current({ rule_ids: next });
      return next;
    });
  }

  function detachRule(id: number) {
    setRuleIds((prev) => {
      const next = prev.filter((x) => x !== id);
      queueSaveRef.current({ rule_ids: next });
      return next;
    });
  }

  function handleRuleCreated(rule: WpRule) {
    setAllRules((prev) => (prev ? [...prev, rule] : [rule]));
    attachRule(rule.id);
  }

  /* ------------------------------------------------------------------ *
   * Render
   * ------------------------------------------------------------------ */

  if (load.kind === "loading") {
    return <p className="text-sm text-zinc-500">Loading session…</p>;
  }
  if (load.kind === "error" || !session) {
    return (
      <div className="flex max-w-xl flex-col gap-3">
        <div className={ERROR_NOTICE_CLS}>
          {load.kind === "error" ? load.message : "No such session."}
        </div>
        <button
          type="button"
          className={SMALL_BUTTON_CLS}
          onClick={() => navigateToSession(null)}
        >
          ← All sessions
        </button>
      </div>
    );
  }

  const range = validateSessionRange(start, end);
  const fetching = fetchState.kind === "running";
  // Sanitized at the injection point only: the editing buffer (and the saved
  // value) stay verbatim, but what lands inside the preview's <style> element
  // can never close it. The plugin applies the same guard at publish render.
  const activeCustomCss = isCustomScheme(scheme) ? sanitizeCss(customCss) : "";
  const darkBackdrop = scheme === "dark" || scheme === "custom-dark";
  const draftUrlTemplate = boot?.draftSessionUrlTemplate;
  const draftUrl =
    typeof draftUrlTemplate === "string" && draftUrlTemplate.includes("%d")
      ? draftUrlTemplate.replace("%d", String(session.id))
      : null;

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="font-serif text-2xl tracking-tight">
            #{session.channel.name || session.channel.id}
          </h1>
          <p className="text-xs text-zinc-500">
            Session {session.id} · saved messages: {session.messageCount}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {saveState === "saving" && (
            <span className="text-xs font-medium text-zinc-400">Saving…</span>
          )}
          {saveState === "error" && (
            <span
              className="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"
              title="A change didn't save — it'll retry on your next edit."
            >
              Unsaved
            </span>
          )}
          {draftUrl && (
            <a href={draftUrl} className={SMALL_BUTTON_CLS}>
              Draft this session
            </a>
          )}
          <button
            type="button"
            className={SMALL_BUTTON_CLS}
            onClick={() => navigateToSession(null)}
          >
            ← All sessions
          </button>
        </div>
      </header>

      {fetchState.kind === "error" && (
        <div className={ERROR_NOTICE_CLS}>{fetchState.message}</div>
      )}

      {rawData && rawData.unresolvedNames > 0 && (
        <div className={WARN_NOTICE_CLS}>
          Couldn&rsquo;t get {rawData.unresolvedNames} author name
          {rawData.unresolvedNames === 1 ? "" : "s"} from Slack — try
          refreshing in a minute.
        </div>
      )}

      {rawData?.truncated && (
        <div className={WARN_NOTICE_CLS}>
          Too many messages in this range — the oldest were left out. Narrow
          the range to capture everything.
        </div>
      )}

      <main className="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_3fr] lg:items-start">
        <div className="order-2 flex flex-col gap-4 lg:order-1">
          <PaneShell title="Fetch">
            <div className="space-y-3 p-4">
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
              {!range.ok && <div className={WARN_NOTICE_CLS}>{range.error}</div>}

              {fetchState.kind === "running" ? (
                <FetchProgressPanel
                  progress={fetchState.progress}
                  onCancel={handleCancelFetch}
                />
              ) : (
                <button
                  type="button"
                  className={`w-full ${PRIMARY_BUTTON_CLS}`}
                  disabled={!range.ok || fetching}
                  onClick={handleRefresh}
                >
                  {session.messageCount > 0 || rawData
                    ? "Refresh messages"
                    : "Fetch messages"}
                </button>
              )}
              <p className="text-xs text-zinc-400">
                Refreshing replaces this session&rsquo;s messages with the
                latest from Slack.
              </p>
              <Toggle
                label="Include thread replies"
                hint="refreshes messages"
                checked={controls.includeReplies}
                onChange={setIncludeReplies}
              />
            </div>
          </PaneShell>

          <PaneShell title="Options">
            <div className="space-y-5 p-4">
              <Group title="Filters">
                <Toggle
                  label="Hide join/leave & system events"
                  checked={controls.hideSystem}
                  onChange={(v) => updateControl("hideSystem", v)}
                />
                <Toggle
                  label="Hide bot messages"
                  checked={controls.hideBots}
                  onChange={(v) => updateControl("hideBots", v)}
                />
              </Group>

              <RulesPanel
                allRules={allRules}
                attachedIds={ruleIds}
                matchCounts={ruleOutcome.matchCounts}
                hasData={!!rawData}
                onAttach={attachRule}
                onDetach={detachRule}
                onCreated={handleRuleCreated}
              />

              <Group title="Display">
                <Toggle
                  label="Show avatars"
                  checked={controls.showAvatars}
                  onChange={(v) => updateControl("showAvatars", v)}
                />
                <Toggle
                  label="Show timestamps"
                  checked={controls.showTimestamps}
                  onChange={(v) => updateControl("showTimestamps", v)}
                />
                <Toggle
                  label="Show reactions"
                  checked={controls.showReactions}
                  onChange={(v) => updateControl("showReactions", v)}
                />
              </Group>

              <Group title="Color scheme">
                <select
                  className={FIELD_CLS}
                  value={scheme}
                  onChange={(e) => updateScheme(e.target.value as TranscriptScheme)}
                  aria-label="Transcript color scheme"
                >
                  <option value="light">Light mode</option>
                  <option value="dark">Dark mode</option>
                  <option value="custom-light">Custom (light)</option>
                  <option value="custom-dark">Custom (dark)</option>
                </select>
              </Group>

              {isCustomScheme(scheme) && (
                <CustomCssEditor
                  value={customCss}
                  onChange={setCustomCss}
                  template={customSchemeTemplate(scheme === "custom-dark")}
                />
              )}

              <UserList
                users={presentUsers}
                userOverrides={userOverrides}
                onUserOverride={setUserOverride}
              />

              {(fetchState.kind !== "running" && !rawData) && (
                <p className="text-xs text-zinc-400">
                  Changes are saved as you make them; the preview shows them
                  after the next refresh.
                </p>
              )}
            </div>
          </PaneShell>
        </div>

        <PaneShell title="Preview" className="order-1 lg:order-2 lg:min-h-[calc(100vh-8rem)]">
          <div
            data-testid="chronicler-preview"
            className={`min-h-0 flex-1 overflow-auto p-3 ${
              darkBackdrop ? "bg-[#1a1d21]" : "bg-white"
            }`}
          >
            {/* dialogueCss is scoped under .slack-log; the custom block comes
                after so its equal-specificity rules win. */}
            <style dangerouslySetInnerHTML={{ __html: dialogueCss }} />
            {activeCustomCss.trim() && (
              <style dangerouslySetInnerHTML={{ __html: activeCustomCss }} />
            )}
            {previewHtml ? (
              <div dangerouslySetInnerHTML={{ __html: previewHtml }} />
            ) : (
              <div className="flex h-full items-center justify-center text-center text-sm text-zinc-400">
                {fetching ? "Fetching messages…" : "Fetch messages to see a preview."}
              </div>
            )}
          </div>
        </PaneShell>
      </main>
    </div>
  );
}

function FetchProgressPanel({
  progress,
  onCancel,
}: {
  progress: FetchProgress;
  onCancel: () => void;
}) {
  return (
    <div
      className="space-y-2 rounded-md border border-zinc-200 bg-zinc-50 p-3"
      role="status"
    >
      <p className="text-sm font-medium text-zinc-700">
        {STEP_LABELS[progress.step]}…
      </p>
      <p className="text-xs tabular-nums text-zinc-500">
        {progress.messagesFetched} message{progress.messagesFetched === 1 ? "" : "s"}
        {" · "}
        {progress.threadsResolved} thread{progress.threadsResolved === 1 ? "" : "s"}
        {progress.usersTotal > 0 &&
          ` · ${progress.usersResolved}/${progress.usersTotal} names`}
      </p>
      {progress.waitSeconds !== null && (
        <p className="text-xs font-medium text-amber-700">
          Waiting on Slack — resuming in {progress.waitSeconds}s…
        </p>
      )}
      <button type="button" className={SMALL_BUTTON_CLS} onClick={onCancel}>
        Cancel
      </button>
    </div>
  );
}

function UserList({
  users,
  userOverrides,
  onUserOverride,
}: {
  users: PresentUser[];
  userOverrides: Record<string, UserOverride>;
  onUserOverride: (id: string, patch: Partial<UserOverride>) => void;
}) {
  const [query, setQuery] = useState("");

  if (users.length === 0) {
    return (
      <Group title="Users">
        <p className="text-xs text-zinc-400">
          Fetch messages to rename &amp; recolor the people in this session.
        </p>
      </Group>
    );
  }

  const filtered = query.trim()
    ? users.filter((u) => u.displayName.toLowerCase().includes(query.trim().toLowerCase()))
    : users;

  return (
    <Group title={`Users (${users.length})`}>
      <input
        type="search"
        placeholder="Filter users…"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        className={`${FIELD_CLS} mb-2`}
      />
      <div className="max-h-56 space-y-1.5 overflow-auto pr-1">
        {filtered.map((u) => {
          const override = userOverrides[u.id] ?? {};
          const color = override.color ?? colorForName(u.name);
          return (
            <div key={u.id} className="flex items-center gap-2">
              <input
                type="color"
                value={color}
                onChange={(e) => onUserOverride(u.id, { color: e.target.value })}
                className="h-7 w-7 shrink-0 cursor-pointer rounded border border-zinc-300 bg-white p-0.5"
                aria-label={`Color for ${u.name}`}
                title={`Color for ${u.name}`}
              />
              <input
                className={FIELD_CLS}
                placeholder={u.displayName}
                value={override.name ?? ""}
                onChange={(e) => onUserOverride(u.id, { name: e.target.value })}
                aria-label={`Rename ${u.displayName}`}
              />
            </div>
          );
        })}
        {filtered.length === 0 && (
          <p className="text-xs text-zinc-400">No users match &ldquo;{query}&rdquo;.</p>
        )}
      </div>
    </Group>
  );
}
