<?php

namespace Chronicler\Slack;

/**
 * Slack answered HTTP 429 (#99). The plugin NEVER sleeps a rate limit out
 * (stateless-PHP ground rule): this exception carries the parsed
 * Retry-After so the proxy can pass it to the browser, which owns all
 * waiting (the #96 session editor's fetch loop).
 */
final class RateLimited extends \RuntimeException
{
    /** Seconds Slack asked us to wait, always >= 1. */
    public readonly int $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct("Slack rate limit hit; retry after {$retryAfter}s.");
    }
}
