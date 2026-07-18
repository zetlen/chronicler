// The relational half of live template validation: everything the canonical
// JSON schema can't express (and the Draft04 engine's if/then gaps), mirrored
// from chronicler_sheets_parse_template()'s rules so authors see the problems
// inline before Save. The PHP validator remains the Save-time authority; the
// server preflight (preflight.ts) closes anything this best-effort pass and
// the structural schema linter can't see (real Expression Language parsing,
// formula cycles, dry-run type checks).
import { isMap, parseDocument } from "yaml";
import type { Scalar, YAMLMap } from "yaml";
import {
  didYouMean,
  FORMULA_FUNCTION_NAMES,
  FORMULA_REF_TYPES,
  formulaParts,
  scanFormula,
} from "./formulaLang";
import {
  hasNonNullKey,
  intOf,
  isTrue,
  mapGet,
  rangeOf,
  seqItems,
  stringOf,
} from "./yamlNodes";

/** Sibling field types a list-field `when` expression may reference. */
export const ENTRY_REF_TYPES = ["number", "toggle", "select", "text"];

export interface TemplateDiagnostic {
  from: number;
  to: number;
  severity: "error" | "warning";
  message: string;
}

interface PropertyInfo {
  type: string | undefined;
  /** Whether a counter declares max (its ["max"] part exists only then). */
  hasMax: boolean;
}

/** First line of a YAML parse message — the rest is a code frame. */
const firstLine = (message: string): string => message.split("\n")[0];

const suggest = (name: string, candidates: Iterable<string>): string => {
  const guess = didYouMean(name, candidates);
  return guess ? ` Did you mean "${guess}"?` : "";
};

/**
 * Lint a template source (YAML or JSON — YAML is a superset) for the rules
 * the structural schema can't express. Returns diagnostics with document
 * offsets; an empty array means "nothing THIS pass can see" — not validity.
 */
