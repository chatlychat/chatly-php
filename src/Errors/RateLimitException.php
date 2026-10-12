<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * HTTP 429 — a rate-limit bucket is empty.
 *
 * Retried by default, honouring the wait the server asks for.
 */
final class RateLimitException extends ChatlyException
{
    /**
     * Seconds to wait before retrying, or null when the response said nothing.
     *
     * ─── WHY THIS LOOKS FOR SUFFIXED HEADERS ──────────────────────────────
     *
     * **There is no plain `Retry-After` on this API.** Rate limiting is
     * per-bucket and every header is suffixed with the bucket name —
     * `Retry-After-short`, `Retry-After-login`, plus any bucket a route
     * opts into. A client that reads only `Retry-After` finds nothing on
     * every real 429.
     *
     * So this reads `Retry-After` when present (a proxy or a self-hosted
     * install may add one) and every `Retry-After-*` otherwise, and returns
     * the LARGEST value found: when several buckets report, waiting for the
     * shortest one would retry into a wall that is still up.
     *
     * An HTTP-date value is converted to a delta from now; a negative delta
     * becomes 0.
     */
    public function getRetryAfter(): ?int
    {
        $best = null;
        foreach ($this->getHeaders() as $name => $value) {
            if ($name !== 'retry-after' && !str_starts_with($name, 'retry-after-')) {
                continue;
            }
            $seconds = self::parseRetryAfter($value);
            if ($seconds === null) {
                continue;
            }
            $best = $best === null ? $seconds : max($best, $seconds);
        }

        return $best;
    }

    private static function parseRetryAfter(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        // RFC 9110 allows an HTTP-date. Never thrown away silently: a date we
        // cannot parse returns null rather than 0, so the backoff falls back
        // to exponential instead of hammering immediately.
        $at = strtotime($value);
        if ($at === false) {
            return null;
        }

        return max(0, $at - time());
    }
}
