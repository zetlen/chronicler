/**
 * Self-contained CSS for the rendered transcript. Every selector is scoped
 * under `.slack-log` so it is safe to (a) inject once into the preview pane and
 * (b) embed in published drafts without leaking styles into the host page.
 *
 * It styles both our own `slk-*` classes and the `s-*` classes emitted by the
 * `slack-markdown` library (mentions, emoji).
 *
 * Appearance is driven by the token tables below — colors, typography, and
 * layout dimensions — declared as custom properties on `.slack-log`, so
 * re-theming means overriding variables (from the custom-CSS panel, a
 * WordPress stylesheet, anywhere). The tables are the single source of truth:
 * the stylesheet and `customCssTemplate()` (the Custom-scheme starting point)
 * are both generated from them, and transform.test.ts holds them together.
 *
 * The scheme is an explicit per-channel choice baked into the markup, never a
 * follow-the-viewer's-OS behavior: the light base paints NO background (it
 * assumes a light host page — a blog post, an email); the `.slack-log.slk-dark`
 * preset repaints every color token including its own dark background, since
 * its host page is still presumably light.
 *
 * Identity colors arrive per message as `--slk-id`/`--slk-id-dark` inline on
 * `.slk-msg` (see shared.ts); `--slk-id-active` picks the one for the scheme.
 */

/** Every supported token, at its light default. */
export const LIGHT_TOKENS: Record<string, string> = {
  /* Colors */
  "--slk-fg": "#1d1c1d",
  "--slk-border": "#e6e6e6",
  "--slk-muted": "#6b6b76",
  "--slk-accent": "#1264a3",
  "--slk-hover": "#f8f8f8",
  "--slk-badge-bg": "#ededed",
  "--slk-code-bg": "#f5f3f0",
  "--slk-code-border": "#e8e3dc",
  "--slk-code-fg": "#c0392b",
  "--slk-quote-border": "#c8c8c8",
  "--slk-quote-fg": "#3a3a3a",
  "--slk-mention-bg": "#e8f2fb",
  "--slk-special-fg": "#9a6700",
  "--slk-special-bg": "#fff4d6",
  "--slk-card-bg": "#fcfcfc",
  "--slk-reaction-bg": "#f4f6f8",
  "--slk-bot-accent": "#ecb22e",
  "--slk-initials": "#ffffff",
  /* Typography */
  "--slk-font":
    '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
  "--slk-mono": "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace",
  "--slk-font-size": "17px",
  "--slk-line-height": "1.46",
  /* Layout */
  "--slk-radius": "8px",
  "--slk-avatar-size": "36px",
  "--slk-thread-indent": "26px",
  "--slk-image-max": "320px",
  "--slk-card-max": "520px",
};

/** The dark preset: color overrides only — typography and layout carry over. */
export const DARK_TOKENS: Record<string, string> = {
  "--slk-fg": "#d1d2d3",
  "--slk-border": "#35373b",
  "--slk-muted": "#9a9ca1",
  "--slk-accent": "#4c9fd6",
  "--slk-hover": "#222529",
  "--slk-badge-bg": "#2f3136",
  "--slk-code-bg": "#232529",
  "--slk-code-border": "#3a3d42",
  "--slk-code-fg": "#e8912d",
  "--slk-quote-border": "#4a4d52",
  "--slk-quote-fg": "#b8b9bb",
  "--slk-mention-bg": "#1f3347",
  "--slk-special-fg": "#e2b03e",
  "--slk-special-bg": "#3a2f14",
  "--slk-card-bg": "#202327",
  "--slk-reaction-bg": "#26292d",
  "--slk-bot-accent": "#b58722",
  "--slk-initials": "#1a1d21",
};

const declarations = (tokens: Record<string, string>): string =>
  Object.entries(tokens)
    .map(([name, value]) => `  ${name}: ${value};`)
    .join("\n");

/**
 * The starting point dropped into an empty CSS editor when a Custom scheme is
 * chosen: every supported token at the given defaults, ready to tune.
 */
