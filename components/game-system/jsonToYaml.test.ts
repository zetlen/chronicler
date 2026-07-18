import { describe, expect, it } from "vitest";
import { parse } from "yaml";
import { jsonToYaml } from "./jsonToYaml";

describe("jsonToYaml", () => {
  it("converts a JSON template to YAML that parses back identically", () => {
    const json = JSON.stringify({
      system: "Monster of the Week",
      version: 1,
      properties: [
        {
          id: "charm",
          label: "Charm",
          type: "number",
          min: -1,
          max: 3,
          detail: "Manipulate Someone",
        },
        { id: "harm", label: "Harm", type: "track", length: 7, live: true },
      ],
      layout: [
        { section: "Ratings", properties: ["charm"], masthead: false },
      ],
    });
    const yaml = jsonToYaml(json)!;
    expect(yaml).not.toBeNull();
    expect(yaml).not.toContain("{"); // block style, not flow JSON
    expect(parse(yaml)).toEqual(JSON.parse(json));
  });

  it("renders multi-line strings as block scalars", () => {
    const json = JSON.stringify({
      system: "X",
      version: 1,
      properties: [
        {
          id: "speed",
          label: "Speed",
          type: "number",
          derived: "floor(vigor / 2)\n  + 2\n  + armor",
        },
      ],
    });
    const yaml = jsonToYaml(json)!;
    expect(yaml).toContain("derived: |");
    expect(parse(yaml)).toEqual(JSON.parse(json));
  });

  it("does not fold long single-line strings", () => {
    const long = "a detail string long enough that default YAML folding would wrap it onto several lines which reads badly";
    const json = JSON.stringify({ system: long, version: 1, properties: [] });
    const yaml = jsonToYaml(json)!;
    expect(yaml).toContain(long);
    expect(parse(yaml)).toEqual(JSON.parse(json));
  });

  it("returns null for YAML input (nothing to convert)", () => {
    expect(jsonToYaml("system: Demo\nversion: 1\n")).toBeNull();
  });

  it("returns null for broken JSON and bare scalars", () => {
    expect(jsonToYaml('{"system": ')).toBeNull();
    expect(jsonToYaml("5")).toBeNull();
    expect(jsonToYaml('"just a string"')).toBeNull();
    expect(jsonToYaml("")).toBeNull();
  });
});
