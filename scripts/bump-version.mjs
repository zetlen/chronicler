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

import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { parseArgs } from "node:util";
import { PLUGIN_FILE, VERSION_HEADER, parseVersion } from "./pluginVersion.mjs";

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
console.log("  Rebuild the zip: npm run build:plugin");
