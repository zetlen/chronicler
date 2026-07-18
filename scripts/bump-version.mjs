#!/usr/bin/env node
// Bump the plugin version — the Version: header in chronicler.php, the
// repo's ONLY version literal (see AGENTS.md).
//
//   npm run bump patch   4.7.1 -> 4.7.2  (fix)
//   npm run bump minor   4.7.1 -> 4.8.0  (feature)
//   npm run bump major   4.7.1 -> 5.0.0
//
// Convenience only: check-version.mjs remains the enforcement. No git
// operations; rebuild the zip afterward with npm run build:plugin.

import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { parseArgs } from "node:util";
import {
  PLUGIN_FILE,
  README_FILE,
  STABLE_TAG,
  VERSION_HEADER,
  parseChangelogLatest,
  parseVersion,
} from "./pluginVersion.mjs";

let level;
try {
  ({ positionals: [level] } = parseArgs({ allowPositionals: true }));
} catch {
  level = undefined;
}
if (!["patch", "minor", "major"].includes(level)) {
  console.error("Usage: npm run bump patch|minor|major");
  process.exit(1);
}

const path = resolve(PLUGIN_FILE);
const source = readFileSync(path, "utf8");
const current = parseVersion(source);

const m = current.match(/^(\d+)\.(\d+)\.(\d+)$/);
if (!m) {
  console.error(`✗ bump: current version '${current}' is not plain semver`);
  process.exit(1);
}
const [major, minor, patch] = m.slice(1).map(Number);
const next =
  level === "major" ? `${major + 1}.0.0`
  : level === "minor" ? `${major}.${minor + 1}.0`
  : `${major}.${minor}.${patch + 1}`;

writeFileSync(path, source.replace(VERSION_HEADER, `$1${next}`));
console.log(`✓ ${PLUGIN_FILE}: ${current} -> ${next}`);

// The wp.org readme's Stable tag is derived from the header (the header stays
// canonical — see AGENTS.md); keep them in lockstep with the SHARED regex.
// A missing readme.txt is fine (older branches predate it), but a readme
// whose tag line no longer parses must fail loudly: skipping silently here
// would also mean check-version.mjs's guard silently stopped matching, and
// drift would ship unflagged.
const readmePath = resolve(README_FILE);
if (existsSync(readmePath)) {
  const readme = readFileSync(readmePath, "utf8");
  if (!STABLE_TAG.test(readme)) {
    console.error(`✗ bump: ${README_FILE} exists but has no parseable 'Stable tag:' line`);
    process.exit(1);
  }
  writeFileSync(readmePath, readme.replace(STABLE_TAG, `$1${next}`));
  console.log(`✓ ${README_FILE}: Stable tag -> ${next}`);
  if (parseChangelogLatest(readme) !== next) {
    console.log(`  Add a '= ${next} =' entry to ${README_FILE}'s == Changelog == (check:version enforces it).`);
  }
}
console.log("  Rebuild the zip: npm run build:plugin");
