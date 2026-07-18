import type { SlackMessage } from "@/lib/transform/slackTypes";
import type { Directory } from "@/lib/transform/directory";
import type { RuleEffect } from "@/lib/transform/rules";

/**
 * The discrete message categories the transformer knows how to render.
 *
 * `classify()` maps a raw Slack message to exactly one of these, and the
 * renderer registry maps each one to a rendering strategy. To support a new
 * category: add it here, give it a branch in `classify()`, and register a
 * renderer in `renderers/index.ts`.
 */
export type MessageKind =
  | "system" // joins, leaves, topic/purpose changes, etc.
  | "bot_message" // a top-level message from a bot/integration
  | "bot_reply" // a bot message posted inside a thread
  | "reply" // a human message posted inside a thread
  | "image" // a message carrying one or more image files
  | "file" // a message carrying non-image file attachments
  | "link" // a message whose primary payload is a link/unfurl
  | "text"; // a plain human text message (the default)

/** How Slack-hosted (auth-required) images should be referenced in output. */
export type ImageMode =
  | "proxy" // route through /api/slack-image (works while the app is hosting it)
  | "link"; // link out to the Slack permalink (portable, but not inline)

export type Density = "comfortable" | "compact";

/**
 * The transcript's color scheme — an explicit per-channel choice, not a
 * follow-the-OS behavior. "light" and "dark" are canned presets. The two
 * custom schemes render the light base and leave theming to the channel's
 * custom CSS; they differ only in the starting palette seeded into the editor
 * ("custom-light" from the light tokens, "custom-dark" from the dark ones).
 */
export type TranscriptScheme = "light" | "dark" | "custom-light" | "custom-dark";

/** Everything a renderer needs that isn't on the message itself. */
export interface RenderContext {
  directory: Directory;
  imageMode: ImageMode;
  /**
   * URL prefix for proxied Slack-hosted images; the raw Slack URL is
   * URL-encoded and appended. Defaults to the Node app's streaming proxy
   * (`/api/slack-image?url=`); the wp-admin session editor points it at the
   * plugin's media mirror (`<rest base>/image?url=`, #103).
   */
  imageProxyBase?: string;
  workspaceUrl?: string;
  /** Display toggles, driven by the controls panel. */
  showAvatars: boolean;
  showTimestamps: boolean;
  showReactions: boolean;
  density: Density;
  /** Color scheme baked into the rendered markup ("dark" adds slk-dark; the
   *  custom schemes render the light base and theme via custom CSS). */
  scheme: TranscriptScheme;
  /** Message kinds to omit from the output (filters). */
  hiddenKinds: ReadonlySet<MessageKind>;
  /** Per-message regex-rule verdicts (hide / extra classes), keyed by ts. */
  ruleEffects?: ReadonlyMap<string, RuleEffect>;
  /** Workspace custom emoji (name → image URL) from Slack's emoji.list. */
  customEmoji: Record<string, string>;
}

/** A structured inline image figure, as carried by BubbleParts.images. */
export interface MessageImage {
  src: string;
  alt: string;
  caption?: string;
}

/**
 * The bubble's chrome, split into independent pieces so the block publisher
 * can serialize them as v3 attributes. `composeBubble()` reproduces the
 * classic flat markup from these parts.
 */
export interface BubbleParts {
  kind: "bubble";
  /** e.g. "slk-msg slk-msg--text" */
  rootClass: string;
  /** deep-link anchor (#msg-<ts>); composeBubble emits id only when non-empty */
  anchorId: string;
  /** plain text */
  authorName: string;
  /** hex */
  authorColor: string;
  /** hex */
  authorColorDark: string;
  /** opaque chrome ('' when avatars off) */
  avatarHtml: string;
  /** badge + timestamp spans ('' when both off) */
  headHtml: string;
  /** RichText-safe text html */
  bodyHtml: string;
  images: MessageImage[];
  /** file cards + attachments (end of body) */
  extrasHtml: string;
  /** reactions row ('' when off) */
  reactionsHtml: string;
  /** Editor-applied visual treatments (e.g. ["ooc"]); rendered as
   *  `slk-msg--<v>` classes. Empty/undefined for app-published transcripts. */
  variants?: string[];
  /** The author's real (un-renamed) Slack name; shown in the author span when
   *  `ooc` is active. Omitted by the publisher when it equals authorName. */
  realName?: string;
}

/** A message rendered as an opaque flat HTML string (no structured parts). */
export interface RawParts {
  kind: "raw";
  rawHtml: string;
}

export type MessageParts = BubbleParts | RawParts;

/** A rendering strategy: pure message -> structured parts. */
export type Renderer = (message: SlackMessage, ctx: RenderContext) => MessageParts;
