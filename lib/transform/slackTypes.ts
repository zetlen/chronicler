/**
 * Minimal, permissive shapes for the slices of the Slack API we actually read.
 *
 * Slack's official type packages are exhaustive but awkward to thread through
 * the transformer (lots of optional unions). These interfaces capture just the
 * fields the renderers care about; the admin bundle casts the responses it
 * receives through the plugin's Slack proxy into them.
 */

export interface SlackFile {
  id?: string;
  name?: string;
  title?: string;
  mimetype?: string;
  filetype?: string;
  pretty_type?: string;
  size?: number;
  url_private?: string;
  url_private_download?: string;
  thumb_360?: string;
  thumb_480?: string;
  thumb_720?: string;
  permalink?: string;
  permalink_public?: string;
  original_w?: number;
  original_h?: number;
}

export interface SlackAttachmentField {
  title?: string;
  value?: string;
  short?: boolean;
}

/** Legacy "attachment" — also how link unfurls and many bot cards arrive. */
export interface SlackAttachment {
  fallback?: string;
  pretext?: string;
  title?: string;
  title_link?: string;
  text?: string;
  from_url?: string;
  image_url?: string;
  thumb_url?: string;
  service_name?: string;
  service_icon?: string;
  author_name?: string;
  author_icon?: string;
  color?: string;
  fields?: SlackAttachmentField[];
}

export interface SlackBotProfile {
  name?: string;
  icons?: Record<string, string>;
}

/** An emoji reaction on a message (from conversations.history/.replies). */
export interface SlackReaction {
  name: string;
  count?: number;
  users?: string[];
}

/** A single message from conversations.history / conversations.replies. */
export interface SlackMessage {
  type?: string;
  /** e.g. "bot_message", "channel_join", "thread_broadcast". Absent for plain user messages. */
  subtype?: string;
  ts: string;
  /** Present on threaded messages; equals `ts` on the thread parent. */
  thread_ts?: string;
  reply_count?: number;
  /** ts of the newest reply; set by Slack on thread parents. */
  latest_reply?: string;

  user?: string;
  bot_id?: string;
  app_id?: string;
  /** Display-name override Slack sets on some bot/integration messages. */
  username?: string;
  bot_profile?: SlackBotProfile;

  text?: string;
  blocks?: SlackBlock[];
  attachments?: SlackAttachment[];
  files?: SlackFile[];
  reactions?: SlackReaction[];
}

/** Loose Block Kit shape — we only flatten section/header/rich text out of it. */
export interface SlackBlock {
  type?: string;
  text?: { type?: string; text?: string } | string;
  elements?: unknown[];
  [key: string]: unknown;
}

/**
 * A thread parent paired with its replies (replies excludes the parent), plus
 * how the selected time window clipped it. Replies always postdate their
 * parent, so for an in-window parent only the tail can be clipped; a parent
 * before the window (surfaced for its in-window replies) can be clipped on
 * both sides.
 */
export interface ThreadedMessage {
  parent: SlackMessage;
  replies: SlackMessage[];
  /** True when the parent predates the window and is shown only as context. */
  parentBeforeWindow?: boolean;
  /** Replies before the window start that were clipped. */
  omittedBefore?: number;
  /** Replies after the window end that were clipped. */
  omittedAfter?: number;
}

export interface ChannelSummary {
  id: string;
  name: string;
  isPrivate: boolean;
}
