import { configDefaults, defineConfig } from "vitest/config";

export default defineConfig({
  // Resolve the "@/*" path alias from tsconfig.json (native in Vite 4+).
  resolve: { tsconfigPaths: true },
  test: {
    // jsdom gives DOMPurify a DOM to sanitize against (rendering is client-side).
    environment: "jsdom",
    include: ["**/*.test.ts", "**/*.test.tsx"],
    // Git worktrees under .claude/ carry their own copies of the suite,
    // pinned to other commits — never sweep them into this repo's run.
    exclude: [...configDefaults.exclude, "**/.claude/**"],
    server: {
      deps: {
        // @wordpress/element re-exports findDOMNode/render/hydrate from
        // react-dom, which React 19 removed. Externalized (native Node ESM)
        // imports hard-fail on those missing named exports; inlining lets
        // Vite's CJS interop resolve them to undefined, and the rich-text
        // APIs the tests use never call them.
        // (RegExp entries match against the full module path.)
        inline: [/node_modules\/@wordpress\//],
      },
    },
  },
});
