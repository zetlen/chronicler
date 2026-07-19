import { describe, it, expect, vi } from "vitest";
import { nextTrackValue, initSheet } from "./sheet.js";

function sheetDom(boot = { restUrl: "https://blog.test/wp-json/chronicler/v1/", nonce: "n0nce", canEdit: true, characterId: 7 }) {
  document.body.innerHTML = `
    <article class="chr-sheet" data-chronicler-sheet>
      <script type="application/json" id="chronicler-sheet-boot">${JSON.stringify(boot)}</script>
      <div class="chr-sheet__error" hidden></div>
      <div class="chr-prop" data-prop="harm" data-type="track">
        <span class="chr-track">
          <button type="button" class="chr-track__box" data-index="0" aria-pressed="true"></button>
          <button type="button" class="chr-track__box" data-index="1" aria-pressed="false"></button>
          <button type="button" class="chr-track__box" data-index="2" aria-pressed="false"></button>
        </span>
        <span class="chr-prop__display">1/3</span>
      </div>
      <div class="chr-prop" data-prop="cool" data-type="number">
        <button type="button" class="chr-step" data-step="-1">-</button>
        <span class="chr-prop__value">0</span>
        <button type="button" class="chr-step" data-step="1">+</button>
        <span class="chr-prop__display">0</span>
      </div>
      <div class="chr-prop" data-prop="cool_static" data-type="number">
        <span class="chr-prop__static">+1</span>
        <span class="chr-prop__display">+1</span>
      </div>
      <div class="chr-prop" data-prop="bloodied" data-type="toggle">
        <span class="chr-prop__static">off</span>
      </div>
    </article>`;
  return document.querySelector("[data-chronicler-sheet]") as HTMLElement;
}

const jsonResponse = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });

describe("nextTrackValue", () => {
  it("marks up to the clicked box", () => {
    expect(nextTrackValue(1, 2)).toBe(3);
  });
  it("clicking the last marked box unmarks it", () => {
    expect(nextTrackValue(3, 2)).toBe(2);
  });
  it("clicking an earlier marked box truncates to it", () => {
    expect(nextTrackValue(3, 0)).toBe(1);
  });
});

