<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * HTTP 404 — no such resource in this workspace.
 *
 * Also what a cross-workspace id looks like: row-level security scopes every read to the key's workspace, so another tenant's id is indistinguishable from a deleted one.
 */
final class NotFoundException extends ChatlyException
{
}
