// Run the plugin's PHP test harness (wordpress-plugin/tests/run.php) in a
// pinned-platform container. See dockerPlatform.mjs for why the platform is
// passed explicitly. The harness needs the plugin's Composer vendor tree
// (symfony/expression-language since #88); a plain checkout provisions it
// here through the composer image — no local Composer required.
import { execFileSync } from "node:child_process";
import { existsSync } from "node:fs";
import { resolve } from "node:path";
import { dockerPlatform } from "./dockerPlatform.mjs";
import { pluginVersion } from "./pluginVersion.mjs";

const vendorReady =
  existsSync(resolve("wordpress-plugin/vendor/autoload.php")) &&
  existsSync(resolve("wordpress-plugin/vendor/opis/json-schema"));
if (!vendorReady) {
  console.log("→ Provisioning wordpress-plugin/vendor (composer install, with dev deps)…");
  execFileSync(
    "docker",
    [
      "run",
      "--rm",
      "--platform",
      dockerPlatform(),
      // See pluginVersion.mjs — the mount has no git context for Composer
      // to derive a root package version from.
      "-e",
      `COMPOSER_ROOT_VERSION=${pluginVersion()}`,
      "-v",
      `${resolve("wordpress-plugin")}:/app`,
      "-w",
      "/app",
      "composer:2",
      "composer",
      "install",
      "--quiet",
    ],
    { stdio: "inherit" },
  );
}

execFileSync(
  "docker",
  [
    "run",
    "--rm",
    "--platform",
    dockerPlatform(),
    "-v",
    `${resolve("wordpress-plugin")}:/plugin`,
    "-w",
    "/plugin",
    "php:8.3-cli",
    "php",
    "tests/run.php",
  ],
  { stdio: "inherit" },
);