describe("initSheet", () => {
  it("POSTs a set op for a track click and reconciles to the server value", async () => {
    const root = sheetDom();
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ prop: "harm", value: 3, display: "3/3" }));
    initSheet(root, fetchMock);

    (root.querySelectorAll(".chr-track__box")[2] as HTMLElement).click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("https://blog.test/wp-json/chronicler/v1/characters/7/properties/harm");
    expect(init.headers).toMatchObject({ "X-WP-Nonce": "n0nce" });
    expect(JSON.parse(String(init.body))).toEqual({ op: "set", value: 3 });
    await vi.waitFor(() =>
      expect(root.querySelector('[data-prop="harm"] .chr-prop__display')!.textContent).toBe("3/3"),
    );
  });

  it("POSTs an adjust op for a stepper click", async () => {
    const root = sheetDom();
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ prop: "cool", value: 1, display: "+1" }));
    initSheet(root, fetchMock);

    (root.querySelector('[data-prop="cool"] [data-step="1"]') as HTMLElement).click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(JSON.parse(String(fetchMock.mock.calls[0][1].body))).toEqual({ op: "adjust", value: 1 });
    await vi.waitFor(() =>
      expect(root.querySelector('[data-prop="cool"] .chr-prop__value')!.textContent).toBe("1"),
    );
  });

  it("shows the server's message and re-syncs on a rejected write", async () => {
    const root = sheetDom();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ code: "chronicler_bad_value", message: "Harm: needs a whole number." }, 400))
      .mockResolvedValueOnce(
        jsonResponse({
          characterId: 7,
          properties: [{ id: "harm", type: "track", value: 1, display: "1/3" }],
        }),
      );
    initSheet(root, fetchMock);

    (root.querySelectorAll(".chr-track__box")[2] as HTMLElement).click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    const error = root.querySelector(".chr-sheet__error") as HTMLElement;
    expect(error.hidden).toBe(false);
    expect(error.textContent).toContain("needs a whole number");
    // Reconciled back to the server's truth:
    expect(root.querySelectorAll('.chr-track__box[aria-pressed="true"]').length).toBe(1);
  });

  it("does not throw when the boot node is missing or malformed", () => {
    const missing = document.createElement("div");
    expect(() => initSheet(missing, vi.fn())).not.toThrow();

    const malformed = document.createElement("div");
    malformed.innerHTML =
      '<script type="application/json" id="chronicler-sheet-boot">{not json</script>';
    expect(() => initSheet(malformed, vi.fn())).not.toThrow();
  });

  it("resync skips properties that render without controls", async () => {
    const root = sheetDom();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ code: "x", message: "no" }, 400))
      .mockResolvedValueOnce(
        jsonResponse({
          characterId: 7,
          properties: [
            { id: "harm", type: "track", value: 1, display: "1/3" },
            { id: "cool_static", type: "number", value: 2, display: "+2" },
          ],
        }),
      );
    initSheet(root, fetchMock);
    (root.querySelectorAll(".chr-track__box")[2] as HTMLElement).click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    // The static prop has no .chr-prop__value control; renderValue must not throw,
    // and the display badge still updates.
    expect(root.querySelector('[data-prop="cool_static"] .chr-prop__display')!.textContent).toBe("+2");
  });

  it("reconciles derived changes carried on the write response (#53)", async () => {
    const root = sheetDom();
    // A Harm write comes back with a derived change to another property;
    // the sheet should apply it without a separate re-sync.
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        prop: "harm",
        value: 3,
        display: "3/3",
        derived: [{ prop: "cool", value: 2, display: "+2" }],
      }),
    );
    initSheet(root, fetchMock);

    (root.querySelectorAll(".chr-track__box")[2] as HTMLElement).click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    await vi.waitFor(() =>
      expect(root.querySelector('[data-prop="cool"] .chr-prop__display')!.textContent).toBe("+2"),
    );
    expect(root.querySelector('[data-prop="cool"] .chr-prop__value')!.textContent).toBe("2");
  });

  it("reconciles a derived property's static text (#88)", async () => {
    const root = sheetDom();
    // Formula-derived properties render statically (never editable); the
    // write echo must still update their text in place.
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        prop: "harm",
        value: 3,
        display: "3/3",
        derived: [{ prop: "bloodied", value: true, display: "on" }],
      }),
    );
    initSheet(root, fetchMock);

    (root.querySelectorAll(".chr-track__box")[2] as HTMLElement).click();
    await vi.waitFor(() =>
      expect(
        root.querySelector('[data-prop="bloodied"] .chr-prop__static')!.textContent,
      ).toBe("on"),
    );
  });
});

