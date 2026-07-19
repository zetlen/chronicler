import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import { act } from "react";
import type { Root } from "react-dom/client";
import { render, screen, fireEvent, cleanup, waitFor } from "@testing-library/react";
import { mountChroniclerAdmin, ROOT_ID } from "@/components/admin/mount";
import { SessionEditorApp } from "@/components/admin/SessionEditorApp";
import type { SessionFull, SessionLight } from "@/components/admin/sessionApi";
import { customSchemeTemplate } from "@/lib/transform/styles";

// Manual createRoot mounts (the entry path) render outside RTL, so React
// needs the act environment flag to keep effects deterministic in tests.
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT =
  true;

// Custom-scheme sessions mount CustomCssEditor: CodeMirror needs layout APIs
// jsdom lacks (a textarea stands in, as in CustomCssEditor.test.tsx), and its
// prefers-dark store needs a matchMedia.
vi.mock("@uiw/react-codemirror", () => ({
  default: ({
    value,
    onChange,
  }: {
    value: string;
    onChange: (v: string) => void;
  }) => (
    <textarea
      aria-label="Custom CSS source"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    />
  ),
}));
window.matchMedia ??= ((query: string) =>
  ({
    matches: false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  }) as unknown as MediaQueryList) as typeof window.matchMedia;

beforeEach(() => {
  window.history.replaceState(null, "", "/wp-admin/admin.php?page=chronicler");
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  delete window.chroniclerBoot;
  document.body.innerHTML = "";
});

function bootPage(extra: Record<string, string> = {}) {
  window.chroniclerBoot = {
    apiBase: "/wp-json/chronicler/v1",
    nonce: "test-nonce",
    ...extra,
  };
}

/* ------------------------------------------------------------------ *
 * Route-aware fetch stub (plain fake — no vi.mock)
 * ------------------------------------------------------------------ */

interface StubResponse {
  status: number;
  body: unknown;
}

interface StubRoute {
  method?: string;
  /** Matched against the end of the URL (string) or the whole URL (RegExp). */
  path: string | RegExp;
  /** A promise-returning function models a slow response (#164 overlap tests). */
  response: StubResponse | ((body: unknown) => StubResponse | Promise<StubResponse>);
}

interface RecordedCall {
  url: string;
  method: string;
  body?: unknown;
  headers: Headers;
}

function stubApi(routes: StubRoute[]) {
  const calls: RecordedCall[] = [];
  const stub = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    const method = init?.method ?? "GET";
    let body: unknown;
    if (typeof init?.body === "string") {
      try {
        body = JSON.parse(init.body);
      } catch {
        body = init.body;
      }
    }
    calls.push({ url, method, body, headers: new Headers(init?.headers) });
    const route = routes.find(
      (r) =>
        (r.method ?? "GET") === method &&
        (typeof r.path === "string" ? url.endsWith(r.path) : r.path.test(url)),
    );
    const res: StubResponse = route
      ? typeof route.response === "function"
        ? await route.response(body)
        : route.response
      : {
          status: 404,
          body: { code: "no_stub", message: `No stub for ${method} ${url}` },
        };
    return {
      ok: res.status >= 200 && res.status < 300,
      status: res.status,
      json: async () => res.body,
    } as Response;
  });
  vi.stubGlobal("fetch", stub);
  return { stub, calls };
}

/* ------------------------------------------------------------------ *
 * Fixtures
 * ------------------------------------------------------------------ */

const SESSION_LIGHT: SessionLight = {
  id: 3,
  integration: "slack",
  channel: { id: "C1", name: "session-log" },
  start: "2026-07-10T00:00:00.000Z",
  end: "2026-07-11T00:00:00.000Z",
  rule_ids: [],
  messageCount: 2,
  created: "2026-07-11 09:00:00",
  updated: "2026-07-11 10:00:00",
};

/** Saved messages in the block-attribute schema — enough to skip auto-fetch. */
const SAVED_MESSAGES: Record<string, unknown>[] = [
  {
    rootClass: "slk-msg slk-msg--text",
    anchorId: "msg-1783480010000200",
    authorName: "Alice",
    authorColor: "#4674b8",
    authorColorDark: "#7aa7e0",
    bodyHtml: "The tide <strong>pulls back</strong>.",
    headHtml: '<span class="slk-msg__time">Jul 10, 2026</span>',
  },
  { html: '<div class="slk-msg slk-system">Bob joined the channel</div>' },
];

