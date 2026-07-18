# Chronicler

A WordPress plugin that chronicles Slack RPG sessions. It adds a session
editor to wp-admin: pick a channel and a time window, fetch every message in
it (threads included) straight from Slack in your browser, curate the result,
and generate a transcript as native Gutenberg blocks — styled like Slack,
editable like any other post content. It also provides schema-driven character
sheets as their own post type.

## Features

- **Session editor** (wp-admin → Chronicler): list saved sessions, create a
  new one from a channel + `datetime-local` range, fetch messages (paginated,
  with full thread replies), preview the transcript, prune and refresh.
  Fetches happen browser-side through the plugin's Slack proxy, so the bot
  token never leaves the server and never reaches Slack from your browser
  directly.
- **Transcripts as Gutenberg blocks**: generation produces
  `chronicler/transcript` / `thread` / `replies` / `message` blocks with
  field-level editable attributes. Rendered markup is identical on the front
  end and in the editor, each post carries its own styles forever, and
  message images are mirrored into the Media Library at generation time.
- **Rules** (Chronicler → Rules): regex rules that tag matching messages with
  CSS classes, managed as a WordPress CPT with drafts and a live match count.
- **Character sheets** (Characters menu): a schema-driven sheet template,
  per-player character posts with a masthead and stat block, live front-end
  editing of tracks/numbers through the REST API.
- Slack `mrkdwn` is parsed by the maintained [`slack-markdown`][sm] library;
  rendered HTML is sanitized with [`dompurify`][dp] before it is stored or
  displayed.

## Install

1. Build `chronicler.zip` with `npm run build:plugin`. Upload it in
   wp-admin → Plugins → Add New → Upload Plugin. Activate it.
2. Create the Slack app, as follows.

### Create a Slack app

Chronicler walks you through this in wp-admin. After activating the plugin,
go to **Chronicler → Settings**: the page shows the Slack app manifest with a
**Copy** button and a numbered, non-technical walkthrough of Slack's
_Create New App → From a manifest_ flow, then the field where you paste the bot
token.

The scopes the manifest requests:

| Scope              | Why                                          |
| ------------------ | -------------------------------------------- |
| `channels:history` | read public channel messages                 |
| `groups:history`   | read private channel messages                |
| `channels:read`    | list public channels                         |
| `groups:read`      | list private channels                        |
| `users:read`       | resolve user IDs to display names            |
| `emoji:read`       | render workspace custom emoji in transcripts |
| `files:read`       | read image bytes for the image mirror        |

The bot must be **a member of any channel** you want to read
(`/invite @yourbot`).

## Development

Needs **Node ≥ 22.18** (the build imports `.ts` from `.mjs` and relies on
Node's native TypeScript type-stripping; `.node-version` pins the exact
runtime for fnm/asdf/nodenv). Copy `.env.example` to `.env` for the optional
deploy and verify knobs.

```bash
npm install
npm run build:admin              # bundle the wp-admin session editor
WP_ENV_PORT=8890 npx @wordpress/env start   # local WordPress with the plugin mounted
```

wp-env (requires Docker) serves WordPress on the chosen port with
`wordpress-plugin/` mounted live from source; log in at `/wp-admin` as
`admin` / `password`. Re-run `npm run build:admin` after editing the editor
source — the bundle is gitignored, not rebuilt on the fly.

```bash
npm test          # TypeScript suite (vitest): transform, grammar, parity fixtures
npm run test:php  # the plugin's dependency-free PHP harness (Docker php:8.3-cli)
npm run lint      # eslint
npm run build:plugin   # build ./chronicler.zip (runs build:admin + composer install)
```

### Repo layout

```
wordpress-plugin/        the product — one omakase plugin, slug `chronicler`
  chronicler.php         loader: Version: header (the canonical version literal;
                         readme.txt's Stable tag is derived from it by npm run bump)
  blocks.php             transcript block registration + render callbacks
  message-render.php     pure message renderer (parity-tested against TS)
  generate/              ES5 generation core shared with the editor
  sheets/                character sheets module
  src/                   Chronicler\ PSR-4 classes (admin page, REST, Slack proxy…)
  tests/                 dependency-free PHP harness (tests/run.php)
components/admin/        session editor source (React), bundled by build:admin
lib/transform/           message transformer: classify → renderers → HTML
lib/wordpress/           block grammar + renderers shared with the plugin, zip packer
scripts/                 build:admin, build:plugin, test:php
```

The wp-admin bundle is built by esbuild into `wordpress-plugin/admin/dist/`
(gitignored). The build asserts two invariants: no `next/*` or `app/` module
may enter the JS module graph, and every CSS selector must stay scoped under
`#chronicler-admin-root`.

### Customizing how messages are rendered

Two cooperating pieces form a switch/strategy structure:

- `lib/transform/classify.ts` maps a raw Slack message to one `MessageKind`
  (`text`, `reply`, `bot_message`, `bot_reply`, `image`, `file`, `link`,
  `system`).
- `lib/transform/renderers/` holds one pure renderer per kind, registered in
  `renderers/index.ts`; shared chrome lives in `shared.ts`, the scoped
  stylesheet in `styles.ts`.

The PHP side (`message-render.php`, `generate/`) is a port of the same logic;
cross-language parity fixtures in `lib/wordpress/` and
`wordpress-plugin/tests/` keep the two byte-for-byte identical. If you change
rendering, change both sides and let the fixtures tell you when they agree.

### Plugin versioning

The `Version:` header in `wordpress-plugin/chronicler.php` is the repo's
canonical version literal; `CHRONICLER_VERSION` is derived from it at runtime
via `get_file_data()` — never edit the constant separately. The wp.org
`Stable tag` in `readme.txt` is derived too (rewritten by `npm run bump`,
guarded against drift by `npm run check:version`). Any change under
`wordpress-plugin/` must bump the header (`npm run bump patch` for a fix,
`npm run bump minor` for a feature), then rebuild the zip with
`npm run build:plugin`. A pre-commit hook enforces the bump. See AGENTS.md
for the full rationale.

## Notes & limitations

- **Images** require the bot token, so the editor previews them through the
  plugin's authenticated proxy and generation mirrors each one — message
  images and avatars alike — into the Media Library so posts stand on their
  own. The bot token is only ever sent to Slack-operated hosts.
- **Threads:** replies are clipped to the selected window, with notes marking
  clipped tails. Threads that started before the window are surfaced (parent
  included as context) when they have in-window replies.
- **mrkdwn:** Block Kit flattening handles section / header / context /
  rich_text / image / divider blocks; anything else renders a visible
  `[unsupported block]` placeholder rather than disappearing.
- **Emoji:** the full standard set renders via `slack-markdown`'s dataset;
  workspace custom emoji render as images via `emoji.list` (needs the
  `emoji:read` scope — reinstall the Slack app if it predates that scope).

[sm]: https://github.com/Sorunome/slack-markdown
[dp]: https://github.com/cure53/DOMPurify
