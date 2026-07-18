import { describe, it, expect, vi, afterEach } from "vitest";
import { ApiError } from "@/components/admin/apiFetch";
import {
  createSlackCall,
  fetchAllChannels,
  fetchSessionData,
  isAbortError,
  type FetchProgress,
  type SlackCall,
} from "@/components/admin/slackFetch";
import type { SlackMessage } from "@/lib/transform/slackTypes";

/**
 * The fetch-pipeline port (#96), tested with plain injected fakes — no
 * vi.mock (vitest 4 fails tests when a mocked module's export throws, even
 * caught; a fake SlackCall sidesteps that entirely).
 */

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  delete window.chroniclerBoot;
});

/** ts helper: unix-seconds number → Slack ts string. */
const ts = (n: number) => n.toFixed(6);

interface FakeUser {
  display_name?: string;
  real_name?: string;
  name?: string;
  image_72?: string;
}

interface FakeSlackOptions {
  topLevel?: SlackMessage[];
  threadReplies?: Record<string, SlackMessage[]>;
  /** When set, cursor-paginate responses in pages of this size. */
  pageSize?: number;
  users?: Record<string, FakeUser>;
  emoji?: Record<string, string>;
  channels?: Array<{ id: string; name: string; is_private?: boolean }>;
  /** Extra channel pages for cursor-pagination tests. */
  channelPages?: Array<Array<{ id: string; name: string; is_private?: boolean }>>;
}

/**
 * A canned Slack behind the SlackCall seam: history serves newest-first
 * (filtered to oldest/latest like Slack), replies echo the parent first,
 * users.info/emoji.list/conversations.list serve fixture maps. Every call
 * is recorded for assertions.
 */
function fakeSlack(opts: FakeSlackOptions) {
  const {
    topLevel = [],
    threadReplies = {},
    pageSize,
    users = {},
    emoji = {},
    channels = [],
    channelPages,
  } = opts;
  const calls: Array<{ method: string; args: Record<string, unknown> }> = [];

  const paged = (all: SlackMessage[], cursor?: unknown) => {
    if (!pageSize) return { messages: all, response_metadata: {} };
    const from = cursor ? Number(cursor) : 0;
    const next = from + pageSize < all.length ? String(from + pageSize) : undefined;
    return {
      messages: all.slice(from, from + pageSize),
      response_metadata: next ? { next_cursor: next } : {},
    };
  };

  const call: SlackCall = async (method, args) => {
    calls.push({ method, args });
    switch (method) {
      case "conversations.history": {
        const oldest = args.oldest ? Number(args.oldest) : -Infinity;
        const latest = args.latest ? Number(args.latest) : Infinity;
        const inclusive = args.inclusive === true || args.inclusive === "true";
        const sorted = topLevel
          .filter((m) => {
            const t = Number(m.ts);
            return inclusive ? t >= oldest && t <= latest : t > oldest && t < latest;
          })
          .sort((a, b) => Number(b.ts) - Number(a.ts));
        return paged(sorted, args.cursor) as Record<string, unknown>;
      }
      case "conversations.replies": {
        const latest = args.latest ? Number(args.latest) : Infinity;
        const parent = topLevel.find((m) => m.ts === args.ts)!;
        const chain = [parent, ...(threadReplies[String(args.ts)] ?? [])].filter(
          (m) => Number(m.ts) <= latest,
        );
        return paged(chain, args.cursor) as Record<string, unknown>;
      }
      case "users.info": {
        const user = users[String(args.user)];
        if (!user) throw new ApiError("Slack returned an error: user_not_found", 502);
        return {
          ok: true,
          user: {
            name: user.name,
            real_name: user.real_name,
            profile: { display_name: user.display_name, image_72: user.image_72 },
          },
        };
      }
      case "emoji.list":
        return { ok: true, emoji };
      case "conversations.list": {
        if (channelPages) {
          const index = args.cursor ? Number(args.cursor) : 0;
          const next = index + 1 < channelPages.length ? String(index + 1) : undefined;
          return {
            ok: true,
            channels: channelPages[index],
            response_metadata: next ? { next_cursor: next } : {},
          };
        }
        return { ok: true, channels };
      }
      default:
        throw new Error(`fakeSlack: unexpected method ${method}`);
    }
  };
  return { call, calls };
}

