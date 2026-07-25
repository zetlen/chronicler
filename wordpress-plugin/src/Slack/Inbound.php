<?php

namespace Chronicler\Slack;

use Chronicler\Settings\Screen;
use Chronicler\Slack\Bot\Commands;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The bot skeleton's inbound surface (spec §1, docs/superpowers/specs/
 * 2026-07-24-slack-bot-in-plugin-design.md): the two POST routes Slack's
 * Request URLs point at. This DELIBERATELY reverses #104's "no inbound
 * Slack surface". The replacement policy is exactly this file: every
 * request is signature-verified (Signature) before any other work, fail
 * closed — 503 unconfigured, 401 unverified, nothing touched either way —
 * and the family never grows without a design doc.
 *
 * Registered on its own rest_api_init hook (sheets/rest.php's pattern),
 * NOT in Rest\Routes::definitions(): that table's contract is "every
 * operation names a WP capability", and Slack is not a WP user. The
 * signature check IS the authentication, so permission_callback is
 * __return_true and the gate runs first in every handler.
 *
 * Route naming: the 'inbound/' middle segment makes a collision with the
 * proxy route /slack/(?P<method>[a-z.]+) impossible — [a-z.]+ cannot span
 * a slash, and WordPress matches route regexes case-insensitively, so the
 * extra segment (not the pattern) is what keeps these families disjoint.
 *
 * Formatting contract: keep each register_rest_route call in the exact
 * quoted-namespace + quoted-route + next-line single-method style —
 * lib/wordpress/openapi.test.ts parses this source to keep openapi.yaml
 * honest (sheets/rest.php carries the same warning).
 */
final class Inbound
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('chronicler/v1', '/slack/inbound/commands', [
            'methods' => 'POST',
            'callback' => [self::class, 'commands'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('chronicler/v1', '/slack/inbound/interactions', [
            'methods' => 'POST',
            'callback' => [self::class, 'interactions'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * The authentication gate as pure data: null when verified, else
     * [status, code, message]. Unit-tested in slack-inbound.test.php; the
     * handlers map it to a response. Order matters: an unconfigured
     * surface reports 503 without leaking whether a signature would have
     * matched.
     */
    public static function gate(?string $secret, mixed $timestamp, string $body, mixed $signature, int $now): ?array
    {
        if ($secret === null) {
            return [503, 'chronicler_no_signing_secret',
                'No Slack signing secret is configured; the inbound Slack surface is disabled.'];
        }
        if (!Signature::verify($secret, $timestamp, $body, $signature, $now)) {
            return [401, 'chronicler_bad_signature', 'Slack request signature verification failed.'];
        }
        return null;
    }

    /** Run the gate against a live request; non-null means "refuse with this". */
    private static function refuse(WP_REST_Request $request): ?WP_REST_Response
    {
        $problem = self::gate(
            Screen::signing_secret(),
            $request->get_header('X-Slack-Request-Timestamp'),
            $request->get_body(),
            $request->get_header('X-Slack-Signature'),
            time()
        );
        if ($problem === null) {
            return null;
        }
        [$status, $code, $message] = $problem;
        return new WP_REST_Response(['code' => $code, 'message' => $message], $status);
    }

    /** POST slack/inbound/commands — slash commands, form-encoded, answered
     * in-body (Slack renders the 200's JSON as the reply). */
    public static function commands(WP_REST_Request $request)
    {
        $refusal = self::refuse($request);
        if ($refusal !== null) {
            return $refusal;
        }
        $params = $request->get_body_params();
        return rest_ensure_response(Commands::dispatch(is_array($params) ? $params : []));
    }

    /**
     * POST slack/inbound/interactions — shortcut/action payloads (a
     * `payload` JSON form field). The skeleton acks 200 and answers "not
     * yet" through the payload's response_url — exercising the exact
     * reply path phase 3's session clipping will use.
     */
    public static function interactions(WP_REST_Request $request)
    {
        $refusal = self::refuse($request);
        if ($refusal !== null) {
            return $refusal;
        }
        $payload = json_decode((string) ($request->get_body_params()['payload'] ?? ''), true);
        $responseUrl = is_array($payload) ? ($payload['response_url'] ?? null) : null;
        if (is_string($responseUrl) && $responseUrl !== '') {
            wp_remote_post($responseUrl, [
                'timeout' => 5,
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'body' => wp_json_encode([
                    'response_type' => 'ephemeral',
                    'text' => 'Session clipping is not implemented yet — this shortcut arrives in a later Chronicler update.',
                ]),
            ]);
        }
        return new WP_REST_Response(null, 200);
    }
}
