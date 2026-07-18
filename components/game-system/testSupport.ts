// Test-only helpers for the Game System editor suites.
import { ensureSyntaxTree, syntaxTree } from "@codemirror/language";
import { EditorState } from "@codemirror/state";
import { yaml } from "@codemirror/lang-yaml";

/**
 * An EditorState for `doc` whose YAML syntax tree is guaranteed complete.
 *
 * EditorState.create runs the initial parse under a small time budget (and
 * only up to ~3000 chars), and with no EditorView attached nothing resumes
 * the work — under full-suite CPU contention the tree comes out partial and
 * tree-based lookups late in the document quietly return nothing.
 * ensureSyntaxTree finishes the parse, but on the field's shared parse
 * context; syntaxTree() reads the field's tree *snapshot*, which only
 * refreshes on a transaction — hence the empty update afterwards.
 */
export function parsedYamlState(doc: string): EditorState {
  const state = EditorState.create({ doc, extensions: [yaml()] });
  if (!ensureSyntaxTree(state, doc.length, 10_000)) {
    throw new Error("YAML parse did not complete in time");
  }
  const parsed = state.update({}).state;
  const treeLength = syntaxTree(parsed).length;
  if (treeLength < doc.length) {
    throw new Error(
      `YAML syntax tree is still partial (${treeLength} of ${doc.length})`,
    );
  }
  return parsed;
}
