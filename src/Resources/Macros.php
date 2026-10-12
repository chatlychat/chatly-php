<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Macros — saved replies, optionally with actions attached.
 *
 * `$chatly->macros->…`
 */
final class Macros extends AbstractResource
{
    /**
     * `GET /v1/macros` — the macro library, as a plain array.
     *
     * ─── NOT CURSOR-PAGINATED, AND THAT IS NOT AN OVERSIGHT ───────────────
     *
     * The handler is `@PaginationExempt`: the service caps the result at 200
     * rows and orders them by usage rank, so the first page IS the answer —
     * paging it would hand you the least-used macros in exchange for a
     * round trip. There is deliberately no `autoList()` here, and no
     * `cursor` or `limit` parameter to pass; this method returns the array
     * the API sends.
     *
     * The shared manifest said `paged: true` for a day, and a client that
     * believed it decoded a bare array as a page envelope.
     *
     * @param array<string,mixed> $query The four filters the route accepts,
     *                                   and only those: `scope`, `team` (a
     *                                   team uuid), `query` (a substring,
     *                                   ≤120 chars) and `language` (a
     *                                   BCP-47-ish tag, which RANKS matching
     *                                   macros first rather than hiding the
     *                                   rest — an unrecognised value simply
     *                                   ranks nothing).
     */
    public function list(array $query = []): mixed
    {
        return $this->httpGet('/v1/macros', $query);
    }

    /**
     * `POST /v1/macros` — create one.
     *
     * A macro must carry a `body`, `actions`, or both; one with neither is
     * refused. `scope` is required, and when it is `team` so is `teamId`.
     *
     * ```php
     * $chatly->macros->create([
     *     'name'      => 'Refund issued',
     *     'body'      => 'Your refund is on its way.',
     *     'scope'     => 'workspace',
     *     'shortCode' => 'refund',
     * ]);
     * ```
     *
     * @param array<string,mixed> $macro `name` (required), `body`, `actions`,
     *                                   `shortCode`, `scope` (required),
     *                                   `teamId`, `language` (default `en`),
     *                                   `tags`.
     */
    public function create(array $macro): mixed
    {
        return $this->httpPost('/v1/macros', $macro);
    }

    /**
     * `PATCH /v1/macros/{id}` — partial update.
     *
     * Send only what changes. Omitted fields are untouched; this is not a
     * replace.
     *
     * @param array<string,mixed> $changes
     */
    public function update(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/macros/' . self::seg($id), $changes);
    }

    /** `DELETE /v1/macros/{id}`. */
    public function delete(string $id): mixed
    {
        return $this->client->request('DELETE', '/v1/macros/' . self::seg($id));
    }
}
