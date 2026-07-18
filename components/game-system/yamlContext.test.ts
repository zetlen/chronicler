import { describe, expect, it } from "vitest";
import { parsedYamlState } from "./testSupport";
import { declaredProperties, valueContextAt } from "./yamlContext";

const doc = `system: Demo
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
  - id: harm
    label: Harm
    type: track
    length: 7
  - id: speed
    label: Speed
    type: number
    derived: |
      floor(vigor / 2)
        + 2
  - id: gear
    label: Gear
    type: list
    fields:
      - id: armed
        label: Armed?
        type: toggle
      - id: notes_col
        label: Notes
        type: text
        when: armed
layout:
  - section: Ratings
    properties: [vigor]
`;

const state = parsedYamlState(doc);
const at = (needle: string, offset = 0) => doc.indexOf(needle) + offset;

describe("valueContextAt", () => {
  it("detects the derived block-scalar context", () => {
    const ctx = valueContextAt(state, at("floor(vigor") + 5);
    expect(ctx?.path).toEqual(["properties", "derived"]);
  });

  it("detects a property type value", () => {
    const ctx = valueContextAt(state, at("type: number") + "type: numb".length);
    expect(ctx?.path).toEqual(["properties", "type"]);
  });

  it("detects a list-field type value", () => {
    const ctx = valueContextAt(state, at("type: toggle") + "type: togg".length);
    expect(ctx?.path).toEqual(["properties", "fields", "type"]);
  });

  it("detects layout property references", () => {
    const ctx = valueContextAt(state, at("[vigor]") + 3);
    expect(ctx?.path).toEqual(["layout", "properties"]);
  });

  it("detects a list-field when value", () => {
    const ctx = valueContextAt(state, at("when: armed") + "when: arm".length);
    expect(ctx?.path).toEqual(["properties", "fields", "when"]);
  });

  it("returns null inside a key", () => {
    expect(valueContextAt(state, at("label: Vigor") + 2)).toBeNull();
  });

  it("resolves contexts beyond the initial-parse viewport", () => {
    // Past ~3000 chars a bare EditorState.create deterministically leaves the
    // tail unparsed — this covers what CPU contention does to shorter docs.
    const properties = Array.from(
      { length: 150 },
      (_, i) => `  - id: p${i}\n    label: P${i}\n    type: number\n`,
    ).join("");
    const bigDoc = `properties:\n${properties}layout:\n  - section: Ratings\n    properties: [p0]\n`;
    const ctx = valueContextAt(parsedYamlState(bigDoc), bigDoc.indexOf("[p0]") + 1);
    expect(ctx?.path).toEqual(["layout", "properties"]);
  });
});

describe("declaredProperties", () => {
  it("collects ids, labels, types, parts, ranges, and fields", () => {
    const props = declaredProperties(doc);
    expect(props.map((p) => p.id)).toEqual(["vigor", "harm", "speed", "gear"]);
    const harm = props.find((p) => p.id === "harm")!;
    expect(harm.label).toBe("Harm");
    expect(harm.type).toBe("track");
    expect(harm.hasMax).toBe(false);
    const gear = props.find((p) => p.id === "gear")!;
    expect(gear.fields.map((f) => f.id)).toEqual(["armed", "notes_col"]);
    expect(gear.fields[0].type).toBe("toggle");
    expect(doc.slice(gear.range[0], gear.range[1])).toContain("id: gear");
  });

  it("survives malformed documents", () => {
    expect(declaredProperties(":")).toEqual([]);
    expect(declaredProperties("properties: 5")).toEqual([]);
  });
});
