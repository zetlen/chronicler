import { describe, it, expect, vi, afterEach } from "vitest";
import { apiFetch, ApiError } from "@/components/admin/apiFetch";

afterEach(() => {
  vi.unstubAllGlobals();
  delete window.chroniclerBoot;
});

type FetchCall = { url: string; init?: RequestInit };

/** Stub fetch returning `status` + JSON `body`; records calls. */
function stubFetch(status: number, body?: unknown) {
  const calls: FetchCall[] = [];
  const stub = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(input), init });
    return {
      ok: status >= 200 && status < 300,
      status,
      json: async () => {
        if (body === undefined) throw new Error("no body");
        return body;
      },
    } as Response;
  });
  vi.stubGlobal("fetch", stub);
  return { stub, calls };
}

function boot(apiBase = "/wp-json/chronicler/v1/") {
  window.chroniclerBoot = { apiBase, nonce: "test-nonce" };
}

describe("apiFetch", () => {
  it("prefixes apiBase and sends the X-WP-Nonce header", async () => {
    boot("/wp-json/chronicler/v1/");
    const { calls } = stubFetch(200, { ok: true });
    await apiFetch("/slack/conversations.list");
    expect(calls).toHaveLength(1);
    expect(calls[0].url).toBe("/wp-json/chronicler/v1/slack/conversations.list");
    const headers = new Headers(calls[0].init?.headers);
    expect(headers.get("X-WP-Nonce")).toBe("test-nonce");
    expect(headers.get("Accept")).toBe("application/json");
    expect(calls[0].init?.credentials).toBe("same-origin");
  });

  it("joins cleanly when neither side has a slash", async () => {
    boot("/wp-json/chronicler/v1");
    const { calls } = stubFetch(200, {});
    await apiFetch("sessions");
    expect(calls[0].url).toBe("/wp-json/chronicler/v1/sessions");
  });

  it("chains a path's query onto a plain-permalink base with & (#174 review)", async () => {
    boot("/?rest_route=/chronicler/v1");
    const { calls } = stubFetch(200, []);
    await apiFetch("sessions?page=1&per_page=50");
    // A second `?` would make PHP parse rest_route as "/…/sessions?page=1"
    // and 404; the query must fold into the base's own query string.
    expect(calls[0].url).toBe("/?rest_route=/chronicler/v1/sessions&page=1&per_page=50");
  });

  it("leaves a path query untouched on a pretty-permalink base", async () => {
    boot("/wp-json/chronicler/v1");
    const { calls } = stubFetch(200, []);
    await apiFetch("sessions?page=2&per_page=50");
    expect(calls[0].url).toBe("/wp-json/chronicler/v1/sessions?page=2&per_page=50");
  });

  it("returns the parsed JSON body", async () => {
    boot();
    stubFetch(200, { channels: [{ id: "C1" }] });
    await expect(apiFetch("slack/conversations.list")).resolves.toEqual({
      channels: [{ id: "C1" }],
    });
  });

  it("sets Content-Type for JSON bodies without clobbering an explicit one", async () => {
    boot();
    const { calls } = stubFetch(200, {});
    await apiFetch("sessions", { method: "POST", body: JSON.stringify({ a: 1 }) });
    expect(new Headers(calls[0].init?.headers).get("Content-Type")).toBe(
      "application/json",
    );

    await apiFetch("sessions", {
      method: "POST",
      body: "raw",
      headers: { "Content-Type": "text/plain" },
    });
    expect(new Headers(calls[1].init?.headers).get("Content-Type")).toBe(
      "text/plain",
    );
  });

  it("throws a typed error carrying the HTTP status and the WP error message", async () => {
    boot();
    stubFetch(403, { code: "rest_forbidden", message: "Sorry, not allowed." });
    const err = await apiFetch("sessions").catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(403);
    expect((err as ApiError).message).toBe("Sorry, not allowed.");
    expect((err as ApiError).retryAfter).toBeUndefined();
  });

  it("falls back to a generic message when the error body isn't WP-shaped", async () => {
    boot();
    stubFetch(500); // json() rejects — e.g. an HTML error page
    const err = await apiFetch("sessions").catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).message).toBe("Request failed (HTTP 500).");
  });

  it("parses {retry_after} into retryAfter on 429 (the #99 proxy contract)", async () => {
    boot();
    stubFetch(429, { retry_after: "30" });
    const err = await apiFetch("slack/conversations.history").catch(
      (e: unknown) => e,
    );
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(429);
    expect((err as ApiError).retryAfter).toBe(30);
  });

  it("leaves retryAfter unset when the 429 body has no usable retry_after", async () => {
    boot();
    stubFetch(429, { message: "slow down" });
    const err = await apiFetch("slack/conversations.history").catch(
      (e: unknown) => e,
    );
    expect((err as ApiError).retryAfter).toBeUndefined();
  });

  it("resolves undefined for 204 responses", async () => {
    boot();
    stubFetch(204);
    await expect(apiFetch("sessions/1", { method: "DELETE" })).resolves.toBeUndefined();
  });

  it("throws a status-0 ApiError when window.chroniclerBoot is missing", async () => {
    const { stub } = stubFetch(200, {});
    const err = await apiFetch("sessions").catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).status).toBe(0);
    expect(stub).not.toHaveBeenCalled();
  });
});