export function customCssTemplate(tokens: Record<string, string>): string {
  return `/* Custom scheme: every supported token.
   Tune freely — or target any .slack-log selector below the block.
   (Per-person identity colors are set in the Users list, not here.) */
.slack-log {
${declarations(tokens)}
}`;
}

/**
 * The seed CSS for a Custom scheme. "custom-light" starts from the light
 * tokens; "custom-dark" starts from the full dark palette and adds the one
 * rule that flips per-person identity colors to their dark variant — so a dark
 * transcript reads correctly on the light base without a scheme class (the
 * custom CSS travels with published posts; a class would not).
 */
export function customSchemeTemplate(dark: boolean): string {
  if (!dark) return customCssTemplate(LIGHT_TOKENS);
  return `${customCssTemplate({ ...LIGHT_TOKENS, ...DARK_TOKENS })}
/* Dark base: use each person's dark identity color (set in the Users list). */
.slack-log .slk-msg { --slk-id-active: var(--slk-id-dark); }`;
}

export const dialogueCss = `
.slack-log {
${declarations(LIGHT_TOKENS)}
  /* Chain-link glyph for the copy-link affordance; overridable via custom CSS. */
  --slk-permalink-ico: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/%3E%3Cpath d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/%3E%3C/svg%3E");
  font-family: var(--slk-font);
  font-size: var(--slk-font-size);
  line-height: var(--slk-line-height);
  color: var(--slk-fg);
  padding: 8px 4px;
}
.slack-log.slk-dark {
${declarations(DARK_TOKENS)}
}
.slack-log * { box-sizing: border-box; }

/* The active identity color must be derived ON .slk-msg: a custom property
   that references another resolves where it is declared, and --slk-id /
   --slk-id-dark only exist as inline style on each message root. */
.slack-log .slk-msg { --slk-id-active: var(--slk-id); }
.slack-log.slk-dark .slk-msg { --slk-id-active: var(--slk-id-dark); }

.slack-log .slk-msg {
  display: flex;
  gap: 8px;
  padding: 8px 8px;
  border-radius: var(--slk-radius);
}
.slack-log .slk-msg + .slk-msg { margin-top: 2px; }
.slack-log .slk-msg:hover { background: var(--slk-hover); }

/* A deep-linked message (URL #msg-... fragment) is spotlighted so the reader
   lands on the right line; scheme tokens keep it legible in light and dark. */
.slack-log .slk-msg:target {
  background: var(--slk-mention-bg);
  box-shadow: inset 3px 0 0 var(--slk-accent);
  scroll-margin-top: 8px;
}

/* Per-message visual variants (editor-applied; lib/transform/variants.ts).
   Opacity fades OOC in both schemes; --slk-accent carries the light/dark hue. */
.slack-log .slk-msg--important { box-shadow: inset 3px 0 0 var(--slk-accent); }
.slack-log .slk-msg--important .slk-msg__body { font-weight: 500; }
.slack-log .slk-msg--ooc { opacity: 0.6; }
.slack-log .slk-msg--ooc .slk-msg__author { font-style: italic; font-weight: 600; }

.slack-log .slk-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--slk-avatar-size);
  height: var(--slk-avatar-size);
  border-radius: var(--slk-radius);
  background: var(--slk-id-active);
  color: var(--slk-initials);
  font-size: 13px;
  font-weight: 700;
  flex: 0 0 auto;
  text-transform: uppercase;
  overflow: hidden;
}
.slack-log .slk-avatar__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  border-radius: inherit;
}
.slack-log .slk-msg__main { min-width: 0; flex: 1 1 auto; }
.slack-log .slk-msg__head {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 1px;
}
.slack-log .slk-msg__author { font-weight: 700; color: var(--slk-id-active, var(--slk-fg)); }
.slack-log .slk-msg__time { color: var(--slk-muted); font-size: 12px; }

/* Copy-link affordance, rendered in place of the timestamp when timestamps are
   hidden (see bubbleParts). The chain-link glyph is a CSS mask — kept out of the
   sanitized markup — tinted via currentColor so it tracks the scheme. It stays
   invisible until the message is hovered (pointer), focused (keyboard), or
   activated by a tap on touch (transcript.js sets .is-active). */
.slack-log .slk-msg__permalink {
  display: inline-flex;
  align-self: center;
  line-height: 0;
  color: var(--slk-muted);
  opacity: 0;
  transition: opacity .12s ease, color .12s ease;
  position: relative;
}
.slack-log .slk-msg__permalink::before {
  content: "";
  width: 0.8em;
  height: 0.8em;
  background-color: currentColor;
  -webkit-mask: var(--slk-permalink-ico) center / contain no-repeat;
  mask: var(--slk-permalink-ico) center / contain no-repeat;
}
.slack-log .slk-msg__permalink:hover { color: var(--slk-accent); }
.slack-log .slk-msg:hover .slk-msg__permalink,
.slack-log .slk-msg:focus-within .slk-msg__permalink { opacity: 1; }
@media (hover: none) {
  /* No hover on touch — reveal only on an explicit tap (.is-active). */
  .slack-log .slk-msg:hover .slk-msg__permalink { opacity: 0; }
  .slack-log .slk-msg.is-active .slk-msg__permalink { opacity: 1; }
}
/* Transient confirmation after a copy (transcript.js toggles .is-copied). */
.slack-log .slk-msg__permalink.is-copied {
  opacity: 1;
  color: var(--slk-accent);
}
.slack-log .slk-msg__permalink.is-copied::after {
  content: "Copied!";
  position: absolute;
  left: 50%;
  bottom: calc(100% + 4px);
  transform: translateX(-50%);
  white-space: nowrap;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
  padding: 1px 6px;
  border-radius: 4px;
  background: var(--slk-fg);
  color: var(--slk-card-bg);
  pointer-events: none;
}
.slack-log .slk-badge {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .03em;
  text-transform: uppercase;
  color: var(--slk-muted);
  background: var(--slk-badge-bg);
  border-radius: 3px;
  padding: 1px 4px;
}
.slack-log .slk-msg__body { word-wrap: break-word; overflow-wrap: anywhere; }
.slack-log .slk-msg__body p { margin: 0 0 6px; }

/* Bot messages get a subtle accent edge. */
.slack-log .slk-msg--bot { border-left: 3px solid var(--slk-bot-accent); }

/* Threaded replies are indented under their parent. */
.slack-log .slk-thread__replies {
  margin: 2px 0 6px var(--slk-thread-indent);
  padding-left: 14px;
  border-left: 2px solid var(--slk-border);
}

/* Time-window markers on threads. */
.slack-log .slk-thread__note {
  color: var(--slk-muted);
  font-size: 12px;
  font-style: italic;
  padding: 2px 0;
}
.slack-log .slk-thread--context > .slk-msg { opacity: .75; }

/* System / channel-event lines. */
.slack-log .slk-system {
  color: var(--slk-muted);
  font-size: 13px;
  text-align: center;
  padding: 4px 0;
}

/* Block Kit extras. */
.slack-log .slk-divider { border: none; border-top: 1px solid var(--slk-border); margin: 8px 0; }
.slack-log .slk-unsupported { color: var(--slk-muted); font-size: 12px; font-style: italic; }

/* Inline marks. */
.slack-log a, .slack-log .slk-link { color: var(--slk-accent); text-decoration: none; }
.slack-log a:hover, .slack-log .slk-link:hover { text-decoration: underline; }
.slack-log strong { font-weight: 700; }
.slack-log em { font-style: italic; }
.slack-log del { text-decoration: line-through; }
.slack-log code, .slack-log .slk-inline-code {
  font-family: var(--slk-mono);
  font-size: 12.5px;
  background: var(--slk-code-bg);
  border: 1px solid var(--slk-code-border);
  border-radius: 3px;
  padding: 1px 4px;
  color: var(--slk-code-fg);
}
.slack-log pre {
  font-family: var(--slk-mono);
  background: var(--slk-code-bg);
  border: 1px solid var(--slk-code-border);
  border-radius: 6px;
  padding: 8px 10px;
  overflow: auto;
  margin: 4px 0;
}
.slack-log pre code { background: none; border: none; padding: 0; color: inherit; }
.slack-log .slk-pre {
  display: block;
  font-family: var(--slk-mono);
  background: var(--slk-code-bg);
  border: 1px solid var(--slk-code-border);
  border-radius: 6px;
  padding: 8px 10px;
  overflow: auto;
  margin: 4px 0;
  color: inherit;
}
.slack-log blockquote, .slack-log .slk-quote {
  margin: 4px 0;
  padding-left: 10px;
  border-left: 3px solid var(--slk-quote-border);
  color: var(--slk-quote-fg);
}

/* Mentions & emoji (slk-* and slack-markdown s-*). */
.slack-log .slk-mention,
.slack-log .s-mention {
  color: var(--slk-accent);
  background: var(--slk-mention-bg);
  border-radius: 3px;
  padding: 0 2px;
  font-weight: 500;
}
.slack-log .slk-mention--special,
.slack-log .s-at-here,
.slack-log .s-at-channel,
.slack-log .s-at-everyone {
  color: var(--slk-special-fg);
  background: var(--slk-special-bg);
}
.slack-log .s-emoji { font-style: normal; }
.slack-log .slk-emoji {
  width: 1.3em;
  height: 1.3em;
  vertical-align: -0.25em;
  display: inline-block;
}
.slack-log .slk-reaction__emoji .slk-emoji { width: 16px; height: 16px; vertical-align: -3px; }

/* Images. */
.slack-log .slk-images { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
.slack-log .slk-image { margin: 0; max-width: var(--slk-image-max); }
.slack-log .slk-image img {
  display: block;
  max-width: 100%;
  height: auto;
  border-radius: var(--slk-radius);
  border: 1px solid var(--slk-border);
}
.slack-log .slk-image figcaption { color: var(--slk-muted); font-size: 12px; margin-top: 3px; }

/* File cards. */
.slack-log .slk-files { display: flex; flex-direction: column; gap: 6px; margin: 6px 0; }
.slack-log .slk-file {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: 360px;
  padding: 8px 10px;
  border: 1px solid var(--slk-border);
  border-radius: var(--slk-radius);
  color: var(--slk-fg);
}
.slack-log .slk-file:hover { background: var(--slk-hover); text-decoration: none; }
.slack-log .slk-file__icon { font-size: 18px; }
.slack-log .slk-file__name { font-weight: 600; }
.slack-log .slk-file__meta { color: var(--slk-muted); font-size: 12px; }

/* Reactions. */
.slack-log .slk-reactions { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.slack-log .slk-reaction {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 7px;
  border: 1px solid var(--slk-border);
  border-radius: 11px;
  background: var(--slk-reaction-bg);
  font-size: 12px;
  line-height: 18px;
}
.slack-log .slk-reaction__count { color: var(--slk-muted); font-weight: 600; }

/* Compact density: tighter padding and smaller avatars. */
.slack-log.slk-density-compact { --slk-avatar-size: 24px; --slk-thread-indent: 18px; }
.slack-log.slk-density-compact .slk-msg { padding: 2px 8px; }
.slack-log.slk-density-compact .slk-msg + .slk-msg { margin-top: 0; }
.slack-log.slk-density-compact .slk-avatar { border-radius: 5px; font-size: 11px; }

/* Attachment / unfurl cards. */
.slack-log .slk-card {
  border: 1px solid var(--slk-border);
  border-left: 4px solid var(--slk-border);
  border-radius: 6px;
  padding: 8px 12px;
  margin: 6px 0;
  max-width: var(--slk-card-max);
  background: var(--slk-card-bg);
}
.slack-log .slk-card__service { color: var(--slk-muted); font-size: 12px; margin-bottom: 2px; }
.slack-log .slk-card__author { font-size: 13px; font-weight: 600; margin-bottom: 2px; }
.slack-log .slk-card__title { font-weight: 700; display: inline-block; margin-bottom: 2px; }
.slack-log .slk-card__text { font-size: 14px; }
.slack-log .slk-card__fields { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 6px; }
.slack-log .slk-card__field-title { font-size: 12px; font-weight: 700; color: var(--slk-muted); }
.slack-log .slk-card__image { max-width: 100%; border-radius: 6px; margin-top: 6px; }
`.trim();
