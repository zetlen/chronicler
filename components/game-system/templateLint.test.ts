import { describe, expect, it } from "vitest";
import schema from "@/wordpress-plugin/sheets/template.schema.json";
import { ENTRY_REF_TYPES, lintTemplateSource } from "./templateLint";

describe("ENTRY_REF_TYPES (from template.schema.json)", () => {
  it("matches the listField type enum minus longtext (as a set)", () => {
    // ENTRY_REF_TYPES is a hand-written literal (unlike FORMULA_REF_TYPES,
    // which reads template.schema.json's x-chronicler-formula block live), so
    // it can drift from the schema silently. This pins it to the same
    // listField.type enum that schema-drift.test.php pins
    // CHRONICLER_SHEETS_LIST_FIELD_TYPES to, and that PHP's
    // schema-drift.test.php separately pins CHRONICLER_FORMULA_ENTRY_REF_TYPES
    // to — closing the loop across the PHP/JS boundary. The schema lists
    // longtext and dice among the list-field types; they're excluded here
    // because a `when` expression can't meaningfully reference free text,
    // and a dice string is notation, not a referencable scalar. Element order
    // differs between the schema enum and ENTRY_REF_TYPES, so this compares
    // as a set.
    const listFieldTypes = (
      schema as unknown as {
        definitions: { listField: { properties: { type: { enum: string[] } } } };
      }
    ).definitions.listField.properties.type.enum;
    const expected = listFieldTypes.filter((t) => t !== "longtext" && t !== "dice");
    expect([...ENTRY_REF_TYPES].sort()).toEqual([...expected].sort());
  });
});

/** A minimal valid template to build variations from. */
const base = `system: Demo
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
    min: 0
    max: 12
  - id: harm
    label: Harm
    type: track
    length: 7
`;

const messages = (text: string) =>
  lintTemplateSource(text).map((d) => d.message);

describe("lintTemplateSource — syntax and shape", () => {
  it("accepts a valid template", () => {
    expect(lintTemplateSource(base)).toEqual([]);
  });

  it("accepts the same template as JSON (YAML superset)", () => {
    const json = JSON.stringify({
      system: "Demo",
      version: 1,
      properties: [
        { id: "vigor", label: "Vigor", type: "number", min: 0, max: 12 },
      ],
    });
    expect(lintTemplateSource(json)).toEqual([]);
  });

  it("returns nothing for a blank document", () => {
    expect(lintTemplateSource("  \n")).toEqual([]);
  });

  it("reports YAML syntax errors with positions", () => {
    const text = "system: [unclosed\nversion: 1\n";
    const diags = lintTemplateSource(text);
    expect(diags.length).toBeGreaterThan(0);
    expect(diags[0].severity).toBe("error");
  });

  it("reports duplicate mapping keys", () => {
    const text = "system: A\nsystem: B\nversion: 1\nproperties:\n  - id: a\n    label: A\n    type: text\n";
    expect(messages(text).join(" ")).toMatch(/unique|already|duplicate/i);
  });

  it("reports a non-mapping document", () => {
    const diags = lintTemplateSource("- just\n- a list\n");
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toContain("object");
  });
});

