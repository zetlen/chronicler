/**
 * The browser-side Slack fetch pipeline (#96): lib/slack/fetchMessages.ts,
 * lib/slack/directory.ts and lib/slack/emoji.ts re-hosted against the
 * plugin's stateless proxy (POST chronicler/v1/slack/{method}, #99).
 *
 * Ported semantics, preserved exactly:
 * - conversations.history pagination with the page cap (default 25 pages of
 *   200, matching the Node app's LOGS_PAGE_CAP default), newest-first pages
 *   so a capped fetch keeps the window's most recent messages and reports
 *   `truncated`.
 * - per-thread conversations.replies stitching, clipped to the window:
 *   omittedBefore from the oldest bound, omittedAfter from reply_count.
 * - the 5-page orphan lookback for threads that started before the window
 *   but continued into it (parentBeforeWindow).
 * - oldest/latest are inclusive unix-seconds bounds.
 * - unique-author (and mention) users.info directory hydration; emoji.list
 *   once per run with alias resolution.
 *
 * Deliberate differences from the Node pipeline (proxy contract, #99):
 * - Slack calls go one-at-a-time through the proxy; a 429 means THIS code
 *   waits `retry_after` seconds (visible countdown via onProgress) and
 *   resumes — the server never sleeps.
 * - conversations.info is not proxied, so channel-mention names resolve
 *   from conversations.list (the same pages the channel picker uses);
 *   users.info hydration is sequential rather than SDK-queue-concurrent.
 *
 * Everything is dependency-injected for tests: `call` replaces the
 * apiFetch-backed transport with a plain fake (no vi.mock — see the repo's
 * vitest-4 note about mocked modules that throw).
 */

import { apiFetch, ApiError } from "@/components/admin/apiFetch";
import { getCharacterNames } from "@/components/admin/sessionApi";
import type { SlackMessage, ThreadedMessage, ChannelSummary } from "@/lib/transform/slackTypes";
import type { NameMaps } from "@/lib/transform/directory";

/** Max history/replies pages (200 messages each) per pagination. */
export const DEFAULT_PAGE_CAP = 25;

/** How far back (in 200-message pages) the orphan-thread scan reaches. */
export const ORPHAN_LOOKBACK_PAGES = 5;

const PAGE_LIMIT = 200;

/** One proxied Slack call: method + args → Slack's JSON body. */
export type SlackCall = (
  method: string,
  args: Record<string, unknown>,
) => Promise<Record<string, unknown>>;

export interface FetchProgress {
  /** The pipeline stage, for the status line. */
  step: "channels" | "history" | "lookback" | "threads" | "directory" | "emoji";
  /** History + replies pages fetched so far. */
  pagesFetched: number;
  /**
   * Messages received on history/replies pages so far — the user-facing
   * counter (people think in messages, not API pages). The lookback scan
   * counts 0: its pages are mostly discarded, and a count that jumps up
   * then never appears in the session would read as a bug.
   */
  messagesFetched: number;
  /** Threads whose reply chains have been stitched. */
  threadsResolved: number;
  /** Directory lookups completed / wanted. */
  usersResolved: number;
  usersTotal: number;
  /** Seconds left in a Slack rate-limit wait, or null when not waiting. */
  waitSeconds: number | null;
}

export interface PipelineOptions {
  signal?: AbortSignal;
  onProgress?: (progress: FetchProgress) => void;
  /** Test seam: replaces the apiFetch-backed rate-limit-aware transport. */
  call?: SlackCall;
  /** Test seam: 1s of countdown wait (fake timers keep tests instant). */
  waitMs?: number;
  /** Test seam: resolves present Slack IDs → character log names (WP REST). */
  resolveCharacterNames?: () => Promise<Record<string, string>>;
}

export interface SessionFetchParams {
  channelId: string;
  /** Inclusive lower bound, unix seconds. */
  oldest: number;
  /** Inclusive upper bound, unix seconds. */
  latest: number;
  includeReplies?: boolean;
  pageCap?: number;
  /**
   * Known channel id → name pairs (the session's own channel, the picker's
   * list) used to resolve <#C…> mentions without conversations.info.
   */
  channelNames?: Record<string, string>;
}

export interface SessionRawData {
  threads: ThreadedMessage[];
  names: NameMaps;
  customEmoji: Record<string, string>;
  unresolvedNames: number;
  threadCount: number;
  messageCount: number;
  truncated: boolean;
}

/** True for a fetch/AbortController cancellation (any transport). */
export function isAbortError(err: unknown): boolean {
  return err instanceof DOMException
    ? err.name === "AbortError"
    : err instanceof Error && err.name === "AbortError";
}

