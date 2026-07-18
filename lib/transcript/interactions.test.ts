import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
// The interaction logic ships in the plugin's side-effect-free core — one
// implementation, no drift. blocks.php prints the published-page entry inline
// (two attach calls against a version-busted core URL).
import { attachTranscriptInteractions, messageUrl } from "@/wordpress-plugin/transcript-core";

/** A minimal transcript: one message with a hidden-timestamp permalink anchor. */
function transcript(): HTMLElement {
  const root = document.createElement("div");
  root.className = "slack-log";
  root.innerHTML = `
    <div class="slk-msg slk-msg--text" id="msg-123">
      <div class="slk-msg__main">
        <div class="slk-msg__head"><span class="slk-msg__author">A</span><a class="slk-msg__permalink" href="#msg-123" aria-label="Copy link to this message"></a></div>
        <div class="slk-msg__body">hi</div>
      </div>
    </div>
    <div class="slk-msg slk-msg--text" id="msg-456">
      <div class="slk-msg__main">
        <div class="slk-msg__head"><span class="slk-msg__author">B</span><a class="slk-msg__permalink" href="#msg-456" aria-label="Copy link to this message"></a></div>
        <div class="slk-msg__body">yo</div>
      </div>
    </div>`;
  document.body.appendChild(root);
  return root;
}

const q = (root: HTMLElement, sel: string) => root.querySelector(sel) as HTMLElement;
const click = (el: Element) =>
  el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));

let writeText: ReturnType<typeof vi.fn>;

beforeEach(() => {
  writeText = vi.fn().mockResolvedValue(undefined);
  Object.defineProperty(navigator, "clipboard", {
    value: { writeText },
    configurable: true,
  });
  // jsdom has no matchMedia; default to a hover-capable (desktop) device.
  window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as unknown as typeof window.matchMedia;
  history.replaceState(null, "", "/post");
});

afterEach(() => {
  document.body.innerHTML = "";
  vi.useRealTimers();
});

describe("messageUrl", () => {
  it("resolves the anchor's #msg- href to an absolute URL", () => {
    const root = transcript();
    const url = messageUrl(q(root, ".slk-msg__permalink") as HTMLAnchorElement);
    expect(url).toMatch(/^https?:\/\/.+#msg-123$/);
  });
});

describe("attachTranscriptInteractions — copy", () => {
  it("copies the message's absolute permalink and suppresses the jump", () => {
    const root = transcript();
    attachTranscriptInteractions(root);
    const link = q(root, ".slk-msg__permalink");

    const notPrevented = click(link);

    expect(writeText).toHaveBeenCalledTimes(1);
    expect(writeText).toHaveBeenCalledWith(expect.stringMatching(/#msg-123$/));
    expect(notPrevented).toBe(false); // preventDefault() was called
  });

  it("flashes a Copied! confirmation, then clears it", () => {
    vi.useFakeTimers();
    const root = transcript();
    attachTranscriptInteractions(root);
    const link = q(root, ".slk-msg__permalink");

    click(link);
    expect(link.classList.contains("is-copied")).toBe(true);

    vi.advanceTimersByTime(1500);
    expect(link.classList.contains("is-copied")).toBe(false);
  });

  it("reflects the permalink in the address bar without navigating", () => {
    const root = transcript();
    attachTranscriptInteractions(root);
    click(q(root, ".slk-msg__permalink"));
    expect(window.location.hash).toBe("#msg-123");
  });

  it("ignores clicks that miss the icon", () => {
    const root = transcript();
    attachTranscriptInteractions(root);
    click(q(root, ".slk-msg__body"));
    expect(writeText).not.toHaveBeenCalled();
  });

  it("binds once even if attached repeatedly", () => {
    const root = transcript();
    attachTranscriptInteractions(root);
    attachTranscriptInteractions(root);
    click(q(root, ".slk-msg__permalink"));
    expect(writeText).toHaveBeenCalledTimes(1);
  });
});

describe("attachTranscriptInteractions — touch reveal", () => {
  beforeEach(() => {
    // Emulate a no-hover (touch) device.
    window.matchMedia = vi
      .fn()
      .mockImplementation((query: string) => ({ matches: query.includes("hover: none") })) as unknown as typeof window.matchMedia;
  });

  it("reveals the tapped message and moves the active state on the next tap", () => {
    const root = transcript();
    attachTranscriptInteractions(root);
    const [a, b] = Array.from(root.querySelectorAll(".slk-msg")) as HTMLElement[];

    click(a);
    expect(a.classList.contains("is-active")).toBe(true);
    expect(b.classList.contains("is-active")).toBe(false);

    click(b);
    expect(a.classList.contains("is-active")).toBe(false);
    expect(b.classList.contains("is-active")).toBe(true);
  });

  it("clears the active state on a tap outside any message", () => {
    const root = transcript();
    attachTranscriptInteractions(root);
    const [a] = Array.from(root.querySelectorAll(".slk-msg")) as HTMLElement[];
    click(a);
    expect(a.classList.contains("is-active")).toBe(true);

    click(root); // the transcript padding, not a message
    expect(a.classList.contains("is-active")).toBe(false);
  });

  it("does not toggle active state on hover-capable devices", () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as unknown as typeof window.matchMedia;
    const root = transcript();
    attachTranscriptInteractions(root);
    const [a] = Array.from(root.querySelectorAll(".slk-msg")) as HTMLElement[];
    click(a);
    expect(a.classList.contains("is-active")).toBe(false);
  });
});

// The published-page entry is PRINTED INLINE by blocks.php with a versioned
// core URL (the separate transcript.js entry died of an unversioned relative
// import — see chronicler_transcript_print_module). Its two attach calls are
// covered here and in ooc-toggle.test.ts; the printed tag itself is runtime
// behavior, verified in wp-env.
