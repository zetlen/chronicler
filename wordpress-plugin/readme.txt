=== Chronicler ===
Contributors: zetlen
Tags: slack, rpg, transcripts, character-sheets, tabletop
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 4.16.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Chronicles Slack RPG sessions — a wp-admin session editor, transcripts as Gutenberg blocks, and schema-driven character sheets.

== Description ==

Chronicler turns the Slack channels where your RPG group actually plays into durable WordPress content:

* **Session editor** — a wp-admin workspace that pulls a channel's history through your Slack bot token, applies transcription rules, and lets you shape the raw messages into a session log before publishing.
* **Transcripts as blocks** — published sessions become Gutenberg block markup (a `chronicler/message` block family) with light/dark/custom schemes, so logs render natively in any theme.
* **Character sheets** — a schema-driven sheet system: describe your game system once in YAML or JSON (properties, tracks, derived-stat formulas, layout) and every character gets a stat block computed from it. Player and Game Master roles ship in the box; GM-only and owner-only fields keep secrets secret.
* **Image mirroring** — message images and avatars are mirrored into the Media Library so transcripts stand on their own, with a daily eviction sweep for unused mirrors.
* **Slack user mapping** — link WordPress accounts to Slack members so characters and logs attribute correctly.

Newer WordPress features are used where available (block-template rendering on block themes, for example) and guarded where not — the plugin degrades per-feature rather than demanding a minimum beyond the declared floor.

== Installation ==

1. Upload the `chronicler` folder to `/wp-content/plugins/`, or upload the release zip through **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Open **Chronicler → Settings** and add your Slack bot token (or define `CHRONICLER_SLACK_BOT_TOKEN` in wp-config.php).
4. Optionally visit **Chronicler → Game System** to describe your game's character sheet, and create characters under **Characters**.

Uninstalling removes the plugin's data: the sessions table, options, roles and capabilities, characters/templates/rules, unused mirrored attachments, and Slack user mappings — on every site of a multisite network. Media you uploaded yourself and images that published transcripts use stay in the Media Library, so surviving content keeps working.

== Frequently Asked Questions ==

= Does this post to Slack? =

No. Chronicler only reads through the bot token. The token is sent exclusively to Slack-operated hosts.

= What PHP and WordPress does it need? =

PHP 8.2+ and WordPress 6.2+. Features that need newer WordPress (block-theme template registration, WP 6.7+) are detected at runtime and skipped gracefully.

= Where is data stored? =

Sessions live in a dedicated `chronicler_sessions` table; rules and the game-system template are custom post types with their config in post meta; characters are a public post type. Uninstalling removes all of it — except media you uploaded yourself and images that published transcripts still use, which stay in the Media Library.

== Changelog ==

= 4.16.0 =
* Uninstall cleanup: removes the sessions table, options, roles and capabilities (including documented per-user grants), plugin post types in every status, unused Slack mirrors, Slack user mappings, the eviction cron, cached data, and stale rewrite rules — per site on multisite. Media you uploaded and images used by published transcripts are kept.
* wordpress.org packaging: this readme, plugin headers, and license alignment.
* Player and Game Master roles can use the media modal on character sheets; players' library view is scoped to their own uploads.
* Game-system template storage moved to post meta, hardened against content filters, failed writes, and plugin rollbacks.

= 4.15.0 =
See the project repository for the full release history.
