<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Triggers — rules that fire an action when an event happens.
 *
 * `$chatly->triggers->…`
 *
 * An event type, a list of conditions, and actions to run when they all
 * match. This is the proactive-messaging and automation surface; the richer
 * DAG kind lives in workflows, which an API key cannot reach.
 */
final class Triggers extends AbstractResource
{
    /**
     * `GET /v1/triggers` — one page of rules.
     *
     * A real cursor page. Rows are PARTIAL when `?fields=` projects, so a
     * field you did not ask for is absent rather than null.
     *
     * @param array<string,mixed> $query `cursor`, `limit`, `sort`, `fields`,
     *                                   `filter[]`.
     */
    public function list(array $query = []): Page
    {
        return $this->httpPage('/v1/triggers', $query);
    }

    /**
     * Every trigger, across pages.
     *
     * @param array<string,mixed> $query    As {@see list()}.
     * @param int|null            $maxPages Stop early. Null walks to the end.
     *
     * @return \Generator<int,mixed>
     */
    public function autoList(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/triggers', $query, $maxPages);
    }

    /**
     * `POST /v1/triggers` — create OR update, keyed on `id`.
     *
     * ─── AN UPSERT, AND THERE IS NO PATCH ─────────────────────────────────
     *
     * Include `id` to replace an existing rule, omit it to create one. Since
     * it is a replace and not a merge, **the body must carry every field you
     * want to keep**: sending `{id, name}` to rename a rule drops its
     * conditions and actions, because they are required fields and absence is
     * not "unchanged" here. Read the rule, change the array, send it all
     * back.
     *
     * Answers **201 on both arms** — Nest's POST default, with no `@HttpCode`
     * to say otherwise — so a 201 does not mean something new was created.
     * The response is the trigger as stored; compare its `id` with what you
     * sent if you need to know which happened.
     *
     * @param array<string,mixed> $trigger `name` (≤160), `eventType` (≤64),
     *                                     `enabled`, `conditions` (≤50; each
     *                                     `field`, `op` — one of `eq`, `neq`,
     *                                     `in`, `gt`, `lt`, `contains`,
     *                                     `matches` — and `value`) and
     *                                     `actions`, all required; `id` to
     *                                     update.
     */
    public function create(array $trigger): mixed
    {
        return $this->httpPost('/v1/triggers', $trigger);
    }

    /**
     * `DELETE /v1/triggers/{id}` — a HARD delete.
     *
     * The row is removed, not flagged — there is no `deletedAt` here and no
     * way back. Until this route existed there was no way to delete a trigger
     * through any surface, so one created by mistake fired forever.
     *
     * Answers **200 with `{ ok: true }`**, not a 204. That acknowledgement is
     * the only thing the route gives back, and a client written against an
     * empty-body assumption throws it away.
     */
    public function delete(string $id): mixed
    {
        return $this->httpDelete('/v1/triggers/' . self::seg($id));
    }
}
