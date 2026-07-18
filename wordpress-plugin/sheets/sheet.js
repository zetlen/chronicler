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
  if (type === "track") {
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
  if (!boot.canEdit) return;
  const errorBox = root.querySelector(".chr-sheet__error");

  const call = (path, init = {}) =>
    fetchImpl(`${boot.restUrl}characters/${boot.characterId}${path}`, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": boot.nonce },
      ...init,
    });

  const resync = async () => {
    const res = await call("/sheet");
    if (!res.ok) return;
    const sheet = await res.json();
    for (const p of sheet.properties) {
      const el = root.querySelector(`[data-prop="${p.id}"]`);
      if (el) renderValue(el, p.value, p.display);
    }
  };

  const send = async (prop, op, value) => {
    errorBox.hidden = true;
    try {
      const res = await call(`/properties/${prop.dataset.prop}`, {
        method: "POST",
        body: JSON.stringify({ op, value }),
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
    if (prop.dataset.type === "track") renderValue(prop, intent.value); // optimistic
    void send(prop, intent.op, intent.value);
  };

  root.addEventListener("click", (e) => {
    const el = e.target.closest(".chr-track__box, .chr-step");
    if (el && !el.disabled) interact(el);
  });
  root.addEventListener("change", (e) => {
    const el = e.target;
    if (el.matches(".chr-toggle, .chr-check, .chr-select, .chr-text, .chr-longtext") && !el.disabled) {
      interact(el);
    }
  });
}

if (typeof document !== "undefined") {
  const root = document.querySelector("[data-chronicler-sheet]");
  if (root) initSheet(root);
}
