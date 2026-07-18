/**
 * datetime-local plumbing for the session editor (#96).
 *
 * The inputs speak the browser-local `YYYY-MM-DDTHH:mm` dialect; the Session
 * stores start/end as ISO-8601 instants (client-owned opaque strings, per
 * Store\Sessions). Validation is the recorded #96 gap: fetching is gated on
 * non-empty, parseable, start < end.
 */

/** Format a Date as a datetime-local input value (browser-local timezone). */
export function toLocalInputValue(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(
    date.getDate(),
  )}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Parse a datetime-local value (browser-local timezone) to epoch ms, or null
 * when empty/unparseable. `new Date("YYYY-MM-DDTHH:mm")` is local time per
 * spec (date-time forms without a zone are local).
 */
export function parseLocalInputValue(value: string): number | null {
  if (!value.trim()) return null;
  const ms = new Date(value).getTime();
  return Number.isNaN(ms) ? null : ms;
}

/** A stored ISO instant back to the input dialect ("" when unparseable). */
export function isoToLocalInputValue(iso: string): string {
  const ms = Date.parse(iso);
  return Number.isNaN(ms) ? "" : toLocalInputValue(new Date(ms));
}

export type RangeValidation =
  | { ok: true; startMs: number; endMs: number; startIso: string; endIso: string }
  | { ok: false; error: string };

/**
 * The fetch gate: both bounds present, parseable, and start strictly before
 * end. Returns the epoch-ms window plus the ISO forms the Session stores.
 */
export function validateSessionRange(start: string, end: string): RangeValidation {
  if (!start.trim() || !end.trim()) {
    return { ok: false, error: "Enter both a start and an end time." };
  }
  const startMs = parseLocalInputValue(start);
  const endMs = parseLocalInputValue(end);
  if (startMs === null || endMs === null) {
    return { ok: false, error: "Enter valid start and end times." };
  }
  if (startMs >= endMs) {
    return { ok: false, error: "Start time must be before end time." };
  }
  return {
    ok: true,
    startMs,
    endMs,
    startIso: new Date(startMs).toISOString(),
    endIso: new Date(endMs).toISOString(),
  };
}

/** Human range like "Jun 9, 2026, 3:00 PM → Jun 10, 2026, 3:00 PM". */
export function formatRange(startIso: string, endIso: string): string {
  const fmt = (iso: string) => {
    const ms = Date.parse(iso);
    if (Number.isNaN(ms)) return iso || "?";
    return new Date(ms).toLocaleString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
    });
  };
  return `${fmt(startIso)} → ${fmt(endIso)}`;
}

/** The store's UTC "Y-m-d H:i:s" timestamps, shown in browser-local time. */
export function formatUtcTimestamp(value: string): string {
  const ms = Date.parse(`${value.replace(" ", "T")}Z`);
  if (Number.isNaN(ms)) return value;
  return new Date(ms).toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}
