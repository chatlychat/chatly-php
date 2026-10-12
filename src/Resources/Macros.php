<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Macros — saved replies, optionally with actions attached.
 *
 * `$chatly->macros->…`
 */
final class Macros extends AbstractResource
{
    /**
     * `GET /v1/macros` — one page.
     *
     * @param array<string,mixed> $query `cursor`, `limit`, `filter[]`, `sort`, `fields`.
     */
    public function list(array $query = []): Page
    {
        return $this->httpPage('/v1/macros', $query);
    }

    /**
     * Every macro, across pages.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function autoList(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/macros', $query, $maxPages);
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
