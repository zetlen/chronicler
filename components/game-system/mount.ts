// Progressive enhancement over the Game System page's <textarea>: the
// textarea stays in the form as the transport (and the whole UX when the
// bundle is missing); the editor mounts beside it and mirrors its document
// back on every change. No client-side gate on saving — PHP stays the
// authority.
import { EditorView } from "@codemirror/view";
import type { Extension } from "@codemirror/state";
import { forEachDiagnostic, setDiagnosticsEffect } from "@codemirror/lint";
import { jsonToYaml } from "./jsonToYaml";
import type { PreflightBoot } from "./preflight";

export const TEXTAREA_SELECTOR = 'textarea[name="chronicler_template_json"]';

const EMPTY_HINT =
  "Describe your game system here — or start from a working example.";
const CHECKING_HINT = "Checking the template…";
const CLEAN_HINT = "No problems found.";

const problemsHint = (count: number): string =>
  `${count} problem${count === 1 ? "" : "s"} found — hover the marked text for details.`;

declare global {
  interface Window {
    chroniclerGameSystemBoot?: PreflightBoot;
  }
}

/**
 * Mount the editor over the configurator textarea. The extension set is
 * injected so tests can exercise the mount without the schema library's
 * import graph; main.ts passes editor.ts's buildExtensions. Returns null —
 * with the textarea untouched — when the page has no configurator or the
 * editor fails to construct.
 */
export function mountGameSystemEditor(
  root: Document,
  buildExtensions: (boot: PreflightBoot | null) => Extension,
  starterText: string,
): EditorView | null {
  const textarea = root.querySelector<HTMLTextAreaElement>(TEXTAREA_SELECTOR);
  const form = textarea?.form;
  if (!textarea || !form) {
    return null;
  }
  const boot = root.defaultView?.chroniclerGameSystemBoot ?? null;

  const container = root.createElement("div");
  container.className = "chronicler-game-system-editor";
  const footer = root.createElement("p");
  footer.className = "chronicler-game-system-footer";
  const status = root.createElement("span");
  const starterButton = root.createElement("button");
  starterButton.type = "button";
  starterButton.className = "button";
  starterButton.textContent = "Insert an example template";
  starterButton.style.marginLeft = "8px";
  const convertButton = root.createElement("button");
  convertButton.type = "button";
  convertButton.className = "button";
  convertButton.textContent = "Convert to YAML";
  convertButton.title =
    "Rewrite this JSON as YAML — easier to read, and you can add comments. Undo restores the JSON.";
  footer.append(status, starterButton);

  // Diagnostics are debounced and the server preflight is a round trip, so
  // "no diagnostics yet" must read as "checking", never as a clean verdict.
  // A lint pass landing (setDiagnosticsEffect) marks the result fresh; any
  // doc change makes it stale again.
  let checked = false;
  // The YAML rendering of the current doc when it's JSON, else null.
  // Computed only when the doc changes — refreshFooter also runs on
  // selection-only updates.
  let convertible: string | null = jsonToYaml(textarea.value);

  const refreshFooter = (view: EditorView): void => {
    const empty = view.state.doc.toString().trim() === "";
    // style.display, not [hidden]: wp-admin's `.button { display:
    // inline-block }` outranks the UA's [hidden] rule.
    starterButton.style.display = empty ? "" : "none";
    convertButton.style.display = convertible === null ? "none" : "";
    if (empty) {
      status.textContent = EMPTY_HINT;
      return;
    }
    if (!checked) {
      status.textContent = CHECKING_HINT;
      return;
    }
    let problems = 0;
    let note: string | null = null;
    forEachDiagnostic(view.state, (d) => {
      if (d.severity === "error" || d.severity === "warning") {
        problems += 1;
      } else {
        note = note ?? d.message;
      }
    });
    status.textContent =
      problems > 0 ? problemsHint(problems) : (note ?? CLEAN_HINT);
  };

  let view: EditorView;
  try {
    view = new EditorView({
      doc: textarea.value,
      extensions: [
        buildExtensions(boot),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) {
            // Mirror continuously so every save path — including
            // programmatic form.submit(), which fires no submit event —
            // posts what the author sees.
            textarea.value = update.state.doc.toString();
            checked = false;
            convertible = jsonToYaml(textarea.value);
          }
          if (
            update.transactions.some((tr) =>
              tr.effects.some((e) => e.is(setDiagnosticsEffect)),
            )
          ) {
            checked = true;
          }
          refreshFooter(update.view);
        }),
      ],
    });
  } catch (error) {
    // The whole point of enhancing progressively: a broken editor must
    // leave the plain textarea working exactly as before.
    console.error("Chronicler: game-system editor failed to start.", error);
    return null;
  }

  textarea.insertAdjacentElement("afterend", container);
  container.append(view.dom);
  container.insertAdjacentElement("afterend", footer);
  textarea.hidden = true;
  // Convert lives on the Validate & Save row — save on the left, convert on
  // the right. If the PHP markup ever changes shape, the footer keeps it.
  const saveRow = form.querySelector<HTMLButtonElement>(
    "button.button-primary",
  )?.parentElement;
  if (saveRow) {
    saveRow.style.display = "flex";
    saveRow.style.justifyContent = "space-between";
    saveRow.style.alignItems = "center";
    saveRow.append(convertButton);
  } else {
    convertButton.style.marginLeft = "8px";
    footer.append(convertButton);
  }
  refreshFooter(view);

  starterButton.addEventListener("click", () => {
    if (view.state.doc.toString().trim() !== "") {
      return; // only offered on an empty document
    }
    view.dispatch({
      changes: { from: 0, to: view.state.doc.length, insert: starterText },
    });
    view.focus();
  });

  convertButton.addEventListener("click", () => {
    // Recompute from the live document rather than trusting the cache — a
    // stale click must never splice YAML derived from older text.
    const yaml = jsonToYaml(view.state.doc.toString());
    if (yaml === null) {
      return;
    }
    view.dispatch({
      changes: { from: 0, to: view.state.doc.length, insert: yaml },
    });
    view.focus();
  });

  // Belt and braces alongside the continuous mirror above.
  form.addEventListener("submit", () => {
    textarea.value = view.state.doc.toString();
  });

  return view;
}
