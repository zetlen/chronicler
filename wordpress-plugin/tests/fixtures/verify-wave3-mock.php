<?php
// wp-env verification mock for the Wave-3 integration fixes — NOT part of
// tests/run.php and never loaded in production. Map it in as an mu-plugin
// via .wp-env.override.json:
//
//   { "mappings": { "wp-content/mu-plugins/verify-wave3-mock.php":
//       "./wordpress-plugin/tests/fixtures/verify-wave3-mock.php" } }
//
// It intercepts every outbound WordPress HTTP call the fixes exercise:
//
// - Slack image hosts (Media\Mirror::ALLOWED_HOSTS) → 200 image/png with a
//   1x1 transparent PNG, so Generate's mirroring needs no Slack network or
//   real bot token.
// - slack.com/api/* (Slack\Client) → 200 {ok, method, received} echoing the
//   form-encoded args the proxy forwarded, so conversations.list forwarding
//   of types/exclude_archived is observable from the response body.

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) {
        return $pre;
    }

    $imageHosts = ['files.slack.com', 'avatars.slack-edge.com', 'secure.gravatar.com', 'a.slack-edge.com'];
    if (in_array(strtolower($host), $imageHosts, true)) {
        return [
            'headers' => ['content-type' => 'image/png'],
            'body' => base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            ),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    if (strtolower($host) === 'slack.com') {
        $received = [];
        if (is_string($args['body'] ?? null)) {
            parse_str($args['body'], $received);
        } elseif (is_array($args['body'] ?? null)) {
            $received = $args['body'];
        }
        $method = basename((string) parse_url($url, PHP_URL_PATH));
        return [
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => wp_json_encode([
                'ok' => true,
                'method' => $method,
                'received' => $received,
                'channels' => [],
                'response_metadata' => [],
            ]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    return $pre;
}, 10, 3);
