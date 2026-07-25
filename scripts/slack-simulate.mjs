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
 * Uses the ?rest_route= URL form so it works under any permalink
 * structure (api-smoke.mjs's convention). Exit code 0 when the outcome
 * matches expectation (200 normally; 401 for --bad-signature/--stale).
 */
import { createHmac } from "node:crypto";
import { readFileSync } from "node:fs";

const url = process.env.CHRONICLER_URL;
const secret = process.env.CHRONICLER_SLACK_SIGNING_SECRET;
const args = process.argv.slice(2);
const flags = args.filter((a) => a.startsWith("--"));
const [mode, arg] = args.filter((a) => !a.startsWith("--"));

if (!url || !secret || !["command", "interaction"].includes(mode) || (mode === "interaction" && !arg)) {
  console.error(
    "Usage: CHRONICLER_URL=… CHRONICLER_SLACK_SIGNING_SECRET=… \\\n" +
      '         node scripts/slack-simulate.mjs command "<text>" [--bad-signature] [--stale]\n' +
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
        user_id: "USIMULATE",
        team_id: "TSIMULATE",
        channel_id: "CSIMULATE",
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
console.log(`HTTP ${res.status} in ${ms}ms`);
console.log(await res.text());

const expectRefusal = flags.includes("--bad-signature") || flags.includes("--stale");
process.exit((expectRefusal ? res.status === 401 : res.status === 200) ? 0 : 1);
