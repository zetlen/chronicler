#!/usr/bin/env node
/**
 * Signed synthetic Slack traffic for the inbound bot surface: exercises
 * the exact wire path Slack uses (raw form body, X-Slack-Signature over
 * v0:{ts}:{body}) with no workspace. The dev/QA counterpart of the
 * Settings page's self-check (which can't loopback inside wp-env).
 *
 *   CHRONICLER_URL=http://localhost:8890 \
 *   CHRONICLER_SLACK_SIGNING_SECRET=devsecret \
 *   node scripts/slack-simulate.mjs command "help"
 *
 *   … slack-simulate.mjs command "help" --bad-signature   # expects 401
 *   … slack-simulate.mjs command "help" --stale           # expects 401
 *   … slack-simulate.mjs interaction payload.json         # shortcut payload
 *
 * Multi-viewer testing — the identity Slack asserts is what the plugin
 * resolves to a WordPress user, so audience-dependent behavior (gm_only
 * stats, owner_only fields, refused rolls) can only be exercised by sending
 * as more than one person:
 *
 *   … slack-simulate.mjs command "my all" --user UGMSIM
 *   … slack-simulate.mjs command "my all" --user UNOBODY   # unlinked
 *
 * --user/--team/--channel each accept "--flag value" or "--flag=value".
 *
 * Uses the ?rest_route= URL form so it works under any permalink
 * structure (api-smoke.mjs's convention). Exit code 0 when the outcome
 * matches expectation (200 normally; 401 for --bad-signature/--stale).
 */
import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";

const url = process.env.CHRONICLER_URL;
const secret = process.env.CHRONICLER_SLACK_SIGNING_SECRET;
// Valued flags take "--flag value" or "--flag=value"; everything else is a
// bare switch. Splitting them here keeps the positional list clean no matter
// where the flags appear.
const VALUED = ["user", "team", "channel"];
const SWITCHES = ["bad-signature", "stale"];
const flags = [];
const values = {};
const positional = [];
for (let i = 2; i < process.argv.length; i++) {
  const token = process.argv[i];
  if (!token.startsWith("--")) {
    positional.push(token);
    continue;
  }
  const [name, inline] = token.slice(2).split(/=(.*)/s);
  if (VALUED.includes(name)) {
    values[name] = inline ?? process.argv[++i];
  } else if (SWITCHES.includes(name)) {
    flags.push(token);
  } else {
    // Fatal rather than ignored. An unrecognized flag silently falling back
    // to the default sender is the worst possible outcome for this script:
    // the run looks like it proved something about another identity when it
    // only re-proved the default. (Observed: zsh doesn't word-split an
    // unquoted "$var", so "--user UGMSIM" can arrive as a single token.)
    console.error(`Unrecognized flag "${token}".`);
    process.exit(2);
  }
}
const [mode, arg] = positional;
const identity = {
  user_id: values.user ?? "USIMULATE",
  team_id: values.team ?? "TSIMULATE",
  channel_id: values.channel ?? "CSIMULATE",
};

if (
  !url ||
  !secret ||
  !["command", "interaction"].includes(mode) ||
  (mode === "interaction" && !arg) ||
  VALUED.some((name) => name in values && !values[name])
) {
  console.error(
    "Usage: CHRONICLER_URL=… CHRONICLER_SLACK_SIGNING_SECRET=… \\\n" +
      '         node scripts/slack-simulate.mjs command "<text>" [--user <id>] [--team <id>] [--channel <id>] [--bad-signature] [--stale]\n' +
      "       … node scripts/slack-simulate.mjs interaction <payload.json> [--bad-signature] [--stale]",
  );
  process.exit(2);
}

const route = mode === "interaction" ? "interactions" : "commands";
const body =
  mode === "interaction"
    ? new URLSearchParams({ payload: readFileSync(arg, "utf8") }).toString()
    : new URLSearchParams({
        command: "/game",
        text: arg ?? "",
        ...identity,
      }).toString();

const timestamp = String(
  Math.floor(Date.now() / 1000) - (flags.includes("--stale") ? 900 : 0),
);
const signingSecret = flags.includes("--bad-signature") ? `${secret}x` : secret;
const signature =
  "v0=" +
  createHmac("sha256", signingSecret).update(`v0:${timestamp}:${body}`).digest("hex");

const started = Date.now();
const res = await fetch(`${url}/?rest_route=/chronicler/v1/slack/inbound/${route}`, {
  method: "POST",
  headers: {
    "Content-Type": "application/x-www-form-urlencoded",
    "X-Slack-Request-Timestamp": timestamp,
    "X-Slack-Signature": signature,
  },
  body,
});
const ms = Date.now() - started;
// Name the sender: a multi-viewer run is a wall of near-identical replies,
// and which identity produced which one is the whole point of the exercise.
console.log(`HTTP ${res.status} in ${ms}ms  (as ${identity.user_id})`);
console.log(await res.text());

const expectRefusal = flags.includes("--bad-signature") || flags.includes("--stale");
process.exit((expectRefusal ? res.status === 401 : res.status === 200) ? 0 : 1);