describe("lintTemplateSource — property rules", () => {
  it("flags duplicate property ids at the second occurrence", () => {
    const text = `${base}  - id: vigor
    label: Again
    type: text
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toContain('"vigor"');
    expect(text.slice(diags[0].from, diags[0].to)).toBe("vigor");
    expect(diags[0].from).toBeGreaterThan(text.indexOf("vigor"));
  });

  it("flags a track without length", () => {
    const text = `system: Demo
version: 1
properties:
  - id: harm
    label: Harm
    type: track
`;
    expect(messages(text)[0]).toMatch(/length/);
  });

  it("flags select and checklist without options", () => {
    const text = `system: Demo
version: 1
properties:
  - id: kind
    label: Kind
    type: select
  - id: moves
    label: Moves
    type: checklist
`;
    const msgs = messages(text);
    expect(msgs).toHaveLength(2);
    expect(msgs[0]).toMatch(/options/);
    expect(msgs[1]).toMatch(/options/);
  });

  it("flags a list without fields", () => {
    const text = `system: Demo
version: 1
properties:
  - id: gear
    label: Gear
    type: list
`;
    expect(messages(text)[0]).toMatch(/fields/);
  });

  it("flags min greater than max", () => {
    const text = `system: Demo
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
    min: 10
    max: 2
`;
    expect(messages(text)[0]).toMatch(/min.*max/i);
  });

  it("flags derived on a non-number/toggle property", () => {
    const text = `system: Demo
version: 1
properties:
  - id: name_thing
    label: Name
    type: text
    derived: "1 + 1"
`;
    expect(messages(text)[0]).toMatch(/number and toggle/);
  });

  it("flags live+gm_only, live+derived, and live lists", () => {
    const text = `system: Demo
version: 1
properties:
  - id: a
    label: A
    type: number
    live: true
    gm_only: true
  - id: b
    label: B
    type: number
    live: true
    derived: "1 + 1"
  - id: c
    label: C
    type: list
    live: true
    fields:
      - id: x
        label: X
        type: text
`;
    const msgs = messages(text);
    expect(msgs).toHaveLength(3);
    expect(msgs[0]).toMatch(/gm_only|GM/);
    expect(msgs[1]).toMatch(/computed|formula/);
    expect(msgs[2]).toMatch(/list/);
  });

  it("flags owner_only+gm_only", () => {
    const text = `system: Demo
version: 1
properties:
  - id: secret
    label: Secret
    type: longtext
    owner_only: true
    gm_only: true
`;
    const msgs = messages(text);
    expect(msgs).toHaveLength(1);
    expect(msgs[0]).toMatch(/owner_only/);
  });

  it("flags duplicate option ids and a select list-field without options", () => {
    const text = `system: Demo
version: 1
properties:
  - id: kind
    label: Kind
    type: select
    options:
      - { id: one, label: One }
      - { id: one, label: Uno }
  - id: gear
    label: Gear
    type: list
    fields:
      - id: sort
        label: Sort
        type: select
`;
    const msgs = messages(text);
    expect(msgs).toHaveLength(2);
    expect(msgs[0]).toMatch(/"one"/);
    expect(msgs[1]).toMatch(/options/);
  });

  const listBase = `system: Demo
version: 1
properties:
  - id: gear
    label: Gear
    type: list
    fields:
      - id: name
        label: Name
        type: text
      - id: is_weapon
        label: Is Weapon?
        type: toggle
      - id: harm_rating
        label: Harm Rating
        type: number
`;

  it('accepts a list-field "when" expression over sibling fields', () => {
    const text = `${listBase}
      - id: notes
        label: Notes
        type: text
        when: is_weapon and harm_rating >= 3
`;
    expect(lintTemplateSource(text)).toEqual([]);
  });

  it('flags a "when" referencing an unknown sibling', () => {
    const text = `${listBase}
      - id: notes
        label: Notes
        type: text
        when: is_wepon
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toContain('is_wepon');
    expect(diags[0].message).toContain('Did you mean "is_weapon"?');
  });

  it('flags a "when" referencing a longtext sibling', () => {
    const text = `${listBase}
      - id: diary
        label: Diary
        type: longtext
      - id: notes
        label: Notes
        type: text
        when: diary
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toContain("longtext");
  });

  it('flags a "when" referencing the field it gates', () => {
    const text = `${listBase}
      - id: notes
        label: Notes
        type: text
        when: notes
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toContain("can't reference the field it gates");
  });

  it('flags an unknown function in a "when"', () => {
    const text = `${listBase}
      - id: notes
        label: Notes
        type: text
        when: flor(harm_rating) > 1
`;
    const diags = lintTemplateSource(text);
    expect(diags[0].message).toContain('Did you mean "floor"?');
  });

  it('flags bracket use on a scalar sibling reference in a "when"', () => {
    const text = `${listBase}
      - id: notes
        label: Notes
        type: text
        when: is_weapon["current"]
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toMatch(/without brackets/);
  });
});

describe("lintTemplateSource — layout cross-references", () => {
  it("flags a layout reference to an undeclared id, with a suggestion", () => {
    const text = `${base}layout:
  - section: Ratings
    properties: [vigr]
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toContain('"vigr"');
    expect(diags[0].message).toContain('"vigor"');
    expect(text.slice(diags[0].from, diags[0].to)).toBe("vigr");
  });

  it("flags a property placed twice in the layout", () => {
    const text = `${base}layout:
  - section: One
    properties: [vigor]
  - section: Two
    properties: [vigor]
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].message).toMatch(/already|once/);
  });
});

describe("lintTemplateSource — derived formula references", () => {
  const withDerived = (derived: string) => `system: Demo
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
  - id: harm
    label: Harm
    type: track
    length: 7
  - id: gear
    label: Gear
    type: list
    fields:
      - id: x
        label: X
        type: text
  - id: moves
    label: Moves
    type: checklist
    options:
      - { id: nine_lives, label: Nine Lives }
  - id: speed
    label: Speed
    type: number
    derived: ${derived}
`;

  it("accepts a valid formula", () => {
    expect(lintTemplateSource(withDerived('"floor(vigor / 2) + harm[\\"current\\"]"'))).toEqual([]);
  });

  it("flags an unknown property reference with a suggestion", () => {
    const msgs = messages(withDerived('"vigr + 1"'));
    expect(msgs).toHaveLength(1);
    expect(msgs[0]).toContain('"vigr"');
    expect(msgs[0]).toContain('"vigor"');
  });

  it("flags a reference to a non-referencable property type", () => {
    const msgs = messages(withDerived('"gear + 1"'));
    expect(msgs[0]).toMatch(/list/);
  });

  it("flags a bare track reference and names its parts", () => {
    const msgs = messages(withDerived('"harm + 1"'));
    expect(msgs[0]).toContain('harm["current"]');
  });

  it("accepts a checklist option reference", () => {
    expect(lintTemplateSource(withDerived('"moves[\\"nine_lives\\"] + 1"'))).toEqual([]);
  });

  it("flags a bare checklist reference and names its options", () => {
    const msgs = messages(withDerived('"moves + 1"'));
    expect(msgs[0]).toContain('moves["nine_lives"]');
  });

  it("flags brackets on a scalar property", () => {
    const msgs = messages(withDerived('"vigor[\\"current\\"] + 1"'));
    expect(msgs[0]).toMatch(/without brackets/);
  });

  it("flags an unknown function with a suggestion", () => {
    const msgs = messages(withDerived('"flor(vigor / 2)"'));
    expect(msgs).toHaveLength(1);
    expect(msgs[0]).toContain("floor");
  });

  it("anchors formula diagnostics to the derived value, including block scalars", () => {
    const text = `system: Demo
version: 1
properties:
  - id: vigor
    label: Vigor
    type: number
  - id: speed
    label: Speed
    type: number
    derived: |
      floor(vigr / 2)
        + 2
`;
    const diags = lintTemplateSource(text);
    expect(diags).toHaveLength(1);
    expect(diags[0].from).toBeGreaterThanOrEqual(text.indexOf("derived:"));
    expect(diags[0].to).toBeGreaterThan(diags[0].from);
  });
});
