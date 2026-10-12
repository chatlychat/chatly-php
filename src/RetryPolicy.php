<?php

declare(strict_types=1);

namespace Chatly;

/**
 * What the client retries, how often, and how long it waits.
 *
 * ─── WHAT IS RETRYABLE, AND WHAT IS NOT ───────────────────────────────────
 *
 * Connection failures, 429 and 5xx. **No other 4xx is ever retried** — a 400,
 * 401, 403, 404, 409 or 422 means the request was wrong, and repeating it
 * wastes the caller's rate-limit budget to arrive at the same refusal.
 *
 * A retried write reuses the `Idempotency-Key` minted for the first attempt.
 * That is the entire reason the header exists: without it an SDK-level retry
 * of a `POST /v1/contacts` that timed out after the server committed would
 * create a second contact.
 */
final class RetryPolicy
{
    /** @var \Closure(float):void */
    private \Closure $sleeper;

    /** @var \Closure(float):float */
    private \Closure $jitter;

    /**
     * @param int   $maxAttempts    Total attempts including the first. 3 means
     *                              one try plus two retries. 1 disables retries.
     * @param float $baseDelay      Seconds for the first backoff step.
     * @param float $maxDelay       Ceiling for a computed backoff step.
     * @param float $maxRetryAfter  Ceiling for a server-supplied `Retry-After`.
     *                              A 429 asking for 900 seconds should not
     *                              park a web request for fifteen minutes;
     *                              past this the error is raised instead.
     * @param bool  $honourRetryAfter Whether a server-supplied wait wins over
     *                              the computed backoff.
     * @param null|\Closure(float):void  $sleeper Override the wait. Tests pass
     *                              a recorder so the suite does not sleep.
     * @param null|\Closure(float):float $jitter  Override the randomness. Takes
     *                              the jitter budget in seconds, returns the
     *                              part to add. Tests pass `fn () => 0.0`.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly float $baseDelay = 0.5,
        public readonly float $maxDelay = 8.0,
        public readonly float $maxRetryAfter = 60.0,
        public readonly bool $honourRetryAfter = true,
        ?\Closure $sleeper = null,
        ?\Closure $jitter = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('RetryPolicy: maxAttempts must be at least 1');
        }
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->jitter = $jitter ?? static fn (float $budget): float => $budget <= 0.0
            ? 0.0
            : $budget * (mt_rand() / mt_getrandmax());
    }

    /** No retries at all. For a caller that owns its own retry loop. */
    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    /** 429 and 5xx. Nothing else. */
    public function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /** @param int $attempt The attempt that just failed, 1-based. */
    public function shouldRetryStatus(int $status, int $attempt): bool
    {
        return $attempt < $this->maxAttempts && $this->isRetryableStatus($status);
    }

    /** A transport failure is always safe to retry; only the budget decides. */
    public function shouldRetryTransport(int $attempt): bool
    {
        return $attempt < $this->maxAttempts;
    }

    /**
     * How long to wait after a failed attempt.
     *
     * Equal jitter — half the computed step, plus a random share of the other
     * half. Full jitter can return ~0 and turn a retry into a hammer; no
     * jitter at all makes a fleet of workers retry in lockstep.
     *
     * Returns null when a server-supplied wait exceeds `maxRetryAfter`,
     * meaning "do not wait, give up".
     *
     * @param int        $attempt    The attempt that just failed, 1-based.
     * @param float|null $retryAfter Seconds the server asked for, if any.
     */
    public function delayFor(int $attempt, ?float $retryAfter = null): ?float
    {
        if ($retryAfter !== null && $this->honourRetryAfter) {
            if ($retryAfter > $this->maxRetryAfter) {
                return null;
            }

            return max(0.0, $retryAfter);
        }

        $step = min($this->maxDelay, $this->baseDelay * (2 ** max(0, $attempt - 1)));
        $half = $step / 2;

        return $half + ($this->jitter)($half);
    }

    /** Wait, and report whether waiting was allowed. */
    public function wait(int $attempt, ?float $retryAfter = null): bool
    {
        $delay = $this->delayFor($attempt, $retryAfter);
        if ($delay === null) {
            return false;
        }
        ($this->sleeper)($delay);

        return true;
    }
}
