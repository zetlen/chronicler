// The derived-formula vocabulary, read from the canonical template schema so
// the editor can never drift from the PHP engine: schema-drift.test.php pins
// x-chronicler-formula to CHRONICLER_FORMULA_FUNCTIONS / _REF_TYPES.
import schema from "@/wordpress-plugin/sheets/template.schema.json";

export interface FormulaFunction {
  name: string;
  args: string;
  doc: string;
}

const vocabulary = (
  schema as unknown as {
    "x-chronicler-formula": { refTypes: string[]; functions: FormulaFunction[] };
  }
)["x-chronicler-formula"];

/** The functions formulas know, with author-facing docs. */
export const FORMULA_FUNCTIONS: readonly FormulaFunction[] =
  vocabulary.functions;

/** Property types a formula may reference (mirrors the PHP context builder). */
export const FORMULA_REF_TYPES: readonly string[] = vocabulary.refTypes;

/** Just the names, for "unknown function" checks and messages. */
export const FORMULA_FUNCTION_NAMES: readonly string[] =
  FORMULA_FUNCTIONS.map((fn) => fn.name);

/** Property types addressed with a ["part"] rather than by bare id. */
export const FORMULA_PART_TYPES = ["track", "counter", "checklist"] as const;

/** Whether a property is referenced as name["part"] rather than bare. */
export function hasFormulaParts(type: string | undefined): boolean {
  return (FORMULA_PART_TYPES as readonly string[]).includes(type ?? "");
}

/**
 * The parts a track/counter/checklist exposes to formulas (mirrors PHP's
 * chronicler_sheets_formula_subkeys: a counter has ["max"] only when it
 * declares max — presence-based, like PHP's isset; a checklist's parts are
 * its option ids, each 1 when checked). Shared by lint hints and autocomplete
 * so their guidance can never disagree.
 */
export function formulaParts(property: {
  type: string;
  hasMax: boolean;
  options?: string[];
}): string[] {
  if (property.type === "checklist") {
    return property.options ?? [];
  }
  if (property.type === "track") {
    return ["current", "max"];
  }
  return property.hasMax ? ["current", "max"] : ["current"];
}

/** Expression-language words that look like identifiers but aren't refs. */
const KEYWORDS = new Set(["and", "or", "not", "in", "true", "false", "null"]);

export interface FormulaIdent {
  name: string;
  from: number;
  to: number;
  /** Whether the identifier is followed by a `[` part lookup. */
  bracketed: boolean;
}

export interface FormulaCall {
  name: string;
  from: number;
  to: number;
}

export interface FormulaScan {
  idents: FormulaIdent[];
  calls: FormulaCall[];
}

/**
 * Best-effort lexical scan of a formula: identifiers (property references)
 * and function calls, with offsets into the expression. String literals and
 * numbers are skipped; expression keywords aren't identifiers. This backs
 * live lint hints and autocomplete — the server's real Expression Language
 * parse at preflight/save remains the authority.
 */
export function scanFormula(expr: string): FormulaScan {
  const idents: FormulaIdent[] = [];
  const calls: FormulaCall[] = [];
  let i = 0;
  while (i < expr.length) {
    const ch = expr[i];
    if (ch === '"' || ch === "'") {
      i += 1;
      while (i < expr.length && expr[i] !== ch) {
        i += expr[i] === "\\" ? 2 : 1;
      }
      i += 1;
      continue;
    }
    if (ch >= "0" && ch <= "9") {
      // Consume the whole numeric token (incl. 1e3 / 0x1f shapes) so its
      // letter suffix can't read as an identifier.
      i += 1;
      while (i < expr.length && /[0-9a-zA-Z_.]/.test(expr[i])) {
        i += 1;
      }
      continue;
    }
    if (/[A-Za-z_]/.test(ch)) {
      const from = i;
      while (i < expr.length && /[A-Za-z0-9_]/.test(expr[i])) {
        i += 1;
      }
      const name = expr.slice(from, i);
      if (KEYWORDS.has(name)) {
        continue;
      }
      let j = i;
      while (j < expr.length && /\s/.test(expr[j])) {
        j += 1;
      }
      if (expr[j] === "(") {
        calls.push({ name, from, to: i });
      } else {
        idents.push({ name, from, to: i, bracketed: expr[j] === "[" });
      }
      continue;
    }
    i += 1;
  }
  return { idents, calls };
}

/** Levenshtein distance, for "did you mean …?" suggestions on small ids. */
export function editDistance(a: string, b: string): number {
  const rows = a.length + 1;
  const cols = b.length + 1;
  const d = new Array(rows * cols).fill(0);
  for (let r = 0; r < rows; r++) d[r * cols] = r;
  for (let c = 0; c < cols; c++) d[c] = c;
  for (let r = 1; r < rows; r++) {
    for (let c = 1; c < cols; c++) {
      const sub = a[r - 1] === b[c - 1] ? 0 : 1;
      d[r * cols + c] = Math.min(
        d[(r - 1) * cols + c] + 1,
        d[r * cols + c - 1] + 1,
        d[(r - 1) * cols + c - 1] + sub,
      );
    }
  }
  return d[rows * cols - 1];
}

/** The closest candidate within edit distance 2, for friendlier messages. */
export function didYouMean(
  name: string,
  candidates: Iterable<string>,
): string | null {
  let best: string | null = null;
  let bestDistance = 3;
  for (const candidate of candidates) {
    const distance = editDistance(name, candidate);
    if (distance > 0 && distance < bestDistance) {
      best = candidate;
      bestDistance = distance;
    }
  }
  return best;
}
