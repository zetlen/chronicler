import { beforeEach, describe, expect, it } from "vitest";
import { setDiagnostics } from "@codemirror/lint";
import { mountGameSystemEditor } from "./mount";

const STARTER = "system: Example\nversion: 1\nproperties:\n  - id: a\n";

function makePage(value: string) {
  // Mirrors the PHP configurator markup: the submit button lives in its own
  // <p> row, which the mount turns into the shared save/convert row.
  document.body.innerHTML = `
    <form method="post">
      <textarea name="chronicler_template_json">${value}</textarea>
      <p><button class="button button-primary">Validate &amp; Save</button></p>
    </form>`;
  return {
    form: document.querySelector("form")!,
    textarea: document.querySelector<HTMLTextAreaElement>("textarea")!,
  };
}

const convertBtn = () =>
  [...document.querySelectorAll<HTMLButtonElement>("button")].find(
    (b) => b.textContent === "Convert to YAML",
  )!;

const mount = () => mountGameSystemEditor(document, () => [], STARTER);

const statusText = () =>
  document.querySelector(".chronicler-game-system-footer span")!.textContent;

beforeEach(() => {
  document.body.innerHTML = "";
});

describe("mountGameSystemEditor", () => {
  it("returns null when the page has no configurator textarea", () => {
    expect(mount()).toBeNull();
  });

  it("hides the textarea and loads its content into the editor", () => {
    const { textarea } = makePage("system: Loaded");
    const view = mount()!;
    expect(view.state.doc.toString()).toBe("system: Loaded");
    expect(textarea.hidden).toBe(true);
    expect(document.querySelector(".chronicler-game-system-editor")).not.toBeNull();
  });

  it("leaves the plain textarea untouched when the editor fails to build", () => {
    const { textarea } = makePage("system: Loaded");
    const view = mountGameSystemEditor(
      document,
      () => {
        throw new Error("duplicate @codemirror/state");
      },
      STARTER,
    );
    expect(view).toBeNull();
    expect(textarea.hidden).toBe(false);
    expect(document.querySelector(".chronicler-game-system-editor")).toBeNull();
    expect(document.querySelector(".chronicler-game-system-footer")).toBeNull();
  });

  it("mirrors the editor document to the textarea on every change", () => {
    const { textarea } = makePage("system: Old");
    const view = mount()!;
    view.dispatch({
      changes: { from: 0, to: view.state.doc.length, insert: "system: New" },
    });
    // No submit event needed — programmatic form.submit() must post this too.
    expect(textarea.value).toBe("system: New");
  });

  it("also syncs on the submit event (belt and braces)", () => {
    const { form, textarea } = makePage("system: Old");
    const view = mount()!;
    view.dispatch({
      changes: { from: 0, to: view.state.doc.length, insert: "system: New" },
    });
    form.dispatchEvent(new Event("submit"));
    expect(textarea.value).toBe("system: New");
  });

  it("offers the example only while the document is empty", () => {
    makePage("");
    const view = mount()!;
    const button = document.querySelector<HTMLButtonElement>(
      ".chronicler-game-system-footer button",
    )!;
    expect(button.style.display).not.toBe("none");
    button.click();
    expect(view.state.doc.toString()).toBe(STARTER);
    expect(button.style.display).toBe("none");
    button.click(); // guarded: never overwrites a non-empty document
    expect(view.state.doc.toString()).toBe(STARTER);
  });

  it("never claims a clean template before a lint pass has landed", () => {
    makePage("system: Loaded");
    const view = mount()!;
    expect(statusText()).toBe("Checking the template…");
    view.dispatch(setDiagnostics(view.state, []));
    expect(statusText()).toBe("No problems found.");
    view.dispatch({ changes: { from: 0, insert: "# edit\n" } });
    expect(statusText()).toBe("Checking the template…");
  });

  it("counts errors and warnings; info-only results surface as a note", () => {
    makePage("system: Loaded");
    const view = mount()!;
    view.dispatch(
      setDiagnostics(view.state, [
        { from: 0, to: 6, severity: "error", message: "bad" },
        { from: 0, to: 6, severity: "warning", message: "iffy" },
      ]),
    );
    expect(statusText()).toBe(
      "2 problems found — hover the marked text for details.",
    );
    view.dispatch(
      setDiagnostics(view.state, [
        { from: 0, to: 6, severity: "info", message: "The check couldn't run." },
      ]),
    );
    expect(statusText()).toBe("The check couldn't run.");
  });

  it("shows a starting hint for empty documents", () => {
    makePage("");
    mount();
    expect(statusText()).toMatch(/example/);
  });

  it("offers YAML conversion only while the document is JSON", () => {
    const json = JSON.stringify({
      system: "Demo",
      version: 1,
      properties: [{ id: "a", label: "A", type: "number" }],
    });
    const { textarea } = makePage(json.replaceAll('"', "&quot;"));
    textarea.value = json; // makePage HTML-escaping is lossy for quotes
    const view = mountGameSystemEditor(document, () => [], STARTER)!;
    // Rebuild the view over the corrected textarea value.
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: json } });
    const convert = convertBtn();
    expect(convert.style.display).not.toBe("none");
    convert.click();
    const converted = view.state.doc.toString();
    expect(converted).toContain("system: Demo");
    expect(converted).not.toContain("{");
    expect(textarea.value).toBe(converted); // mirror keeps the form current
    expect(convert.style.display).toBe("none"); // now YAML — nothing to convert
    convert.click(); // guarded: a stale click never rewrites YAML
    expect(view.state.doc.toString()).toBe(converted);
  });

  it("hides the convert button for YAML documents", () => {
    makePage("system: Demo");
    mount();
    expect(convertBtn().style.display).toBe("none");
  });

  it("shares the save row: Validate & Save left, Convert to YAML right", () => {
    makePage('{"system": "Demo", "version": 1, "properties": []}');
    mount();
    const save = document.querySelector<HTMLButtonElement>("button.button-primary")!;
    const row = save.parentElement!;
    expect(convertBtn().parentElement).toBe(row);
    expect(row.style.display).toBe("flex");
    expect(row.style.justifyContent).toBe("space-between");
    expect(row.firstElementChild).toBe(save);
    expect(row.lastElementChild).toBe(convertBtn());
  });

  it("falls back to the footer when the save row isn't found", () => {
    document.body.innerHTML = `
      <form method="post">
        <textarea name="chronicler_template_json">{"system":"D","version":1,"properties":[]}</textarea>
        <input type="submit" value="Save">
      </form>`;
    mount();
    expect(convertBtn().parentElement!.className).toBe(
      "chronicler-game-system-footer",
    );
  });
});
