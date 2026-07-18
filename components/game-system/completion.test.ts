import { describe, expect, it } from "vitest";
import {
  CompletionContext,
  type CompletionResult,
  type CompletionSource,
} from "@codemirror/autocomplete";
import { gameSystemCompletion } from "./completion";
import { parsedYamlState } from "./testSupport";

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
  - id: luck
    label: Luck
    type: counter
  - id: gear
    label: Gear
    type: list
    fields:
      - id: armed
        label: Armed?
        type: toggle
      - id: notes_col
        label: Notes
        type: te
    derived_not: x
  - id: speed
    label: Speed
    type: number
    derived: flo
layout:
  - section: Ratings
    properties: [vigor, vi]
`;

const stubResult: CompletionResult = {
  from: 0,
  options: [{ label: "from-library" }],
};
const library: CompletionSource = () => stubResult;
const source = gameSystemCompletion(library);

function completeAt(pos: number, explicit = true) {
  const state = parsedYamlState(doc);
  return source(
    new CompletionContext(state, pos, explicit),
  ) as CompletionResult | null;
}

const after = (needle: string) => doc.indexOf(needle) + needle.length;

describe("gameSystemCompletion — formula context", () => {
  const result = completeAt(after("derived: flo"));

  it("offers referencable properties, with track/counter parts spelled out", () => {
    const labels = result!.options.map((o) => o.label);
    expect(labels).toContain("vigor");
    expect(labels).toContain('harm["current"]');
    expect(labels).toContain('harm["max"]');
    expect(labels).toContain('luck["current"]');
    expect(labels).not.toContain('luck["max"]'); // counter without max
    expect(labels).not.toContain("gear"); // lists aren't referencable
  });

  it("offers the five functions with docs, applying an open paren", () => {
    const floor = result!.options.find((o) => o.label === "floor")!;
    expect(floor.apply).toBe("floor(");
    expect(floor.info).toBeDefined();
  });

  it("completes from the start of the identifier being typed", () => {
    expect(result!.from).toBe(doc.indexOf("flo", doc.indexOf("derived:")));
  });

  it("documents properties with their sheet label and type", () => {
    const vigor = result!.options.find((o) => o.label === "vigor")!;
    const info = (vigor.info as () => HTMLElement)();
    expect(info.textContent).toContain("Vigor");
    expect(info.textContent).toContain("number");
  });
});

describe("gameSystemCompletion — type values", () => {
  it("offers all nine property types with per-type docs", () => {
    const result = completeAt(after("type: number") - "umber".length);
    const labels = result!.options.map((o) => o.label);
    expect(labels).toHaveLength(9);
    expect(labels).toContain("track");
    const track = result!.options.find((o) => o.label === "track")!;
    const info = (track.info as () => HTMLElement)();
    expect(info.textContent).toMatch(/track|boxes/i);
  });

  it("offers only the five list-field types inside fields", () => {
    const result = completeAt(after("type: te"));
    const labels = result!.options.map((o) => o.label);
    expect(labels).toHaveLength(5);
    expect(labels).toContain("toggle");
    expect(labels).not.toContain("track");
  });
});

describe("gameSystemCompletion — reference contexts", () => {
  it("offers unplaced property ids in layout sections", () => {
    const result = completeAt(after("[vigor, vi"));
    const labels = result!.options.map((o) => o.label);
    expect(labels).not.toContain("vigor"); // already placed
    expect(labels).toContain("harm");
  });

  it('offers referencable sibling fields and functions for a list-field "when"', () => {
    const whenDoc = `properties:
  - id: gear
    label: Gear
    type: list
    fields:
      - id: name
        label: Name
        type: text
      - id: armed
        label: Armed?
        type: toggle
      - id: diary
        label: Diary
        type: longtext
      - id: notes
        label: Notes
        type: text
        when: ar
`;
    const whenState = parsedYamlState(whenDoc);
    const result = source(
      new CompletionContext(whenState, whenDoc.indexOf("when: ar") + "when: ar".length, true),
    ) as CompletionResult | null;
    const labels = (result?.options ?? []).map((o) => o.label);
    expect(labels).toContain("armed");
    expect(labels).toContain("name");
    expect(labels).not.toContain("diary"); // longtext isn't referencable
    // A field can't be excluded from its own `when` completion cheaply
    // (DeclaredField carries no range) — the lint (Task 5) flags a
    // self-reference immediately, so we don't fake self-exclusion here.
    expect(labels).toContain("floor"); // formula functions complete too
  });
});

describe("gameSystemCompletion — passthrough", () => {
  it("defers to the library source everywhere else", () => {
    expect(completeAt(after("label: Vig"))).toBe(stubResult);
    expect(completeAt(after("system: De"))).toBe(stubResult);
  });

  it("suppresses custom completions on empty non-explicit prefixes", () => {
    const pos = after("derived: flo") - 3;
    expect(completeAt(pos - 1, false)).toBeNull();
  });
});
