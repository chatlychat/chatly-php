<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Reporting rollups.
 *
 * `$chatly->reports->…`
 *
 * ─── EVERY ROUTE HERE IS AN AGGREGATION, NONE IS A LIST ──────────────────
 *
 * All thirteen are `@PaginationExempt` and none has a cursor. Where a rollup
 * could grow without bound it is CAPPED instead, and each cap is reported
 * differently — `attribution` sums its tail into one row, `labels` counts the
 * remainder in `otherLabels`, `teams` and `cohorts` echo back the window they
 * actually used. **There is nothing to page to, so read the cap, not the row
 * count.**
 *
 * The other recurring trap: a window you ask for is CLAMPED, not refused, on
 * most of these — so label a chart from the value the response echoes rather
 * than from your own input. {@see attribution()} is the exception that
 * refuses instead, and says why.
 *
 * Two of these answer bare JSON arrays ({@see attribution()},
 * {@see sources()}, plus {@see agents()} and {@see conversationsByDay()}
 * above) while the rest answer objects. There is no pattern to it; check per
 * route.
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

    /**
     * `GET /v1/reports/sources` — conversation volume by channel of origin.
     *
     * **A BARE JSON ARRAY**, not a page envelope. `channelId` is null when a
     * conversation carries a channel type with no surviving channel row — in
     * which case `channelName` is a human label for the TYPE rather than
     * null, so a row is always renderable.
     *
     * `days` clamped to 1–90.
     */
    public function sources(?int $days = null): mixed
    {
        return $this->httpGet('/v1/reports/sources', $days === null ? [] : ['days' => $days]);
    }

    /**
     * `GET /v1/reports/attribution` — volume and resolution by captured
     * campaign data.
     *
     * **A BARE JSON ARRAY.** Two things about the rows:
     *
     *  * The row with **`value: null` is the untagged bucket and is a real
     *    row.** Dropping it inflates every other row's `percentage`.
     *  * The tail beyond the row cap is summed into a single `(other)` row,
     *    which is why there is nothing to page to.
     *
     * ─── THE ONE REPORT THAT REFUSES A BAD PARAMETER ──────────────────────
     *
     * An unknown `$dimension` is a 400, not a silent fall back to
     * `utm_source` — a typo quietly answered as the default would be a
     * confident report of the wrong thing with no way for the caller to
     * notice. `days` is still clamped (1–90, default 30) in the usual way.
     *
     * @param string|null $dimension `utm_source` by default. The accepted set
     *                               is named in the 400's own message.
     */
    public function attribution(?string $dimension = null, ?int $days = null): mixed
    {
        $query = [];
        if ($dimension !== null) {
            $query['dimension'] = $dimension;
        }
        if ($days !== null) {
            $query['days'] = $days;
        }

        return $this->httpGet('/v1/reports/attribution', $query);
    }

    /**
     * `GET /v1/reports/cohorts` — weekly contact-retention grid.
     *
     * One object, **not a bare array of cells**. `weeks` in the response is
     * the CLAMPED window the report actually used (2–26, default 8), which
     * can differ from what you asked for — a caller labelling its axes needs
     * the echoed value and not its own input.
     */
    public function cohorts(?int $weeks = null): mixed
    {
        return $this->httpGet('/v1/reports/cohorts', $weeks === null ? [] : ['weeks' => $weeks]);
    }

    /**
     * `GET /v1/reports/response-times` — first-response and resolution
     * percentiles.
     *
     * One object. Percentiles are **seconds** and are `null`, not 0, when
     * `sampleSize` is 0.
     *
     * It carries a `byDay` series of the same shape
     * {@see conversationsByDay()} returns BARE, so the two routes are not
     * interchangeable even though they contain the same numbers.
     *
     * `days` clamped to 1–90, default 30.
     */
    public function responseTimes(?int $days = null): mixed
    {
        return $this->httpGet(
            '/v1/reports/response-times',
            $days === null ? [] : ['days' => $days],
        );
    }

    /**
     * `GET /v1/reports/teams` — per-team volume, percentiles, CSAT, SLA
     * breaches.
     *
     * One object whose `rows` hold one entry per team **plus a `null` teamId
     * row for the unassigned bucket** — a real row, not a placeholder.
     *
     * ─── READ `businessHours.applied` BEFORE CAPTIONING A DURATION ────────
     *
     * `applied: false` means the duration figures are CALENDAR time and
     * `businessHours.reason` says why. The numbers are never zeroed and never
     * relabelled when that happens, so a caller that ignores the flag
     * publishes calendar hours under a business-hours heading.
     *
     * @param array<string,mixed> $query `from` (ISO date or instant,
     *                                   inclusive), `to` (exclusive),
     *                                   `business_hours`.
     */
    public function teams(array $query = []): mixed
    {
        return $this->httpGet('/v1/reports/teams', $query);
    }

    /**
     * `GET /v1/reports/labels` — volume, resolution time and CSAT by label.
     *
     * One object. `rows` is capped at the **50 busiest** labels with the
     * remainder counted in `otherLabels`, and `trend` is emitted for the top
     * **8 only** — there is no cursor, so the tail is genuinely unreachable
     * from here rather than one page away. `trend[].counts` is keyed by label
     * NAME, not id.
     *
     * @param array<string,mixed> $query `from`, `to`, `group_by` (`hour`,
     *                                   `day`, `week` or `month`).
     */
    public function labels(array $query = []): mixed
    {
        return $this->httpGet('/v1/reports/labels', $query);
    }

    /**
     * `GET /v1/reports/voice` — call volume, answer rate, talk time, heatmap.
     *
     * One object. **Rates are 0–1, not percentages.**
     *
     * `recordingStorage.exact: false` means `totalBytesUpperBound` is part
     * measured and part bitrate extrapolation over `unmeasuredCalls` — so
     * that flag is as much part of the contract as the byte count, and
     * quoting the number without it overstates what is known.
     *
     * `days` clamped, max 365.
     */
    public function voice(?int $days = null): mixed
    {
        return $this->httpGet('/v1/reports/voice', $days === null ? [] : ['days' => $days]);
    }

    /**
     * `GET /v1/reports/themes` — the latest theme batch: what customers wrote
     * about, clustered.
     *
     * One object, **NOT a bare array**. `batchDate` names the snapshot every
     * row was measured over, and is **null with `themes` empty when no batch
     * has ever been generated** — which is a workspace that has not run the
     * generator, not an error.
     *
     * Theme rows omit `conversationIds`;
     * {@see themeConversations()} serves those.
     *
     * @param string|null $date A specific batch date. Omitted resolves the
     *                          latest batch first and then reads it.
     */
    public function themes(?string $date = null): mixed
    {
        return $this->httpGet('/v1/reports/themes', $date === null ? [] : ['date' => $date]);
    }

    /**
     * `GET /v1/reports/themes/{id}/conversations` — the ids in one theme, as
     * the snapshot recorded them.
     *
     * One object wrapping the id list. Bounded by the batch cap of 5,000
     * conversations.
     *
     * ─── AN UNKNOWN THEME ID IS A 200 WITH AN EMPTY LIST ──────────────────
     *
     * Not a 404. So this route **cannot tell you** whether the id is wrong,
     * the theme has been purged, or the theme genuinely holds nothing. If
     * that distinction matters, check the id against {@see themes()} first.
     */
    public function themeConversations(string $id): mixed
    {
        return $this->client->request(
            'GET',
            '/v1/reports/themes/' . self::seg($id) . '/conversations',
        );
    }
}
