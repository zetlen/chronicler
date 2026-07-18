import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";
import { zipSync, strToU8 } from "fflate";

/**
 * Packaging for the Chronicler WordPress plugin.
 *
 * The plugin is static code, checked in under wordpress-plugin/ where it can
 * be linted and reviewed as real PHP/JS. One omakase plugin (slug
 * `chronicler`) bundles the session editor, the transcript-blocks module, and
 * the character-sheets module. Blocks carry their own per-post styles (see
 * blocks.php); the plugin needs re-installing only when its code changes.
 */

const PLUGIN_DIR = join(process.cwd(), "wordpress-plugin");

/** Directories under wordpress-plugin/ that are dev-only, never shipped. */
const EXCLUDED = new Set(["tests"]);

/** Every shippable plugin file, keyed by path relative to wordpress-plugin/. */
export function pluginFiles(): Record<string, string> {
  const out: Record<string, string> = {};
  const walk = (dir: string) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        if (!EXCLUDED.has(entry.name)) walk(join(dir, entry.name));
      } else {
        if (entry.name.endsWith(".test.ts")) continue;
        const abs = join(dir, entry.name);
        out[relative(PLUGIN_DIR, abs)] = readFileSync(abs, "utf8");
      }
    }
  };
  walk(PLUGIN_DIR);
  return out;
}

/**
 * The installable plugin archive, in the layout wp-admin's Upload Plugin
 * flow expects: a top-level plugin directory containing the plugin file.
 */
export function pluginZip(): Uint8Array {
  const entries: Record<string, Uint8Array> = {};
  for (const [path, content] of Object.entries(pluginFiles())) {
    entries[`chronicler/${path}`] = strToU8(content);
  }
  return zipSync(entries);
}
