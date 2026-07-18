// The plugin version, parsed from the Version: header in
// wordpress-plugin/chronicler.php — the repo's ONLY version literal (see
// AGENTS.md; CHRONICLER_VERSION is derived from the header at runtime).
// Shared by the version-bump guard (check-version.mjs), the bump helper
// (bump-version.mjs), and the composer invocations, which pass it as
// COMPOSER_ROOT_VERSION: the container mounts only wordpress-plugin/, so
// Composer has no git context to guess a root version from and would
// otherwise warn and default to 1.0.0.

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

export const PLUGIN_FILE = "wordpress-plugin/chronicler.php";

export const VERSION_HEADER = /^(\s*\*\s*Version:\s*)(\S+)\s*$/m;

/** The Version: header from chronicler.php source, or throws. */
export function parseVersion(source) {
  const header = source.match(VERSION_HEADER)?.[2];
  if (!header) {
    throw new Error(`could not find the Version: header in ${PLUGIN_FILE}`);
  }
  return header;
}

/** The version of the working-tree checkout. */
export function pluginVersion() {
  return parseVersion(readFileSync(resolve(PLUGIN_FILE), "utf8"));
}
