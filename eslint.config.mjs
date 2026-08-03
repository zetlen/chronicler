import { defineConfig, globalIgnores } from "eslint/config";
import js from "@eslint/js";
import tseslint from "typescript-eslint";
import reactHooks from "eslint-plugin-react-hooks";
import globals from "globals";

const eslintConfig = defineConfig([
  globalIgnores([
    // WordPress-runtime sources (shipped inside the plugin zip): ES5 against
    // the wp.* globals, where Gutenberg's `edit`/`save` conventions clash
    // with this repo's React lint rules.
    "wordpress-plugin/**",
    // Agent worktrees: full repo copies, linted (or not) in their own
    // checkouts. Gitignored, but flat config doesn't read .gitignore.
    ".claude/worktrees/**",
    // Memory-plugin scratch space: not project code, same .gitignore caveat.
    ".remember/**",
  ]),
  js.configs.recommended,
  ...tseslint.configs.recommended,
  // The admin bundle depends on the compiler-powered hooks rules —
  // react-hooks/purity and react-hooks/set-state-in-effect in particular.
  reactHooks.configs.flat.recommended,
  {
    languageOptions: {
      globals: { ...globals.browser, ...globals.node },
    },
    // Treat a leading underscore as "intentionally unused" (e.g. params kept
    // for signature symmetry across the renderer strategies).
    rules: {
      "@typescript-eslint/no-unused-vars": [
        "warn",
        {
          argsIgnorePattern: "^_",
          varsIgnorePattern: "^_",
          caughtErrorsIgnorePattern: "^_",
        },
      ],
    },
  },
]);

export default eslintConfig;
