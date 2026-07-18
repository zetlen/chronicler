#!/usr/bin/env node
/**
 * Version-bump guard for the plugin (#77). The Version: header in
 * wordpress-plugin/chronicler.php is the repo's ONLY version literal (the
 * plugin zip is the only release artifact since 4.0.0; CHRONICLER_VERSION
 * and COMPOSER_ROOT_VERSION are derived from the header, and package.json
 * has no version field).
 *
 * The check: if anything under wordpress-plugin/ changed relative to the
 * base ref (merge-base with main), the header must have changed too.
 *
 * Callers:
 *  - `npm run check:version`         — checks committed state (HEAD vs base)
 *  - `check-version.mjs --staged`    — pre-commit hook: checks the index,
 *    so the FIRST commit that touches the plugin on a branch must carry the
 *    bump, and later commits pass because the branch already differs from
 *    main. `git commit --no-verify` is the escape hatch.
 *  - `check-version.mjs --base <ref>` — explicit base (CI, spot checks).
 */

import { execFileSync } from "node:child_process";
import { parseArgs } from "node:util";
import { parseVersion, PLUGIN_FILE } from "./pluginVersion.mjs";

const PLUGIN_DIR = "wordpress-plugin/";

let staged, baseArg;
try {
  const { values } = parseArgs({
    options: {
      staged: { type: "boolean", default: false },
      base: { type: "string" },
    },
  });
  staged = values.staged;
  baseArg = values.base ?? null;
} catch (err) {
  console.error(`✗ check-version: ${err.message}`);
  console.error("Usage: check-version.mjs [--staged] [--base <ref>]");
  process.exit(1);
}

function git(...cmd) {
  return execFileSync("git", cmd, { encoding: "utf8" }).trim();
}

function tryGit(...cmd) {
  try {
    return git(...cmd);
  } catch {
    return null;
  }
}

function fail(message) {
  console.error(`✗ check-version: ${message}`);
  process.exit(1);
}

// --- resolve what to compare -------------------------------------------------

const base =
  baseArg ??
  tryGit("merge-base", "main", "HEAD") ??
  tryGit("merge-base", "origin/main", "HEAD");

// Current content: the index in --staged mode (what this commit will
// contain), HEAD otherwise.
const currentSource = staged
  ? tryGit("show", `:${PLUGIN_FILE}`)
  : tryGit("show", `HEAD:${PLUGIN_FILE}`);
if (currentSource === null) fail(`${PLUGIN_FILE} not found`);

let current;
try {
  current = parseVersion(currentSource);
} catch (err) {
  fail(err.message);
}

// --- bump check ----------------------------------------------------------------

if (base) {
  const changed = (
    staged
      ? git("diff", "--cached", "--name-only", base)
      : git("diff", "--name-only", base, "HEAD")
  )
    .split("\n")
    .filter((f) => f.startsWith(PLUGIN_DIR));

  const baseSource = tryGit("show", `${base}:${PLUGIN_FILE}`);
  if (changed.length > 0 && baseSource !== null) {
    const baseVersion = parseVersion(baseSource);
    if (current === baseVersion) {
      fail(
        `plugin files changed but the version is still ${baseVersion}.\n` +
          `  Bump the 'Version:' header in ${PLUGIN_FILE} — easiest: npm run bump patch|minor\n` +
          `  (semver: minor for a feature, patch for a fix), then rebuild with npm run build:plugin.\n` +
          `  Changed: ${changed.slice(0, 5).join(", ")}${changed.length > 5 ? ", …" : ""}`,
      );
    }
  }
}

console.log(`✓ check-version: ${current}`);
