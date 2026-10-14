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

    /**
     * `GET /v1/macros/{id}` — one macro, in full.
     *
     * {@see list()} is capped at 200 rows by usage rank, so this is how you
     * read a macro that is rarely used enough to have fallen off the end of
     * it.
     */
    public function get(string $id): mixed
    {
        return $this->client->request('GET', '/v1/macros/' . self::seg($id));
    }

    /**
     * `GET /v1/macros/actions` — what a macro's `actions` may contain.
     *
     * `{ limit, actions }` — an object, not a list. A fixed vocabulary of
     * eleven entries defined in code, with `limit` saying how many actions
     * one macro may carry. Build an action editor from this; the enum moves
     * with releases and a hard-coded copy goes stale.
     *
     * Worth knowing where the related failure surfaces: a macro row whose
     * action has since been REMOVED from this registry answers **422** when
     * it is run, naming the action and its index. That is one of the few
     * routes on this API that returns 422 at all — schema validation here is
     * 400.
     */
    public function actions(): mixed
    {
        return $this->httpGet('/v1/macros/actions');
    }

    /**
     * `GET /v1/macros/templates` — the installable starter catalogue.
     *
     * ─── AN OBJECT, NOT AN ARRAY, AND NOT A PAGE ──────────────────────────
     *
     * `{ items, total }`. It reads like a list and sits one
     * `@PaginationExempt` hop from being a bare array, but it is neither that
     * nor a cursor envelope — there is no `nextCursor` and no `hasMore`, so a
     * client that treats it as either gets nothing. `total` is
     * `count($items)` over a frozen in-process catalogue: exact, and never a
     * hint at a second page.
     *
     * Rows are the LITE shape — no body. {@see template()} for one in full.
     */
    public function templates(): mixed
    {
        return $this->httpGet('/v1/macros/templates');
    }

    /**
     * `GET /v1/macros/templates/{slug}` — one template, with its body.
     *
     * An unknown slug is a **404**, not a 400 — a missing member of a
     * collection rather than a malformed request. (It used to be a 400, from
     * an enum parse in front of the lookup, which made the service's own 404
     * unreachable.)
     *
     * `actions` and `shortCode` are genuinely **ABSENT** from most templates
     * rather than null — a starter library that silently closed conversations
     * would be a nasty surprise on first install — so test for the key, not
     * for a null.
     */
    public function template(string $slug): mixed
    {
        return $this->client->request('GET', '/v1/macros/templates/' . self::seg($slug, 'slug'));
    }
}
