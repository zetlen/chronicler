/**
 * The WordPress-specific mapping into the host-agnostic SessionProcessor
 * inputs (#3): how a stored WpRule becomes a RegexRule, and how a session's
 * editorState becomes SessionRenderOptions. Shared by the React session editor
 * AND the block-editor generation engine so both resolve rules and display
 * options IDENTICALLY — the whole point of one authoritative transcription
 * pass is defeated if the two surfaces map their inputs differently.
 */

import type { MessageKind } from "@/lib/transform/types";
import type { RegexRule } from "@/lib/transform/rules";
import type { SessionRenderOptions } from "@/lib/session/SessionProcessor";
import {
  DEFAULT_SESSION_CONTROLS,
  type SessionControls,
  type SessionEditorState,
  type WpRule,
} from "@/components/admin/sessionApi";

/** A stored rule is always live once attached; blank/invalid patterns are
 *  inert in applyRules, exactly as in the app. */
export function toRegexRule(rule: WpRule): RegexRule {
  return {
    id: String(rule.id),
    pattern: rule.pattern,
    flags: rule.flags,
    mode: rule.mode,
    className: rule.className,
    tagNames: rule.tagNames,
    treatments: rule.treatments,
    enabled: true,
  };
}

/** The session's attached rules resolved to RegexRules, in attachment order;
 *  ids with no matching rule (deleted since) drop out. */
export function resolveRegexRules(
  allRules: WpRule[],
  ruleIds: number[],
): RegexRule[] {
  const byId = new Map(allRules.map((r) => [r.id, r]));
  return ruleIds.flatMap((id) => {
    const rule = byId.get(id);
    return rule ? [toRegexRule(rule)] : [];
  });
}

/** The display toggles that omit whole message kinds (filters panel). */
export function hiddenKindsFor(controls: SessionControls): Set<MessageKind> {
  const hidden = new Set<MessageKind>();
  if (controls.hideSystem) hidden.add("system");
  if (controls.hideBots) {
    hidden.add("bot_message");
    hidden.add("bot_reply");
  }
  return hidden;
}

/**
 * A session's editorState → SessionRenderOptions. Density is always
 * "comfortable" and images always route through the mirror proxy (the caller
 * supplies its base — the editor's boot value, or the block editor's localized
 * one); unset controls fall back to DEFAULT_SESSION_CONTROLS.
 */
export function sessionRenderOptions(
  editorState: SessionEditorState,
  opts: { imageProxyBase?: string },
): SessionRenderOptions {
  const controls = { ...DEFAULT_SESSION_CONTROLS, ...editorState.controls };
  return {
    scheme: editorState.scheme ?? "light",
    density: "comfortable",
    showAvatars: controls.showAvatars,
    showTimestamps: controls.showTimestamps,
    showReactions: controls.showReactions,
    hiddenKinds: hiddenKindsFor(controls),
    userOverrides: editorState.userOverrides ?? {},
    imageMode: "proxy",
    imageProxyBase: opts.imageProxyBase,
  };
}
