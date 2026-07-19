/**
 * Copy-link affordance for transcripts — the pure, side-effect-free core.
 *
 * When timestamps are hidden, each message renders a chain-link icon in place
 * of the time (see .slk-msg__permalink in the block stylesheet). Without JS the
 * icon is a plain <a href="#msg-..."> — it still jumps to the message and shows
 * the fragment in the address bar. This module enhances it:
 *   - click/tap the icon: copy the message's absolute #msg-... permalink to the
 *     clipboard, flash a "Copied!" confirmation, and reflect it in the URL;
 *   - on touch (no hover), a tap on a message reveals its icon first, since
 *     there is no pointer to hover with.
 *
 * transcript.js self-initializes this on the published page; the app preview
 * imports attachTranscriptInteractions directly. Keeping the core free of
 * import-time side effects lets both bind exactly once, with no double-firing.
 */

/** The absolute permalink for a message, resolved from its in-page anchor. */
export function messageUrl(anchor) {
  // An <a href="#msg-..."> element resolves .href against the page URL for us.
  return anchor.href;
}

/** True on devices with no hover (touch), where reveal must be tap-driven. */
function isHoverless() {
  return typeof matchMedia === "function" && matchMedia("(hover: none)").matches;
}

/** Briefly mark the icon as copied; the stylesheet paints the "Copied!" note. */
function flashCopied(link) {
  link.classList.add("is-copied");
  clearTimeout(link.__chroniclerCopiedTimer);
  link.__chroniclerCopiedTimer = setTimeout(() => {
    link.classList.remove("is-copied");
  }, 1200);
}

/* ------------------------------------------------------------------ *
 * OOC toggle (#90)
 * ------------------------------------------------------------------ */

/**
 * The transcript-wide "Show OOC messages" checkbox (#90). Hidden is the
 * SERVER default: blocks.php prints `display: none` for `.slk-msg--ooc`
 * unless the root carries `slk-ooc-shown` — which only this code adds. No
 * JS ⇒ no checkbox ⇒ OOC stays hidden (visually AND from the
 * accessibility tree; display:none removes it, no aria-hidden needed),
 * and print hides unconditionally. A deep link (#msg-…) into an OOC
 * message starts revealed so copied links always land. No control renders
 * on transcripts without OOC messages. Idempotent per root; returns a
 * detach function.
 *
 * A playtester's "what does this do?" (#185): the label now carries a live
 * count — "(N hidden)" flipping to "(N shown)" — so the checkbox's effect
 * is visible before it is ever clicked, and a plain-language tooltip
 * spells out the OOC jargon for readers who don't know it.
 */
export function attachOocToggle(root) {
  if (!root || root.__chroniclerOocBound) return () => {};
  if (!root.querySelector || !root.querySelector(".slk-msg--ooc")) return () => {};
  root.__chroniclerOocBound = true;

  // The copy-link contract: a fragment pointing into an OOC message must
  // land on a visible line, so that page load starts revealed.
  const anchored =
    location.hash.length > 1 ? document.getElementById(location.hash.slice(1)) : null;
  const startRevealed = !!(
    anchored && root.contains(anchored) && anchored.closest(".slk-msg--ooc")
  );

  const count = root.querySelectorAll(".slk-msg--ooc").length;
  const noun = count === 1 ? "message" : "messages";

  const label = document.createElement("label");
  label.className = "slk-ooc-toggle";
  const box = document.createElement("input");
  box.type = "checkbox";
  box.checked = startRevealed;
  label.appendChild(box);
  label.appendChild(document.createTextNode(" Show OOC messages"));
  const badge = document.createElement("span");
  badge.className = "slk-ooc-count";
  label.appendChild(badge);
  root.insertBefore(label, root.firstChild);

  const apply = () => {
    root.classList.toggle("slk-ooc-shown", box.checked);
    badge.textContent = box.checked ? `(${count} shown)` : `(${count} hidden)`;
    label.title = box.checked
      ? `Showing ${count} out-of-character ${noun} — table talk between the players, not part of the story. Uncheck to hide ${count === 1 ? "it" : "them"} again.`
      : `This transcript hides ${count} out-of-character ${noun} — table talk between the players, not part of the story. Check to show ${count === 1 ? "it" : "them"}.`;
  };
  apply();
  box.addEventListener("change", apply);

  return () => {
    box.removeEventListener("change", apply);
    label.remove();
    root.classList.remove("slk-ooc-shown");
    delete root.__chroniclerOocBound;
  };
}

/**
 * Wire copy + touch-reveal behavior to a transcript root (a container element
 * or `document`). Idempotent per root; returns a detach function.
 */
export function attachTranscriptInteractions(root) {
  if (!root || root.__chroniclerTranscriptBound) return () => {};
  root.__chroniclerTranscriptBound = true;

  const onClick = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const link = target.closest(".slk-msg__permalink");
    if (link) {
      // Enhance the plain anchor into a copy action (optimistic: the toast and
      // URL update are instant; the clipboard write settles in the background).
      event.preventDefault();
      const url = messageUrl(link);
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).catch(() => {});
      }
      flashCopied(link);
      try {
        history.replaceState(null, "", url);
      } catch (_err) {
        // Some embeddings forbid replaceState; the clipboard copy still worked.
      }
      return;
    }

    // Touch only: a tap on a message row reveals its (otherwise hover-only)
    // icon. Move the active state to the tapped message; a tap on neither
    // (transcript padding) clears it.
    if (isHoverless()) {
      const message = target.closest(".slk-msg");
      root.querySelectorAll(".slk-msg.is-active").forEach((other) => {
        if (other !== message) other.classList.remove("is-active");
      });
      if (message) message.classList.toggle("is-active");
    }
  };

  root.addEventListener("click", onClick);
  return () => {
    root.removeEventListener("click", onClick);
    delete root.__chroniclerTranscriptBound;
  };
}
