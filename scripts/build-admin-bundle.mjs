#!/usr/bin/env node
// Build the wp-admin bundles:
//
//   components/admin/main.tsx  --esbuild-->  wordpress-plugin/admin/dist/chronicler-admin.js   (#96)
//   components/admin/admin.css --tailwind->  wordpress-plugin/admin/dist/chronicler-admin.css  (#96)
//   components/game-system/main.ts --------> wordpress-plugin/admin/dist/chronicler-game-system.js (#149)
//
// React is bundled (not externalized to wp globals), minified, browser-only.
// Two invariants are asserted after building:
//
//   1. No next/* or app/ module anywhere in the JS module graph — the bundle
//      must run with no Next.js runtime (checked via esbuild's metafile).
//   2. Every selector in the CSS is scoped under #chronicler-admin-root so
//      nothing bleeds into wp-admin chrome (Tailwind preflight/utilities are
//      nested under the root in admin.css; the residual `:root, :host`
//      theme-variable block is rewritten onto the root here).
//
// dist/ is gitignored; scripts/build-plugin-zip.mjs runs this before packing
// so built zips always ship a fresh bundle.
//
//   node scripts/build-admin-bundle.mjs   (or: npm run build:admin)

import { execFileSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync, statSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const outDir = join(root, "wordpress-plugin", "admin", "dist");
const jsOut = join(outDir, "chronicler-admin.js");
const cssOut = join(outDir, "chronicler-admin.css");

mkdirSync(outDir, { recursive: true });

/* ----------------------------------------------------------------- *
 * JS: esbuild bundle
 * ----------------------------------------------------------------- */

const result = await build({
  absWorkingDir: root,
  entryPoints: ["components/admin/main.tsx"],
  outfile: jsOut,
  bundle: true,
  minify: true,
  format: "iife",
  platform: "browser",
  target: "es2020",
  jsx: "automatic",
  // React's dev/prod split reads this; without it the bundle throws at runtime.
  define: { "process.env.NODE_ENV": '"production"' },
  metafile: true,
  logLevel: "info",
});

// Invariant 1: the admin bundle must not lean on the Next.js app. Any module
// under app/ or from the next package in the graph is a build failure.
const offenders = Object.keys(result.metafile.inputs).filter(
  (input) =>
    input === "next" ||
    input.startsWith("app/") ||
    input.startsWith("next/") ||
    /(^|\/)node_modules\/next\//.test(input),
);
if (offenders.length > 0) {
  console.error(
    "✗ Forbidden modules in the admin bundle graph (next/* and app/ may not ship to wp-admin):",
  );
  for (const input of offenders) console.error(`  - ${input}`);
  process.exit(1);
}
console.log(
  `✓ Module graph clean: ${Object.keys(result.metafile.inputs).length} inputs, no next/* or app/ modules`,
);

/* ----------------------------------------------------------------- *
 * CSS: Tailwind 4 via its official CLI
 * ----------------------------------------------------------------- */

const tailwindCli = join(root, "node_modules", "@tailwindcss", "cli", "dist", "index.mjs");
execFileSync(
  process.execPath,
  [
    tailwindCli,
    "--cwd",
    root,
    "-i",
    join(root, "components", "admin", "admin.css"),
    "-o",
    cssOut,
    "--minify",
  ],
  { stdio: "inherit" },
);

// Tailwind emits its theme variables on `:root, :host` no matter how the
// imports are nested. The editor lives entirely under #chronicler-admin-root,
// so moving the variables there is equivalent for us and inert for wp-admin.
let css = readFileSync(cssOut, "utf8");
const scopedRoot = css
  .replaceAll(":root,:host", "#chronicler-admin-root")
  .replaceAll(":root, :host", "#chronicler-admin-root");
// The @property fallback block (for browsers without @property) resets --tw-*
// variables on `*`; scope it to the editor's subtree as well.
const scoped = scopedRoot.replaceAll(
  "*,:before,:after,::backdrop{",
  "#chronicler-admin-root *,#chronicler-admin-root :before,#chronicler-admin-root :after,#chronicler-admin-root ::backdrop{",
);
writeFileSync(cssOut, scoped);

// Invariant 2: every selector is scoped under #chronicler-admin-root. Strip
// comments and at-rule preludes, then inspect what's left before each `{`.
const noComments = scoped.replace(/\/\*[\s\S]*?\*\//g, "");
const selectorish = noComments.match(/[^{};]+\{/g) ?? [];
const unscoped = selectorish
  .map((s) => s.trim())
  .filter((s) => !s.startsWith("@")) // @layer/@media/@supports/@property preludes
  .filter((s) => !s.includes("#chronicler-admin-root"))
  // Declarations inside @property bodies (e.g. `syntax:"*";inherits:false`)
  // are not selectors; anything with a colon-terminated ident before `{`
  // that we'd flag here is a real leak, so keep the filter strict.
  .filter((s) => !/^[-a-z]+\s*:/i.test(s));
if (unscoped.length > 0) {
  console.error(
    "✗ CSS selectors escaped the #chronicler-admin-root scope (would restyle wp-admin):",
  );
  for (const s of [...new Set(unscoped)]) console.error(`  - ${s}`);
  process.exit(1);
}
console.log("✓ CSS fully scoped under #chronicler-admin-root");

/* ----------------------------------------------------------------- *
 * JS: the Game System editor bundle (#149)
 * ----------------------------------------------------------------- */

const gameSystemOut = join(outDir, "chronicler-game-system.js");

// codemirror-json-schema renders hover/completion docs through its
// utils/markdown module, which statically imports markdown-it + shiki
// (~415 kB minified) for markdown-with-code-highlighting we don't need.
// Swap the module for components/game-system/markdown-lite.ts (same
// exported signature); the graph invariant below keeps the swap honest.
const markdownLitePlugin = {
  name: "markdown-lite",
  setup(build) {
    build.onResolve({ filter: /^\.\.\/utils\/markdown$/ }, (args) => {
      if (!args.importer.includes("codemirror-json-schema")) {
        return null;
      }
      return { path: join(root, "components", "game-system", "markdown-lite.ts") };
    });
  },
};

const gameSystemResult = await build({
  absWorkingDir: root,
  entryPoints: ["components/game-system/main.ts"],
  outfile: gameSystemOut,
  bundle: true,
  minify: true,
  format: "iife",
  platform: "browser",
  target: "es2020",
  // The starter example imports as text straight from the drift-guarded
  // fixture corpus, so the inserted example can never diverge from what
  // the validators accept.
  loader: { ".yaml": "text" },
  plugins: [markdownLitePlugin],
  metafile: true,
  logLevel: "info",
});

const gameSystemOffenders = Object.keys(gameSystemResult.metafile.inputs).filter(
  (input) =>
    input === "next" ||
    input.startsWith("app/") ||
    input.startsWith("next/") ||
    /(^|\/)node_modules\/next\//.test(input) ||
    // The markdown-lite swap must hold: neither shiki nor markdown-it may
    // ride into wp-admin.
    /(^|\/)node_modules\/(shiki|@shikijs\/[^/]+|markdown-it)\//.test(input),
);
if (gameSystemOffenders.length > 0) {
  console.error(
    "✗ Forbidden modules in the game-system bundle graph (next/*, app/, shiki, markdown-it):",
  );
  for (const input of gameSystemOffenders) console.error(`  - ${input}`);
  process.exit(1);
}
console.log(
  `✓ Game-system module graph clean: ${Object.keys(gameSystemResult.metafile.inputs).length} inputs, no next/app/shiki/markdown-it modules`,
);

// Size budget: the denylist above mirrors upstream's CURRENT markdown deps.
// If a codemirror-json-schema release moves the module path (breaking the
// swap) and renames its renderer deps (dodging the denylist), the graph
// check passes while ~400 kB rides back in — the budget catches any such
// silent regression regardless of module names. Bundle is ~700 kB today.
const GAME_SYSTEM_BUDGET_KB = 850;
const gameSystemSize = statSync(gameSystemOut).size;
if (gameSystemSize > GAME_SYSTEM_BUDGET_KB * 1024) {
  console.error(
    `✗ chronicler-game-system.js is ${(gameSystemSize / 1024).toFixed(1)} kB — over the ${GAME_SYSTEM_BUDGET_KB} kB budget. ` +
      "Did the markdown-lite swap stop matching codemirror-json-schema's internals?",
  );
  process.exit(1);
}

for (const file of [jsOut, cssOut, gameSystemOut]) {
  const { size } = statSync(file);
  if (size === 0) {
    console.error(`✗ ${file} is empty`);
    process.exit(1);
  }
  console.log(`✓ Wrote ${file} (${(size / 1024).toFixed(1)} kB)`);
}
