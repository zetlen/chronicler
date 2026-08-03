/**
 * Inline sheet editing. Optimistic: controls update immediately, every
 * response reconciles to the server's canonical value, and a rejected write
 * re-syncs the whole sheet (one recovery path for every failure mode).
 *
 * Pure helpers are exported for tests; the module self-initializes on
 * character pages via the [data-chronicler-sheet] root.
 */

/** Track click semantics: mark up to the box; re-click the top box to unmark it. */
export function nextTrackValue(current, clickedIndex) {
  return clickedIndex + 1 === current ? clickedIndex : clickedIndex + 1;
}

/** The {op, value} a control interaction means. Null for non-control targets. */
export function opForControl(el) {
  if (el.matches(".chr-track__box")) {
    const boxes = [...el.parentElement.querySelectorAll(".chr-track__box")];
    const current = boxes.filter((b) => b.getAttribute("aria-pressed") === "true").length;
    return { op: "set", value: nextTrackValue(current, Number(el.dataset.index)) };
  }
  if (el.matches(".chr-step")) return { op: "adjust", value: Number(el.dataset.step) };
  if (el.matches(".chr-toggle")) return { op: "toggle", value: null };
  if (el.matches(".chr-check")) return { op: "toggle", value: el.value };
  if (el.matches(".chr-select")) return { op: "set", value: el.value };
  if (el.matches(".chr-text") || el.matches(".chr-longtext")) return { op: "set", value: el.value };
  return null;
}

function renderValue(prop, value, display) {
  const type = prop.dataset.type;
  if (type === "opinions") {
    // One PC's set ({rating, notes}), possibly partial: the optimistic
    // update sends {rating} alone, reconciles send the whole set. Only
    // editable markup reconciles — a static (someone else's) set has no
    // controls and refreshes with the page.
    const set = value && typeof value === "object" ? value : {};
    if (typeof set.rating === "number") {
      prop.querySelectorAll(".chr-track__box[data-index]").forEach((box, i) => {
        box.setAttribute("aria-pressed", i < set.rating ? "true" : "false");
      });
    }
    if (typeof set.notes === "string") {
      const input = prop.querySelector(".chr-opinion__notes");
      if (input && document.activeElement !== input) input.value = set.notes;
    }
  } else if (type === "track") {
    const boxes = prop.querySelectorAll(".chr-track__box[data-index]");
    if (boxes.length > 0) {
      boxes.forEach((box, i) => {
        box.setAttribute("aria-pressed", i < value ? "true" : "false");
      });
    }
  } else if (type === "number" || type === "counter") {
    const el = prop.querySelector(".chr-prop__value");
    if (el) el.textContent = String(value);
  } else if (type === "toggle") {
    const el = prop.querySelector(".chr-toggle");
    if (el) el.checked = Boolean(value);
  } else if (type === "select") {
    const el = prop.querySelector(".chr-select");
    if (el) el.value = value;
  } else if (type === "checklist") {
    const boxes = prop.querySelectorAll(".chr-check");
    if (boxes.length > 0) {
      boxes.forEach((box) => {
        box.checked = Array.isArray(value) && value.includes(box.value);
      });
    }
  } else {
    const input = prop.querySelector(".chr-text, .chr-longtext");
    if (input && document.activeElement !== input) input.value = value;
  }
  const badge = prop.querySelector(".chr-prop__display");
  if (badge && display !== undefined) badge.textContent = display;
  // Formula-derived properties (#88) render statically — plain text, no
  // control, no badge. Reconcile their text too; the child-element guard
  // leaves structured statics (track boxes, checklists) alone.
  const stat = prop.querySelector(".chr-prop__static");
  if (stat && display !== undefined && stat.children.length === 0) {
    stat.textContent = display;
  }
}

