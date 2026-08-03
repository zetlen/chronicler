import { afterEach, describe, expect, it, vi } from "vitest";
import {
  configApiCall,
  extractTunnelUrl,
  parseEnv,
  renderManifestJson,
  restBase,
  upsertEnv,
} from "./sandbox.mjs";

describe("parseEnv", () => {
  it("reads KEY=value lines and keeps '=' inside values", () => {
    expect(parseEnv("A=1\nB=x=y\n# note\nnot a pair\n")).toEqual({
      A: "1",
      B: "x=y",
    });
  });

  it("strips one layer of matching surrounding quotes", () => {
    expect(parseEnv('A="quoted"\nB=\'single\'\nC="mismatched\'\nD=bare\n')).toEqual({
      A: "quoted",
      B: "single",
      C: "\"mismatched'",
      D: "bare",
    });
  });

  it("tolerates CRLF line endings", () => {
    expect(parseEnv('A=1\r\nB="quoted"\r\n')).toEqual({
      A: "1",
      B: "quoted",
    });
  });
});

describe("upsertEnv", () => {
  it("rewrites existing keys in place and preserves everything else", () => {
    const out = upsertEnv("# keep me\nA=old\nB=2", { A: "new" });
    expect(out).toBe("# keep me\nA=new\nB=2\n");
  });

  it("appends missing keys at the end", () => {
    const out = upsertEnv("A=1\n", { NEW: "v" });
    expect(out).toBe("A=1\nNEW=v\n");
  });

  it("handles a file with no trailing newline", () => {
    expect(upsertEnv("A=1", { B: "2" })).toBe("A=1\nB=2\n");
  });
});

describe("renderManifestJson", () => {
  const YAML = 'display_information:\n  name: T\nsettings:\n  interactivity:\n    request_url: "{{REST_BASE}}/slack/inbound/interactions"\n';

  it("substitutes REST_BASE and returns JSON", () => {
    const json = JSON.parse(renderManifestJson(YAML, "https://x.example/wp-json/chronicler/v1"));
    expect(json.settings.interactivity.request_url).toBe(
      "https://x.example/wp-json/chronicler/v1/slack/inbound/interactions",
    );
  });

  it("refuses a manifest without the placeholder", () => {
    expect(() => renderManifestJson("name: T\n", "https://x.example")).toThrow(/REST_BASE/);
  });
});

describe("extractTunnelUrl", () => {
  it("finds the trycloudflare origin in cloudflared chatter", () => {
    const noise = "INF +--+\nINF |  https://tall-word-pairs.trycloudflare.com  |\n";
    expect(extractTunnelUrl(noise)).toBe("https://tall-word-pairs.trycloudflare.com");
  });

  it("returns null when no URL has appeared yet", () => {
    expect(extractTunnelUrl("starting tunnel…")).toBeNull();
  });
});

describe("restBase", () => {
  it("appends the plugin REST namespace", () => {
    expect(restBase("https://x.example")).toBe("https://x.example/wp-json/chronicler/v1");
  });
});

const jsonResponse = (body: object) => ({ json: async () => body });

describe("configApiCall", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("passes a successful call straight through", async () => {
    vi.stubGlobal("fetch", async () => jsonResponse({ ok: true, app_id: "A1" }));
    const data = await configApiCall(
      "apps.manifest.update",
      { app_id: "A1" },
      { token: "xoxe-ok", refresh_token: "xoxe-1-r" },
      async () => { throw new Error("must not persist"); },
    );
    expect(data.app_id).toBe("A1");
  });

  it("rotates, persists, and retries once when the token has expired", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const events: string[] = [];
    vi.stubGlobal("fetch", async (url: string, init: RequestInit) => {
      calls.push({ url: String(url), init });
      const urlStr = String(url);
      if (calls.length === 1) {
        events.push("fetch:1:apps.manifest.update");
        return jsonResponse({ ok: false, error: "token_expired" });
      }
      if (urlStr.endsWith("tooling.tokens.rotate")) {
        events.push("fetch:2:tooling.tokens.rotate");
        return jsonResponse({ ok: true, token: "xoxe-new", refresh_token: "xoxe-1-new" });
      }
      events.push("fetch:3:retry");
      return jsonResponse({ ok: true, app_id: "A1" });
    });
    const persisted: object[] = [];
    const data = await configApiCall(
      "apps.manifest.update",
      { app_id: "A1" },
      { token: "xoxe-old", refresh_token: "xoxe-1-old" },
      async (t: { token: string; refresh_token: string }) => {
        persisted.push(t);
        events.push("persist");
      },
    );
    expect(persisted).toEqual([{ token: "xoxe-new", refresh_token: "xoxe-1-new" }]);
    expect(data.app_id).toBe("A1");
    expect((calls.at(-1)!.init.headers as Record<string, string>).Authorization).toBe(
      "Bearer xoxe-new",
    );
    // Verify ordering: persist must happen before the retry fetch
    const persistIndex = events.indexOf("persist");
    const retryIndex = events.indexOf("fetch:3:retry");
    expect(persistIndex).toBeGreaterThan(-1);
    expect(retryIndex).toBeGreaterThan(-1);
    expect(persistIndex).toBeLessThan(retryIndex);
  });

  it("throws regeneration instructions when rotation is refused", async () => {
    vi.stubGlobal("fetch", async (url: string) =>
      String(url).endsWith("tooling.tokens.rotate")
        ? jsonResponse({ ok: false, error: "invalid_refresh_token" })
        : jsonResponse({ ok: false, error: "invalid_auth" }),
    );
    await expect(
      configApiCall("apps.manifest.update", {}, { token: "x", refresh_token: "r" }, async () => {}),
    ).rejects.toThrow(/api\.slack\.com\/apps/);
  });

  it("surfaces non-auth API errors with the method name", async () => {
    vi.stubGlobal("fetch", async () => jsonResponse({ ok: false, error: "invalid_manifest" }));
    await expect(
      configApiCall("apps.manifest.update", {}, { token: "x", refresh_token: "r" }, async () => {}),
    ).rejects.toThrow("apps.manifest.update failed: invalid_manifest");
  });
});
