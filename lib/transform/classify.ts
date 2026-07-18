import type { SlackMessage } from "@/lib/transform/slackTypes";
import type { MessageKind } from "@/lib/transform/types";

/**
 * Subtypes Slack uses for channel "events" rather than human conversation.
 * These render as muted system lines instead of chat bubbles.
 */
const SYSTEM_SUBTYPES = new Set<string>([
  "channel_join",
  "channel_leave",
  "channel_topic",
  "channel_purpose",
  "channel_name",
  "channel_archive",
  "channel_unarchive",
  "group_join",
  "group_leave",
  "group_topic",
  "group_purpose",
  "group_name",
  "pinned_item",
  "unpinned_item",
  "bot_add",
  "bot_remove",
  "reminder_add",
]);

function isReply(m: SlackMessage): boolean {
  return !!m.thread_ts && m.thread_ts !== m.ts;
}

function isBot(m: SlackMessage): boolean {
  return !!m.bot_id || m.subtype === "bot_message";
}

function hasImage(m: SlackMessage): boolean {
  return (m.files ?? []).some((f) => f.mimetype?.startsWith("image/"));
}

function hasFile(m: SlackMessage): boolean {
  return (m.files ?? []).length > 0;
}

function hasLink(m: SlackMessage): boolean {
  // A link unfurl arrives as an attachment carrying the source URL...
  if ((m.attachments ?? []).some((a) => a.title_link || a.from_url)) return true;
  // ...or the message text itself is primarily a Slack link entity.
  return /<https?:\/\//.test(m.text ?? "");
}

/**
 * Map a raw Slack message to exactly one {@link MessageKind}.
 *
 * This is the single, ordered decision point for categorization — think of it
 * as the "switch". Order matters: earlier branches win. To change which
 * renderer a message gets, adjust the branches here; to change how a category
 * *looks*, edit its renderer in `renderers/`.
 */
export function classify(m: SlackMessage): MessageKind {
  // 1. Channel events (joins, topic changes, …) — never chat bubbles.
  if (m.subtype && SYSTEM_SUBTYPES.has(m.subtype)) return "system";

  // 2. Bots get dedicated strategies, split by whether they're in a thread.
  if (isBot(m)) return isReply(m) ? "bot_reply" : "bot_message";

  // 3. Human threaded replies.
  if (isReply(m)) return "reply";

  // 4. Media-bearing messages.
  if (hasImage(m)) return "image";
  if (hasFile(m)) return "file";

  // 5. Link-centric messages (unfurls / shared URLs).
  if (hasLink(m)) return "link";

  // 6. Everything else is plain text.
  return "text";
}