const WINDOW = { channelId: "C1", oldest: 1000, latest: 2000 };
const NO_DIRECTORY = { users: {}, emoji: {} };

describe("fetchSessionData windowing (fetchMessages.ts port)", () => {
  it("clips replies to the window and counts the clipped tail", async () => {
    const parent: SlackMessage = {
      ts: ts(1100), thread_ts: ts(1100), user: "U1", text: "parent",
      reply_count: 2, latest_reply: ts(2500),
    };
    const { call } = fakeSlack({
      ...NO_DIRECTORY,
      topLevel: [parent],
      threadReplies: {
        [ts(1100)]: [
          { ts: ts(1150), thread_ts: ts(1100), user: "U2", text: "in-window" },
          { ts: ts(2500), thread_ts: ts(1100), user: "U2", text: "after" },
        ],
      },
      users: { U1: { name: "u1" }, U2: { name: "u2" } },
    });
    const { threads } = await fetchSessionData(WINDOW, { call });
    const t = threads.find((x) => x.parent.ts === ts(1100))!;
    expect(t.replies.map((r) => r.text)).toEqual(["in-window"]);
    expect(t.omittedAfter).toBe(1);
    expect(t.parentBeforeWindow).toBeUndefined();
  });

  it("surfaces threads whose parent predates the window (orphan lookback)", async () => {
    const orphanParent: SlackMessage = {
      ts: ts(500), thread_ts: ts(500), user: "U1", text: "old parent",
      reply_count: 2, latest_reply: ts(1200),
    };
    const { call } = fakeSlack({
      topLevel: [orphanParent],
      threadReplies: {
        [ts(500)]: [
          { ts: ts(600), thread_ts: ts(500), user: "U2", text: "before" },
          { ts: ts(1200), thread_ts: ts(500), user: "U2", text: "inside" },
        ],
      },
      users: { U1: { name: "u1" }, U2: { name: "u2" } },
    });
    const { threads } = await fetchSessionData(WINDOW, { call });
    expect(threads).toHaveLength(1);
    expect(threads[0].parentBeforeWindow).toBe(true);
    expect(threads[0].replies.map((r) => r.text)).toEqual(["inside"]);
    expect(threads[0].omittedBefore).toBe(1);
  });

  it("skips pre-window threads with no in-window replies", async () => {
    const stale: SlackMessage = {
      ts: ts(500), thread_ts: ts(500), user: "U1", text: "stale",
      reply_count: 1, latest_reply: ts(800),
    };
    const { call, calls } = fakeSlack({
      topLevel: [stale],
      threadReplies: { [ts(500)]: [{ ts: ts(800), thread_ts: ts(500), user: "U2", text: "old" }] },
    });
    const { threads } = await fetchSessionData(WINDOW, { call });
    expect(threads).toHaveLength(0);
    // latest_reply (800) < oldest (1000): not even worth a replies call.
    expect(calls.filter((c) => c.method === "conversations.replies")).toHaveLength(0);
  });

  it("makes no replies calls and no lookback scan when includeReplies is false", async () => {
    const parent: SlackMessage = {
      ts: ts(1100), thread_ts: ts(1100), user: "U1", text: "p",
      reply_count: 2, latest_reply: ts(1200),
    };
    const { call, calls } = fakeSlack({ topLevel: [parent], users: { U1: {} } });
    const { threads } = await fetchSessionData(
      { ...WINDOW, includeReplies: false },
      { call },
    );
    expect(threads).toHaveLength(1);
    expect(threads[0].replies).toEqual([]);
    expect(calls.filter((c) => c.method === "conversations.replies")).toHaveLength(0);
    // Window fetch only — no orphan scan.
    expect(calls.filter((c) => c.method === "conversations.history")).toHaveLength(1);
  });

  it("caps history pagination at pageCap and reports truncation", async () => {
    // Six in-window messages, two per page: a cap of 2 pages covers only the
    // four newest; the two oldest fall off and the result says so.
    const msgs: SlackMessage[] = [1100, 1200, 1300, 1400, 1500, 1600].map((t) => ({
      ts: ts(t), user: "U1", text: `m${t}`,
    }));
    const { call } = fakeSlack({ topLevel: msgs, pageSize: 2, users: { U1: {} } });
    const result = await fetchSessionData({ ...WINDOW, pageCap: 2 }, { call });
    expect(result.truncated).toBe(true);
    expect(result.threads.map((t) => t.parent.text)).toEqual([
      "m1300", "m1400", "m1500", "m1600",
    ]);
  });

  it("caps reply-chain pagination and counts capped replies as omitted", async () => {
    const parent: SlackMessage = {
      ts: ts(1100), thread_ts: ts(1100), user: "U1", text: "parent",
      reply_count: 8, latest_reply: ts(1900),
    };
    const replies: SlackMessage[] = [1150, 1200, 1250, 1300, 1350, 1400, 1450, 1900].map(
      (t) => ({ ts: ts(t), thread_ts: ts(1100), user: "U2", text: `r${t}` }),
    );
    // Pages of 3: [parent, r1, r2], [r3, r4, r5], [r6, r7, r8]. A cap of 2
    // pages yields five replies; the rest count toward omittedAfter.
    const { call } = fakeSlack({
      topLevel: [parent],
      threadReplies: { [ts(1100)]: replies },
      pageSize: 3,
      users: { U1: {}, U2: {} },
    });
    const result = await fetchSessionData({ ...WINDOW, pageCap: 2 }, { call });
    expect(result.truncated).toBe(true);
    const t1 = result.threads[0];
    expect(t1.replies).toHaveLength(5);
    expect(t1.omittedAfter).toBe(3);
  });

  it("orders orphan threads with window messages by parent ts", async () => {
    const orphan: SlackMessage = {
      ts: ts(500), thread_ts: ts(500), user: "U1", text: "old",
      reply_count: 1, latest_reply: ts(1300),
    };
    const inWindow: SlackMessage = { ts: ts(1100), user: "U2", text: "plain" };
    const { call } = fakeSlack({
      topLevel: [orphan, inWindow],
      threadReplies: { [ts(500)]: [{ ts: ts(1300), thread_ts: ts(500), user: "U2", text: "r" }] },
      users: { U1: {}, U2: {} },
    });
    const { threads } = await fetchSessionData(WINDOW, { call });
    expect(threads.map((t) => t.parent.text)).toEqual(["old", "plain"]);
  });
});

