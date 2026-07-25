// Cursor-position awareness for the Game System editor: which template slot
// is being edited (via the Lezer YAML tree), and what the document currently
// declares (via the `yaml` parser). Both back the custom completion sources.
import { syntaxTree } from "@codemirror/language";
import type { EditorState } from "@codemirror/state";
import type { SyntaxNode } from "@lezer/common";
import { isMap, isSeq, parseDocument } from "yaml";
import type { Node, YAMLMap, YAMLSeq } from "yaml";
import { hasNonNullKey, mapGet, optionIds, seqItems, stringOf } from "./yamlNodes";

export interface ValueContext {
  /**
   * Ancestor key chain from the document root down to the pair whose VALUE
   * the cursor is in — e.g. ["properties", "derived"] inside a formula,
   * ["layout", "properties"] inside a section's id list.
   */
  path: string[];
}

const keyOf = (state: EditorState, pair: SyntaxNode): string | null => {
  const key = pair.getChild("Key");
  if (!key) {
    return null;
  }
  return state
    .sliceDoc(key.from, key.to)
    .trim()
    .replace(/^["']|["']$/g, "");
};

/**
 * The pair-key path enclosing `pos`, or null when the cursor is on a key (or
 * outside any pair value). Works for block and flow (JSON-style) collections.
 */
export function valueContextAt(
  state: EditorState,
  pos: number,
): ValueContext | null {
  let node: SyntaxNode | null = syntaxTree(state).resolveInner(pos, -1);
  const path: string[] = [];
  let innermost = true;
  for (; node; node = node.parent) {
    if (node.name !== "Pair") {
      continue;
    }
    const key = node.getChild("Key");
    if (!key) {
      continue;
    }
    if (innermost) {
      // On (or before the end of) the key itself → not a value position.
      if (pos <= key.to) {
        return null;
      }
      innermost = false;
    }
    const name = keyOf(state, node);
    if (name !== null) {
      path.unshift(name);
    }
  }
  return path.length > 0 ? { path } : null;
}

export interface DeclaredField {
  id: string;
  label: string;
  type: string;
}

export interface DeclaredProperty {
  id: string;
  label: string;
  type: string;
  /** Whether a counter declares max (its ["max"] part exists only then). */
  hasMax: boolean;
  /** Option ids, for select and checklist — a checklist's formula parts. */
  options: string[];
  /** Document offsets of the property's map node. */
  range: [number, number];
  fields: DeclaredField[];
}

export interface TemplateOutline {
  /** Declared properties, in document order. */
  properties: DeclaredProperty[];
  /** Property ids already placed in layout sections (each appears once). */
  placed: Set<string>;
}

/**
 * One parse of the document → everything the completion sources need.
 * Best-effort: malformed documents yield an empty outline, never a throw.
 */
export function templateOutline(text: string): TemplateOutline {
  const outline: TemplateOutline = { properties: [], placed: new Set() };
  let doc;
  try {
    doc = parseDocument(text);
  } catch {
    return outline;
  }
  if (!isMap(doc.contents)) {
    return outline;
  }
  const root = doc.contents as YAMLMap;
  for (const section of seqItems(mapGet(root, "layout"))) {
    if (!isMap(section)) {
      continue;
    }
    for (const id of seqItems(mapGet(section as YAMLMap, "properties"))) {
      const value = stringOf(id);
      if (value !== undefined) {
        outline.placed.add(value);
      }
    }
  }
  const propertiesNode = mapGet(root, "properties");
  if (!isSeq(propertiesNode)) {
    return outline;
  }
  const declared = outline.properties;
  for (const item of (propertiesNode as YAMLSeq).items as Node[]) {
    if (!isMap(item)) {
      continue;
    }
    const property = item as YAMLMap;
    const id = stringOf(mapGet(property, "id"));
    const type = stringOf(mapGet(property, "type"));
    if (id === undefined || type === undefined) {
      continue;
    }
    const fields: DeclaredField[] = [];
    const fieldsNode = mapGet(property, "fields");
    if (isSeq(fieldsNode)) {
      for (const field of (fieldsNode as YAMLSeq).items as Node[]) {
        if (!isMap(field)) {
          continue;
        }
        const fieldId = stringOf(mapGet(field as YAMLMap, "id"));
        const fieldType = stringOf(mapGet(field as YAMLMap, "type"));
        if (fieldId !== undefined && fieldType !== undefined) {
          fields.push({
            id: fieldId,
            label: stringOf(mapGet(field as YAMLMap, "label")) ?? fieldId,
            type: fieldType,
          });
        }
      }
    }
    const range = (item as { range?: [number, number, number] }).range;
    declared.push({
      id,
      label: stringOf(mapGet(property, "label")) ?? id,
      type,
      hasMax: hasNonNullKey(property, "max"),
      options: optionIds(property),
      range: range ? [range[0], range[1]] : [0, 0],
      fields,
    });
  }
  return outline;
}

/**
 * The properties the document declares right now, best-effort: entries with
 * a string id and type. Malformed documents yield [] rather than throwing.
 */
export function declaredProperties(text: string): DeclaredProperty[] {
  return templateOutline(text).properties;
}
