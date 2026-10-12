<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * HTTP 401 — the API key is missing, malformed, revoked or expired.
 *
 * A wrong key, not an insufficient one: compare with {@see PermissionException}.
 */
final class AuthenticationException extends ChatlyException
{
}
