import { describe, expect, it } from "vitest";
import { EditorView } from "@codemirror/view";
import { yaml } from "@codemirror/lang-yaml";
import { tabKeymap } from "./tabKeymap";

function makeView(doc: string, cursor: number) {
  const view = new EditorView({
    doc,
    parent: document.body,
    extensions: [yaml(), tabKeymap],
  });
  view.dispatch({ selection: { anchor: cursor } });
  return view;
}

const pressTab = (view: EditorView, shift = false) =>
  view.contentDOM.dispatchEvent(
    new KeyboardEvent("keydown", {
      key: "Tab",
      shiftKey: shift,
      bubbles: true,
      cancelable: true,
    }),
  );

describe("tabKeymap", () => {
  it("indents the current line with spaces, never a tab character", () => {
    const view = makeView("- id: vigor\n", 2);
    pressTab(view);
    expect(view.state.doc.toString()).toBe("  - id: vigor\n");
    expect(view.state.doc.toString()).not.toContain("\t");
    view.destroy();
  });

  it("keeps the event from tabbing focus away", () => {
    const view = makeView("system: Demo\n", 0);
    const event = new KeyboardEvent("keydown", {
      key: "Tab",
      bubbles: true,
      cancelable: true,
    });
    view.contentDOM.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
    view.destroy();
  });

  it("Shift-Tab dedents", () => {
    const view = makeView("    label: Vigor\n", 6);
    pressTab(view, true);
    expect(view.state.doc.toString()).toBe("  label: Vigor\n");
    view.destroy();
  });

  it("indents every selected line", () => {
    const view = new EditorView({
      doc: "a: 1\nb: 2\n",
      parent: document.body,
      extensions: [yaml(), tabKeymap],
    });
    view.dispatch({ selection: { anchor: 0, head: view.state.doc.length } });
    pressTab(view);
    expect(view.state.doc.toString()).toBe("  a: 1\n  b: 2\n");
    view.destroy();
  });
});
