// One-way JSON → YAML conversion for the Game System editor: templates
// stored as JSON (every pre-4.9 template) read better as YAML, where block
// scalars keep derived formulas legible and comments become possible. YAML
// is a JSON superset, so nothing needs converting the other way.
import { stringify } from "yaml";

/**
 * The YAML rendering of a JSON template source, or null when the text isn't
 * a JSON object/array (already YAML, broken JSON, or a bare scalar — nothing
 * a conversion button should touch). Semantics are preserved exactly: the
 * output parses back to the same document.
 */
export function jsonToYaml(text: string): string | null {
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    return null;
  }
  if (data === null || typeof data !== "object") {
    return null;
  }
  // lineWidth 0: never fold long strings — folded prose re-wraps
  // unpredictably under later edits. Multi-line strings still come out as
  // block literals, which is exactly what derived formulas want.
  return stringify(data, { indent: 2, lineWidth: 0 });
}
