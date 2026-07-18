// Extension assembly for the Game System editor. This module is the only
// place codemirror-json-schema is imported: unit tests exercise the mount
// (mount.ts) and the sources (completion.ts, templateLint.ts, preflight.ts)
// without dragging the library's import graph into vitest; this file is
// covered by the runtime verification pass instead.
import { basicSetup } from "codemirror";
import { EditorView, hoverTooltip } from "@codemirror/view";
import type { Extension } from "@codemirror/state";
import { yaml, yamlLanguage } from "@codemirror/lang-yaml";
import { linter, lintGutter, type Diagnostic } from "@codemirror/lint";
import { stateExtensions } from "codemirror-json-schema";
import {
  yamlCompletion,
  yamlSchemaHover,
  yamlSchemaLinter,
} from "codemirror-json-schema/yaml";
import schema from "@/wordpress-plugin/sheets/template.schema.json";
import { gameSystemCompletion } from "./completion";
import { lintTemplateSource } from "./templateLint";
import { createPreflight, type PreflightBoot } from "./preflight";
import { tabKeymap } from "./tabKeymap";

/**
 * One linter, three layers: syntax + relational rules (templateLint),
 * structural schema validation (codemirror-json-schema), and — only once
 * both are clean — the server preflight, which runs the real Save-time
 * parse (Expression Language syntax, formula cycles, dry-run type checks).
 */
function gameSystemLinter(
  boot: PreflightBoot | null,
): (view: EditorView) => Promise<Diagnostic[]> {
  const structural = yamlSchemaLinter();
  const preflight = boot === null ? null : createPreflight(boot);
  return async (view) => {
    const text = view.state.doc.toString();
    if (text.trim() === "") {
      return [];
    }
    const local: Diagnostic[] = [
      ...lintTemplateSource(text),
      ...structural(view),
    ];
    // Only real errors hold the server check back — a benign YAML warning
    // must not suppress the one layer that can see formula problems.
    const hasErrors = local.some((d) => d.severity === "error");
    if (hasErrors || preflight === null) {
      return local;
    }
    return [...local, ...(await preflight(text))];
  };
}

/** wp-admin-friendly chrome; CodeMirror injects these styles itself. */
const theme = EditorView.theme({
  "&": {
    backgroundColor: "#fff",
    border: "1px solid #8c8f94",
    borderRadius: "4px",
    fontSize: "13px",
    maxHeight: "70vh",
  },
  "&.cm-focused": {
    outline: "2px solid #2271b1",
    outlineOffset: "-1px",
  },
  ".cm-scroller": {
    fontFamily: "Consolas, Monaco, monospace",
    overflow: "auto",
  },
  ".cm-content, .cm-gutter": { minHeight: "480px" },
  ".cm-tooltip": { maxWidth: "36em" },
  ".cm6-json-schema-hover--code-wrapper": { opacity: "0.75" },
});

export function buildExtensions(boot: PreflightBoot | null): Extension {
  // The library source occasionally returns a bare [] where the
  // CompletionSource contract wants null; normalize.
  const library = yamlCompletion();
  return [
    basicSetup,
    tabKeymap,
    yaml(),
    stateExtensions(schema as never),
    yamlLanguage.data.of({
      autocomplete: gameSystemCompletion((context) => {
        const result = library(context);
        return Array.isArray(result) ? null : result;
      }),
    }),
    hoverTooltip(yamlSchemaHover()),
    linter(gameSystemLinter(boot)),
    lintGutter(),
    theme,
  ];
}
