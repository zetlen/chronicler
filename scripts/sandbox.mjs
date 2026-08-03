/**
 * Slack sandbox harness: wires local wp-env to a disposable Slack
 * workspace through an ephemeral cloudflared tunnel. See
 * docs/superpowers/specs/2026-08-03-slack-sandbox-design.md and
 * docs/runbooks/slack-sandbox.md.
 */

import { execFileSync, spawn } from "node:child_process";
import { createHmac } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { parse as parseYaml } from "yaml";

const ENV_LINE = /^([A-Za-z_][A-Za-z0-9_]*)=(.*?)\r?$/;

export function parseEnv(text) {
  const values = {};
  for (const line of text.split(/\r?\n/)) {
    const m = ENV_LINE.exec(line);
    if (!m) continue;
    let value = m[2];
    const quoted = /^"(.*)"$/.exec(value) ?? /^'(.*)'$/.exec(value);
    if (quoted) value = quoted[1];
    values[m[1]] = value;
  }
  return values;
}

export function upsertEnv(text, updates) {
  const pending = new Map(Object.entries(updates));
  const lines = text.split("\n").map((line) => {
    const m = ENV_LINE.exec(line);
    if (!m || !pending.has(m[1])) return line;
    const value = pending.get(m[1]);
    pending.delete(m[1]);
    return `${m[1]}=${value}`;
  });
  while (lines.length && lines[lines.length - 1] === "") lines.pop();
  for (const [key, value] of pending) lines.push(`${key}=${value}`);
  return lines.join("\n") + "\n";
}

export function renderManifestJson(yamlText, base) {
  if (!yamlText.includes("{{REST_BASE}}")) {
    throw new Error("manifest has no {{REST_BASE}} placeholder — wrong file?");
  }
  return JSON.stringify(parseYaml(yamlText.replaceAll("{{REST_BASE}}", base)));
}

export function extractTunnelUrl(text) {
  return /https:\/\/[a-z0-9-]+\.trycloudflare\.com/.exec(text)?.[0] ?? null;
}

export function restBase(origin) {
  return `${origin}/wp-json/chronicler/v1`;
}

export const SLACK_API = "https://slack.com/api";
export const CONFIG_TOKEN_HELP =
  "Generate a new app-config token pair at https://api.slack.com/apps " +
  '("Your App Configuration Tokens", scoped to the sandbox workspace) and ' +
  "update SANDBOX_SLACK_CONFIG_TOKEN and SANDBOX_SLACK_CONFIG_REFRESH_TOKEN " +
  "in .env.local.";

async function slackJson(url, init) {
  const res = await fetch(url, init);
  return res.json();
}

export async function configApiCall(method, payload, tokens, persistTokens) {
  const call = (token) =>
    slackJson(`${SLACK_API}/${method}`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json; charset=utf-8",
      },
      body: JSON.stringify(payload),
    });
  let data = await call(tokens.token);
  if (data.ok === false && ["token_expired", "invalid_auth"].includes(data.error)) {
    const rotated = await slackJson(`${SLACK_API}/tooling.tokens.rotate`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ refresh_token: tokens.refresh_token }),
    });
    if (rotated.ok === false) {
      throw new Error(`config token rotation failed (${rotated.error}). ${CONFIG_TOKEN_HELP}`);
    }
    // Persist before retrying: rotation consumes the old refresh token, so
    // losing the new pair here would strand the user in the browser flow.
    await persistTokens({ token: rotated.token, refresh_token: rotated.refresh_token });
    data = await call(rotated.token);
  }
  if (data.ok === false) throw new Error(`${method} failed: ${data.error}`);
  return data;
}

const WP_ENV_PORT = "8890";
export const WP_BASE = `http://localhost:${WP_ENV_PORT}`;

function wpCli(...args) {
  execFileSync("npx", ["@wordpress/env", "run", "cli", "wp", ...args], {
    stdio: ["ignore", "inherit", "inherit"],
    env: { ...process.env, WP_ENV_PORT },
  });
}

function wpCliQuiet(name, ...args) {
  try {
    execFileSync("npx", ["@wordpress/env", "run", "cli", "wp", ...args], {
      stdio: "pipe",
      env: { ...process.env, WP_ENV_PORT },
    });
  } catch (err) {
    throw new Error(`wp option update ${name} failed (exit ${err.status})`);
  }
}

