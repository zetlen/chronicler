/**
 * SessionProcessor — the single authoritative transcription engine (#3).
 *
 * One object turns raw session data + transcription rules + display options
 * into a structured, renderer-ready intermediate (`ProcessedSession`). Two
 * thin, tree-shakeable serializers live beside it — `toPreviewHtml` (the
 * editor preview) and `toMessageAttributes` (the transcript block's stored
 * attributes) — so BOTH surfaces derive from the same rule application and
 * can never drift. Nothing here is React- or WordPress-specific: the caller
 * resolves its own rules (WpRule → RegexRule) and display toggles, so the
 * same engine bundles into the admin app and the block editor alike.
 *
 * Why a facade over lib/transform rather than calling it directly: the
 * RenderContext assembly (applyRules → ruleEffects, createDirectory, the
 * display toggles) previously lived inline in SessionEditor, which is exactly
 * why a rule change could be saved without ever re-filtering the transcript.
 * Centralizing it means "apply the rules" is one call, shared by preview and
 * generation.
 *
 * `process()` caches the rule outcome and re-runs applyRules only when the
 * rules themselves change: an options-only `update()` (toggling avatars,
 * switching scheme) reuses the prior match pass, since rule verdicts depend
 * on the messages and rules, never on display options.
 */

import type { NameMaps, UserOverride } from "@/lib/transform/directory";
import { createDirectory } from "@/lib/transform/directory";
import {
  applyRules,
  EMPTY_RULE_OUTCOME,
  type RegexRule,
  type RuleOutcome,
} from "@/lib/transform/rules";
import type { ThreadedMessage } from "@/lib/transform/slackTypes";
import type {
  Density,
  ImageMode,
  MessageKind,
  RenderContext,
  TranscriptScheme,
} from "@/lib/transform/types";

/**
 * The raw material a transcript is rendered from — the render-relevant subset
 * of the editor's fetched SessionRawData (which additionally carries fetch
 * progress/telemetry the engine ignores). A stored session's persisted `raw`
 * satisfies this shape, so a reopened session rehydrates straight into it.
 */
export interface SessionSource {
  threads: ThreadedMessage[];
  names: NameMaps;
  /** Workspace custom emoji (name → image URL); absent is treated as none. */
  customEmoji?: Record<string, string>;
}

/**
 * Display/render choices the caller owns — everything a RenderContext needs
 * that isn't derived from the source or the rules. Kept host-agnostic: the
 * WordPress editor maps its SessionControls into `hiddenKinds` and points
 * `imageProxyBase` at the media mirror; another host could differ.
 */
export interface SessionRenderOptions {
  scheme: TranscriptScheme;
  density: Density;
  showAvatars: boolean;
  showTimestamps: boolean;
  showReactions: boolean;
  hiddenKinds: ReadonlySet<MessageKind>;
  userOverrides: Record<string, UserOverride>;
  imageMode: ImageMode;
  imageProxyBase?: string;
  workspaceUrl?: string;
}

/**
 * The intermediate the serializers consume: the thread tree plus the fully
 * assembled RenderContext (rule effects baked in) and the raw rule outcome
 * (match counts / proposed tags) the editor's rules panel reads. Both
 * serializers are pure functions of this — no further rule logic downstream.
 */
export interface ProcessedSession {
  threads: ThreadedMessage[];
  ctx: RenderContext;
  outcome: RuleOutcome;
}

function buildRenderContext(
  source: SessionSource,
  options: SessionRenderOptions,
  outcome: RuleOutcome,
): RenderContext {
  return {
    directory: createDirectory(source.names, options.userOverrides),
    imageMode: options.imageMode,
    imageProxyBase: options.imageProxyBase,
    workspaceUrl: options.workspaceUrl,
    showAvatars: options.showAvatars,
    showTimestamps: options.showTimestamps,
    showReactions: options.showReactions,
    density: options.density,
    scheme: options.scheme,
    hiddenKinds: options.hiddenKinds,
    ruleEffects: outcome.effects,
    customEmoji: source.customEmoji ?? {},
  };
}

export class SessionProcessor {
  private readonly source: SessionSource;
  private rules: readonly RegexRule[];
  private options: SessionRenderOptions;
  /** Memoized applyRules result; invalidated only when `rules` change. */
  private cachedOutcome: RuleOutcome | null = null;

  private constructor(
    source: SessionSource,
    rules: readonly RegexRule[],
    options: SessionRenderOptions,
  ) {
    this.source = source;
    this.rules = rules;
    this.options = options;
  }

  /** Create a processor over immutable source data with initial rules/opts. */
  static init(
    source: SessionSource,
    rules: readonly RegexRule[],
    options: SessionRenderOptions,
  ): SessionProcessor {
    return new SessionProcessor(source, rules, options);
  }

  /**
   * Replace the rules and/or display options (the source is fixed for the
   * processor's life — a genuine re-fetch makes a new one). Re-running the
   * match pass is skipped when the rules reference is unchanged; pass the same
   * array to signal an options-only edit.
   */
  update(rules: readonly RegexRule[], options: SessionRenderOptions): this {
    if (rules !== this.rules) {
      this.rules = rules;
      this.cachedOutcome = null;
    }
    this.options = options;
    return this;
  }

  /** The current rule outcome (match counts / tags), computed once per rule set. */
  private outcome(): RuleOutcome {
    if (this.cachedOutcome === null) {
      this.cachedOutcome =
        this.rules.length === 0
          ? EMPTY_RULE_OUTCOME
          : applyRules(this.source.threads, this.rules);
    }
    return this.cachedOutcome;
  }

  /** Apply the current rules + options and return the renderer-ready IR. */
  process(): ProcessedSession {
    const outcome = this.outcome();
    return {
      threads: this.source.threads,
      ctx: buildRenderContext(this.source, this.options, outcome),
      outcome,
    };
  }
}