export function lintTemplateSource(text: string): TemplateDiagnostic[] {
  if (text.trim() === "") {
    return [];
  }
  const doc = parseDocument(text);
  if (doc.errors.length > 0) {
    // Syntax problems make everything downstream unreliable; report only them.
    return doc.errors.map((err) => ({
      from: err.pos[0],
      to: Math.max(err.pos[1], err.pos[0] + 1),
      severity: "error" as const,
      message: firstLine(err.message),
    }));
  }
  const diags: TemplateDiagnostic[] = doc.warnings.map((warn) => ({
    from: warn.pos[0],
    to: Math.max(warn.pos[1], warn.pos[0] + 1),
    severity: "warning" as const,
    message: firstLine(warn.message),
  }));
  if (!isMap(doc.contents)) {
    return [
      ...diags,
      {
        from: 0,
        to: Math.max(text.trimEnd().length, 1),
        severity: "error",
        message:
          "The template must be an object with system, version, and properties.",
      },
    ];
  }
  const root = doc.contents as YAMLMap;
  const report = (node: unknown, message: string) => {
    const [from, to] = rangeOf(node);
    diags.push({ from, to, severity: "error", message });
  };

  // --- Properties: declarations, per-type requirements, flag conflicts ------
  const declared = new Map<string, PropertyInfo>();
  for (const item of seqItems(mapGet(root, "properties"))) {
    if (!isMap(item)) {
      continue; // the structural schema flags non-object entries
    }
    const property = item as YAMLMap;
    const idNode = mapGet(property, "id");
    const id = stringOf(idNode);
    const typeNode = mapGet(property, "type");
    const type = stringOf(typeNode);
    if (id !== undefined) {
      if (declared.has(id)) {
        report(idNode, `Two properties share the id "${id}" — ids must be unique.`);
      } else {
        declared.set(id, {
          type,
          hasMax: hasNonNullKey(property, "max"),
        });
      }
    }
    if (id === undefined || type === undefined) {
      continue; // structural schema owns missing/mistyped id and type
    }
    const anchor = typeNode;
    if (type === "track" && mapGet(property, "length") === undefined) {
      report(anchor, `"${id}" is a track — it needs "length": how many boxes the track has.`);
    }
    if ((type === "select" || type === "checklist") && mapGet(property, "options") === undefined) {
      report(anchor, `"${id}" is a ${type} — it needs "options": the choices to offer.`);
    }
    if (type === "list" && mapGet(property, "fields") === undefined) {
      report(anchor, `"${id}" is a list — it needs "fields": what each entry records.`);
    }
    checkBounds(property, `"${id}"`, report);
    const live = isTrue(mapGet(property, "live"));
    const derivedNode = mapGet(property, "derived");
    if (derivedNode !== undefined && type !== "number" && type !== "toggle") {
      report(
        mapGet(property, "derived"),
        `"${id}" is a ${type} property — formulas ("derived") work on number and toggle properties.`,
      );
    }
    if (isTrue(mapGet(property, "gm_only")) && isTrue(mapGet(property, "owner_only"))) {
      report(
        mapGet(property, "owner_only"),
        `"${id}" can't be both owner_only and gm_only — gm_only hides it from the owning player; owner_only shows it to them and the GM.`,
      );
    }
    if (live && isTrue(mapGet(property, "gm_only"))) {
      report(
        mapGet(property, "live"),
        `"${id}" can't be both live and gm_only — GM-only fields are never player-editable.`,
      );
    } else if (live && derivedNode !== undefined) {
      report(
        mapGet(property, "live"),
        `"${id}" is computed from a formula — it can't also be live.`,
      );
    } else if (live && type === "list") {
      report(
        mapGet(property, "live"),
        `"${id}" is a list — lists are edited in wp-admin and can't be live.`,
      );
    }
    checkOptions(property, `Property "${id}"`, report);
    checkListFields(property, id, report);
  }

  // --- Formulas: reference validity (real EL parsing happens server-side) ---
  for (const item of seqItems(mapGet(root, "properties"))) {
    if (!isMap(item)) {
      continue;
    }
    const derivedNode = mapGet(item as YAMLMap, "derived");
    const expr = stringOf(derivedNode);
    if (expr !== undefined) {
      checkFormula(expr, derivedNode as Scalar, declared, diags);
    }
  }

  // --- Layout: every reference declared, each property placed once ----------
  const placed = new Set<string>();
  for (const section of seqItems(mapGet(root, "layout"))) {
    if (!isMap(section)) {
      continue;
    }
    for (const ref of seqItems(mapGet(section as YAMLMap, "properties"))) {
      const id = stringOf(ref);
      if (id === undefined) {
        continue;
      }
      if (!declared.has(id)) {
        report(
          ref,
          `The layout names "${id}", but no property has that id.${suggest(id, declared.keys())}`,
        );
      } else if (placed.has(id)) {
        report(ref, `"${id}" is already placed in the layout — each property appears once.`);
      } else {
        placed.add(id);
      }
    }
  }

  return diags.sort((a, b) => a.from - b.from);
}

/** min/max sanity for a property or list field (both optional integers). */
function checkBounds(
  map: YAMLMap,
  label: string,
  report: (node: unknown, message: string) => void,
): void {
  const min = intOf(mapGet(map, "min"));
  const max = intOf(mapGet(map, "max"));
  if (min !== undefined && max !== undefined && min > max) {
    report(mapGet(map, "min"), `${label}: min (${min}) is greater than max (${max}).`);
  }
}

/** Duplicate option ids within a select/checklist. */
function checkOptions(
  map: YAMLMap,
  label: string,
  report: (node: unknown, message: string) => void,
): void {
  const seen = new Set<string>();
  for (const option of seqItems(mapGet(map, "options"))) {
    if (!isMap(option)) {
      continue;
    }
    const idNode = mapGet(option as YAMLMap, "id");
    const id = stringOf(idNode);
    if (id === undefined) {
      continue;
    }
    if (seen.has(id)) {
      report(idNode, `${label}: two options share the id "${id}".`);
    }
    seen.add(id);
  }
}

