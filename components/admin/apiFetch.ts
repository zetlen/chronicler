/**
 * The one HTTP seam for the wp-admin session editor (#96).
 *
 * Every REST call the editor makes goes through `apiFetch` so the boot
 * contract lives in exactly one place: the enqueue script on the plugin side
 * sets `window.chroniclerBoot = { apiBase, nonce }` before this bundle runs,
 * `apiBase` pointing at the `chronicler/v1` namespace (#97) and `nonce` being
 * `wp_create_nonce('wp_rest')`, which WordPress verifies from the X-WP-Nonce
 * header on every request.
 */

/** The boot payload the plugin's enqueue must define before the bundle runs. */
export interface ChroniclerBoot {
  /**
   * Absolute or root-relative REST base for the plugin's namespace, e.g.
   * `rest_url('chronicler/v1/')`. A trailing slash is optional; plain-permalink
   * (`?rest_route=/chronicler/v1`) bases work too since paths are appended.
   */
  apiBase: string;
  /** `wp_create_nonce('wp_rest')`, sent as X-WP-Nonce on every request. */
  nonce: string;
  /**
   * post-new.php URL template for "Draft this session" (#102), with `%d`
   * standing in for the session id. Added by the plugin side of #102; the
   * editor hides the draft button while the field is absent, so either
   * half ships independently.
   */
  draftSessionUrlTemplate?: string;
}

declare global {
  interface Window {
    chroniclerBoot?: ChroniclerBoot;
  }
}

/**
 * A REST failure with the HTTP status attached, and — for 429 responses whose
 * body carries `{retry_after}` (the #99 Slack-proxy contract) — the number of
 * seconds the browser should wait before retrying.
 */
export class ApiError extends Error {
  readonly status: number;
  /** Seconds to wait before retrying (429 responses only). */
  readonly retryAfter?: number;
  /** The parsed JSON error body, when the response carried one. */
  readonly body?: unknown;

  constructor(
    message: string,
    status: number,
    opts: { retryAfter?: number; body?: unknown } = {},
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.retryAfter = opts.retryAfter;
    this.body = opts.body;
  }
}

/** The boot object if the page defined a plausible one, else null. */
export function getBoot(): ChroniclerBoot | null {
  if (typeof window === "undefined") return null;
  const boot = window.chroniclerBoot;
  if (!boot || typeof boot.apiBase !== "string" || typeof boot.nonce !== "string") {
    return null;
  }
  return boot;
}

/** Join the REST base and an endpoint path without doubling slashes. */
function joinApi(apiBase: string, path: string): string {
  return `${apiBase.replace(/\/+$/, "")}/${path.replace(/^\/+/, "")}`;
}

/** WP REST errors look like `{code, message, data:{status}}`; be lenient. */
function messageFrom(body: unknown, status: number): string {
  if (body && typeof body === "object" && "message" in body) {
    const m = (body as { message?: unknown }).message;
    if (typeof m === "string" && m.trim() !== "") return m;
  }
  return `Request failed (HTTP ${status}).`;
}

/** `{retry_after}` seconds from a 429 body, when present and numeric. */
function retryAfterFrom(body: unknown): number | undefined {
  if (body && typeof body === "object" && "retry_after" in body) {
    const n = Number((body as { retry_after?: unknown }).retry_after);
    if (Number.isFinite(n) && n >= 0) return n;
  }
  return undefined;
}

/**
 * Fetch `path` (relative to the boot `apiBase`) with the REST nonce attached,
 * JSON in and JSON out. Non-2xx responses throw {@link ApiError}. Callers pass
 * standard `RequestInit` extras (method, body, signal); JSON bodies get their
 * Content-Type set automatically.
 */
export async function apiFetch<T = unknown>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const boot = getBoot();
  if (!boot) {
    throw new ApiError(
      "Chronicler admin bundle has no window.chroniclerBoot — the enqueue must define it before the bundle runs.",
      0,
    );
  }

  const headers = new Headers(init.headers);
  headers.set("X-WP-Nonce", boot.nonce);
  headers.set("Accept", "application/json");
  if (init.body != null && !headers.has("Content-Type")) {
    headers.set("Content-Type", "application/json");
  }

  const res = await fetch(joinApi(boot.apiBase, path), {
    credentials: "same-origin",
    ...init,
    headers,
  });

  let body: unknown;
  if (res.status !== 204) {
    try {
      body = await res.json();
    } catch {
      body = undefined; // non-JSON body; the status carries the story
    }
  }

  if (!res.ok) {
    throw new ApiError(messageFrom(body, res.status), res.status, {
      retryAfter: res.status === 429 ? retryAfterFrom(body) : undefined,
      body,
    });
  }
  return body as T;
}
