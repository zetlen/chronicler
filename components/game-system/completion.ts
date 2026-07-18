// The Game System editor's completion source: the schema-driven library
// completion (keys, enum values, snippets) everywhere, replaced with
// purpose-built completions in the places the schema can't know about —
// formula expressions, cross-references, and per-type documentation.
import type {
  Completion,
  CompletionContext,
  CompletionResult,
  CompletionSource,
} from "@codemirror/autocomplete";
import schema from "@/wordpress-plugin/sheets/template.schema.json";
import {
  FORMULA_FUNCTIONS,
  FORMULA_REF_TYPES,
  formulaParts,
} from "./formulaLang";
import { renderMarkdown } from "./markdown-lite";
import { ENTRY_REF_TYPES } from "./templateLint";
import {
  templateOutline,
  valueContextAt,
  type DeclaredProperty,
} from "./yamlContext";

/**
 * Lazy doc panel for a completion. renderMarkdown HTML-escapes its whole
 * input before adding its own <code>/<strong>/<em> tags, so assigning it to
 * innerHTML can't inject markup even if a doc string were hostile.
 */
const docInfo = (markdown: string) => (): HTMLElement => {
  const dom = document.createElement("div");
  dom.innerHTML = renderMarkdown(markdown, false);
  return dom;
};

interface TypeEnum {
  enum: string[];
  markdownEnumDescriptions: string[];
}

const definitions = (
  schema as unknown as {
    definitions: {
      property: { properties: { type: TypeEnum } };
      listField: { properties: { type: TypeEnum } };
    };
  }
).definitions;

const typeOptions = (kind: "property" | "listField"): Completion[] => {
  const { enum: values, markdownEnumDescriptions: docs } =
    definitions[kind].properties.type;
  return values.map((value, index) => ({
    label: value,
    type: "constant",
    info: docInfo(docs[index] ?? ""),
  }));
};

const PROPERTY_TYPE_OPTIONS = typeOptions("property");
const LIST_FIELD_TYPE_OPTIONS = typeOptions("listField");

const functionOptions = (): Completion[] =>
  FORMULA_FUNCTIONS.map((fn) => ({
    label: fn.name,
    detail: `(${fn.args})`,
    type: "function" as const,
    apply: `${fn.name}(`,
    info: docInfo(fn.doc),
  }));

const formulaOptions = (declared: DeclaredProperty[]): Completion[] => {
  const options: Completion[] = [];
  for (const property of declared) {
    if (!FORMULA_REF_TYPES.includes(property.type)) {
      continue;
    }
    const doc = `**${property.label}** — ${property.type}`;
    if (property.type === "track" || property.type === "counter") {
      for (const part of formulaParts(property)) {
        options.push({
          label: `${property.id}["${part}"]`,
          type: "variable",
          boost: 1,
          info: docInfo(
            `${doc}. The ${part === "max" ? "highest possible" : "current"} value.`,
          ),
        });
      }
    } else {
      options.push({
        label: property.id,
        type: "variable",
        boost: 1,
        info: docInfo(doc),
      });
    }
  }
  return [...options, ...functionOptions()];
};

const referenceOptions = (declared: DeclaredProperty[]): Completion[] =>
  declared.map((property) => ({
    label: property.id,
    detail: property.type,
    type: "variable",
    info: docInfo(`**${property.label}** — ${property.type}`),
  }));

const endsWith = (path: string[], ...tail: string[]): boolean =>
  tail.length <= path.length &&
  tail.every((key, i) => path[path.length - tail.length + i] === key);

/**
 * Wraps the schema-driven library completion with the editor's own sources.
 * The library source is injected so unit tests (and the build's markdown
 * stub) stay decoupled from codemirror-json-schema's import graph.
 */
export function gameSystemCompletion(library: CompletionSource): CompletionSource {
  return (context: CompletionContext): CompletionResult | ReturnType<CompletionSource> => {
    const valueContext = valueContextAt(context.state, context.pos);
    if (valueContext === null) {
      return library(context);
    }
    const { path } = valueContext;
    // The word check runs BEFORE any option building: most keystrokes in a
    // custom context (spaces, operators, brackets) bail here without paying
    // for a document parse.
    const custom = (build: () => Completion[]): CompletionResult | null => {
      const word = context.matchBefore(/[A-Za-z0-9_]*/);
      if (word === null || (word.text === "" && !context.explicit)) {
        return null;
      }
      return {
        from: word.from,
        options: build(),
        validFor: /^[A-Za-z0-9_]*$/,
      };
    };
    const outline = () => templateOutline(context.state.doc.toString());

    if (endsWith(path, "properties", "derived")) {
      return custom(() => formulaOptions(outline().properties));
    }
    if (endsWith(path, "type") && path.includes("properties")) {
      return custom(() =>
        path.includes("fields") ? LIST_FIELD_TYPE_OPTIONS : PROPERTY_TYPE_OPTIONS,
      );
    }
    if (endsWith(path, "layout", "properties")) {
      return custom(() => {
        const { properties, placed } = outline();
        return referenceOptions(properties.filter((p) => !placed.has(p.id)));
      });
    }
    if (endsWith(path, "fields", "when")) {
      return custom(() => {
        // Which field's `when` is being edited isn't knowable here: fields
        // carry no range (only the enclosing list property does), so the
        // field being typed can't be excluded from its own sibling list.
        // Offering it anyway is harmless — the lint (Task 5) immediately
        // flags a self-reference in a field's own `when`.
        const enclosing = outline().properties.find(
          (p) => context.pos >= p.range[0] && context.pos <= p.range[1],
        );
        const fieldOptions: Completion[] = (enclosing?.fields ?? [])
          .filter((field) => ENTRY_REF_TYPES.includes(field.type))
          .map((field) => ({
            label: field.id,
            detail: field.type,
            type: "variable" as const,
            info: docInfo(
              `**${field.label}** — show this field only while the expression is true.`,
            ),
          }));
        return [...fieldOptions, ...functionOptions()];
      });
    }
    return library(context);
  };
}

