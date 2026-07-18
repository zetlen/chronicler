import type { Renderer } from "@/lib/transform/types";
import type { SlackMessage } from "@/lib/transform/slackTypes";
import { mrkdwnToHtml } from "@/lib/transform/mrkdwn";
import { escapeHtml } from "@/lib/transform/shared";

/** Fallback phrasing when Slack didn't supply pre-rendered event text. */
function describe(m: SlackMessage): string {
  switch (m.subtype) {
    case "channel_join":
    case "group_join":
      return "joined the channel";
    case "channel_leave":
    case "group_leave":
      return "left the channel";
    case "channel_topic":
    case "group_topic":
      return "set the channel topic";
    case "channel_purpose":
    case "group_purpose":
      return "set the channel purpose";
    case "channel_name":
    case "group_name":
      return "renamed the channel";
    case "channel_archive":
      return "archived the channel";
    case "channel_unarchive":
      return "un-archived the channel";
    case "pinned_item":
      return "pinned an item";
    case "unpinned_item":
      return "un-pinned an item";
    default:
      return m.subtype ?? "channel event";
  }
}

/**
 * Channel events (joins, leaves, topic changes, …). Rendered as a muted,
 * centered line rather than a chat bubble. Slack usually supplies ready-made
 * `text` like "<@U> has joined the channel"; we fall back to {@link describe}.
 */
export const renderSystem: Renderer = (m, ctx) => {
  const inner = m.text
    ? mrkdwnToHtml(m.text, ctx)
    : `${escapeHtml(ctx.directory.userName(m.user))} ${escapeHtml(describe(m))}`;

  return { kind: "raw", rawHtml: `<div class="slk-system">${inner}</div>` };
};
