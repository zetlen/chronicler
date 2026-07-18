import { describe, expect, it } from "vitest";
import {
  FORMULA_FUNCTIONS,
  FORMULA_REF_TYPES,
  scanFormula,
} from "./formulaLang";

describe("formula vocabulary (from template.schema.json)", () => {
  it("exposes the five documented functions with docs", () => {
    expect(FORMULA_FUNCTIONS.map((f) => f.name)).toEqual([
      "floor",
      "ceil",
      "round",
      "min",
      "max",
    ]);
    for (const fn of FORMULA_FUNCTIONS) {
      expect(fn.doc.length).toBeGreaterThan(0);
      expect(fn.args.length).toBeGreaterThan(0);
    }
  });

  it("exposes the referencable property types", () => {
    expect(FORMULA_REF_TYPES).toEqual([
      "number",
      "track",
      "counter",
      "toggle",
      "select",
      "text",
    ]);
  });
});

describe("scanFormula", () => {
  it("finds bare identifiers with offsets", () => {
    const scan = scanFormula("vigor + armor");
    expect(scan.idents).toEqual([
      { name: "vigor", from: 0, to: 5, bracketed: false },
      { name: "armor", from: 8, to: 13, bracketed: false },
    ]);
    expect(scan.calls).toEqual([]);
  });

  it("separates function calls from identifiers", () => {
    const scan = scanFormula("floor(vigor / 2)");
    expect(scan.calls).toEqual([{ name: "floor", from: 0, to: 5 }]);
    expect(scan.idents).toEqual([
      { name: "vigor", from: 6, to: 11, bracketed: false },
    ]);
  });

  it("marks bracketed identifiers and skips their string keys", () => {
    const scan = scanFormula('harm["current"] + 1');
    expect(scan.idents).toEqual([
      { name: "harm", from: 0, to: 4, bracketed: true },
    ]);
  });

  it("skips string literal contents entirely", () => {
    const scan = scanFormula("kind == 'monstrous' ? 2 : 1");
    expect(scan.idents.map((i) => i.name)).toEqual(["kind"]);
  });

  it("ignores expression-language keywords and literals", () => {
    const scan = scanFormula("hurt and not safe or true");
    expect(scan.idents.map((i) => i.name)).toEqual(["hurt", "safe"]);
  });

  it("does not mistake exponent suffixes for identifiers", () => {
    expect(scanFormula("1e3 + vigor").idents.map((i) => i.name)).toEqual([
      "vigor",
    ]);
  });

  it("handles calls with spaces before the paren", () => {
    const scan = scanFormula("min (a, b)");
    expect(scan.calls.map((c) => c.name)).toEqual(["min"]);
    expect(scan.idents.map((i) => i.name)).toEqual(["a", "b"]);
  });

  it("scans multi-line formulas with correct offsets", () => {
    const expr = "floor(vigor / 2)\n  + armor";
    const scan = scanFormula(expr);
    const armor = scan.idents.find((i) => i.name === "armor");
    expect(armor).toBeDefined();
    expect(expr.slice(armor!.from, armor!.to)).toBe("armor");
  });
});
