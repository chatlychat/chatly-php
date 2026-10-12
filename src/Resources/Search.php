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
