#!/usr/bin/env node
/**
 * Basic-auth integration smoke for the chronicler/v1 API (#112): exercises
 * every route as an external client would — WordPress Application Password
 * over HTTP Basic, no cookies, no nonces. Leaves the site as it found it
 * (everything it creates, it deletes).
 *
 *   CHRONICLER_URL=http://localhost:8890 \
 *   CHRONICLER_USER=chronicler-bot \
 *   CHRONICLER_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
 *   node scripts/api-smoke.mjs
 *
 * Bot-user setup is documented in docs/rest-api.md. Uses the ?rest_route=
 * URL form so it works under any permalink structure.
 */

const url = process.env.CHRONICLER_URL;
const user = process.env.CHRONICLER_USER;
const password = process.env.CHRONICLER_APP_PASSWORD;
if (!url || !user || !password) {
  console.error("Set CHRONICLER_URL, CHRONICLER_USER, CHRONICLER_APP_PASSWORD.");
  process.exit(2);
}

const auth = "Basic " + Buffer.from(`${user}:${password}`).toString("base64");
let failures = 0;

function report(ok, desc, detail = "") {
  console.log(`${ok ? "✓" : "✗"} ${desc}${ok || !detail ? "" : ` — ${detail}`}`);
  if (!ok) failures++;
}

async function call(method, path, body, { authenticated = true } = {}) {
  const res = await fetch(`${url}/?rest_route=/chronicler/v1${path}`, {
    method,
    redirect: "manual",
    headers: {
      ...(authenticated ? { Authorization: auth } : {}),
      ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  let json = null;
  try {
    json = await res.json();
  } catch {
    // 302s and empty bodies are fine.
  }
  return { status: res.status, json };
}

// --- auth gates ---------------------------------------------------------------

{
  const { status } = await call("GET", "/sessions", undefined, { authenticated: false });
  report(status === 401, `unauthenticated request is rejected (${status})`);
}

// --- sessions lifecycle ---------------------------------------------------------

{
  const { status, json } = await call("GET", "/sessions");
  report(status === 200 && Array.isArray(json), `GET /sessions lists (${status})`);
}

let sessionId = null;
{
  const draft = {
    integration: "slack",
    channel: { id: "CSMOKE", name: "api-smoke" },
    start: "2026-01-01T00:00:00.000Z",
    // The half-open pattern documented for headless clients: end = start,
    // PUT the real end later.
    end: "2026-01-01T00:00:00.000Z",
  };
  const { status, json } = await call("POST", "/sessions", draft);
  sessionId = json?.id ?? null;
  report(
    status === 201 && Number.isInteger(sessionId),
    `POST /sessions creates a draft (${status}, id ${sessionId})`,
  );
}
{
  const { status, json } = await call("GET", `/sessions/${sessionId}`);
  report(
    status === 200 && Array.isArray(json?.messages) && typeof json?.editorState === "object",
    `GET /sessions/{id} returns the full shape (${status})`,
  );
}
{
  const { status, json } = await call("PUT", `/sessions/${sessionId}`, {
    end: "2026-01-01T04:00:00.000Z",
    messages: [{ html: '<div class="slk-msg">smoke</div>' }],
  });
  report(
    status === 200 && json?.messageCount === 1,
    `PUT /sessions/{id} extends the session (${status}, messageCount ${json?.messageCount})`,
  );
}
{
  const { status, json } = await call("PUT", `/sessions/${sessionId}`, {
    messages: [{ bogus: "field" }],
  });
  report(status === 400, `PUT /sessions/{id} rejects off-schema messages (${status})`, json?.code);
}

// --- rules lifecycle ------------------------------------------------------------

let ruleId = null;
{
  const { status, json } = await call("POST", "/rules", { pattern: "^#api-smoke$", mode: "hide" });
  ruleId = json?.id ?? null;
  report(status === 201 && Number.isInteger(ruleId), `POST /rules creates (${status}, id ${ruleId})`);
}
{
  const { status, json } = await call("PUT", `/rules/${ruleId}`, { description: "api smoke" });
  report(
    status === 200 && json?.description === "api smoke",
    `PUT /rules/{id} patches (${status})`,
  );
}
{
  const { status, json } = await call("POST", "/rules", { pattern: "x", mode: "bogus" });
  report(status === 400, `POST /rules rejects a bad mode (${status})`, json?.code);
}
{
  const { status, json } = await call("GET", "/rules");
  report(
    status === 200 && Array.isArray(json) && json.some((r) => r.id === ruleId),
    `GET /rules lists the new rule (${status})`,
  );
}

// --- settings -------------------------------------------------------------------

{
  const { status, json } = await call("GET", "/settings");
  report(
    status === 200 && typeof json?.settings === "object" && typeof json?.channelDefaults === "object",
    `GET /settings returns the document (${status})`,
  );
}
{
  const { status } = await call("PUT", "/settings", {});
  report(status === 200, `PUT /settings empty merge is a no-op (${status})`);
}

// --- slack proxy ----------------------------------------------------------------

{
  const { status, json } = await call("POST", "/slack/auth.test", {});
  const connected = status === 200 && json?.ok === true;
  const unconfigured = status === 409 && json?.code === "chronicler_no_token";
  report(
    connected || unconfigured,
    connected
      ? "POST /slack/auth.test reaches Slack through the proxy (200)"
      : `POST /slack/auth.test answers the documented no-token 409 (${status})`,
    json?.code ?? json?.error,
  );
}
{
  const { status, json } = await call("POST", "/slack/chat.postMessage", {});
  report(status === 400, `POST /slack rejects a non-allowlisted method (${status})`, json?.code);
}

// --- image mirror ---------------------------------------------------------------

{
  const { status, json } = await call("GET", "/image&url=https%3A%2F%2Fexample.com%2Fx.png&format=json");
  report(status === 400, `GET /image rejects a non-Slack host (${status})`, json?.code);
}

// --- import ---------------------------------------------------------------------

{
  const { status, json } = await call("POST", "/import", {});
  report(
    status === 200 && json?.rules?.created === 0,
    `POST /import empty payload is idempotent (${status})`,
  );
}

// --- cleanup --------------------------------------------------------------------

{
  const { status, json } = await call("DELETE", `/rules/${ruleId}`);
  report(status === 200 && json?.deleted === true, `DELETE /rules/{id} cleans up (${status})`);
}
{
  const { status, json } = await call("DELETE", `/sessions/${sessionId}`);
  report(status === 200 && json?.deleted === true, `DELETE /sessions/{id} cleans up (${status})`);
}

console.log(failures === 0 ? "\nAll checks passed." : `\n${failures} check(s) FAILED.`);
process.exit(failures === 0 ? 0 : 1);
