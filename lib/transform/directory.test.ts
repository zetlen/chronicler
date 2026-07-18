import { describe, it, expect } from "vitest";
import { createDirectory } from "@/lib/transform/directory";

describe("directory.realUserName", () => {
  it("ignores rename overrides (returns the real Slack name)", () => {
    const dir = createDirectory(
      { users: { U1: "Alice" }, channels: {} },
      { U1: { name: "Graz the Bold" } },
    );
    expect(dir.userName("U1")).toBe("Graz the Bold"); // override wins here
    expect(dir.realUserName("U1")).toBe("Alice"); // real name here
  });
  it("falls back to the id, then Unknown", () => {
    const dir = createDirectory({ users: {}, channels: {} });
    expect(dir.realUserName("U9")).toBe("U9");
    expect(dir.realUserName()).toBe("Unknown");
  });
});

describe("directory.userName character layer", () => {
  const maps = {
    users: { U1: "Alice", U2: "Bob" },
    channels: {},
    characters: { U1: "WOLFGANG" },
  };
  it("uses the character name when there is no override", () => {
    const dir = createDirectory(maps);
    expect(dir.userName("U1")).toBe("WOLFGANG");
    expect(dir.userName("U2")).toBe("Bob"); // no character → Slack name
  });
  it("lets a manual override beat the character name", () => {
    const dir = createDirectory(maps, { U1: { name: "Graz" } });
    expect(dir.userName("U1")).toBe("Graz");
  });
  it("keeps realUserName and color on the real Slack name", () => {
    const dir = createDirectory(maps);
    expect(dir.realUserName("U1")).toBe("Alice");
    // color derives from the Slack name, stable across the character rename
    expect(dir.userColor("U1")).toBe(
      createDirectory({ users: { U1: "Alice" }, channels: {} }).userColor("U1"),
    );
  });
});
