import { colorForName } from "@/lib/transform/color";

/**
 * Client-safe name + color resolution. The server resolves Slack IDs to names
 * and ships the maps to the browser (see lib/slack/directory.ts); the browser
 * then builds a Directory from those maps — plus any user-supplied renames and
 * color picks — and the renderers use it synchronously. Nothing here imports
 * the Slack SDK, so it's safe in the client bundle.
 */

/** A per-user override set in the UI: a display-name and/or a color. */
export interface UserOverride {
  name?: string;
  color?: string; // hex, e.g. "#4674b8"
}

export interface Directory {
  /** Display name for a user ID (honors overrides), falling back to the ID. */
  userName(id?: string): string;
  /** Display name for a user ID IGNORING any rename override (the real Slack
   *  name), falling back to the ID — used to un-rename OOC messages. */
  realUserName(id?: string): string;
  /** Channel name (without `#`) for a channel ID, falling back to the ID. */
  channelName(id?: string): string;
  /** Identity color (hex) for a user: their override, else a stable default. */
  userColor(id?: string): string;
  /** Profile image URL for a user, or undefined to fall back to initials. */
  userAvatar(id?: string): string | undefined;
}

/** Resolved ID → name maps, produced server-side and sent to the client. */
export interface NameMaps {
  users: Record<string, string>;
  channels: Record<string, string>;
  /** User ID → profile image URL (image_72), when Slack provides one. */
  avatars?: Record<string, string>;
  /** User ID → the player's character log name (goes-by or title), when mapped. */
  characters?: Record<string, string>;
}

/**
 * Build a Directory from resolved name maps and optional per-user overrides.
 * Name overrides (keyed by user ID) win for the display name; color overrides
 * win for the identity color, otherwise a stable hash-based default is used.
 */
export function createDirectory(
  maps: NameMaps,
  userOverrides: Record<string, UserOverride> = {},
): Directory {
  const nameOf = (id?: string): string => {
    if (!id) return "Unknown";
    const override = userOverrides[id]?.name?.trim();
    return override || maps.characters?.[id] || maps.users[id] || id;
  };

  const realNameOf = (id?: string): string => {
    if (!id) return "Unknown";
    return maps.users[id] || id;
  };

  return {
    userName: nameOf,
    realUserName: realNameOf,
    channelName: (id) => (id ? maps.channels[id] ?? id : "channel"),
    userAvatar: (id) => (id ? maps.avatars?.[id] : undefined),
    userColor: (id) => {
      if (!id) return colorForName("Unknown");
      // Default color is derived from the *resolved* name (not the rename) so
      // it's stable across renames and matches the color the picker shows.
      return userOverrides[id]?.color || colorForName(maps.users[id] ?? id);
    },
  };
}
