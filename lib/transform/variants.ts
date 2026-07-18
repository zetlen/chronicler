/**
 * The message-variant vocabulary: per-message visual treatments applied in the
 * WordPress editor and rendered as `slk-msg--<v>` root classes. This module is
 * the single source of truth for the vocabulary and its stable order; both
 * composeBubble (TS) and chronicler_render_message (PHP) key off it, and
 * editor.js duplicates it under test (editorScript.test.ts enforces parity).
 */
export const MESSAGE_VARIANTS = ["ooc", "important"] as const;
export type MessageVariant = (typeof MESSAGE_VARIANTS)[number];

/**
 * The `slk-msg--<v>` classes for a message's variants, emitted in the fixed
 * vocabulary order regardless of input order (deterministic for parity), with
 * unknown entries dropped.
 */
export function variantClasses(variants: readonly string[]): string[] {
  return MESSAGE_VARIANTS.filter((v) => variants.includes(v)).map((v) => `slk-msg--${v}`);
}

/** Whether the "out of character" treatment is active. */
export function isOoc(variants: readonly string[]): boolean {
  return variants.includes("ooc");
}
