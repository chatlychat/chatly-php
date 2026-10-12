<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * HTTP 409 — a conflicting write, including an idempotency-key mismatch.
 *
 * The same Idempotency-Key with a DIFFERENT body is a 409: the API replays the first response for an identical body and refuses a key that is being reused for a different operation.
 */
final class ConflictException extends ChatlyException
{
}
