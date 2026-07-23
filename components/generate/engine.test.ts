import { describe, it, expect } from "vitest";
import type { SlackMessage } from "@/lib/transform/slackTypes";
import type { WpRule } from "@/components/admin/sessionApi";
import { SessionProcessor } from "@/lib/session/SessionProcessor";
import { toMessageAttributes } from "@/lib/session/toMessageAttributes";
import { resolveRegexRules, sessionRenderOptions } from "@/components/admin/sessionRender";
import { messagesFor, prepareGeneration } from "@/components/generate/engine";

function msg(ts: string, user: string, text: string): SlackMessage {
  return { type: "message", ts, user, text };
}

const RAW = {
  threads: [
    { parent: msg("1", "U2", "hello there"), replies: [] },
    { parent: msg("2", "U1", "this is a secret aside"), replies: [] },
  ],
  names: { users: { U1: "Ripley", U2: "Daisy" }, channels: {} },
  customEmoji: {},
};

const HIDE_SECRET: WpRule = {
  id: 7,
  pattern: "secret",
  flags: "i",
  mode: "hide",
  className: "",
  tagNames: "",
  treatments: "",
  description: "",
};

const SESSION = {
  rule_ids: [7],
  editorState: { scheme: "light" as const, controls: {} },
  raw: RAW,
};

describe("generation engine messagesFor", () => {
  it("returns null when the session has no stored raw", () => {
    expect(messagesFor({ ...SESSION, raw: null }, [HIDE_SECRET])).toBeNull();
    expect(messagesFor({ ...SESSION, raw: undefined }, [HIDE_SECRET])).toBeNull();
    expect(messagesFor({ rule_ids: [], raw: { names: RAW.names } }, [])).toBeNull();
  });

  it("computes rule-applied attributes identical to a direct process()", () => {
    const out = messagesFor(SESSION, [HIDE_SECRET]);
    // The hide rule drops the "secret" message.
    expect(out).toHaveLength(1);
    expect(out?.[0].bodyHtml).toContain("hello there");

    const expected = toMessageAttributes(
      SessionProcessor.init(
        RAW,
        resolveRegexRules([HIDE_SECRET], SESSION.rule_ids),
        sessionRenderOptions(SESSION.editorState, {}),
      ).process(),
    );
    expect(out).toEqual(expected);
  });

  it("applies no rules when none are attached", () => {
    const out = messagesFor({ ...SESSION, rule_ids: [] }, [HIDE_SECRET]);
    expect(out).toHaveLength(2);
  });
});

describe("prepareGeneration (batched render)", () => {
  it("returns null when the session has no stored raw", () => {
    expect(prepareGeneration({ ...SESSION, raw: null }, [HIDE_SECRET])).toBeNull();
  });

  it("reports the visible total and yields batches summing to it", () => {
    const prep = prepareGeneration({ ...SESSION, rule_ids: [] }, []);
    expect(prep?.total).toBe(2);
  });

  it("streams the SAME attributes as messagesFor, in order, with monotonic progress", async () => {
    const prep = prepareGeneration({ ...SESSION, rule_ids: [] }, []);
    expect(prep).not.toBeNull();

    const collected: Record<string, unknown>[] = [];
    let batches = 0;
    let lastDone = 0;
    // budget 0 forces one message per slice, so ordering/progress is exercised.
    for await (const { batch, done, total } of prep!.batches(0)) {
      batches += 1;
      expect(batch).toHaveLength(1);
      expect(done).toBe(lastDone + 1); // one message per slice, forward only
      expect(done).toBeLessThanOrEqual(total);
      lastDone = done;
      collected.push(...batch);
    }
    expect(batches).toBe(2); // two visible messages → two slices at budget 0
    expect(lastDone).toBe(prep!.total);
    // Byte-identical to the synchronous path.
    expect(collected).toEqual(messagesFor({ ...SESSION, rule_ids: [] }, []));
  });
});