/** List-field rules: unique ids, select options, bounds, `when` expressions. */
function checkListFields(
  property: YAMLMap,
  propertyId: string,
  report: (node: unknown, message: string) => void,
): void {
  const fields = seqItems(mapGet(property, "fields"));
  const fieldTypes = new Map<string, string | undefined>();
  for (const field of fields) {
    if (!isMap(field)) {
      continue;
    }
    const idNode = mapGet(field as YAMLMap, "id");
    const id = stringOf(idNode);
    if (id === undefined) {
      continue;
    }
    if (fieldTypes.has(id)) {
      report(idNode, `Property "${propertyId}": two fields share the id "${id}".`);
    }
    const type = stringOf(mapGet(field as YAMLMap, "type"));
    fieldTypes.set(id, type);
    if (type === "select" && mapGet(field as YAMLMap, "options") === undefined) {
      report(
        mapGet(field as YAMLMap, "type"),
        `"${propertyId}" field "${id}" is a select — it needs "options": the choices to offer.`,
      );
    }
    checkBounds(field as YAMLMap, `"${propertyId}" field "${id}"`, report);
    checkOptions(field as YAMLMap, `"${propertyId}" field "${id}"`, report);
  }
  for (const field of fields) {
    if (!isMap(field)) {
      continue;
    }
    const id = stringOf(mapGet(field as YAMLMap, "id"));
    const whenNode = mapGet(field as YAMLMap, "when");
    if (whenNode === undefined || id === undefined) {
      continue;
    }
    const expr = stringOf(whenNode);
    if (expr === undefined || expr.trim() === "") {
      report(
        whenNode,
        `"${propertyId}" field "${id}": "when" must be an expression over the entry's other fields.`,
      );
      continue;
    }
    const referencable = new Set(
      [...fieldTypes.entries()]
        .filter(([fid, type]) => fid !== id && type !== undefined && ENTRY_REF_TYPES.includes(type))
        .map(([fid]) => fid),
    );
    const scan = scanFormula(expr);
    for (const call of scan.calls) {
      if (!FORMULA_FUNCTION_NAMES.includes(call.name)) {
        report(
          whenNode,
          `Formulas don't know "${call.name}" — they know ${FORMULA_FUNCTION_NAMES.join(", ")}.${suggest(call.name, FORMULA_FUNCTION_NAMES)}`,
        );
      }
    }
    for (const ident of scan.idents) {
      if (ident.name === id) {
        report(
          whenNode,
          `"${propertyId}" field "${id}": "when" can't reference the field it gates.`,
        );
      } else if (!fieldTypes.has(ident.name)) {
        report(
          whenNode,
          `"${propertyId}" field "${id}": "when" references "${ident.name}", but no sibling field has that id.${suggest(ident.name, referencable)}`,
        );
      } else if (!referencable.has(ident.name)) {
        report(
          whenNode,
          `"${propertyId}" field "${id}": "when" can't use "${ident.name}" — it's a ${fieldTypes.get(ident.name)} field.`,
        );
      } else if (ident.bracketed) {
        report(whenNode, `"${ident.name}" is a field — reference it without brackets.`);
      }
    }
  }
}

function checkFormula(
  expr: string,
  anchor: Scalar,
  declared: Map<string, PropertyInfo>,
  diags: TemplateDiagnostic[],
): void {
  const [from, to] = rangeOf(anchor);
  const report = (message: string) =>
    diags.push({ from, to, severity: "error", message });
  const referencable = new Set(
    [...declared.entries()]
      .filter(([, info]) => info.type !== undefined && FORMULA_REF_TYPES.includes(info.type))
      .map(([id]) => id),
  );
  const scan = scanFormula(expr);
  for (const call of scan.calls) {
    if (!FORMULA_FUNCTION_NAMES.includes(call.name)) {
      diags.push({
        from,
        to,
        severity: "error",
        message: `Formulas don't know "${call.name}" — they know ${FORMULA_FUNCTION_NAMES.join(", ")}.${suggest(call.name, FORMULA_FUNCTION_NAMES)}`,
      });
    }
  }
  for (const ident of scan.idents) {
    const info = declared.get(ident.name);
    if (info === undefined) {
      report(
        `The formula references "${ident.name}", but no property has that id.${suggest(ident.name, referencable)}`,
      );
      continue;
    }
    if (info.type !== undefined && !FORMULA_REF_TYPES.includes(info.type)) {
      report(
        `Formulas can't use "${ident.name}" — it's a ${info.type}. Formulas read ${FORMULA_REF_TYPES.slice(0, -1).join(", ")}, and ${FORMULA_REF_TYPES[FORMULA_REF_TYPES.length - 1]} properties.`,
      );
      continue;
    }
    const hasParts = info.type === "track" || info.type === "counter";
    if (hasParts && !ident.bracketed) {
      const parts = formulaParts({ type: info.type ?? "", hasMax: info.hasMax })
        .map((part) => `${ident.name}["${part}"]`)
        .join(" or ");
      report(`"${ident.name}" is a ${info.type} — reference a part: ${parts}.`);
    } else if (!hasParts && ident.bracketed) {
      report(`"${ident.name}" is a ${info.type} — reference it without brackets.`);
    }
  }
}
