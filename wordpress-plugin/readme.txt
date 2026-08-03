=== Chronicler ===
Contributors: zetlen
Tags: slack, rpg, transcripts, character-sheets, tabletop
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 4.29.1
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

= 4.29.1 =
* The login arrival notice is now a toast: top center, out of the page flow, dismissed by a click or on its own after 10 seconds.

= 4.29.0 =
* Players land on their character sheet after logging in, with a one-time arrival notice. GMs, admins, and links to specific pages keep their normal destinations.

= 4.28.1 =
* Documentation: the Game System editor's help text for a dice field on a list now explains entry references — {entry["…"]} reaches the entry's own fields, like a weapon adding its own harm rating — and notes that an unresolvable reference errors clearly at roll time.

= 4.28.0 =
* Gear that knows its own numbers: dice on a list entry can now reference the entry's own fields with {entry["…"]} — write 1d8 + {entry["harm_rating"]} on a weapon and /game roll adds that weapon's harm rating, not a character stat. Entry fields live only behind the entry name, so a gear entry's "harm" never collides with the character's Harm track. A reference to a field the entry doesn't have, or one that isn't a number, comes back as a clear error instead of quietly rolling a 0.

= 4.27.0 =
* A move carries its own roll: a list on the character sheet (Moves, Gear) can declare a dice field, and any entry with dice written on it — "2d6 + {sharp}" on the move you already have — shows up in /game roll alongside the system's rolls, named after the entry. Gate the dice on the entry's "has it" toggle with when: and untaken moves stay quiet. The game system's rolls no longer need a per-character "when": that key is removed, and the GM never edits the system just because a player advanced. A dice string that doesn't parse simply offers no roll (the sheet editor flags it); nothing else on the sheet is affected.

= 4.26.0 =
* Slack bot: /game my <thing> shows any part of your linked character — one stat, a whole section, or the lot — and /game roll <name> rolls one of the rolls your game system declares, with your own modifiers already worked in and every die shown. Both read your sheet through a single new visibility gate, so game-master-only fields stay out of Slack no matter who asks.

= 4.25.0 =
* Slack bot skeleton: the /game slash command answers from this site (signature-verified inbound endpoints — the plugin's first, deliberately reversing the old outbound-only policy). Settings gains a Signing Secret field and a "Test inbound endpoint" check. The Slack app manifest now includes the command, message shortcuts, interactivity, and the commands and chat:write scopes — update your Slack app's manifest once after upgrading.
* Link your Slack account to your character yourself: run /game link <your character's name> in Slack and the bot replies with your Slack member ID and a link to your sheet, where a new "Slack member" box saves it. Because WordPress already limits sheet editing to a character's owner (and GMs), being able to open the sheet is the only permission check needed — there is nothing to approve or confirm.

= 4.24.0 =
* Draft templates: "Draft this session" can now start from an Adventure Log layout — a summary standfirst, a "Previously" recap, the session transcript, and Loot & Rewards / Next Time sections — picked from a new dropdown beside the button in the session editor. Both layouts (Adventure log, and the original minimal session log) are also registered as block patterns under a new "Chronicler" pattern category, so you can insert them into any post by hand.

= 4.23.1 =
* Character sheets: a game system's main attributes (number-type ratings like a Monster of the Week playbook's Charm/Cool/Sharp/Tough/Weird) once again read as headline stats. Each modifier is now a large boxed figure anchoring its row, with the label and the moves it rolls beside it, instead of a body-text number in a plain row. Applies to any game system's core stats out of the box, no per-system CSS needed.

= 4.23.0 =
* WordPress tags now apply automatically when you generate a session transcript: if an attached wp-tag rule matches the log, its tag is staged on the draft as soon as generation finishes, instead of waiting for the "Apply tags from session" sidebar button. That button stays for re-applying after you edit a rule. Like the transcript itself, the tags are staged in the editor — save the post to keep them.

= 4.22.2 =
* Transcription rule editor: the Treatment field is now a dropdown (None / Out of character / Important) instead of a free-text box. Previously the box showed a faint "ooc" placeholder that looked like a set value, so a Treatment rule saved with the field left blank silently applied no treatment. The dropdown makes the choice explicit; existing rules keep their treatment, and any legacy multi-value entry collapses to its first treatment.

