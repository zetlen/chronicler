<?php

namespace Chronicler\Slack;

/**
 * Slack request-signature verification — the ONLY authentication on the
 * bot skeleton's inbound surface (spec §1: docs/superpowers/specs/
 * 2026-07-24-slack-bot-in-plugin-design.md). Pure: no WordPress, no
 * clock — callers pass time() so the replay window is unit-testable.
 *
 * The signature covers the RAW request body byte-for-byte; callers must
 * hand in WP_REST_Request::get_body(), never re-encoded params.
 */
final class Signature
{
    /** Slack's documented replay window, seconds, either direction. */
    public const TOLERANCE = 300;

    /** The v0 signature for a secret + timestamp + raw-body triple. */
    public static function compute(string $secret, string $timestamp, string $body): string
    {
        return 'v0=' . hash_hmac('sha256', "v0:$timestamp:$body", $secret);
    }

    /**
     * Fail closed: a missing/malformed header, a timestamp outside the
     * tolerance window (forward skew counts too), or any mismatch is
     * false — never an exception. hash_equals keeps the compare
     * constant-time.
     */
    public static function verify(string $secret, mixed $timestamp, string $body, mixed $signature, int $now): bool
    {
        if (!is_string($timestamp) || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            return false;
        }
        if (abs($now - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }
        if (!is_string($signature) || !str_starts_with($signature, 'v0=')) {
            return false;
        }
        return hash_equals(self::compute($secret, $timestamp, $body), $signature);
    }
}
