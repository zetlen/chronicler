/**
 * Deterministic per-user identity colors for avatars and display names.
 *
 * Two index-matched palettes of the same 10 hue families: `PALETTE` is
 * calibrated to read on the light message background, `DARK_PALETTE` on the
 * dark one. `color.test.ts` enforces WCAG AA (4.5:1) for every entry against
 * its background — recalibrate there if the transcript backgrounds change.
 * A stable hash maps a name to an index, so a given person always gets the
 * same hue family in both schemes until it's overridden in the UI. Values are
 * hex so they drop straight into an <input type="color"> and inline styles.
 */
export const PALETTE = [
  "#3d6bbf", // blue
  "#c22e1d", // red
  "#177245", // green
  "#8e44ad", // purple
  "#a2540a", // orange
  "#c9438a", // pink
  "#0c828d", // cyan
  "#7757ff", // indigo
  "#617400", // gold
  "#a3195b", // magenta
];

export const DARK_PALETTE = [
  "#6a93d8", // blue
  "#e06055", // red
  "#2eb872", // green
  "#c778dd", // purple
  "#ef8632", // orange
  "#ef91bd", // pink
  "#18b3c2", // cyan
  "#9a85ff", // indigo
  "#c9b210", // gold
  "#ea6ba1", // magenta
];

/** Stable hash of a display name onto a palette index. */
export function paletteIndexForName(name: string): number {
  let hash = 0;
  for (let i = 0; i < name.length; i++) {
    hash = (hash << 5) - hash + name.charCodeAt(i);
    hash |= 0;
  }
  return Math.abs(hash) % PALETTE.length;
}

export function colorForName(name: string): string {
  return PALETTE[paletteIndexForName(name)];
}

/**
 * The dark-scheme counterpart of a light identity color. Palette hues map to
 * their index-matched dark calibration; anything else (a user-picked override)
 * is that user's choice in both schemes and passes through unchanged.
 */
export function darkVariantOf(color: string): string {
  const i = PALETTE.indexOf(color);
  return i === -1 ? color : DARK_PALETTE[i];
}

/**
 * Distinct identity colors for a channel's cast. Users are processed in
 * user-ID order (first-seen order varies by date range and must not shift
 * results); each user without an existing color starts at their hash index
 * and probes forward past entries already pinned — by an existing override
 * that is a palette entry, or by an earlier assignment in this pass. Only
 * when every entry is taken (cast larger than the palette) does a user fall
 * back to their plain hash entry. Returns assignments for the gaps only.
 */
export function assignDistinctColors(
  users: readonly { id: string; name: string }[],
  existing: Record<string, { color?: string }>,
): Record<string, string> {
  const taken = new Set<number>();
  for (const override of Object.values(existing)) {
    if (!override.color) continue;
    const i = PALETTE.indexOf(override.color);
    if (i !== -1) taken.add(i);
  }
  const out: Record<string, string> = {};
  for (const user of [...users].sort((a, b) => a.id.localeCompare(b.id))) {
    if (existing[user.id]?.color) continue;
    const start = paletteIndexForName(user.name);
    let index = start;
    for (let step = 0; step < PALETTE.length; step++) {
      const candidate = (start + step) % PALETTE.length;
      if (!taken.has(candidate)) {
        index = candidate;
        break;
      }
    }
    taken.add(index);
    out[user.id] = PALETTE[index];
  }
  return out;
}
