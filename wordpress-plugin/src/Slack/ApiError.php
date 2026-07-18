<?php

namespace Chronicler\Slack;

/**
 * A Slack Web API call failed: transport error, non-JSON response, or an
 * `ok: false` body (#99). Carries a stable machine code alongside the
 * human message so the proxy can surface it structurally.
 */
final class ApiError extends \RuntimeException
{
    /**
     * Slack's error code for `ok: false` responses (e.g. "invalid_auth",
     * "channel_not_found"); "http_error" for transport failures;
     * "invalid_response" when Slack's body was not a JSON object.
     */
    public readonly string $slackError;

    public function __construct(string $slackError, string $message = '')
    {
        $this->slackError = $slackError;
        parent::__construct($message !== '' ? $message : $slackError);
    }
}