function throwIfAborted(signal?: AbortSignal): void {
  if (signal?.aborted) {
    throw new DOMException("The fetch was canceled.", "AbortError");
  }
}

/** An abortable delay. */
function delay(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    throwIfAborted(signal);
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", onAbort);
      resolve();
    }, ms);
    function onAbort() {
      clearTimeout(timer);
      reject(new DOMException("The fetch was canceled.", "AbortError"));
    }
    signal?.addEventListener("abort", onAbort, { once: true });
  });
}

/**
 * The default transport: POST slack/{method} through apiFetch, and when the
 * proxy relays a Slack 429 ({retry_after}, the #99 contract) wait it out
 * with a per-second countdown, then retry. Waiting is the BROWSER's job by
 * design — the plugin never sleeps.
 */
export function createSlackCall(opts: {
  signal?: AbortSignal;
  onWait?: (secondsRemaining: number | null) => void;
  waitMs?: number;
}): SlackCall {
  const second = opts.waitMs ?? 1_000;
  return async function call(method, args) {
    for (;;) {
      throwIfAborted(opts.signal);
      try {
        return await apiFetch<Record<string, unknown>>(`slack/${method}`, {
          method: "POST",
          body: JSON.stringify(args),
          signal: opts.signal,
        });
      } catch (err) {
        if (!(err instanceof ApiError) || err.status !== 429) throw err;
        const total = Math.max(1, Math.ceil(err.retryAfter ?? 30));
        for (let remaining = total; remaining > 0; remaining--) {
          opts.onWait?.(remaining);
          await delay(second, opts.signal);
        }
        opts.onWait?.(null);
      }
    }
  };
}

/* ------------------------------------------------------------------ *
 * Channel listing (the picker + mention resolution)
 * ------------------------------------------------------------------ */

interface ConversationsListBody {
  channels?: Array<{ id?: string; name?: string; is_private?: boolean }>;
  response_metadata?: { next_cursor?: string };
}

/**
 * Every channel the proxy's conversations.list returns, sorted by name —
 * public and private, archived excluded, matching the Node app's
 * listChannels (lib/slack/fetchMessages.ts) exactly. The proxy whitelists
 * `types`/`exclude_archived` for conversations.list since 3.20.0 (the
 * recorded #99 gap, closed).
 */
export async function fetchAllChannels(call: SlackCall): Promise<ChannelSummary[]> {
  const channels: ChannelSummary[] = [];
  let cursor: string | undefined;
  do {
    const res = (await call("conversations.list", {
      types: "public_channel,private_channel",
      exclude_archived: true,
      limit: PAGE_LIMIT,
      ...(cursor ? { cursor } : {}),
    })) as ConversationsListBody;
    for (const c of res.channels ?? []) {
      if (c.id && c.name) {
        channels.push({ id: c.id, name: c.name, isPrivate: !!c.is_private });
      }
    }
    cursor = res.response_metadata?.next_cursor || undefined;
  } while (cursor);
  channels.sort((a, b) => a.name.localeCompare(b.name));
  return channels;
}

/* ------------------------------------------------------------------ *
 * Threaded-message fetch — the lib/slack/fetchMessages.ts port
 * ------------------------------------------------------------------ */

interface PageResult {
  messages?: SlackMessage[];
  response_metadata?: { next_cursor?: string };
}

const tsAsc = (a: SlackMessage, b: SlackMessage) => Number(a.ts) - Number(b.ts);

function startsThread(m: SlackMessage): boolean {
  return !!m.reply_count && m.thread_ts === m.ts;
}

async function pageAll(
  fetchPage: (cursor?: string) => Promise<PageResult>,
  maxPages: number,
  onPage?: (messageCount: number) => void,
): Promise<{ messages: SlackMessage[]; truncated: boolean }> {
  const messages: SlackMessage[] = [];
  let cursor: string | undefined;
  let pages = 0;
  do {
    const res = await fetchPage(cursor);
    messages.push(...(res.messages ?? []));
    cursor = res.response_metadata?.next_cursor || undefined;
    pages++;
    onPage?.(res.messages?.length ?? 0);
  } while (cursor && pages < maxPages);
  return { messages, truncated: !!cursor };
}

interface ThreadContext {
  call: SlackCall;
  channel: string;
  oldest: number;
  latest: number;
  pageCap: number;
  onPage?: (messageCount: number) => void;
}

/**
 * Hydrate one thread, clipped to the window — reply chain fetched up to
 * `latest`; splitting on `oldest` counts the clipped head, reply_count the
 * clipped tail. (Verbatim semantics from lib/slack/fetchMessages.ts.)
 */