export async function wpEnvIsUp() {
  try {
    return (await fetch(`${WP_BASE}/?rest_route=/`)).ok;
  } catch {
    return false;
  }
}

export async function ensureWpEnv() {
  if (!(await wpEnvIsUp())) {
    console.log("wp-env is down — starting it (a cold start takes a few minutes)…");
    execFileSync("npx", ["@wordpress/env", "start"], {
      stdio: "inherit",
      env: { ...process.env, WP_ENV_PORT },
    });
  }
  // Slack request URLs must be clean /wp-json paths, not ?rest_route= query
  // strings, so the REST base has to survive permalink settings.
  wpCli("rewrite", "structure", "/%postname%/", "--hard");
}

export function injectSecrets({ botToken, signingSecret }) {
  wpCliQuiet("chronicler_slack_bot_token", "option", "update", "chronicler_slack_bot_token", botToken);
  wpCliQuiet("chronicler_slack_signing_secret", "option", "update", "chronicler_slack_signing_secret", signingSecret);
}

const ENV_PATH = new URL("../.env.local", import.meta.url);
const MANIFEST_PATH = new URL("../wordpress-plugin/slack-app-manifest.yml", import.meta.url);

let tunnelChild = null;
process.on("exit", () => tunnelChild?.kill());

function startTunnel() {
  return new Promise((resolve, reject) => {
    const child = spawn("cloudflared", ["tunnel", "--url", WP_BASE], {
      stdio: ["ignore", "pipe", "pipe"],
    });
    tunnelChild = child;
    child.on("error", (err) =>
      reject(
        err.code === "ENOENT"
          ? new Error(
              "cloudflared is not installed. Grab it from " +
                "https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/ " +
                "and rerun.",
            )
          : err,
      ),
    );
    let seen = "";
    let settled = false;
    const onChunk = (chunk) => {
      seen += chunk;
      const url = extractTunnelUrl(seen);
      if (url) {
        settled = true;
        child.stdout.off("data", onChunk);
        child.stderr.off("data", onChunk);
        resolve(url);
      }
    };
    child.stdout.on("data", onChunk);
    child.stderr.on("data", onChunk);
    child.on("exit", (code) => {
      if (settled) {
        console.error(`cloudflared tunnel died (exit ${code}) — rerun npm run sandbox`);
        process.exit(1);
        return;
      }
      reject(new Error(`cloudflared exited (${code}) before printing a tunnel URL:\n${seen}`));
    });
  });
}

async function selfCheck({ botToken, signingSecret, tunnelOrigin }) {
  const auth = await slackJson(`${SLACK_API}/auth.test`, {
    method: "POST",
    headers: { Authorization: `Bearer ${botToken}` },
  });
  if (auth.ok === false) {
    throw new Error(
      `auth.test failed (${auth.error}) — is SANDBOX_SLACK_BOT_TOKEN the xoxb ` +
        "token from the sandbox app's OAuth & Permissions page?",
    );
  }
  console.log(`✓ outbound: bot ${auth.user} is installed in ${auth.team}`);

  // Signed by hand rather than via slack-simulate.mjs: the check must hit the
  // exact /wp-json URL the manifest advertises, not the ?rest_route= fallback.
  const body = new URLSearchParams({
    command: "/game",
    text: "help",
    user_id: "USANDBOXCHECK",
    team_id: auth.team_id,
    channel_id: "CSANDBOXCHECK",
  }).toString();
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature =
    "v0=" + createHmac("sha256", signingSecret).update(`v0:${timestamp}:${body}`).digest("hex");
  const res = await fetch(`${restBase(tunnelOrigin)}/slack/inbound/commands`, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      "X-Slack-Request-Timestamp": timestamp,
      "X-Slack-Signature": signature,
    },
    body,
  });
  const text = await res.text();
  if (!res.ok) {
    throw new Error(
      `inbound self-check failed: HTTP ${res.status} through the tunnel — Slack ` +
        `would hit the same wall. Response body:\n${text}`,
    );
  }
  console.log("✓ inbound: signed /game help round-tripped through the tunnel");
}

const installUrl = (appId) => `https://api.slack.com/apps/${appId}/install-on-team`;

