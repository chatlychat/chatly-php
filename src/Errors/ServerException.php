<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * HTTP 5xx — the API failed to answer.
 *
 * Retryable, and retried by default. If it survives the retry budget it reaches you.
 */
final class ServerException extends ChatlyException
{
}
