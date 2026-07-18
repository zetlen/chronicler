// The authoritative validation layer: when everything the editor can check
// locally is clean, the buffer is sent to the plugin's preflight endpoint,
// which runs the exact Save-time parse (chronicler_sheets_parse_template) —
// real Expression Language syntax, formula cycles, dry-run type checks. Its
// verdict comes back as a single diagnostic. Fails soft: if the request
// can't complete, the editor stays quiet and Save still validates.
import type { Diagnostic } from "@codemirror/lint";

export interface PreflightBoot {
  preflightUrl: string;
  nonce: string;
}

interface PreflightResponse {
  valid?: boolean;
  code?: string;
  message?: string;
}

/**
 * Anchor a server message to the document: PHP messages quote the offending
 * property id (Property "vigor": …) — underline its declaration
 * (`id: vigor`) when one exists, else its first whole-word occurrence;
 * otherwise mark the first line.
 */
export function anchorForMessage(
  text: string,
  message: string,
): { from: number; to: number } {
  const quoted = message.match(/"([a-z][a-z0-9_]*)"/);
  if (quoted) {
    const token = quoted[1];
    // Prefer the declaration site over an incidental mention (the same word
    // may appear in the system title, a label, or another formula first).
    const declaration = new RegExp(`(["']?id["']?\\s*:\\s*["']?)(${token})\\b`).exec(text);
    if (declaration) {
      const from = declaration.index + declaration[1].length;
      return { from, to: from + token.length };
    }
    const anywhere = new RegExp(`\\b${token}\\b`).exec(text);
    if (anywhere) {
      return { from: anywhere.index, to: anywhere.index + token.length };
    }
  }
  const firstLineEnd = text.indexOf("\n");
  return { from: 0, to: firstLineEnd === -1 ? text.length : firstLineEnd };
}

/**
 * A per-editor preflight runner with a one-entry cache: the CodeMirror lint
 * pass re-runs on focus and configuration changes, and identical text must
 * not re-POST.
 */
export function createPreflight(
  boot: PreflightBoot,
): (text: string) => Promise<Diagnostic[]> {
  let cachedText: string | null = null;
  let cachedDiagnostics: Diagnostic[] = [];
  // An unreachable check must not read as a clean bill of health: say so,
  // quietly (info, not error — Save still validates everything).
  const unavailable = (text: string): Diagnostic[] => [
    {
      ...anchorForMessage(text, ""),
      severity: "info",
      source: "Chronicler",
      message:
        "The full template check couldn't run — Validate & Save still checks everything.",
    },
  ];
  return async (text: string): Promise<Diagnostic[]> => {
    if (text === cachedText) {
      return cachedDiagnostics;
    }
    let response: PreflightResponse;
    try {
      const res = await fetch(boot.preflightUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": boot.nonce,
        },
        body: JSON.stringify({ source: text }),
      });
      if (!res.ok) {
        return unavailable(text);
      }
      response = (await res.json()) as PreflightResponse;
    } catch {
      return unavailable(text); // offline/aborted — Save remains the authority
    }
    const diagnostics: Diagnostic[] =
      response.valid === true || typeof response.message !== "string"
        ? []
        : [
            {
              ...anchorForMessage(text, response.message),
              severity: "error",
              source: "Chronicler",
              message: response.message,
            },
          ];
    cachedText = text;
    cachedDiagnostics = diagnostics;
    return diagnostics;
  };
}
