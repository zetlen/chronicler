import { describe, it, expect } from "vitest";
import {
  PALETTE,
  DARK_PALETTE,
  colorForName,
  darkVariantOf,
  assignDistinctColors,
  paletteIndexForName,
} from "@/lib/transform/color";

/** WCAG 2.x relative luminance of a #rrggbb color. */
function luminance(hex: string): number {
  const channel = (i: number) => {
    const v = parseInt(hex.slice(i, i + 2), 16) / 255;
    return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

// The transcript's message backgrounds — keep in sync with styles.ts tokens.
const LIGHT_BG = "#ffffff";
const DARK_BG = "#1a1d21";

describe("identity palettes", () => {
  it("every light hue reads on white at AA (4.5:1)", () => {
    for (const c of PALETTE) {
      expect.soft(contrast(c, LIGHT_BG), `${c} on ${LIGHT_BG}`).toBeGreaterThanOrEqual(4.5);
    }
  });

  it("every dark hue reads on the dark background at AA (4.5:1)", () => {
    for (const c of DARK_PALETTE) {
      expect.soft(contrast(c, DARK_BG), `${c} on ${DARK_BG}`).toBeGreaterThanOrEqual(4.5);
    }
  });

  it("palettes are index-matched and darkVariantOf maps between them", () => {
    expect(DARK_PALETTE).toHaveLength(PALETTE.length);
    for (const [i, light] of PALETTE.entries()) {
      expect(darkVariantOf(light)).toBe(DARK_PALETTE[i]);
    }
  });

  it("darkVariantOf passes user-picked colors through unchanged", () => {
    expect(darkVariantOf("#123456")).toBe("#123456");
  });

  it("colorForName is stable and stays inside the palette", () => {
    const c = colorForName("Somebody");
    expect(colorForName("Somebody")).toBe(c);
    expect(PALETTE).toContain(c);
  });

  // sRGB → Oklab (Björn Ottosson's reference); ΔE is Euclidean in Oklab.
  // Calibrated on the pre-curation palette: confusable pairs (teal/green,
  // blue/ocean, amber/gold) sat at ΔE ≤ 0.051; the classic still-distinct
  // red/orange pair sits at 0.083 — so 0.08 is the distinguishability floor.
  function oklab(hex: string): [number, number, number] {
    const [r, g, b] = [1, 3, 5].map((i) => {
      const v = parseInt(hex.slice(i, i + 2), 16) / 255;
      return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    return [
      0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
      1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
      0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
    ];
  }
  function deltaE(a: string, b: string): number {
    const [l1, a1, b1] = oklab(a);
    const [l2, a2, b2] = oklab(b);
    return Math.hypot(l1 - l2, a1 - a2, b1 - b2);
  }
  const MIN_DISTINCT = 0.08;

  it("every pair of light hues is distinguishable at text size", () => {
    for (let i = 0; i < PALETTE.length; i++) {
      for (let j = i + 1; j < PALETTE.length; j++) {
        expect
          .soft(deltaE(PALETTE[i], PALETTE[j]), `${PALETTE[i]} vs ${PALETTE[j]}`)
          .toBeGreaterThanOrEqual(MIN_DISTINCT);
      }
    }
  });

  it("every pair of dark hues is distinguishable at text size", () => {
    for (let i = 0; i < DARK_PALETTE.length; i++) {
      for (let j = i + 1; j < DARK_PALETTE.length; j++) {
        expect
          .soft(deltaE(DARK_PALETTE[i], DARK_PALETTE[j]), `${DARK_PALETTE[i]} vs ${DARK_PALETTE[j]}`)
          .toBeGreaterThanOrEqual(MIN_DISTINCT);
      }
    }
  });
});

describe("assignDistinctColors", () => {
  const cast = [
    { id: "U3", name: "STORY" },
    { id: "U1", name: "DRIZZT" },
    { id: "U2", name: "KEEPER" },
    { id: "U5", name: "WOLFGANG" },
    { id: "U4", name: "STACY" },
  ];

  it("assigns every unassigned speaker a distinct palette entry", () => {
    const out = assignDistinctColors(cast, {});
    expect(Object.keys(out).sort()).toEqual(["U1", "U2", "U3", "U4", "U5"]);
    const values = Object.values(out);
    expect(new Set(values).size).toBe(values.length);
    for (const c of values) expect(PALETTE).toContain(c);
  });

  it("is deterministic regardless of input order", () => {
    expect(assignDistinctColors([...cast].reverse(), {})).toEqual(
      assignDistinctColors(cast, {}),
    );
  });

  it("starts each user at their hash index", () => {
    const alone = cast[0];
    expect(assignDistinctColors([alone], {})).toEqual({
      [alone.id]: PALETTE[paletteIndexForName(alone.name)],
    });
  });

  it("respects existing overrides: never reassigns or collides with them", () => {
    const pinned = PALETTE[paletteIndexForName("DRIZZT")];
    const out = assignDistinctColors(cast, { U1: { color: pinned } });
    expect(out.U1).toBeUndefined();
    expect(Object.values(out)).not.toContain(pinned);
  });

  it("returns nothing when everyone already has a color", () => {
    const existing = Object.fromEntries(cast.map((u) => [u.id, { color: "#123456" }]));
    expect(assignDistinctColors(cast, existing)).toEqual({});
  });

  it("wraps to hash-entry reuse only when the cast exceeds the palette", () => {
    const big = Array.from({ length: PALETTE.length + 1 }, (_, i) => ({
      id: `U${String(i).padStart(2, "0")}`,
      name: `Speaker ${i}`,
    }));
    const out = assignDistinctColors(big, {});
    expect(Object.keys(out)).toHaveLength(big.length);
    // All 10 palette entries are in use before any entry repeats.
    expect(new Set(Object.values(out)).size).toBe(PALETTE.length);
  });
});
