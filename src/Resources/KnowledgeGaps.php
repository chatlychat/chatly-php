<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Knowledge gaps — questions the knowledge base could not answer.
 *
 * `$chatly->knowledgeGaps->…`
 *
 * Recorded automatically when retrieval comes back empty or an assistant
 * gives up, clustered by question and counted. The point of the resource is
 * the triage loop: read the most-asked gaps, draft an article from the
 * conversation that produced one, mark it resolved.
 */
final class KnowledgeGaps extends AbstractResource
{
    /** The four triage states a gap can be in. */
    public const STATUSES = ['open', 'drafted', 'resolved', 'dismissed'];

    /**
     * `GET /v1/knowledge-gaps` — gaps, **most-asked first**.
     *
     * A bare array, `@PaginationExempt`: the recorder caps a workspace at 200
     * open gaps, so the first answer is the whole answer. The ordering is the
     * value here — the top row is the article worth writing next.
     *
     * ─── AN UNRECOGNISED `status` IS IGNORED, NOT REFUSED ─────────────────
     *
     * The handler parses it with `safeParse` and drops the filter when it
     * fails, so `['status' => 'opne']` returns **every** gap with a 200
     * rather than a 400 or an empty list. Check the rows you got back against
     * what you asked for; the server will not tell you.
     *
     * `limit` is clamped to 1–200 and an unparseable value falls back to 100.
     *
     * @param array<string,mixed> $query `status` (one of {@see STATUSES}),
     *                                   `limit`.
     */
    public function list(array $query = []): mixed
    {
        return $this->httpGet('/v1/knowledge-gaps', $query);
    }

    /**
     * `PATCH /v1/knowledge-gaps/{id}` — triage one gap.
     *
     * The status is a strict enum here, unlike the filter on {@see list()} —
     * a bad value on the WRITE is a 400, because quietly recording the wrong
     * state is worse than refusing. A 404 when the id is not this
     * workspace's.
     *
     * @param string $status One of {@see STATUSES}. `resolved` when you have
     *                       written the article, `dismissed` when the
     *                       question is not one you intend to answer, `open`
     *                       to put it back.
     */
    public function update(string $id, string $status): mixed
    {
        return $this->httpPatch('/v1/knowledge-gaps/' . self::seg($id), ['status' => $status]);
    }

    /**
     * `POST /v1/knowledge-gaps/{id}/draft` — write a DRAFT article from the
     * conversation this gap was found in.
     *
     * ─── READ `articleId`, NOT THE STATUS CODE ────────────────────────────
     *
     * The response is `{ gap, articleId, reason }` and **`articleId` is
     * nullable on a 201**. A gap with no conversation to draft from — one
     * clustered from several, or one whose thread has since been purged —
     * comes back with a null id and a `reason` saying so. Nothing failed; a
     * 201 here does not mean an article exists.
     *
     * The article is a DRAFT. It is not published, not indexed and not
     * visible in the help centre until a person publishes it, which is
     * deliberate: this generates text from a support conversation and that is
     * not something to put in front of customers unread.
     *
     * Takes no body.
     */
    public function draft(string $id): mixed
    {
        return $this->httpPost('/v1/knowledge-gaps/' . self::seg($id) . '/draft');
    }
}
