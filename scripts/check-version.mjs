#!/usr/bin/env node
/**
 * Version-bump guard for the plugin (#77). The Version: header in
 * wordpress-plugin/chronicler.php is the repo's ONLY version literal (the
 * plugin zip is the only release artifact since 4.0.0; CHRONICLER_VERSION
 * and COMPOSER_ROOT_VERSION are derived from the header, and package.json
 * has no version field). The wp.org readme.txt's Stable tag is derived too
 * — bump-version.mjs rewrites it, and the sync check below guards drift.
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
import {
  PLUGIN_FILE,
  README_FILE,
  parseChangelogLatest,
  parseStableTag,
  parseVersion,
} from "./pluginVersion.mjs";

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
    // stderr piped (not inherited): probing for files that may not exist at
    // a rev — e.g. readme.txt before its first commit — is normal control
    // flow and shouldn't print git's fatal line.
    return execFileSync("git", cmd, { encoding: "utf8", stdio: ["pipe", "pipe", "pipe"] }).trim();
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
if (base === null) {
  // tryGit pipes git's stderr, so without this line a shallow or oddly
  // configured clone would skip the bump check with no hint it never ran.
  console.error(
    "⚠ check-version: no merge-base with main/origin/main — the bump check was skipped",
  );
}

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

// --- readme Stable tag + changelog sync (#163) -------------------------------
// The wp.org readme's Stable tag derives from the header; a hand-edit that
// bumps only one of the two is always a mistake. A missing readme is fine
// (older branches predate it) — a readme whose tag doesn't PARSE is not:
// failing open here would disable this guard and bump's rewrite in the same
// stroke, since both use the shared STABLE_TAG regex (pluginVersion.mjs).
const readmeSource = staged
  ? tryGit("show", `:${README_FILE}`)
  : tryGit("show", `HEAD:${README_FILE}`);
if (readmeSource !== null) {
  const stableTag = parseStableTag(readmeSource);
  if (stableTag === null) {
    fail(`${README_FILE} exists but has no parseable 'Stable tag:' line.`);
  }
  if (stableTag !== current) {
    fail(
      `${README_FILE} Stable tag (${stableTag}) is out of sync with the Version: header (${current}).\n` +
        "  Fix with npm run bump patch|minor — it rewrites both.",
    );
  }
  // The changelog's newest heading is a version literal nothing derives, so
  // guard it too: wp.org renders a stable tag with no matching changelog
  // entry as an abandoned/incomplete listing.
  const changelogLatest = parseChangelogLatest(readmeSource);
  if (changelogLatest !== current) {
    fail(
      `${README_FILE} newest changelog entry (${changelogLatest ?? "none"}) doesn't match the Version: header (${current}).\n` +
        `  Add a '= ${current} =' entry under == Changelog ==.`,
    );
  }
}

console.log(`✓ check-version: ${current}`);
