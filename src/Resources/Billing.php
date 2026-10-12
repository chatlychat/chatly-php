<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Billing — plans and metered usage.
 *
 * `$chatly->billing->…`
 */
final class Billing extends AbstractResource
{
    /**
     * The metered event types `usage()` will accept.
     *
     * A closed list on the server side, so it is a closed list here. Passing
     * anything else is a 400 before the handler does any work.
     */
    public const USAGE_EVENT_TYPES = [
        'conversation.opened',
        'message.sent.agent',
        'message.sent.bot',
        'ai.tokens',
    ];

    /** `GET /v1/billing/plans` — the plans available to this workspace. */
    public function plans(): mixed
    {
        return $this->httpGet('/v1/billing/plans');
    }

    /**
     * `GET /v1/billing/usage` — month-to-date usage for ONE event type.
     *
     * ```php
     * $tokens = $chatly->billing->usage('ai.tokens');
     * // ['workspaceId' => …, 'eventType' => 'ai.tokens',
     * //  'from' => '2026-10-01T00:00:00.000Z', 'to' => '2026-11-01T00:00:00.000Z',
     * //  'total' => 184203, 'count' => 912]
     * ```
     *
     * ─── `$eventType` IS REQUIRED ─────────────────────────────────────────
     *
     * One event type per call — this is not a rollup across all of them. The
     * handler validates the value against {@see USAGE_EVENT_TYPES} and throws
     * before doing anything else, so a call with no query is a 400 and never
     * a total. It was optional in four of these SDKs, which made the method
     * impossible to call successfully; it is required here so the mistake is
     * a PHP error at the call site instead of a 400 in production.
     *
     * The window defaults to the current calendar month in UTC: `from` is the
     * first of this month and `to` is the first of next, exclusive. Both are
     * ISO-8601 instants, and `to` must be strictly greater than `from`.
     *
     * Needs `workspace:billing:read`, which is an owner-level scope.
     *
     * @param string              $eventType One of {@see USAGE_EVENT_TYPES}.
     * @param array<string,mixed> $query     `from` and `to`. A
     *                                       `DateTimeInterface` is accepted
     *                                       and formatted for you.
     *
     * @throws \InvalidArgumentException If `$eventType` is empty or not one of
     *                                  the four. Checked here so a typo fails
     *                                  at the call site rather than costing a
     *                                  round trip to learn the same thing.
     */
    public function usage(string $eventType, array $query = []): mixed
    {
        if (!\in_array($eventType, self::USAGE_EVENT_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Chatly: unknown usage eventType "%s". Known: %s.',
                $eventType,
                implode(', ', self::USAGE_EVENT_TYPES),
            ));
        }

        return $this->httpGet('/v1/billing/usage', ['eventType' => $eventType] + $query);
    }
}
