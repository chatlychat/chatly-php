<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * HTTP 403 — the key is valid but lacks a scope the route requires.
 *
 * Call {@see \\Chatly\\ChatlyClient::whoami()} to see the scopes the key actually carries. A key also gets 403 from the ~746 routes that require a signed-in person; those are not SDK surface.
 */
final class PermissionException extends ChatlyException
{
}