async function main() {
  let envText;
  try {
    envText = readFileSync(ENV_PATH, "utf8");
  } catch (err) {
    if (err.code === "ENOENT") {
      console.error(`Missing sandbox config tokens in .env.local. ${CONFIG_TOKEN_HELP}`);
      process.exit(1);
    }
    throw err;
  }
  const env = parseEnv(envText);
  if (!env.SANDBOX_SLACK_CONFIG_TOKEN || !env.SANDBOX_SLACK_CONFIG_REFRESH_TOKEN) {
    console.error(`Missing sandbox config tokens in .env.local. ${CONFIG_TOKEN_HELP}`);
    process.exit(1);
  }
  const persistEnv = (updates) => {
    writeFileSync(ENV_PATH, upsertEnv(readFileSync(ENV_PATH, "utf8"), updates));
    Object.assign(env, updates);
  };
  const tokens = () => ({
    token: env.SANDBOX_SLACK_CONFIG_TOKEN,
    refresh_token: env.SANDBOX_SLACK_CONFIG_REFRESH_TOKEN,
  });
  const persistTokens = async ({ token, refresh_token }) =>
    persistEnv({
      SANDBOX_SLACK_CONFIG_TOKEN: token,
      SANDBOX_SLACK_CONFIG_REFRESH_TOKEN: refresh_token,
    });

  await ensureWpEnv();
  const tunnelOrigin = await startTunnel();
  console.log(`✓ tunnel: ${tunnelOrigin}`);
  const manifest = renderManifestJson(readFileSync(MANIFEST_PATH, "utf8"), restBase(tunnelOrigin));

  if (!env.SANDBOX_SLACK_APP_ID) {
    const created = await configApiCall("apps.manifest.create", { manifest }, tokens(), persistTokens);
    persistEnv({
      SANDBOX_SLACK_APP_ID: created.app_id,
      SANDBOX_SLACK_SIGNING_SECRET: created.credentials.signing_secret,
    });
    console.log(
      [
        `✓ created Slack app ${created.app_id}; its request URLs point at this tunnel.`,
        "",
        "One manual step left (a browser OAuth grant Slack won't let us script):",
        `  1. Install it: ${installUrl(created.app_id)}`,
        "  2. Copy the Bot User OAuth Token (xoxb-…) from OAuth & Permissions",
        "  3. Add it to .env.local as SANDBOX_SLACK_BOT_TOKEN=…",
        "  4. Rerun: npm run sandbox",
      ].join("\n"),
    );
    process.exit(0);
  }
  if (!env.SANDBOX_SLACK_BOT_TOKEN) {
    console.error(
      `The sandbox app exists but SANDBOX_SLACK_BOT_TOKEN is missing from .env.local.\n` +
        `Install the app (${installUrl(env.SANDBOX_SLACK_APP_ID)}), then paste the xoxb ` +
        "token in and rerun.",
    );
    process.exit(1);
  }
  if (!env.SANDBOX_SLACK_SIGNING_SECRET) {
    console.error(
      `SANDBOX_SLACK_SIGNING_SECRET is missing from .env.local.\n` +
        `Copy it from the app's App Credentials page (https://api.slack.com/apps/${env.SANDBOX_SLACK_APP_ID}), ` +
        "then rerun.",
    );
    process.exit(1);
  }

  injectSecrets({
    botToken: env.SANDBOX_SLACK_BOT_TOKEN,
    signingSecret: env.SANDBOX_SLACK_SIGNING_SECRET,
  });
  console.log("✓ secrets: bot token + signing secret set in wp-env");
  const updated = await configApiCall(
    "apps.manifest.update",
    { app_id: env.SANDBOX_SLACK_APP_ID, manifest },
    tokens(),
    persistTokens,
  );
  console.log("✓ manifest: request URLs now point at the tunnel");
  if (updated.permissions_updated) {
    console.log(`! scopes changed — reinstall before they take effect: ${installUrl(env.SANDBOX_SLACK_APP_ID)}`);
  }
  await selfCheck({
    botToken: env.SANDBOX_SLACK_BOT_TOKEN,
    signingSecret: env.SANDBOX_SLACK_SIGNING_SECRET,
    tunnelOrigin,
  });
  console.log('\nSandbox ready — type "/game help" in the sandbox workspace. Ctrl-C stops the tunnel.');
  process.on("SIGINT", () => process.exit(0));
  await new Promise(() => {}); // hold the tunnel open until Ctrl-C
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch((err) => {
    console.error(err.message);
    process.exit(1);
  });
}
