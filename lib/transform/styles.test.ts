import { describe, it, expect } from "vitest";
import { dialogueCss } from "@/lib/transform/styles";

describe("dialogueCss variant rules", () => {
  it("styles the important variant", () => {
    expect(dialogueCss).toContain(".slk-msg--important");
  });
  it("fades the ooc variant (scheme-agnostic via opacity)", () => {
    expect(dialogueCss).toContain(".slk-msg--ooc");
    expect(dialogueCss).toMatch(/\.slk-msg--ooc\s*{\s*opacity/);
  });
});
