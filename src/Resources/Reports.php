<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Reporting rollups.
 *
 * `$chatly->reports->…`
 *
 * All four are aggregations over a time window, not paginated lists.
 */
final class Reports extends AbstractResource
{
    /**
     * `GET /v1/reports/overview` — high-level workspace metrics.
     *
     * `days` is clamped server-side to 1–90 and defaults to 7. An
     * unparseable value falls back to the default rather than erroring, so
     * check the response's own window rather than assuming yours was used.
     */
    public function overview(?int $days = null): mixed
    {
        return $this->httpGet('/v1/reports/overview', $days === null ? [] : ['days' => $days]);
    }

    /**
     * `GET /v1/reports/agents` — per-agent volume, latency and CSAT.
     *
     * `days` clamped to 1–90, default 7.
     */
    public function agents(?int $days = null): mixed
    {
        return $this->httpGet('/v1/reports/agents', $days === null ? [] : ['days' => $days]);
    }

    /**
     * `GET /v1/reports/conversations-by-day` — daily buckets.
     *
     * `days` clamped to 1–90, default 14 (not 7, unlike the other two).
     */
    public function conversationsByDay(?int $days = null): mixed
    {
        return $this->httpGet(
            '/v1/reports/conversations-by-day',
            $days === null ? [] : ['days' => $days],
        );
    }

    /**
     * `GET /v1/reports/sla` — coverage, per-target attainment, and breaches
     * by policy, team and agent.
     *
     * The breach list is capped at 200 rows. Takes a date range rather than a
     * day count, defaulting to 30 days.
     *
     * @param array<string,mixed> $query `from` / `to` (ISO-8601), `days`,
     *                                   `group_by`, `business_hours`.
     */
    public function sla(array $query = []): mixed
    {
        return $this->httpGet('/v1/reports/sla', $query);
    }
}