= 4.22.1 =
* Character sheet text fields — including opinion Notes — now autosave as you type (#8). Previously they only saved when the field lost focus, so typing a note and then following a link, hitting Back, or closing the tab discarded the edit. Edits now persist shortly after you stop typing, and any still-pending change is flushed when you leave the page.

= 4.22.0 =
* Transcription rules now apply when you generate a draft (#3). Sessions persist their last fetched Slack data, so reopening a session rehydrates it: attaching, editing, or removing a rule (or a filter) immediately re-filters the stored messages with no re-fetch, and "Draft this session" flushes those changes before it navigates. Previously a rule added after the initial fetch was saved but never applied to the stored transcript, so the generated draft ignored it. "Refresh messages" remains the only action that re-contacts Slack.

= 4.21.0 =
* Per-PC opinion boxes on NPC pages (#183): a new `opinions` sheet-property type renders one rating-circles + notes set per player character on each NPC page, labeled with the PC's name. Each set is that player's private notebook — visible only to them and the GM, live-editable right on the page without wp-admin. GMs see and can edit every set, including from the Stat Block editor.

= 4.20.2 =
* Sheet editor polish from the 2026-07-14 playtest: field labels in the Stat Block editor no longer word-wrap, and the example Game System template's gear list now models the recommended shape — a "Description" field (instead of "Effect") and a Tags field on every item, not just weapons.

= 4.20.1 =
* The "Show OOC messages" checkbox on published transcripts now shows how many messages it affects — "(3 hidden)" flips to "(3 shown)" — and a tooltip explains that OOC means out-of-character table talk.
* The rules editors (the session editor's Transcription Rules panel and the standalone Rules screen) now warn when a pattern has no word boundaries or anchors, so a rule like "orin" no longer silently matches inside "Dorin". The warning is advisory; such rules still save and run.

= 4.20.0 =
* Transcription rules can now mark matching messages as out of character or important — the same treatments the block editor offers per message. An out-of-character match shows the author's real name in the byline and joins the transcript's "Show OOC messages" toggle, and the treatment carries through to the saved session and published transcript.

= 4.19.0 =
* The media library no longer collects duplicate copies of the same image: a Slack image already mirrored under a different URL now reuses the existing attachment instead of storing another copy, and a daily task fingerprints images mirrored before this release so they are recognized too.

= 4.18.0 =
* NPC is a real flag now: a checkbox on the character editor (replacing the old `npc` tag convention — recheck existing NPCs by hand). NPC pages show only the portrait, name, tagline, and intro; the stat block and "Played by" are hidden from everyone who can't edit the character, on the page and the public REST sheet alike. Editors still see the full sheet, with a note explaining what visitors see. The Active Character box is disabled for NPCs, and flagging a character NPC clears its active status.

= 4.17.0 =
* Session autosave is sturdier: saves never overlap (so a slow save can no longer overwrite a newer one), a failed save retries on its own with backoff instead of waiting for your next edit, a hung save times out instead of wedging autosave, and navigating away flushes pending edits.
* The session list is paginated — large installs load the newest 50 with a "Load more" button (which keeps your rows and offers a retry if a page fails) — and the REST route accepts standard page/per_page parameters. The transcript-generation session picker pages through everything.
* REST calls from the session editor now work on plain-permalink (?rest_route=) installs when they carry query parameters.
* Transcript blocks moved to Block API v3, ready for WordPress's iframed block editor.
* Editor scripts now load only on post, page, and reusable-block editors instead of every block-editor screen (filterable via chronicler_editor_post_types); the generation sidebar loads on post editors only.
* Deactivating the plugin now clears its rewrite rules (network-wide deactivation clears every site), so the /characters URLs no longer linger for other content to trip over.
* The Plugins screen and Chronicler → Settings now warn that deleting the plugin removes its sessions and character sheets (published transcripts and uploaded media are kept).

= 4.16.0 =
* Uninstall cleanup: removes the sessions table, options, roles and capabilities (including documented per-user grants), plugin post types in every status, unused Slack mirrors, Slack user mappings, the eviction cron, cached data, and stale rewrite rules — per site on multisite. Media you uploaded and images used by published transcripts are kept.
* wordpress.org packaging: this readme, plugin headers, and license alignment.
* Player and Game Master roles can use the media modal on character sheets; players' library view is scoped to their own uploads.
* Game-system template storage moved to post meta, hardened against content filters, failed writes, and plugin rollbacks.

= 4.15.0 =
See the project repository for the full release history.