const SESSION_FULL: SessionFull = {
  ...SESSION_LIGHT,
  editorState: { userOverrides: {}, scheme: "light", customCss: "", controls: {} },
  messages: SAVED_MESSAGES,
};

const EDITOR_ROUTES: StubRoute[] = [
  { path: /\/sessions\/3$/, response: { status: 200, body: SESSION_FULL } },
  { path: "/rules", response: { status: 200, body: [] } },
];

/** GET /sessions carries pagination args since #164. */
const LIST_PATH = /\/sessions\?page=\d+&per_page=\d+$/;

/** `count` sequential light sessions starting at id `start`. */
const pageOf = (start: number, count: number): SessionLight[] =>
  Array.from({ length: count }, (_, i) => ({
    ...SESSION_LIGHT,
    id: start + i,
    channel: { id: `C${start + i}`, name: `chan-${start + i}` },
  }));

/* ------------------------------------------------------------------ *
 * Mount / list view
 * ------------------------------------------------------------------ */

describe("mountChroniclerAdmin (entry smoke test)", () => {
  it("mounts the app on the session list, through the apiFetch seam", async () => {
    document.body.innerHTML = `<div id="${ROOT_ID}"></div>`;
    bootPage();
    const { calls } = stubApi([
      { path: LIST_PATH, response: { status: 200, body: [SESSION_LIGHT] } },
    ]);

    let root: Root | null = null;
    await act(async () => {
      root = mountChroniclerAdmin();
    });
    expect(root).not.toBeNull();

    // The list view: heading, the stored session's row, the create CTA.
    expect(screen.getByText("Chronicler sessions")).toBeTruthy();
    await screen.findByText("#session-log");
    expect(screen.getByRole("button", { name: "New session" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Edit" })).toBeTruthy();

    // The list load went through apiFetch with the boot nonce.
    expect(calls[0].url).toBe("/wp-json/chronicler/v1/sessions?page=1&per_page=50");
    expect(calls[0].headers.get("X-WP-Nonce")).toBe("test-nonce");

    await act(async () => {
      root!.unmount();
    });
  });

  it("console-errors (no crash) when the root div is missing", () => {
    bootPage();
    const error = vi.spyOn(console, "error").mockImplementation(() => {});
    expect(mountChroniclerAdmin()).toBeNull();
    expect(error).toHaveBeenCalledOnce();
    expect(String(error.mock.calls[0][0])).toContain(ROOT_ID);
  });

  it("console-errors (no crash) when window.chroniclerBoot is missing", () => {
    document.body.innerHTML = `<div id="${ROOT_ID}"></div>`;
    const error = vi.spyOn(console, "error").mockImplementation(() => {});
    expect(mountChroniclerAdmin()).toBeNull();
    expect(error).toHaveBeenCalledOnce();
    expect(String(error.mock.calls[0][0])).toContain("chroniclerBoot");
  });
});

describe("session list", () => {
  it("Edit deep-links the editor via the session query arg", async () => {
    bootPage();
    stubApi([
      { path: LIST_PATH, response: { status: 200, body: [SESSION_LIGHT] } },
      ...EDITOR_ROUTES,
    ]);
    render(<SessionEditorApp />);
    fireEvent.click(await screen.findByRole("button", { name: "Edit" }));
    expect(new URLSearchParams(window.location.search).get("session")).toBe("3");
    // The editor loads the full session and renders its saved messages.
    await screen.findByText("#session-log");
    await waitFor(() =>
      expect(document.querySelector(".slack-log")).not.toBeNull(),
    );
  });

  it("surfaces a list-load failure", async () => {
    bootPage();
    stubApi([
      {
        path: LIST_PATH,
        response: { status: 403, body: { message: "Sorry, not allowed." } },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText(/Sorry, not allowed\./);
  });

  it("pages: a full first page offers Load more, which appends page 2 (#164)", async () => {
    bootPage();
    const { calls } = stubApi([
      {
        path: LIST_PATH,
        response: () => {
          const page = calls.filter((c) => LIST_PATH.test(c.url)).length;
          return {
            status: 200,
            body: page === 1 ? pageOf(1, 50) : pageOf(51, 3),
          };
        },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#chan-1");
    expect(screen.getAllByRole("button", { name: "Edit" })).toHaveLength(50);

    fireEvent.click(screen.getByRole("button", { name: "Load more" }));
    await screen.findByText("#chan-53");
    expect(screen.getAllByRole("button", { name: "Edit" })).toHaveLength(53);
    // A short page means the well is dry: the affordance goes away.
    expect(screen.queryByRole("button", { name: "Load more" })).toBeNull();
    expect(calls[1].url).toContain("sessions?page=2&per_page=50");
  });

  it("keeps loaded rows when Load more fails, and offers an inline retry (#174 review)", async () => {
    bootPage();
    let listCalls = 0;
    stubApi([
      {
        path: LIST_PATH,
        response: () => {
          listCalls += 1;
          if (listCalls === 1) return { status: 200, body: pageOf(1, 50) };
          if (listCalls === 2)
            return { status: 500, body: { message: "db went away" } };
          return { status: 200, body: pageOf(51, 3) };
        },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#chan-1");

    fireEvent.click(screen.getByRole("button", { name: "Load more" }));
    await screen.findByText(/db went away/);
    // The already-loaded page survives the failed append…
    expect(screen.getAllByRole("button", { name: "Edit" })).toHaveLength(50);

    // …and the inline Retry completes it.
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    await screen.findByText("#chan-53");
    expect(screen.getAllByRole("button", { name: "Edit" })).toHaveLength(53);
    expect(screen.queryByText(/db went away/)).toBeNull();
  });
});

/* ------------------------------------------------------------------ *
 * Create view
 * ------------------------------------------------------------------ */

function gotoRoute(query: string) {
  window.history.replaceState(null, "", `/wp-admin/admin.php?page=chronicler&${query}`);
}

const CHANNELS_ROUTE: StubRoute = {
  method: "POST",
  path: "/slack/conversations.list",
  response: {
    status: 200,
    body: {
      ok: true,
      channels: [
        { id: "C1", name: "session-log", is_private: false },
        { id: "C2", name: "gm-notes", is_private: true },
      ],
    },
  },
};

describe("create view", () => {
  it("lists channels (private flagged) through the proxy", async () => {
    gotoRoute("session=new");
    bootPage();
    stubApi([CHANNELS_ROUTE]);
    render(<SessionEditorApp />);
    await screen.findByRole("option", { name: /session-log/ });
    expect(screen.getByRole("option", { name: /🔒 gm-notes/ })).toBeTruthy();
  });

  it("validation gates the create/fetch flow (the recorded #96 gap)", async () => {
    gotoRoute("session=new");
    bootPage();
    stubApi([CHANNELS_ROUTE]);
    render(<SessionEditorApp />);
    await screen.findByRole("option", { name: /session-log/ });

    const button = screen.getByRole("button", {
      name: /Create session & fetch messages/,
    }) as HTMLButtonElement;
    fireEvent.change(screen.getByLabelText("Channel"), { target: { value: "C1" } });

    // start after end → gated with an explanation.
    fireEvent.change(screen.getByLabelText("Start"), {
      target: { value: "2026-07-11T10:00" },
    });
    fireEvent.change(screen.getByLabelText("End"), {
      target: { value: "2026-07-10T10:00" },
    });
    expect(button.disabled).toBe(true);
    expect(screen.getByText("Start time must be before end time.")).toBeTruthy();

    // Emptying a bound gates too.
    fireEvent.change(screen.getByLabelText("End"), { target: { value: "" } });
    expect(button.disabled).toBe(true);
    expect(screen.getByText("Enter both a start and an end time.")).toBeTruthy();

    // A valid range un-gates.
    fireEvent.change(screen.getByLabelText("End"), {
      target: { value: "2026-07-12T10:00" },
    });
    expect(button.disabled).toBe(false);
  });

  it("creates with channel defaults applied, then hands off to the editor", async () => {
    gotoRoute("session=new");
    bootPage();
    const created: SessionFull = {
      ...SESSION_FULL,
      id: 9,
      rule_ids: [7],
      editorState: { scheme: "dark" },
    };
    const { calls } = stubApi([
      CHANNELS_ROUTE,
      {
        path: "/settings",
        response: {
          status: 200,
          body: {
            settings: {},
            channelDefaults: {
              C1: { scheme: "dark", customCss: "", rule_ids: [7] },
            },
          },
        },
      },
      { method: "POST", path: "/sessions", response: { status: 201, body: created } },
      { path: /\/sessions\/9$/, response: { status: 200, body: created } },
      {
        path: "/rules",
        response: {
          status: 200,
          body: [
            {
              id: 7,
              pattern: "\\[end\\]",
              flags: "i",
              mode: "end",
              className: "",
              tagNames: "",
              treatments: "",
              description: "",
            },
          ],
        },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByRole("option", { name: /session-log/ });
    fireEvent.change(screen.getByLabelText("Channel"), { target: { value: "C1" } });
    fireEvent.change(screen.getByLabelText("Start"), {
      target: { value: "2026-07-10T10:00" },
    });
    fireEvent.change(screen.getByLabelText("End"), {
      target: { value: "2026-07-11T10:00" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: /Create session & fetch messages/ }),
    );

    // The editor view takes over at &session=9.
    await screen.findByText("#session-log");
    expect(new URLSearchParams(window.location.search).get("session")).toBe("9");

    const post = calls.find((c) => c.method === "POST" && c.url.endsWith("/sessions"))!;
    expect(post).toBeDefined();
    const body = post.body as Record<string, unknown>;
    expect(body.integration).toBe("slack");
    expect(body.channel).toEqual({ id: "C1", name: "session-log" });
    // ISO instants derived from the validated local range.
    expect(Date.parse(String(body.start))).toBe(new Date("2026-07-10T10:00").getTime());
    expect(Date.parse(String(body.end))).toBe(new Date("2026-07-11T10:00").getTime());
    // Channel defaults applied: rules attached, scheme adopted.
    expect(body.rule_ids).toEqual([7]);
    expect((body.editorState as Record<string, unknown>).scheme).toBe("dark");
  });
});

/* ------------------------------------------------------------------ *
 * Editor view (saved-session path — no Slack traffic)
 * ------------------------------------------------------------------ */

describe("editor view", () => {
  it("renders a reopened session from its saved messages without touching Slack", async () => {
    gotoRoute("session=3");
    bootPage();
    const { calls } = stubApi(EDITOR_ROUTES);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    const log = document.querySelector(".slack-log")!;
    expect(log).not.toBeNull();
    expect(log.innerHTML).toContain("Alice");
    expect(log.innerHTML).toContain("<strong>pulls back</strong>");
    expect(log.innerHTML).toContain("slk-system");

    // Saved messages mean no auto-fetch: zero slack/* proxy calls.
    expect(calls.every((c) => !c.url.includes("/slack/"))).toBe(true);
  });

  it("hides the draft button without the boot template, shows it with one", async () => {
    gotoRoute("session=3");
    bootPage();
    stubApi(EDITOR_ROUTES);
    const first = render(<SessionEditorApp />);
    await screen.findByText("#session-log");
    expect(screen.queryByText("Draft this session")).toBeNull();
    first.unmount();

    bootPage({
      draftSessionUrlTemplate:
        "/wp-admin/post-new.php?chronicler_session=%d&_wpnonce=abc",
    });
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");
    const link = (await screen.findByText("Draft this session")) as HTMLAnchorElement;
    expect(link.getAttribute("href")).toBe(
      "/wp-admin/post-new.php?chronicler_session=3&_wpnonce=abc",
    );
  });

  it("swaps an untouched custom-CSS template when the custom scheme flips (#92)", async () => {
    gotoRoute("session=3");
    bootPage();
    const { calls } = stubApi([
      {
        path: /\/sessions\/3$/,
        response: {
          status: 200,
          body: {
            ...SESSION_FULL,
            editorState: {
              ...SESSION_FULL.editorState,
              scheme: "custom-light",
              customCss: customSchemeTemplate(false),
            },
          },
        },
      },
      { path: "/rules", response: { status: 200, body: [] } },
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: { status: 200, body: SESSION_FULL },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "custom-dark" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    const put = calls.find((c) => c.method === "PUT")!;
    expect(put).toBeDefined();
    const editorState = (put.body as Record<string, unknown>)
      .editorState as Record<string, string>;
    expect(editorState.scheme).toBe("custom-dark");
    // The pristine light template followed the scheme to its dark variant.
    expect(editorState.customCss).toBe(customSchemeTemplate(true));
  });

  it("keeps user-edited custom CSS when the custom scheme flips", async () => {
    gotoRoute("session=3");
    bootPage();
    const edited = ".slack-log { border: 3px dashed hotpink; }";
    const { calls } = stubApi([
      {
        path: /\/sessions\/3$/,
        response: {
          status: 200,
          body: {
            ...SESSION_FULL,
            editorState: {
              ...SESSION_FULL.editorState,
              scheme: "custom-light",
              customCss: edited,
            },
          },
        },
      },
      { path: "/rules", response: { status: 200, body: [] } },
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: { status: 200, body: SESSION_FULL },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "custom-dark" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    const put = calls.find((c) => c.method === "PUT")!;
    const editorState = (put.body as Record<string, unknown>)
      .editorState as Record<string, string>;
    expect(editorState.customCss).toBe(edited);
  });

  it("keeps stored custom CSS inside the preview style element (#159)", async () => {
    gotoRoute("session=3");
    bootPage();
    const hostile = ".slack-log{color:teal}</style><script>window.pwned=1</script>";
    stubApi([
      {
        path: /\/sessions\/3$/,
        response: {
          status: 200,
          body: {
            ...SESSION_FULL,
            editorState: {
              ...SESSION_FULL.editorState,
              scheme: "custom-light",
              customCss: hostile,
            },
          },
        },
      },
      { path: "/rules", response: { status: 200, body: [] } },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    // The payload must not escape the style element: no script node exists,
    // and every injected stylesheet is markup-free.
    expect(document.querySelector("script")).toBeNull();
    const styles = [...document.querySelectorAll("style")];
    expect(styles.length).toBeGreaterThan(0);
    for (const style of styles) {
      expect(style.textContent).not.toContain("<");
    }
    // The editor's own value stays exactly what was stored — sanitizing
    // happens at the injection point, never in the user's editing buffer.
    expect(
      (screen.getByLabelText("Custom CSS source") as HTMLTextAreaElement).value,
    ).toBe(hostile);
  });

  it("updates the header count from the PUT response (#124)", async () => {
    gotoRoute("session=3");
    bootPage();
    stubApi([
      ...EDITOR_ROUTES,
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: { status: 200, body: { ...SESSION_FULL, messageCount: 561 } },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");
    expect(screen.getByText(/saved messages: 2/)).toBeTruthy();

    // Any edit queues the debounced PUT; the response carries the count the
    // store now holds, and the header must adopt it without a reload.
    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "dark" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    expect(screen.getByText(/saved messages: 561/)).toBeTruthy();
  });

  it("serializes overlapping saves: an edit during an in-flight PUT waits it out (#164)", async () => {
    gotoRoute("session=3");
    bootPage();
    let releaseFirstPut!: () => void;
    const firstPutGate = new Promise<void>((resolve) => {
      releaseFirstPut = resolve;
    });
    let puts = 0;
    let inFlight = 0;
    let maxInFlight = 0;
    const { calls } = stubApi([
      ...EDITOR_ROUTES,
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: async () => {
          puts += 1;
          inFlight += 1;
          maxInFlight = Math.max(maxInFlight, inFlight);
          if (puts === 1) await firstPutGate;
          inFlight -= 1;
          return { status: 200, body: SESSION_FULL };
        },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    // First edit → debounced PUT 1, held open by the gate.
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "dark" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    expect(puts).toBe(1);

    // A second edit lands while PUT 1 is in flight. Its debounce fires, but
    // the in-flight guard parks the patch instead of overlapping.
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "light" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000);
    });
    expect(puts).toBe(1);

    // PUT 1 completes → the parked patch follows as PUT 2. Never concurrent.
    releaseFirstPut();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(3_000);
    });
    expect(puts).toBe(2);
    expect(maxInFlight).toBe(1);
    const followUp = calls.filter((c) => c.method === "PUT")[1];
    const editorState = (followUp.body as Record<string, unknown>)
      .editorState as Record<string, unknown>;
    expect(editorState.scheme).toBe("light");
  });

  it("flushes a pending edit when the editor unmounts before the debounce (#174 review)", async () => {
    gotoRoute("session=3");
    bootPage();
    const { calls } = stubApi([
      ...EDITOR_ROUTES,
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: { status: 200, body: SESSION_FULL },
      },
    ]);
    const view = render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "dark" },
    });
    // Unmount with the debounce still pending — navigation away must not
    // drop the edit; the cleanup flushes immediately.
    await act(async () => {
      view.unmount();
      await vi.advanceTimersByTimeAsync(0);
    });
    const put = calls.find((c) => c.method === "PUT");
    expect(put).toBeDefined();
    const editorState = (put!.body as Record<string, unknown>)
      .editorState as Record<string, unknown>;
    expect(editorState.scheme).toBe("dark");
  });

  it("completes a parked edit after unmount without a debounce wait (#174 review)", async () => {
    gotoRoute("session=3");
    bootPage();
    let releaseFirstPut!: () => void;
    const firstPutGate = new Promise<void>((resolve) => {
      releaseFirstPut = resolve;
    });
    let puts = 0;
    const { calls } = stubApi([
      ...EDITOR_ROUTES,
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: async () => {
          puts += 1;
          if (puts === 1) await firstPutGate;
          return { status: 200, body: SESSION_FULL };
        },
      },
    ]);
    const view = render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "dark" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    expect(puts).toBe(1); // in flight, held by the gate

    // A second edit parks, then the user navigates away mid-flight.
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "light" },
    });
    await act(async () => {
      view.unmount();
    });
    expect(puts).toBe(1);

    // When PUT 1 settles there is no debounce timer coming back — the
    // completion path must flush the parked patch straight away.
    releaseFirstPut();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(puts).toBe(2);
    const followUp = calls.filter((c) => c.method === "PUT")[1];
    const editorState = (followUp.body as Record<string, unknown>)
      .editorState as Record<string, unknown>;
    expect(editorState.scheme).toBe("light");
  });

  it("backs off between consecutive failed retries (#174 review)", async () => {
    gotoRoute("session=3");
    bootPage();
    let puts = 0;
    stubApi([
      ...EDITOR_ROUTES,
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: () => {
          puts += 1;
          return puts < 3
            ? { status: 500, body: { message: "boom" } }
            : { status: 200, body: SESSION_FULL };
        },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "dark" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    expect(puts).toBe(1); // failed; retry scheduled at 5s

    await act(async () => {
      await vi.advanceTimersByTimeAsync(5_100);
    });
    expect(puts).toBe(2); // failed again; next retry doubled to 10s

    await act(async () => {
      await vi.advanceTimersByTimeAsync(6_000);
    });
    expect(puts).toBe(2); // 6s < 10s: the backoff really grew

    await act(async () => {
      await vi.advanceTimersByTimeAsync(5_000);
    });
    expect(puts).toBe(3); // 11s ≥ 10s: third attempt, succeeds
    expect(screen.queryByText("Unsaved")).toBeNull();
  });

  it("retries a failed save on its own timer, not only on the next edit (#164)", async () => {
    gotoRoute("session=3");
    bootPage();
    let puts = 0;
    const { calls } = stubApi([
      ...EDITOR_ROUTES,
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: () => {
          puts += 1;
          return puts === 1
            ? { status: 500, body: { message: "boom" } }
            : { status: 200, body: SESSION_FULL };
        },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "dark" },
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    expect(puts).toBe(1);
    expect(screen.getByText("Unsaved")).toBeTruthy();

    // The retry timer re-sends the merged patch without user action…
    await act(async () => {
      await vi.advanceTimersByTimeAsync(6_000);
    });
    expect(puts).toBe(2);
    const retry = calls.filter((c) => c.method === "PUT")[1];
    const editorState = (retry.body as Record<string, unknown>)
      .editorState as Record<string, unknown>;
    expect(editorState.scheme).toBe("dark");
    // …and success clears the badge.
    expect(screen.queryByText("Unsaved")).toBeNull();
  });

  it("persists editor-state changes with a debounced PUT", async () => {
    gotoRoute("session=3");
    bootPage();
    const { calls } = stubApi([
      ...EDITOR_ROUTES,
      {
        method: "PUT",
        path: /\/sessions\/3$/,
        response: { status: 200, body: SESSION_FULL },
      },
    ]);
    render(<SessionEditorApp />);
    await screen.findByText("#session-log");

    vi.useFakeTimers();
    fireEvent.change(screen.getByLabelText("Transcript color scheme"), {
      target: { value: "dark" },
    });
    // The preview re-renders immediately (scheme baked into the fragment)…
    expect(document.querySelector(".slack-log.slk-dark")).not.toBeNull();
    expect(screen.getByTestId("chronicler-preview").className).toContain(
      "bg-[#1a1d21]",
    );

    // …and the debounced PUT lands after the save window.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1_500);
    });
    const put = calls.find((c) => c.method === "PUT");
    expect(put).toBeDefined();
    const editorState = (put!.body as Record<string, unknown>)
      .editorState as Record<string, unknown>;
    expect(editorState.scheme).toBe("dark");
  });
});
