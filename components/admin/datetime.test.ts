import { describe, it, expect } from "vitest";
import {
  isoToLocalInputValue,
  parseLocalInputValue,
  toLocalInputValue,
  validateSessionRange,
} from "@/components/admin/datetime";

describe("validateSessionRange (the fetch gate — recorded #96 gap)", () => {
  it("rejects empty bounds", () => {
    expect(validateSessionRange("", "2026-07-11T10:00")).toEqual({
      ok: false,
      error: "Enter both a start and an end time.",
    });
    expect(validateSessionRange("2026-07-11T10:00", "  ")).toEqual({
      ok: false,
      error: "Enter both a start and an end time.",
    });
  });

  it("rejects unparseable values", () => {
    const result = validateSessionRange("not-a-date", "2026-07-11T10:00");
    expect(result).toEqual({ ok: false, error: "Enter valid start and end times." });
  });

  it("rejects start at or after end", () => {
    expect(validateSessionRange("2026-07-11T10:00", "2026-07-11T10:00")).toEqual({
      ok: false,
      error: "Start time must be before end time.",
    });
    expect(validateSessionRange("2026-07-11T11:00", "2026-07-11T10:00")).toEqual({
      ok: false,
      error: "Start time must be before end time.",
    });
  });

  it("accepts a valid range and derives ms + ISO forms", () => {
    const result = validateSessionRange("2026-07-10T10:00", "2026-07-11T10:00");
    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.endMs - result.startMs).toBe(24 * 60 * 60 * 1000);
    expect(result.startMs).toBe(new Date("2026-07-10T10:00").getTime());
    expect(Date.parse(result.startIso)).toBe(result.startMs);
    expect(Date.parse(result.endIso)).toBe(result.endMs);
  });
});

describe("datetime-local round trips", () => {
  it("toLocalInputValue ↔ parseLocalInputValue", () => {
    const value = "2026-07-11T09:30";
    const ms = parseLocalInputValue(value)!;
    expect(toLocalInputValue(new Date(ms))).toBe(value);
  });

  it("parseLocalInputValue is null for empty/garbage", () => {
    expect(parseLocalInputValue("")).toBeNull();
    expect(parseLocalInputValue("garbage")).toBeNull();
  });

  it("isoToLocalInputValue survives the ISO forms the session stores", () => {
    const local = "2026-07-11T09:30";
    const iso = new Date(parseLocalInputValue(local)!).toISOString();
    expect(isoToLocalInputValue(iso)).toBe(local);
    expect(isoToLocalInputValue("not-iso")).toBe("");
  });
});