describe("initSheet — opinions (#183)", () => {
  function opinionDom(
    boot: Record<string, unknown> = {
      restUrl: "https://blog.test/wp-json/chronicler/v1/",
      nonce: "n0nce",
      canEdit: false,
      canOpine: true,
      characterId: 9,
    },
  ) {
    const box = (i: number, pressed: boolean) =>
      `<button type="button" class="chr-track__box" data-index="${i}" aria-pressed="${pressed}"></button>`;
    document.body.innerHTML = `
      <article class="chr-sheet" data-chronicler-sheet>
        <script type="application/json" id="chronicler-sheet-boot">${JSON.stringify(boot)}</script>
        <div class="chr-sheet__error" hidden></div>
        <div class="chr-prop chr-opinion" data-prop="opinions" data-pc="21" data-type="opinions" data-length="6">
          <span class="chr-prop__label">Alec’s Opinion</span>
          <span class="chr-track">${[0, 1, 2, 3, 4, 5].map((i) => box(i, i < 2)).join("")}</span>
          <span class="chr-prop__display">2/6</span>
          <textarea class="chr-longtext chr-opinion__notes">shifty</textarea>
        </div>
        <div class="chr-prop chr-opinion" data-prop="opinions" data-pc="22" data-type="opinions" data-length="6">
          <span class="chr-prop__label">Sam’s Opinion</span>
          <span class="chr-prop__static">
            <span class="chr-track__box" aria-hidden="true" data-marked="1"></span>
            <span class="chr-track__box" aria-hidden="true" data-marked="0"></span>
          </span>
          <span class="chr-prop__display">1/6</span>
        </div>
      </article>`;
    return document.querySelector("[data-chronicler-sheet]") as HTMLElement;
  }

  it("activates on canOpine alone and posts a rating click to the opinions route", async () => {
    const root = opinionDom();
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ prop: "opinions", pc: 21, value: { rating: 4, notes: "shifty" }, display: "4/6" }),
    );
    initSheet(root, fetchMock);

    (root.querySelectorAll('[data-pc="21"] .chr-track__box')[3] as HTMLElement).click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("https://blog.test/wp-json/chronicler/v1/characters/9/opinions/opinions");
    expect(JSON.parse(String(init.body))).toEqual({ pc: 21, field: "rating", op: "set", value: 4 });
    await vi.waitFor(() =>
      expect(root.querySelector('[data-pc="21"] .chr-prop__display')!.textContent).toBe("4/6"),
    );
    expect(
      root.querySelectorAll('[data-pc="21"] .chr-track__box[aria-pressed="true"]').length,
    ).toBe(4);
  });

  it("posts a notes change as the notes field of the same set", async () => {
    const root = opinionDom();
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ prop: "opinions", pc: 21, value: { rating: 2, notes: "reformed" }, display: "2/6" }),
    );
    initSheet(root, fetchMock);

    const notes = root.querySelector('[data-pc="21"] .chr-opinion__notes') as HTMLTextAreaElement;
    notes.value = "reformed";
    notes.dispatchEvent(new Event("change", { bubbles: true }));
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(JSON.parse(String(fetchMock.mock.calls[0][1].body))).toEqual({
      pc: 21,
      field: "notes",
      op: "set",
      value: "reformed",
    });
  });

  it("stays inert when the viewer can neither edit nor opine", () => {
    const root = opinionDom({
      restUrl: "https://blog.test/wp-json/chronicler/v1/",
      nonce: "n0nce",
      canEdit: false,
      canOpine: false,
      characterId: 9,
    });
    const fetchMock = vi.fn();
    initSheet(root, fetchMock);
    (root.querySelectorAll('[data-pc="21"] .chr-track__box')[3] as HTMLElement).click();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("re-syncs each set by pc after a rejected write", async () => {
    const root = opinionDom();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ code: "chronicler_forbidden", message: "Not your pen." }, 403))
      .mockResolvedValueOnce(
        jsonResponse({
          characterId: 9,
          properties: [
            {
              id: "opinions",
              type: "opinions",
              value: { "21": { rating: 1, notes: "server truth" }, "22": { rating: 5, notes: "hidden" } },
            },
          ],
        }),
      );
    initSheet(root, fetchMock);

    (root.querySelectorAll('[data-pc="21"] .chr-track__box')[3] as HTMLElement).click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    const error = root.querySelector(".chr-sheet__error") as HTMLElement;
    expect(error.hidden).toBe(false);
    expect(error.textContent).toContain("Not your pen");
    // The editable set reconciled to the server's truth…
    expect(
      root.querySelectorAll('[data-pc="21"] .chr-track__box[aria-pressed="true"]').length,
    ).toBe(1);
    expect((root.querySelector('[data-pc="21"] .chr-opinion__notes') as HTMLTextAreaElement).value).toBe("server truth");
    expect(root.querySelector('[data-pc="21"] .chr-prop__display')!.textContent).toBe("1/6");
    // …and the static set's markup was left alone (it has no controls).
    expect(
      root.querySelectorAll('[data-pc="22"] .chr-track__box[data-marked="1"]').length,
    ).toBe(1);
  });
});
