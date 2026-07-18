import { useState, useSyncExternalStore } from "react";
import CodeMirror from "@uiw/react-codemirror";
import { css as cssLang } from "@codemirror/lang-css";
import { Group } from "@/components/Group";

const SMALL_BUTTON_CLS =
  "rounded border border-zinc-300 px-2 py-0.5 text-xs font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800";

// Stable store handles for prefers-color-scheme, hoisted out of the component
// so useSyncExternalStore keeps one subscription instead of re-adding the
// matchMedia listener on every render/keystroke.
function subscribePrefersDark(onStoreChange: () => void) {
  const mq = window.matchMedia("(prefers-color-scheme: dark)");
  mq.addEventListener("change", onStoreChange);
  return () => mq.removeEventListener("change", onStoreChange);
}
const getPrefersDark = () => window.matchMedia("(prefers-color-scheme: dark)").matches;
const getPrefersDarkServer = () => false;

/**
 * Free-form CSS layered after the base transcript stylesheet — in the preview
 * and in published drafts. Selectors are the author's own (target
 * `.slack-log …`); nothing is rewritten or scoped automatically.
 */
export function CustomCssEditor({
  value,
  onChange,
  template,
}: {
  value: string;
  onChange: (v: string) => void;
  /** The scheme's seed CSS, restored by "Clear custom CSS". */
  template: string;
}) {
  const [confirmingClear, setConfirmingClear] = useState(false);
  const prefersDark = useSyncExternalStore(
    subscribePrefersDark,
    getPrefersDark,
    getPrefersDarkServer,
  );

  return (
    <Group title="Custom CSS">
      <p className="text-xs text-zinc-400 dark:text-zinc-500">
        Applied after the built-in styles — to the preview and to WordPress
        drafts. Target <code>.slack-log</code> selectors or override its{" "}
        <code>--slk-*</code> variables.
      </p>
      <div className="overflow-hidden rounded-md border border-zinc-300 dark:border-zinc-600">
        <CodeMirror
          value={value}
          onChange={onChange}
          extensions={[cssLang()]}
          theme={prefersDark ? "dark" : "light"}
          height="200px"
          placeholder={".slack-log .slk-msg__author { text-transform: uppercase; }"}
          basicSetup={{ foldGutter: false, highlightActiveLine: false }}
        />
      </div>
      <div className="flex items-center justify-end gap-2">
        {confirmingClear ? (
          <>
            <span
              role="alert"
              className="text-xs text-zinc-500 dark:text-zinc-400"
            >
              Replace your custom CSS with the default template?
            </span>
            <button
              type="button"
              onClick={() => {
                onChange(template);
                setConfirmingClear(false);
              }}
              className="rounded border border-red-300 px-2 py-0.5 text-xs font-medium text-red-700 transition hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950"
            >
              Replace
            </button>
            <button
              type="button"
              onClick={() => setConfirmingClear(false)}
              className={SMALL_BUTTON_CLS}
            >
              Cancel
            </button>
          </>
        ) : (
          <button
            type="button"
            onClick={() => setConfirmingClear(true)}
            className={SMALL_BUTTON_CLS}
          >
            Clear custom CSS
          </button>
        )}
      </div>
    </Group>
  );
}