export function initSheet(root, fetchImpl = fetch) {
  const bootEl = root.querySelector("#chronicler-sheet-boot");
  if (!bootEl) return;
  let boot;
  try {
    boot = JSON.parse(bootEl.textContent);
  } catch {
    return; // malformed boot payload — leave the sheet as server-rendered
  }
  // canEdit covers the whole sheet; canOpine (#183) covers just the viewer's
  // own opinion sets on an NPC page — either is reason to wire controls.
  if (!boot.canEdit && !boot.canOpine) return;
  const errorBox = root.querySelector(".chr-sheet__error");

  const call = (path, init = {}) =>
    fetchImpl(`${boot.restUrl}characters/${boot.characterId}${path}`, {
      credentials: "same-origin",
      // keepalive lets a save started as the page unmounts (the #8 flush) run
      // to completion; the sheet's JSON writes are far under the 64 KB cap.
      keepalive: true,
      headers: { "Content-Type": "application/json", "X-WP-Nonce": boot.nonce },
      ...init,
    });

  const resync = async () => {
    const res = await call("/sheet");
    if (!res.ok) return;
    const sheet = await res.json();
    for (const p of sheet.properties) {
      // Opinions (#183) arrive as a pc-id → set map; each set reconciles
      // its own [data-prop][data-pc] block.
      if (p.type === "opinions") {
        for (const [pc, set] of Object.entries(p.value || {})) {
          const el = root.querySelector(`[data-prop="${p.id}"][data-pc="${pc}"]`);
          if (el) renderValue(el, set, `${set.rating}/${el.dataset.length}`);
        }
        continue;
      }
      const el = root.querySelector(`[data-prop="${p.id}"]`);
      if (el) renderValue(el, p.value, p.display);
    }
  };

  const send = async (prop, op, value, field) => {
    errorBox.hidden = true;
    try {
      // An opinion set (data-pc) writes one field of one PC's set through
      // the opinions route; everything else stays on the properties route.
      const pc = prop.dataset.pc;
      const res = await call(pc ? `/opinions/${prop.dataset.prop}` : `/properties/${prop.dataset.prop}`, {
        method: "POST",
        body: JSON.stringify(pc ? { pc: Number(pc), field, op, value } : { op, value }),
      });
      const body = await res.json();
      if (!res.ok) throw new Error(body.message || "The change was rejected.");
      renderValue(prop, body.value, body.display);
      // Dependent-rule cascades (#53) ride back as `derived`; apply them so a
      // coupled property (e.g. Unstable following Harm) updates in place.
      for (const change of body.derived || []) {
        const el = root.querySelector(`[data-prop="${change.prop}"]`);
        if (el) renderValue(el, change.value, change.display);
      }
    } catch (err) {
      errorBox.textContent = err.message;
      errorBox.hidden = false;
      await resync();
    }
  };

  const interact = (el) => {
    const prop = el.closest(".chr-prop");
    const intent = prop && opForControl(el);
    if (!intent) return;
    // Inside an opinion set the control itself names the field: the track
    // boxes are the rating, the textarea is the notes.
    const field = prop.dataset.pc ? (el.matches(".chr-track__box") ? "rating" : "notes") : undefined;
    if (prop.dataset.type === "track") renderValue(prop, intent.value); // optimistic
    if (field === "rating") renderValue(prop, { rating: intent.value }); // optimistic
    void send(prop, intent.op, intent.value, field);
  };

  // Text fields (#8): `change` only fires on blur, so a player who types a
  // note and then navigates away — link, back button, closed tab — before the
  // field loses focus lost the edit entirely. Autosave on `input` (debounced
  // per field) so typing persists on its own, and flush anything still pending
  // when the page is hidden/unloaded so the last keystrokes aren't dropped.
  const TEXT_SELECTOR = ".chr-text, .chr-longtext";
  const INPUT_DEBOUNCE_MS = 600;
  const pending = new Map(); // field el -> timer id

  const clearPending = (el) => {
    const timer = pending.get(el);
    if (timer !== undefined) {
      clearTimeout(timer);
      pending.delete(el);
    }
  };

  const flushPending = () => {
    for (const el of [...pending.keys()]) {
      clearPending(el);
      interact(el);
    }
  };

  root.addEventListener("click", (e) => {
    const el = e.target.closest(".chr-track__box, .chr-step");
    if (el && !el.disabled) interact(el);
  });
  root.addEventListener("input", (e) => {
    const el = e.target;
    if (el.matches(TEXT_SELECTOR) && !el.disabled) {
      clearPending(el);
      pending.set(
        el,
        setTimeout(() => {
          pending.delete(el);
          interact(el);
        }, INPUT_DEBOUNCE_MS),
      );
    }
  });
  root.addEventListener("change", (e) => {
    const el = e.target;
    if (el.matches(".chr-toggle, .chr-check, .chr-select, .chr-text, .chr-longtext") && !el.disabled) {
      // Blur saves immediately; drop any debounced input-save so it fires once.
      clearPending(el);
      interact(el);
    }
  });
  // `visibilitychange → hidden` is the reliable "leaving" signal across
  // desktop and mobile; `pagehide` covers the plain unload. A keepalive write
  // (see `call`) survives the unload the flush races against.
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") flushPending();
  });
  window.addEventListener("pagehide", flushPending);
}

/**
 * The address-bar URL with the login landing's one-time chr_welcome arg
 * removed, or null when there is nothing to strip (GitHub #1). The banner
 * stays up for the pageview being read; replacing the URL is what makes
 * "one-time" true — a reload or bookmark gets the clean permalink, not a
 * resurrected banner.
 */
export function welcomeCleanUrl(href) {
  const url = new URL(href);
  if (!url.searchParams.has("chr_welcome")) return null;
  url.searchParams.delete("chr_welcome");
  return url.toString();
}

/**
 * Toast behavior for the arrival notice (GitHub #1): positioned top-center
 * by sheet.css; dismissed by a click anywhere on it, or on its own after 10
 * seconds. The exit class drives a 300ms fade (sheet.css) and removal
 * follows it — the timings must agree.
 */
export function initWelcomeToast(el) {
  let gone = false;
  const dismiss = () => {
    if (gone) return;
    gone = true;
    el.classList.add("chr-sheet__welcome--out");
    setTimeout(() => el.remove(), 300);
  };
  el.addEventListener("click", dismiss);
  setTimeout(dismiss, 10000);
}

if (typeof document !== "undefined") {
  const root = document.querySelector("[data-chronicler-sheet]");
  if (root) initSheet(root);
  const toast = document.querySelector(".chr-sheet__welcome");
  if (toast) initWelcomeToast(toast);
  const clean = welcomeCleanUrl(window.location.href);
  if (clean !== null) history.replaceState(null, "", clean);
}
