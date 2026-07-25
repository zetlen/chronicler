// Shared typed accessors over the `yaml` package's AST, used by both the
// relational linter (templateLint.ts) and the document outline that feeds
// completion (yamlContext.ts) — one implementation so lint and completion
// can never disagree about how a key or value is read.
import { isMap, isScalar, isSeq } from "yaml";
import type { Node, Pair, YAMLMap, YAMLSeq } from "yaml";

export const mapGet = (
  map: YAMLMap,
  key: string,
): Node | null | undefined => {
  const pair = (map.items as Pair[]).find(
    (item) => isScalar(item.key) && item.key.value === key,
  );
  return pair ? (pair.value as Node | null) : undefined;
};

export const stringOf = (node: Node | null | undefined): string | undefined =>
  isScalar(node) && typeof node.value === "string" ? node.value : undefined;

export const intOf = (node: Node | null | undefined): number | undefined =>
  isScalar(node) && typeof node.value === "number" && Number.isInteger(node.value)
    ? node.value
    : undefined;

export const isTrue = (node: Node | null | undefined): boolean =>
  isScalar(node) && node.value === true;

export const seqItems = (node: Node | null | undefined): Node[] =>
  isSeq(node) ? ((node as YAMLSeq).items as Node[]) : [];

/**
 * Whether a key is present with a non-null value — PHP's isset() semantics,
 * which chronicler_sheets_formula_subkeys uses to decide whether a counter
 * exposes ["max"]. `max:` with no value is "not set" on both sides.
 */
export const hasNonNullKey = (map: YAMLMap, key: string): boolean => {
  const node = mapGet(map, key);
  if (node === undefined || node === null) {
    return false;
  }
  return !(isScalar(node) && node.value === null);
};

/**
 * The declared option ids of a select or checklist, in order. A checklist's
 * are also its formula parts — moves["the_big_entrance"] — so lint,
 * completion and the PHP context builder read the same list.
 */
export const optionIds = (map: YAMLMap): string[] => {
  const ids: string[] = [];
  for (const option of seqItems(mapGet(map, "options"))) {
    const id = isMap(option) ? stringOf(mapGet(option as YAMLMap, "id")) : undefined;
    if (id !== undefined) {
      ids.push(id);
    }
  }
  return ids;
};

/** [from, to] document offsets for a node; degenerate but valid when absent. */
export const rangeOf = (node: unknown): [number, number] => {
  const range = (node as { range?: [number, number, number] } | null)?.range;
  if (!range) {
    return [0, 1];
  }
  return [range[0], Math.max(range[1], range[0] + 1)];
};