describe("fetchSessionData directory + emoji hydration", () => {
  it("hydrates authors and mentions once each; failures count unresolved", async () => {
    const messages: SlackMessage[] = [
      { ts: ts(1100), user: "U1", text: "hi <@U3> and <@U3> again" },
      { ts: ts(1200), user: "U1", text: "also <@UMISSING>" },
    ];
    const { call, calls } = fakeSlack({
      topLevel: messages,
      users: {
        U1: { display_name: "Alice", image_72: "https://a/img.png" },
        U3: { real_name: "Carol" },
      },
    });
    const data = await fetchSessionData(WINDOW, { call });
    expect(data.names.users).toEqual({ U1: "Alice", U3: "Carol" });
    expect(data.names.avatars).toEqual({ U1: "https://a/img.png" });
    expect(data.unresolvedNames).toBe(1); // UMISSING
    const infoCalls = calls.filter((c) => c.method === "users.info");
    expect(infoCalls).toHaveLength(3); // unique ids only
    expect(calls.filter((c) => c.method === "emoji.list")).toHaveLength(1);
  });

  it("resolves channel mentions from conversations.list (conversations.info is not proxied)", async () => {
    const messages: SlackMessage[] = [
      { ts: ts(1100), user: "U1", text: "see <#C2> and <#CGONE>" },
    ];
    const { call, calls } = fakeSlack({
      topLevel: messages,
      users: { U1: { name: "u1" } },
      channels: [{ id: "C2", name: "war-room" }],
    });
    const data = await fetchSessionData(
      { ...WINDOW, channelNames: { C1: "session-log" } },
      { call },
    );
    expect(data.names.channels).toEqual({ C2: "war-room" });
    expect(data.unresolvedNames).toBe(1); // CGONE
    expect(calls.filter((c) => c.method === "conversations.list")).toHaveLength(1);
  });

  it("skips the channel-list top-up when known names cover every mention", async () => {
    const messages: SlackMessage[] = [{ ts: ts(1100), user: "U1", text: "in <#C1>" }];
    const { call, calls } = fakeSlack({ topLevel: messages, users: { U1: {} } });
    const data = await fetchSessionData(
      { ...WINDOW, channelNames: { C1: "session-log" } },
      { call },
    );
    expect(data.names.channels).toEqual({ C1: "session-log" });
    expect(calls.filter((c) => c.method === "conversations.list")).toHaveLength(0);
  });

  it("resolves emoji aliases and drops dangling ones", async () => {
    const { call } = fakeSlack({
      topLevel: [{ ts: ts(1100), user: "U1", text: "x" }],
      users: { U1: {} },
      emoji: {
        party: "https://emoji/party.gif",
        celebrate: "alias:party",
        broken: "alias:nowhere",
      },
    });
    const data = await fetchSessionData(WINDOW, { call });
    expect(data.customEmoji).toEqual({
      party: "https://emoji/party.gif",
      celebrate: "https://emoji/party.gif",
    });
  });

  it("reports progress through the steps", async () => {
    const parent: SlackMessage = {
      ts: ts(1100), thread_ts: ts(1100), user: "U1", text: "p",
      reply_count: 1, latest_reply: ts(1200),
    };
    const { call } = fakeSlack({
      topLevel: [parent],
      threadReplies: { [ts(1100)]: [{ ts: ts(1200), thread_ts: ts(1100), user: "U1", text: "r" }] },
      users: { U1: { name: "u1" } },
    });
    const seen: FetchProgress[] = [];
    await fetchSessionData(WINDOW, { call, onProgress: (p) => seen.push(p) });
    const steps = new Set(seen.map((p) => p.step));
    for (const step of ["history", "lookback", "threads", "directory", "emoji"]) {
      expect(steps.has(step as FetchProgress["step"])).toBe(true);
    }
    const last = seen[seen.length - 1];
    expect(last.pagesFetched).toBeGreaterThan(0);
    // 1 from the history page + 2 from the reply chain (parent echo + reply);
    // the lookback scan's pages deliberately count 0.
    expect(last.messagesFetched).toBe(3);
    expect(last.threadsResolved).toBe(1);
    expect(last.usersResolved).toBe(1);
  });

  it("resolves character names into names.characters", async () => {
    const { call } = fakeSlack({
      topLevel: [{ ts: ts(1100), user: "U1", text: "hi" }],
      users: { U1: { display_name: "Alice" } },
    });
    const data = await fetchSessionData(WINDOW, {
      call,
      resolveCharacterNames: async () => ({ U1: "WOLFGANG" }),
    });
    expect(data.names.characters).toEqual({ U1: "WOLFGANG" });
  });

  it("leaves characters empty when resolution fails", async () => {
    const { call } = fakeSlack({
      topLevel: [{ ts: ts(1100), user: "U1", text: "hi" }],
      users: { U1: { display_name: "Alice" } },
    });
    const data = await fetchSessionData(WINDOW, {
      call,
      resolveCharacterNames: async () => {
        throw new Error("boom");
      },
    });
    expect(data.names.characters).toEqual({});
    expect(data.names.users).toEqual({ U1: "Alice" });
  });
});

