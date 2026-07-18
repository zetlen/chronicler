import { describe, it, expect } from "vitest";
import { variantClasses, isOoc, MESSAGE_VARIANTS } from "@/lib/transform/variants";

describe("variantClasses", () => {
  it("maps known variants to slk-msg-- classes in the vocabulary order", () => {
    // input order is irrelevant -> deterministic output for byte-parity
    expect(variantClasses(["important", "ooc"])).toEqual(["slk-msg--ooc", "slk-msg--important"]);
  });
  it("ignores unknown entries (whitelist)", () => {
    expect(variantClasses(["ooc", "bogus", "collapsed"])).toEqual(["slk-msg--ooc"]);
  });
  it("returns [] for no variants", () => {
    expect(variantClasses([])).toEqual([]);
  });
});

describe("isOoc", () => {
  it("is true only when ooc is present", () => {
    expect(isOoc(["ooc"])).toBe(true);
    expect(isOoc(["important"])).toBe(false);
    expect(isOoc([])).toBe(false);
  });
});

describe("MESSAGE_VARIANTS", () => {
  it("is the fixed two-variant vocabulary", () => {
    expect(MESSAGE_VARIANTS).toEqual(["ooc", "important"]);
  });
});
