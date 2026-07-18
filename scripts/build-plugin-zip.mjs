#!/usr/bin/env node
// Build the installable WordPress plugin zip. The packaging logic lives in
// lib/wordpress/plugin.ts (loaded via Node's TypeScript type stripping) so
// plugin.test.ts can assert against the exact bytes this script writes.
//
//   npm run build:plugin              -> ./chronicler.zip
//   npm run build:plugin -- /tmp/c.zip
//
// Before packing, this vendors the plugin's PHP dependencies into
// wordpress-plugin/vendor (gitignored) so the zip activates on shared hosts
// with no Composer. Prefers a local composer, falling back to the composer:2
// Docker image — the same pattern npm run test:php uses for PHP itself.
// It also builds the wp-admin session-editor bundle into
// wordpress-plugin/admin/dist (gitignored), so built zips always ship it.
//
// The packaging code resolves wordpress-plugin/ from the working directory;
// npm always runs scripts at the package root, so prefer the npm form.

import { execFileSync } from "node:child_process";
import { existsSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { parseArgs } from "node:util";
import { dockerPlatform } from "./dockerPlatform.mjs";
import { pluginVersion } from "./pluginVersion.mjs";
import { pluginZip } from "../lib/wordpress/plugin.ts";

if (!existsSync(resolve("wordpress-plugin/chronicler.php"))) {
  console.error("Run from the repo root (easiest: npm run build:plugin).");
  process.exit(1);
}

let outArg;
try {
  ({ positionals: [outArg] } = parseArgs({ allowPositionals: true }));
} catch (err) {
  console.error(`✗ build:plugin: ${err.message}`);
  console.error("Usage: npm run build:plugin [-- <output.zip>]");
  process.exit(1);
}
const out = resolve(outArg ?? "chronicler.zip");

/** True when `cmd --version` runs cleanly (also weeds out broken shims). */
function available(cmd) {
  try {
    execFileSync(cmd, ["--version"], { stdio: "ignore" });
    return true;
  } catch {
    return false;
  }
}

// The session-editor bundle (#96): dist/ is gitignored, so it must be built
// fresh here for the zip to carry it. The script asserts its own invariants
// (no next/* in the graph, CSS scoped) and exits non-zero on failure.
execFileSync(process.execPath, [resolve("scripts/build-admin-bundle.mjs")], {
  stdio: "inherit",
});

const pluginDir = resolve("wordpress-plugin");
// COMPOSER_ROOT_VERSION: see pluginVersion.mjs — without it, Composer in the
// container warns and defaults the root package version to 1.0.0.
const rootVersion = `COMPOSER_ROOT_VERSION=${pluginVersion()}`;
const composerArgs = [
  "install",
  "--no-dev",
  "--optimize-autoloader",
  "--no-interaction",
];
if (available("composer")) {
  execFileSync("composer", composerArgs, {
    cwd: pluginDir,
    stdio: "inherit",
    env: { ...process.env, COMPOSER_ROOT_VERSION: pluginVersion() },
  });
} else if (available("docker")) {
  console.log("No local composer; using the composer:2 Docker image.");
  execFileSync(
    "docker",
    ["run", "--rm", "--platform", dockerPlatform(), "-e", rootVersion, "-v", `${pluginDir}:/app`, "-w", "/app", "composer:2", ...composerArgs],
    { stdio: "inherit" },
  );
} else {
  console.error(
    "Vendoring PHP dependencies needs composer or docker on the PATH.",
  );
  process.exit(1);
}
if (!existsSync(resolve(pluginDir, "vendor/autoload.php"))) {
  console.error("composer install ran but vendor/autoload.php is missing.");
  process.exit(1);
}

writeFileSync(out, pluginZip());
console.log(`✓ Wrote ${out}`);
