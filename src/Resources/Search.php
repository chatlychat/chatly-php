<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Full-text search across contacts, conversations, messages, tickets and KB
 * articles.
 *
 * `$chatly->search->…`
 */
final class Search extends AbstractResource
{
    /** The entity kinds `/v1/search` knows about. */
    public const KINDS = ['contact', 'conversation', 'message', 'ticket', 'kb'];

    /**
     * `GET /v1/search`.
     *
     * ```php
     * $hits = $chatly->search->query('refund', ['kinds' => ['ticket', 'conversation']]);
     * ```
     *
     * ─── `kinds`, NOT `kinds[]` ───────────────────────────────────────────
     *
     * This route is the one place in the SDK that sends a repeated parameter
     * WITHOUT brackets, and it matters. The API's query parser hands the
     * handler the literal keys it received, and this route's schema expects
     * `kinds`; `?kinds[]=kb` arrives as a key named `kinds[]`, which the
     * schema strips — so the restriction is silently dropped and you get
     * results from every kind while believing you asked for one. (The
     * `filter[]` surface on the LIST endpoints is the opposite: it accepts
     * both, and brackets are the documented form.)
     *
     * Pass `['kinds' => ['ticket','kb']]` and this sends
     * `kinds=ticket&kinds=kb`.
     *
     * ─── RETURNS THE WHOLE ENVELOPE, NOT JUST THE HITS ───────────────────
     *
     * `{ items, nextCursor, tier, facets }`, and the two extra fields are the
     * reason this is not a `Page`:
     *
     *  * `facets` is per-entity totals for the query **ignoring** the `kinds`
     *    filter, so a UI can render tabs with counts that do not vanish when
     *    you click one. You cannot recompute it from `items`.
     *  * `tier` is which search backend answered.
     *
     * A paginator that yielded only hits would throw both away, so this
     * hands back what the API sent. {@see autoQuery()} is the hits-only walk
     * for when that is all you want.
     *
     * @param array<string,mixed> $options `kinds` (list, from {@see KINDS}),
     *                                     `from` / `to` (ISO-8601 datetimes),
     *                                     `channelId`, `assigneeUserId`,
     *                                     `status`, `limit` (1–100, default
     *                                     25), `cursor`.
     */
    public function query(string $q, array $options = []): mixed
    {
        return $this->httpGet('/v1/search', ['q' => $q] + $options);
    }

    /**
     * Every hit for a query, across pages.
     *
     * Yields `SearchHit` rows — `kind`, `id`, `title`, `snippet`, `rank`,
     * `createdAt`, `conversationId`. **`facets` and `tier` are not available
     * here**, because they describe a page rather than a hit; call
     * {@see query()} if you need either.
     *
     * ```php
     * foreach ($chatly->search->autoQuery('refund', ['kinds' => ['ticket']]) as $hit) {
     *     echo $hit['kind'], ' ', $hit['title'], PHP_EOL;
     * }
     * ```
     *
     * The cursor is `rank|createdAt|id` from the last row of the previous
     * page, base64url-encoded — rank alone is not a stable sort key, ties are
     * common in full-text search, and without the tiebreak a walk repeats or
     * skips rows across page boundaries. Pass it back as received; this does.
     *
     * There is no `autoKb()`: that is this method with
     * `['kinds' => ['kb']]`.
     *
     * @param array<string,mixed> $options As {@see query()}.
     *
     * @return \Generator<int,mixed>
     */
    public function autoQuery(string $q, array $options = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/search', ['q' => $q] + $options, $maxPages);
    }

    /**
     * The same route, narrowed to help-centre articles.
     *
     * Worth its own method because "find the article that answers this" is
     * the common case, and getting there otherwise means knowing that
     * `kinds` exists and that it must not carry brackets.
     *
     * @param array<string,mixed> $options As {@see query()}. An explicit
     *                                     `kinds` here wins, so you can widen
     *                                     it again.
     */
    public function kb(string $q, array $options = []): mixed
    {
        return $this->query($q, ['kinds' => ['kb']] + $options);
    }
}
