// Tab belongs to the editor here: YAML lives and dies by indentation, and a
// Tab that jumps to the Save button mid-edit is worse than useless. Bound
// YAML-safely — indentation is always spaces (indentMore uses the indent
// unit; a literal \t is a YAML syntax error), and when the completion popup
// is open Tab accepts the highlighted suggestion first, the usual IDE
// muscle memory. Keyboard users still escape the editor: the default keymap
// (via basicSetup) binds Ctrl-m / Alt-Shift-m to tab-focus mode, where Tab
// moves focus again.
import type { Extension } from "@codemirror/state";
import { keymap } from "@codemirror/view";
import { indentLess, indentMore } from "@codemirror/commands";
import { acceptCompletion } from "@codemirror/autocomplete";

export const tabKeymap: Extension = keymap.of([
  // First binding that handles the key wins; acceptCompletion returns false
  // when no completion is active, falling through to indentation.
  { key: "Tab", run: acceptCompletion },
  { key: "Tab", run: indentMore, shift: indentLess },
]);
