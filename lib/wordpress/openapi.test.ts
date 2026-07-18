import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { parse } from "yaml";

/**
 * openapi.yaml is hand-maintained; this suite keeps it honest against BOTH
 * sources of chronicler/v1 routes (#160): Routes::definitions() (#112) and
 * the character-sheet registrations in wordpress-plugin/sheets/rest.php,
 * which hook rest_api_init directly. Same approach as sheetsPlugin.test.ts:
 * pin the PHP source. The PHP side of the core contract is pinned by
 * tests/routes.test.php (every operation carries a response schema; response
 * shapes mirror the stores), so path/method agreement here closes the loop.
 */

const ROOT = join(__dirname, "..", "..");
const spec = parse(readFileSync(join(ROOT, "openapi.yaml"), "utf8"));
const routesPhp = readFileSync(
  join(ROOT, "wordpress-plugin", "src", "Rest", "Routes.php"),
  "utf8",
);
const sheetsPhp = readFileSync(
  join(ROOT, "wordpress-plugin", "sheets", "rest.php"),
  "utf8",
);

const HTTP_METHODS = ["get", "post", "put", "delete", "patch"] as const;

/** WP route regex -> openapi path template ((?P<id>\d+) -> {id}). */
function toOpenapiPath(route: string): string {
  return route.replace(/\(\?P<(\w+)>[^)]+\)/g, "{$1}");
}

/** (openapi path) -> lowercase methods, from Routes::definitions() source. */
function coreSurface(): Map<string, string[]> {
  const surface = new Map<string, string[]>();
  // Each definitions() entry opens with the quoted route key and lists its
  // methods on the next line. The route regexes contain no quotes, so a
  // quoted-string capture is exact.
  const entry = /'(\/[^']*)' => \[\s*\n(?:\s*'schema'[^\n]*\n)?\s*'methods' => \[([^\]]+)\]/g;
  for (const match of routesPhp.matchAll(entry)) {
    const methods = [...match[2].matchAll(/'([A-Z]+)'/g)].map((m) =>
      m[1].toLowerCase(),
    );
    surface.set(toOpenapiPath(match[1]), methods);
  }
  return surface;
}

/** (openapi path) -> lowercase methods, from sheets/rest.php source. */
function sheetsSurface(): Map<string, string[]> {
  const surface = new Map<string, string[]>();
  // Each registration names the namespace, the route, and a single-method
  // string on the next line ('methods' => 'GET').
  const entry =
    /register_rest_route\('chronicler\/v1', '(\/[^']*)', \[\s*\n\s*'methods' => '([A-Z]+)'/g;
  for (const match of sheetsPhp.matchAll(entry)) {
    const path = toOpenapiPath(match[1]);
    surface.set(path, [...(surface.get(path) ?? []), match[2].toLowerCase()]);
  }
  return surface;
}

/** The union: every chronicler/v1 route the plugin registers. */
function phpSurface(): Map<string, string[]> {
  const surface = coreSurface();
  for (const [path, methods] of sheetsSurface()) {
    surface.set(path, [...(surface.get(path) ?? []), ...methods]);
  }
  return surface;
}

function specSurface(): Map<string, string[]> {
  const surface = new Map<string, string[]>();
  for (const [path, item] of Object.entries(spec.paths as Record<string, object>)) {
    surface.set(
      path,
      HTTP_METHODS.filter((m) => m in (item as Record<string, unknown>)),
    );
  }
  return surface;
}

describe("openapi.yaml stays honest against the plugin's registered routes", () => {
  const core = coreSurface();
  const sheets = sheetsSurface();
  const php = phpSurface();
  const yaml = specSurface();

  it("extraction found both route tables, whole", () => {
    // A regex that silently rots would shrink these. Instead of pinning a
    // count, compare each parser's yield against an independent structural
    // count in the same file: every definitions() entry carries exactly one
    // 'methods' array literal (the registration code reuses the key, but
    // only with variables), and sheets/rest.php has one register_rest_route
    // call per route (single-method routes, so no path collapses the count).
    expect(core.size).toBeGreaterThan(0);
    expect(core.size).toBe((routesPhp.match(/'methods' => \[/g) ?? []).length);
    expect(sheets.size).toBeGreaterThan(0);
    expect(sheets.size).toBe(
      (sheetsPhp.match(/register_rest_route\(/g) ?? []).length,
    );
    // The two tables must not overlap — a shared path would merge here and
    // hide a collision the router would happily serve.
    expect(php.size).toBe(core.size + sheets.size);
  });

  it("documents exactly the routes the plugin registers", () => {
    expect([...yaml.keys()].sort()).toEqual([...php.keys()].sort());
  });

  it("documents exactly each route's methods", () => {
    for (const [path, methods] of php) {
      expect(yaml.get(path)?.sort(), path).toEqual([...methods].sort());
    }
  });

  it("every operation declares a success response", () => {
    for (const [path, item] of Object.entries(spec.paths as Record<string, object>)) {
      for (const method of HTTP_METHODS) {
        const op = (item as Record<string, { responses?: Record<string, unknown> }>)[method];
        if (!op) continue;
        const codes = Object.keys(op.responses ?? {});
        expect(
          codes.some((c) => c.startsWith("2") || c.startsWith("3")),
          `${method.toUpperCase()} ${path}`,
        ).toBe(true);
      }
    }
  });

  it("every POST/PUT documents its request body", () => {
    for (const [path, item] of Object.entries(spec.paths as Record<string, object>)) {
      for (const method of ["post", "put"] as const) {
        const op = (item as Record<string, { requestBody?: unknown }>)[method];
        if (!op) continue;
        expect(op.requestBody, `${method.toUpperCase()} ${path}`).toBeDefined();
      }
    }
  });

  it("every $ref resolves to a defined component", () => {
    const defined = new Set<string>();
    for (const [kind, entries] of Object.entries(
      spec.components as Record<string, Record<string, unknown>>,
    )) {
      for (const name of Object.keys(entries)) defined.add(`#/components/${kind}/${name}`);
    }
    const refs = readFileSync(join(ROOT, "openapi.yaml"), "utf8").matchAll(
      /\$ref: "(#\/components\/[^"]+)"/g,
    );
    for (const [, ref] of refs) {
      expect(defined.has(ref), ref).toBe(true);
    }
  });

  it("names the auth scheme and the versioning policy", () => {
    expect(spec.components.securitySchemes.basicAuth.scheme).toBe("basic");
    expect(spec.info.description).toContain("additive only");
    expect(spec.info.description).toContain("chronicler_compose");
  });
});