async function hydrateThread(
  t: ThreadContext,
  parent: SlackMessage,
  parentBeforeWindow: boolean,
): Promise<{ thread: ThreadedMessage; truncated: boolean }> {
  const { messages: chain, truncated } = await pageAll(
    (cursor) =>
      t.call("conversations.replies", {
        channel: t.channel,
        ts: parent.ts,
        latest: String(t.latest),
        inclusive: true,
        limit: PAGE_LIMIT,
        ...(cursor ? { cursor } : {}),
      }) as Promise<PageResult>,
    t.pageCap,
    t.onPage,
  );
  // The replies endpoint echoes the parent as the first element.
  const all = chain.filter((m) => m.ts !== parent.ts).sort(tsAsc);
  const replies = all.filter((m) => Number(m.ts) >= t.oldest);
  const omittedBefore = all.length - replies.length;
  const omittedAfter = Math.max(0, (parent.reply_count ?? 0) - all.length);
  const thread: ThreadedMessage = {
    parent,
    replies,
    ...(parentBeforeWindow ? { parentBeforeWindow: true } : {}),
    ...(omittedBefore ? { omittedBefore } : {}),
    ...(omittedAfter ? { omittedAfter } : {}),
  };
  return { thread, truncated };
}

/**
 * Thread parents older than the window whose latest reply lands in or after
 * it, bounded to ORPHAN_LOOKBACK_PAGES of history (threads starting more
 * than ~1,000 messages before the window are not surfaced — the documented
 * limitation, so the lookback's own pagination flag is dropped).
 */
async function findOrphanParents(t: ThreadContext): Promise<SlackMessage[]> {
  const { messages: before } = await pageAll(
    (cursor) =>
      t.call("conversations.history", {
        channel: t.channel,
        latest: String(t.oldest),
        inclusive: false,
        limit: PAGE_LIMIT,
        ...(cursor ? { cursor } : {}),
      }) as Promise<PageResult>,
    ORPHAN_LOOKBACK_PAGES,
    // Scanned pages advance the page counter but never the message count.
    () => t.onPage?.(0),
  );
  return before.filter(
    (m) => startsThread(m) && Number(m.latest_reply ?? 0) >= t.oldest,
  );
}

/* ------------------------------------------------------------------ *
 * Directory + emoji hydration (lib/slack/{directory,emoji}.ts ports)
 * ------------------------------------------------------------------ */

const USER_MENTION = /<@([A-Z0-9]+)(?:\|[^>]+)?>/g;
const CHANNEL_MENTION = /<#([A-Z0-9]+)(?:\|[^>]*)?>/g;

function collectIds(messages: SlackMessage[]): {
  users: Set<string>;
  channels: Set<string>;
} {
  const users = new Set<string>();
  const channels = new Set<string>();
  for (const m of messages) {
    if (m.user) users.add(m.user);
    const text = m.text ?? "";
    for (const match of text.matchAll(USER_MENTION)) users.add(match[1]);
    for (const match of text.matchAll(CHANNEL_MENTION)) channels.add(match[1]);
  }
  return { users, channels };
}

interface UsersInfoBody {
  user?: {
    name?: string;
    real_name?: string;
    profile?: { display_name?: string; image_72?: string };
  };
}

const MAX_ALIAS_HOPS = 10;

/** emoji.list once, alias entries resolved; failures degrade to {}. */
async function fetchCustomEmoji(call: SlackCall): Promise<Record<string, string>> {
  let raw: Record<string, string>;
  try {
    const body = (await call("emoji.list", {})) as { emoji?: Record<string, string> };
    raw = body.emoji ?? {};
  } catch (err) {
    if (isAbortError(err)) throw err;
    console.warn("emoji.list failed; custom emoji disabled for this render:", err);
    return {};
  }
  const resolved: Record<string, string> = {};
  for (const [name, value] of Object.entries(raw)) {
    let target = value;
    let hops = 0;
    while (target.startsWith("alias:") && hops < MAX_ALIAS_HOPS) {
      target = raw[target.slice("alias:".length)] ?? "";
      hops++;
    }
    if (target && !target.startsWith("alias:")) resolved[name] = target;
  }
  return resolved;
}

/* ------------------------------------------------------------------ *
 * The pipeline
 * ------------------------------------------------------------------ */

/**
 * Fetch a session's window: threaded messages, the name/avatar directory,
 * and custom emoji — everything the transform needs, shaped like the Node
 * app's /api/logs response.
 */