describe("createSlackCall rate-limit handling", () => {
  function bootPage() {
    window.chroniclerBoot = { apiBase: "/wp-json/chronicler/v1", nonce: "n" };
  }

  /** fetch stub serving a queue of {status, body} responses. */
  function stubFetchQueue(queue: Array<{ status: number; body: unknown }>) {
    const stub = vi.fn(async () => {
      const next = queue.shift();
      if (!next) throw new Error("fetch queue exhausted");
      return {
        ok: next.status >= 200 && next.status < 300,
        status: next.status,
        json: async () => next.body,
      } as Response;
    });
    vi.stubGlobal("fetch", stub);
    return stub;
  }

  it("waits retry_after seconds on a 429 (visible countdown) and resumes", async () => {
    vi.useFakeTimers();
    bootPage();
    const stub = stubFetchQueue([
      { status: 429, body: { code: "chronicler_rate_limited", retry_after: 2 } },
      { status: 200, body: { ok: true, channels: [] } },
    ]);
    const waits: Array<number | null> = [];
    const call = createSlackCall({ onWait: (s) => waits.push(s) });
    const promise = call("conversations.list", {});
    await vi.advanceTimersByTimeAsync(2_000);
    await expect(promise).resolves.toEqual({ ok: true, channels: [] });
    expect(waits).toEqual([2, 1, null]); // per-second countdown, then cleared
    expect(stub).toHaveBeenCalledTimes(2);
  });

  it("falls back to a 30s wait when the 429 carries no retry_after", async () => {
    vi.useFakeTimers();
    bootPage();
    stubFetchQueue([
      { status: 429, body: { code: "chronicler_rate_limited" } },
      { status: 200, body: { ok: true } },
    ]);
    const waits: Array<number | null> = [];
    const call = createSlackCall({ onWait: (s) => waits.push(s) });
    const promise = call("emoji.list", {});
    await vi.advanceTimersByTimeAsync(30_000);
    await expect(promise).resolves.toEqual({ ok: true });
    expect(waits[0]).toBe(30);
  });

  it("aborting during the wait rejects with an AbortError", async () => {
    vi.useFakeTimers();
    bootPage();
    stubFetchQueue([
      { status: 429, body: { retry_after: 60 } },
      { status: 200, body: { ok: true } },
    ]);
    const controller = new AbortController();
    const call = createSlackCall({ signal: controller.signal });
    const promise = call("emoji.list", {});
    const settled = promise.catch((e: unknown) => e);
    await vi.advanceTimersByTimeAsync(500);
    controller.abort();
    const err = await settled;
    expect(isAbortError(err)).toBe(true);
  });

  it("non-429 ApiErrors pass through untouched", async () => {
    bootPage();
    stubFetchQueue([
      { status: 502, body: { code: "chronicler_slack_error", message: "Slack returned an error: channel_not_found" } },
    ]);
    const call = createSlackCall({});
    const err = await call("conversations.history", { channel: "C1" }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(502);
  });
});

describe("fetchAllChannels", () => {
  it("walks cursor pages and sorts by name", async () => {
    const { call, calls } = fakeSlack({
      channelPages: [
        [{ id: "C2", name: "zeta" }, { id: "C3", name: "alpha", is_private: true }],
        [{ id: "C1", name: "mid" }],
      ],
    });
    const channels = await fetchAllChannels(call);
    expect(channels).toEqual([
      { id: "C3", name: "alpha", isPrivate: true },
      { id: "C1", name: "mid", isPrivate: false },
      { id: "C2", name: "zeta", isPrivate: false },
    ]);
    expect(calls.filter((c) => c.method === "conversations.list")).toHaveLength(2);
    expect(calls[1].args.cursor).toBe("1");
  });

  it("requests private channels and excludes archived, like the Node app", async () => {
    const { call, calls } = fakeSlack({ channels: [{ id: "C1", name: "one" }] });
    await fetchAllChannels(call);
    // Exactly listChannels' arguments (lib/slack/fetchMessages.ts) — the
    // proxy whitelists both since 3.20.0 (the closed #99 gap).
    expect(calls[0].args.types).toBe("public_channel,private_channel");
    expect(calls[0].args.exclude_archived).toBe(true);
  });
});
