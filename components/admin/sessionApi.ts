/**
 * Typed wrappers over the chronicler/v1 storage routes (#101) — thin: the
 * shapes here mirror src/Rest/Schemas.php and Store\Sessions/Rules exactly,
 * and every call rides the apiFetch seam (nonce, ApiError).
 */

import { apiFetch } from "@/components/admin/apiFetch";
import type { UserOverride } from "@/lib/transform/directory";
import type { TranscriptScheme } from "@/lib/transform/types";
import type { RuleMode } from "@/lib/transform/rules";

/** Display toggles persisted in editorState (a subset of lib/presets Controls). */
export interface SessionControls {
  hideSystem: boolean;
  hideBots: boolean;
  includeReplies: boolean;
  showAvatars: boolean;
  showTimestamps: boolean;
  showReactions: boolean;
}

export const DEFAULT_SESSION_CONTROLS: SessionControls = {
  hideSystem: false,
  hideBots: false,
  includeReplies: true,
  showAvatars: true,
  showTimestamps: false,
  showReactions: true,
};

/**
 * Client-owned editor state, round-tripped through the Session
 * (Schemas::editorState is additionalProperties:true, so `controls` rides
 * along even though only the first three fields are schema-documented).
 */
export interface SessionEditorState {
  userOverrides?: Record<string, UserOverride>;
  scheme?: TranscriptScheme;
  customCss?: string;
  controls?: Partial<SessionControls>;
}

export interface SessionChannel {
  id: string;
  name: string;
}

/** GET /sessions row (light shape — no payload). */
export interface SessionLight {
  id: number;
  integration: string;
  channel: SessionChannel;
  start: string;
  end: string;
  rule_ids: number[];
  messageCount: number;
  created: string;
  updated: string;
}

/** GET /sessions/{id} — the full Session, message payload included. */
export interface SessionFull extends SessionLight {
  editorState: SessionEditorState;
  messages: Record<string, unknown>[];
}

/** A stored Rule (Store\Rules / #109). */
export interface WpRule {
  id: number;
  pattern: string;
  flags: string;
  mode: RuleMode;
  className: string;
  tagNames: string;
  description: string;
}

/** One channel's Session defaults from GET /settings (Schemas::channelDefault). */
export interface ChannelDefault {
  userOverrides?: Record<string, UserOverride>;
  scheme?: TranscriptScheme;
  customCss?: string;
  controls?: Partial<SessionControls>;
  rule_ids?: number[];
}

export interface SettingsResponse {
  settings: Record<string, string>;
  channelDefaults: Record<string, ChannelDefault>;
}

/** GET /sessions page size — mirrors the server default (Sessions::DEFAULT_PER_PAGE). */
export const SESSIONS_PER_PAGE = 50;

/** One page (#164). A full page means there may be more; ask for the next. */
export function listSessions(page = 1, signal?: AbortSignal): Promise<SessionLight[]> {
  return apiFetch<SessionLight[]>(
    `sessions?page=${page}&per_page=${SESSIONS_PER_PAGE}`,
    { signal },
  );
}

export function getSession(id: number, signal?: AbortSignal): Promise<SessionFull> {
  return apiFetch<SessionFull>(`sessions/${id}`, { signal });
}

export interface SessionCreateBody {
  integration: "slack";
  channel: SessionChannel;
  start: string;
  end: string;
  rule_ids?: number[];
  editorState?: SessionEditorState;
}

export function createSession(body: SessionCreateBody): Promise<SessionFull> {
  return apiFetch<SessionFull>("sessions", {
    method: "POST",
    body: JSON.stringify(body),
  });
}

export interface SessionPatch {
  channel?: SessionChannel;
  start?: string;
  end?: string;
  rule_ids?: number[];
  editorState?: SessionEditorState;
  messages?: Record<string, unknown>[];
}

export function putSession(
  id: number,
  patch: SessionPatch,
  signal?: AbortSignal,
): Promise<SessionFull> {
  return apiFetch<SessionFull>(`sessions/${id}`, {
    method: "PUT",
    body: JSON.stringify(patch),
    signal,
  });
}

export function listRules(signal?: AbortSignal): Promise<WpRule[]> {
  return apiFetch<WpRule[]>("rules", { signal });
}

export interface RuleCreateBody {
  pattern: string;
  flags?: string;
  mode: RuleMode;
  className?: string;
  tagNames?: string;
  description?: string;
}

export function createRule(body: RuleCreateBody): Promise<WpRule> {
  return apiFetch<WpRule>("rules", {
    method: "POST",
    body: JSON.stringify(body),
  });
}

export function getSettings(signal?: AbortSignal): Promise<SettingsResponse> {
  return apiFetch<SettingsResponse>("settings", { signal });
}

/** Slack member ID → the mapped player's character log name (goes-by or title). */
export function getCharacterNames(
  signal?: AbortSignal,
): Promise<Record<string, string>> {
  return apiFetch<Record<string, string>>("characters/names", { signal });
}
