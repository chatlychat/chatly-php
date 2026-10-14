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

    /**
     * `GET /v1/billing` — the billing summary: plan, seats, period, balance.
     *
     * A singleton, `@PaginationExempt`. The call to make to answer "what is
     * this workspace on and when does it renew".
     *
     * The whole controller is `@AllowWhenSuspended`, so this keeps answering
     * for a workspace that has been suspended for non-payment — which is the
     * only state in which somebody urgently needs to read it.
     */
    public function summary(): mixed
    {
        return $this->httpGet('/v1/billing');
    }

    /**
     * `GET /v1/billing/invoices` — invoices mirrored from Stripe.
     *
     * ─── IT PAGINATES, BUT THE ENVELOPE IS `{ data, nextCursor }` ─────────
     *
     * Not `{ items, nextCursor, hasMore }` like the rest of this API. It
     * predates the shared helper and the difference is load-bearing for
     * anybody already reading it, so it is left as it is. That is why this
     * method returns the decoded body rather than a
     * {@see \Chatly\Pagination\Page}, and why
     * there is no `autoInvoices()`: this SDK's pager reads `items`, and
     * handing it this body would be the "bare array read as an empty page"
     * bug from the other direction.
     *
     * **Walk it yourself** — pass `nextCursor` back as `?cursor=` until it is
     * null. The cursor is an ISO timestamp; pass it back exactly as received
     * and never construct one.
     *
     * @param array<string,mixed> $query `cursor`, `limit`. An unparseable
     *                                   `limit` is dropped rather than
     *                                   refused, so you get the default.
     */
    public function invoices(array $query = []): mixed
    {
        return $this->httpGet('/v1/billing/invoices', $query);
    }

    /**
     * `GET /v1/billing/usage/monthly` — one entry per usage event type, for a
     * month.
     *
     * A fixed rollup across the KNOWN event types, so unlike
     * {@see usage()} this is the all-types answer in one call. It also
     * carries a top-level `total` — the sum of every `items[].total` — so a
     * caller need not add them up.
     *
     * @param array<string,mixed> $query `month`, as `YYYY-MM` in UTC. It
     *                                   defaults to the current calendar
     *                                   month, and `monthStart` in the
     *                                   response echoes whichever was used.
     */
    public function usageMonthly(array $query = []): mixed
    {
        return $this->httpGet('/v1/billing/usage/monthly', $query);
    }

    /**
     * `GET /v1/billing/usage/daily` — a day-bucketed series for ONE event
     * type.
     *
     * ─── THE SERIES IS DENSE: ZEROS ARE INCLUDED ──────────────────────────
     *
     * One entry per UTC day across `[from, to)`, whether or not anything
     * happened. So the array length is the number of days and not the number
     * of active days — do not count rows to count usage, and do not assume a
     * gap means missing data. Each `day` is the UTC midnight that starts it,
     * as a full ISO string.
     *
     * Service-capped at 365 days. `from` / `to` omitted defaults to the
     * current calendar month.
     *
     * @param array<string,mixed> $query `eventType` — effectively REQUIRED,
     *                                   and validated against
     *                                   {@see USAGE_EVENT_TYPES} the same way
     *                                   {@see usage()} validates it — plus
     *                                   `from` and `to` as ISO-8601 instants.
     */
    public function usageDaily(array $query = []): mixed
    {
        return $this->httpGet('/v1/billing/usage/daily', $query);
    }
}
