import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createPreflight } from "./preflight";

const boot = { preflightUrl: "https://example.test/preflight", nonce: "n0nce" };

const doc = `system: Demo
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
    derived: "1 + + "
`;

type FetchStub = ReturnType<typeof vi.fn>;
let fetchStub: FetchStub;

const respondWith = (body: unknown, ok = true) =>
  Promise.resolve({
    ok,
    json: () => Promise.resolve(body),
  } as Response);

beforeEach(() => {
  fetchStub = vi.fn();
  vi.stubGlobal("fetch", fetchStub);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("createPreflight", () => {
  it("returns no diagnostics when the server says valid", async () => {
    fetchStub.mockReturnValue(respondWith({ valid: true, system: "Demo" }));
    const preflight = createPreflight(boot);
    expect(await preflight(doc)).toEqual([]);
    expect(fetchStub).toHaveBeenCalledWith(
      boot.preflightUrl,
      expect.objectContaining({
        method: "POST",
        headers: expect.objectContaining({ "X-WP-Nonce": boot.nonce }),
      }),
    );
    const body = JSON.parse(
      (fetchStub.mock.calls[0][1] as RequestInit).body as string,
    );
    expect(body).toEqual({ source: doc });
  });

  it("maps an invalid verdict to one diagnostic anchored at the named id", async () => {
    fetchStub.mockReturnValue(
      respondWith({
        valid: false,
        code: "chronicler_formula_syntax",
        message: 'Property "vigor": Unexpected token "operator" of value "+".',
      }),
    );
    const preflight = createPreflight(boot);
    const diags = await preflight(doc);
    expect(diags).toHaveLength(1);
    expect(diags[0].severity).toBe("error");
    expect(diags[0].message).toContain("vigor");
    expect(doc.slice(diags[0].from, diags[0].to)).toBe("vigor");
  });

  it("anchors to the first line when the message names nothing findable", async () => {
    fetchStub.mockReturnValue(
      respondWith({ valid: false, message: "Something went sideways." }),
    );
    const preflight = createPreflight(boot);
    const diags = await preflight(doc);
    expect(diags).toHaveLength(1);
    expect(diags[0].from).toBe(0);
    expect(diags[0].to).toBe(doc.indexOf("\n"));
  });

  it("reports an unavailable check as an info note, never a clean verdict", async () => {
    fetchStub.mockReturnValue(Promise.reject(new Error("offline")));
    const offline = await createPreflight(boot)(doc);
    expect(offline).toHaveLength(1);
    expect(offline[0].severity).toBe("info");
    expect(offline[0].message).toMatch(/Validate & Save/);
    fetchStub.mockReturnValue(respondWith({}, false));
    const denied = await createPreflight(boot)(doc);
    expect(denied).toHaveLength(1);
    expect(denied[0].severity).toBe("info");
  });

  it("retries after a failure instead of caching it", async () => {
    fetchStub.mockReturnValueOnce(Promise.reject(new Error("blip")));
    fetchStub.mockReturnValue(respondWith({ valid: true }));
    const preflight = createPreflight(boot);
    expect((await preflight(doc))[0]?.severity).toBe("info");
    expect(await preflight(doc)).toEqual([]);
    expect(fetchStub).toHaveBeenCalledTimes(2);
  });

  it("anchors at the property's declaration, not an earlier mention", async () => {
    const shadowed = `system: vigor chronicles
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
`;
    fetchStub.mockReturnValue(
      respondWith({ valid: false, message: 'Property "vigor": broken.' }),
    );
    const diags = await createPreflight(boot)(shadowed);
    expect(shadowed.slice(diags[0].from, diags[0].to)).toBe("vigor");
    expect(diags[0].from).toBe(shadowed.indexOf("id: vigor") + "id: ".length);
  });

  it("caches the verdict per source text", async () => {
    fetchStub.mockReturnValue(respondWith({ valid: true }));
    const preflight = createPreflight(boot);
    await preflight(doc);
    await preflight(doc);
    expect(fetchStub).toHaveBeenCalledTimes(1);
    await preflight(doc + "\n# changed");
    expect(fetchStub).toHaveBeenCalledTimes(2);
  });
});
