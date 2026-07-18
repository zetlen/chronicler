import { describe, it, expect, beforeEach, afterEach } from "vitest";
// Same one-implementation contract as interactions.test.ts: the toggle ships
// in the plugin's side-effect-free core; transcript.js self-initializes it.
//
// Hidden is the SERVER default: blocks.php prints display:none for
// .slk-msg--ooc unless the root carries slk-ooc-shown. This suite covers the
// JS half — the checkbox is the only thing that can add that class.
import { attachOocToggle } from "@/wordpress-plugin/transcript-core";

/** A transcript with two story messages and two OOC messages. */
function transcript(oocCount = 2): HTMLElement {
  const root = document.createElement("div");
  root.className = "slack-log";
  const msg = (id: string, ooc: boolean) => `
    <div class="slk-msg slk-msg--text${ooc ? " slk-msg--ooc" : ""}" id="msg-${id}">
      <div class="slk-msg__main"><div class="slk-msg__body">m${id}</div></div>
    </div>`;
  root.innerHTML =
    msg("1", false) + msg("2", oocCount > 0) + msg("3", false) + msg("4", oocCount > 1);
  document.body.appendChild(root);
  return root;
}

const checkbox = (root: HTMLElement) =>
  root.querySelector(".slk-ooc-toggle input") as HTMLInputElement | null;

beforeEach(() => {
  history.replaceState(null, "", "/post");
});

afterEach(() => {
  document.body.innerHTML = "";
});

describe("attachOocToggle", () => {
  it("renders the checkbox, unchecked, without granting slk-ooc-shown", () => {
    const root = transcript();
    attachOocToggle(root);
    const box = checkbox(root);
    expect(box).not.toBeNull();
    expect(box!.checked).toBe(false);
    expect(root.textContent).toContain("Show OOC messages");
    // Hidden stays the server default until the reader opts in.
    expect(root.classList.contains("slk-ooc-shown")).toBe(false);
  });

  it("renders no control on a transcript without OOC messages", () => {
    const root = transcript(0);
    attachOocToggle(root);
    expect(checkbox(root)).toBeNull();
  });

  it("checking reveals, unchecking re-hides", () => {
    const root = transcript();
    attachOocToggle(root);
    const box = checkbox(root)!;

    box.checked = true;
    box.dispatchEvent(new Event("change", { bubbles: true }));
    expect(root.classList.contains("slk-ooc-shown")).toBe(true);

    box.checked = false;
    box.dispatchEvent(new Event("change", { bubbles: true }));
    expect(root.classList.contains("slk-ooc-shown")).toBe(false);
  });

  it("starts revealed when the page deep-links to an OOC message", () => {
    history.replaceState(null, "", "/post#msg-2"); // msg-2 is OOC
    const root = transcript();
    attachOocToggle(root);
    expect(root.classList.contains("slk-ooc-shown")).toBe(true);
    expect(checkbox(root)!.checked).toBe(true);
  });

  it("stays hidden when the deep link targets a story message", () => {
    history.replaceState(null, "", "/post#msg-1");
    const root = transcript();
    attachOocToggle(root);
    expect(root.classList.contains("slk-ooc-shown")).toBe(false);
    expect(checkbox(root)!.checked).toBe(false);
  });

  it("is idempotent per root", () => {
    const root = transcript();
    attachOocToggle(root);
    attachOocToggle(root);
    expect(root.querySelectorAll(".slk-ooc-toggle").length).toBe(1);
  });

  it("detaching removes the control and any reveal", () => {
    const root = transcript();
    const detach = attachOocToggle(root);
    const box = checkbox(root)!;
    box.checked = true;
    box.dispatchEvent(new Event("change", { bubbles: true }));

    detach();
    expect(checkbox(root)).toBeNull();
    expect(root.classList.contains("slk-ooc-shown")).toBe(false);
  });
});
