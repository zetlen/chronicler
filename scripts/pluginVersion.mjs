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

// The wp.org readme and its Stable tag, derived from the Version: header just
// like CHRONICLER_VERSION (#163). ONE regex serves both the bump rewrite
// (replace with `$1<next>`) and the drift check (parseStableTag) — two
// hand-kept copies is how both sides silently stop matching at once. /i
// because wp.org's readme parser lowercases header keys, so a capitalization
// edit must not disable the tooling; the lookahead (instead of a trailing
// \s*$) leaves the line's own EOL bytes untouched on rewrite.
export const README_FILE = "wordpress-plugin/readme.txt";
export const STABLE_TAG = /^(\s*stable tag:\s*)(\S+)(?=[^\S\n]*\r?$)/im;

/** The readme's Stable tag, or null when none parses. */
export function parseStableTag(source) {
  return source.match(STABLE_TAG)?.[2] ?? null;
}

/**
 * The newest (first) `= x.y.z =` heading under == Changelog ==, or null.
 * check-version.mjs requires it to match the header: a changelog whose
 * latest entry lags the shipped version reads as abandoned on wp.org.
 */
export function parseChangelogLatest(source) {
  const changelog = source.split(/^==\s*Changelog\s*==\s*$/im)[1];
  return changelog?.match(/^=\s*(\S+)\s*=\s*\r?$/m)?.[1] ?? null;
}