export async function fetchSessionData(
  params: SessionFetchParams,
  options: PipelineOptions = {},
): Promise<SessionRawData> {
  const { channelId, oldest, latest, includeReplies = true } = params;
  const pageCap = params.pageCap ?? DEFAULT_PAGE_CAP;
  const { signal, onProgress } = options;

  const progress: FetchProgress = {
    step: "history",
    pagesFetched: 0,
    messagesFetched: 0,
    threadsResolved: 0,
    usersResolved: 0,
    usersTotal: 0,
    waitSeconds: null,
  };
  const report = (patch: Partial<FetchProgress>) => {
    Object.assign(progress, patch);
    onProgress?.({ ...progress });
  };

  const call =
    options.call ??
    createSlackCall({
      signal,
      waitMs: options.waitMs,
      onWait: (secondsRemaining) => report({ waitSeconds: secondsRemaining }),
    });

  const t: ThreadContext = {
    call,
    channel: channelId,
    oldest,
    latest,
    pageCap,
    onPage: (messageCount) =>
      report({
        pagesFetched: progress.pagesFetched + 1,
        messagesFetched: progress.messagesFetched + messageCount,
      }),
  };

  // 1. Top-level history for the window (newest-first pages, capped).
  report({ step: "history" });
  const { messages: parents, truncated: parentsTruncated } = await pageAll(
    (cursor) =>
      call("conversations.history", {
        channel: channelId,
        oldest: String(oldest),
        latest: String(latest),
        inclusive: true,
        limit: PAGE_LIMIT,
        ...(cursor ? { cursor } : {}),
      }) as Promise<PageResult>,
    pageCap,
    t.onPage,
  );
  let truncated = parentsTruncated;

  const threads: ThreadedMessage[] = [];
  const hydrate = async (parent: SlackMessage, parentBeforeWindow: boolean) => {
    const result = await hydrateThread(t, parent, parentBeforeWindow);
    truncated ||= result.truncated;
    report({ threadsResolved: progress.threadsResolved + 1 });
    return result.thread;
  };

  // 2. The orphan lookback, then per-thread reply stitching.
  if (includeReplies) {
    report({ step: "lookback" });
    for (const parent of await findOrphanParents(t)) {
      const thread = await hydrate(parent, true);
      if (thread.replies.length > 0) threads.push(thread);
    }
  }

  report({ step: "threads" });
  for (const parent of parents) {
    threads.push(
      includeReplies && startsThread(parent)
        ? await hydrate(parent, false)
        : { parent, replies: [] },
    );
  }
  threads.sort((a, b) => Number(a.parent.ts) - Number(b.parent.ts));

  // 3. Directory hydration: users via users.info; channels from the known
  //    map (conversations.info is not proxied), topped up from
  //    conversations.list only when a mention needs it.
  const allMessages = threads.flatMap((th) => [th.parent, ...th.replies]);
  const { users, channels } = collectIds(allMessages);
  let unresolved = 0;

  const channelNames: Record<string, string> = { ...(params.channelNames ?? {}) };
  if ([...channels].some((id) => !channelNames[id])) {
    try {
      for (const c of await fetchAllChannels(call)) channelNames[c.id] = c.name;
    } catch (err) {
      if (isAbortError(err)) throw err;
      // Mentioned channels render as raw IDs and count as unresolved below.
    }
  }

  report({ step: "directory", usersTotal: users.size });
  const userNames: Record<string, string> = {};
  const avatars: Record<string, string> = {};
  for (const id of users) {
    try {
      const res = (await call("users.info", { user: id })) as UsersInfoBody;
      const profile = res.user?.profile;
      userNames[id] =
        profile?.display_name || res.user?.real_name || res.user?.name || id;
      if (profile?.image_72) avatars[id] = profile.image_72;
    } catch (err) {
      if (isAbortError(err)) throw err;
      unresolved++;
    }
    report({ usersResolved: progress.usersResolved + 1 });
  }

  const channelMap: Record<string, string> = {};
  for (const id of channels) {
    if (channelNames[id]) {
      channelMap[id] = channelNames[id];
    } else {
      unresolved++;
    }
  }

  // Character log names: mapped players render as their character. A failure is
  // non-fatal — speakers just stay their Slack names.
  let characters: Record<string, string> = {};
  if (users.size > 0) {
    try {
      characters = await (options.resolveCharacterNames ??
        (() => getCharacterNames(signal)))();
    } catch (err) {
      if (isAbortError(err)) throw err;
      characters = {};
    }
  }

  // 4. Custom emoji, once.
  report({ step: "emoji" });
  const customEmoji = await fetchCustomEmoji(call);

  return {
    threads,
    names: { users: userNames, channels: channelMap, avatars, characters },
    customEmoji,
    unresolvedNames: unresolved,
    threadCount: threads.length,
    messageCount: allMessages.length,
    truncated,
  };
}
