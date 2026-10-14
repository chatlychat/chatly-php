<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Tickets — work items, usually created from a conversation.
 *
 * `$chatly->tickets->…`
 */
final class Tickets extends AbstractResource
{
    /**
     * `GET /v1/tickets` — one page, newest first.
     *
     * Excludes `merged` tickets unless you filter on status explicitly.
     * Filtering is the standard `filter[]` surface:
     *
     * ```php
     * $chatly->tickets->list(['filter[]' => ['status:open', 'priority:urgent']]);
     * ```
     *
     * @param array<string,mixed> $query `cursor`, `limit`, `filter[]`, `sort`, `fields`.
     */
    public function list(array $query = []): Page
    {
        return $this->httpPage('/v1/tickets', $query);
    }

    /**
     * Every ticket, across pages.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function autoList(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/tickets', $query, $maxPages);
    }

    /**
     * `GET /v1/tickets/{id}/comments` — the comment thread.
     *
     * Includes internal notes, which are not visible to the customer. If you
     * are mirroring these anywhere customer-facing, filter on `isInternal`.
     */
    public function comments(string $id): mixed
    {
        return $this->client->request('GET', '/v1/tickets/' . self::seg($id) . '/comments');
    }

    /**
     * `GET /v1/tickets/for-conversation/{conversationId}` — the tickets
     * raised from one conversation.
     */
    public function forConversation(string $conversationId): mixed
    {
        return $this->client->request(
            'GET',
            '/v1/tickets/for-conversation/' . self::seg($conversationId, 'conversationId'),
        );
    }

    /** `GET /v1/tickets/queues` — the workspace's ticket queues. */
    public function queues(): mixed
    {
        return $this->httpGet('/v1/tickets/queues');
    }

    /**
     * `POST /v1/tickets/{id}/merge` — absorb `$loserId` into `$id`.
     *
     * The loser keeps its row and becomes `merged`, with `mergedIntoId`
     * pointing here, and its SLA clocks stop. Nothing is deleted.
     */
    public function merge(string $id, string $loserId): mixed
    {
        return $this->httpPost('/v1/tickets/' . self::seg($id) . '/merge', ['loserId' => $loserId]);
    }

    /**
     * `POST /v1/tickets/{id}/split` — carve a second ticket out of this one.
     *
     * For when one report was two problems. `title` is required: defaulting
     * to the origin's would produce two tickets nobody can tell apart in a
     * queue. `priority`, `type`, `assigneeUserId` and `tags` inherit from the
     * origin when omitted — and an explicit `null` for `assigneeUserId`
     * leaves the child unassigned, which is a different thing from omitting
     * it.
     *
     * @param array<string,mixed> $fields `description`, `priority`
     *                                    (`low|normal|high|urgent`), `type`
     *                                    (`question|incident|problem|task`),
     *                                    `assigneeUserId`, `tags`.
     */
    public function split(string $id, string $title, array $fields = []): mixed
    {
        return $this->httpPost(
            '/v1/tickets/' . self::seg($id) . '/split',
            ['title' => $title] + $fields,
        );
    }

    /**
     * `POST /v1/tickets/bulk` — one action across many tickets.
     *
     * ```php
     * $chatly->tickets->bulk(['t1', 't2'], 'status', ['status' => 'closed']);
     * $chatly->tickets->bulk($ids, 'assign', ['assigneeUserId' => null]); // unassign
     * ```
     *
     * The response reports `updated` plus the ids that were NOT updated and
     * why — **read it**. A 200 does not mean every id in your list moved;
     * ids outside your key's inbox scope are refused individually.
     *
     * @param array<int,string>   $ids     Ticket ids. At least one.
     * @param string              $action  `status`, `assign`, `priority` or `queue`.
     * @param array<string,mixed> $payload The value for that action: `status`,
     *                                     `assigneeUserId` (null unassigns),
     *                                     `priority`, `queueId` (null removes).
     */
    public function bulk(array $ids, string $action, array $payload = []): mixed
    {
        $body = ['ids' => array_values($ids), 'action' => $action];
        if ($payload !== []) {
            $body['payload'] = $payload;
        }

        return $this->httpPost('/v1/tickets/bulk', $body);
    }

    /**
     * `GET /v1/tickets/{id}/conversations` — every thread this ticket covers,
     * **origin first**.
     *
     * A bare array, `@PaginationExempt`. The point of the route is a tracker:
     * one ticket explaining an outage that fifty customers wrote in about, and
     * this is how all fifty are reachable from it.
     *
     * ─── THE FIELDS ARE NULLABLE, AND NOT BECAUSE OF PERMISSIONS ──────────
     *
     * `subject`, `status` and `channelType` are null when the link's
     * conversation was **hard-deleted** — the query LEFT joins, so the row
     * survives as a tombstone rather than vanishing from the count. Read
     * `isOrigin` to find the thread the ticket was opened from.
     */
    public function conversations(string $id): mixed
    {
        return $this->client->request('GET', '/v1/tickets/' . self::seg($id) . '/conversations');
    }

    /**
     * `GET /v1/tickets/open-count/{conversationId}` — "is there unfinished
     * work on this thread?"
     *
     * `{ openTickets: n }` — one integer in an object, which is why it is
     * `@PaginationExempt`.
     *
     * Note the path shape: the parameter is a CONVERSATION id, not a ticket
     * id, even though the route lives under `/v1/tickets`. It is gated on
     * being able to see that conversation — answering for a thread on an
     * inbox the key is kept off would both leak the fact and confirm the id
     * exists.
     */
    public function openCount(string $conversationId): mixed
    {
        return $this->client->request(
            'GET',
            '/v1/tickets/open-count/' . self::seg($conversationId, 'conversationId'),
        );
    }

    /**
     * `GET /v1/tickets/{id}/time` — logged time, with both totals.
     *
     * `{ items, totalMinutes, billableMinutes }` — an object with an `items`
     * key, and **not a page**: no cursor, nothing to walk. `billableMinutes`
     * is the figure that reaches an invoice and is COMPUTED server-side, so
     * do not re-derive it by filtering `items` on `billable` — that is the
     * whole reason it is sent.
     */
    public function time(string $id): mixed
    {
        return $this->client->request('GET', '/v1/tickets/' . self::seg($id) . '/time');
    }

    /**
     * `GET /v1/tickets/{id}/status-history` — transitions, **oldest first**,
     * with time spent in each status.
     *
     * The service caps at 500 rows, and **`truncated` is what distinguishes a
     * capped timeline from a short one** — without it, a 500-row answer and a
     * complete one look identical.
     *
     * Two nulls that are not errors: `previousStatus` is null on the ticket's
     * first row, and `timeInPreviousStatusSeconds` is null on the first row
     * RETURNED — which, on a truncated history, is not the ticket's first.
     * `status` at the top level comes from `tickets.status` rather than from
     * the last history row, so it is right even when rows are missing, and
     * `currentStatusForSeconds` is null when there is no recorded transition
     * at all.
     *
     * `changeSource` tells an `api` change from a `workflow`, an `sla_breach`
     * or a `customer_reply` — useful for working out what moved a ticket
     * nobody remembers touching.
     */
    public function statusHistory(string $id): mixed
    {
        return $this->client->request('GET', '/v1/tickets/' . self::seg($id) . '/status-history');
    }

    /**
     * `GET /v1/tickets/{id}/lineage` — what this ticket was split from or
     * follows up, and vice versa.
     *
     * ─── A NULL REF WITH A NON-NULL ID IS A DANGLING POINTER ──────────────
     *
     * The response carries the raw column values (`splitFromTicketId`,
     * `parentTicketId`) **alongside** the resolved refs (`splitFrom`,
     * `followUpOf`) precisely so you can tell them apart: neither column has
     * a foreign key to prevent the target being gone. `splitFrom: null` with
     * `splitFromTicketId: "…"` means the parent was deleted, which is a
     * different thing from "this was never split".
     *
     * Each list is capped at 25, with `splitIntoTruncated` /
     * `followUpsTruncated` saying so.
     */
    public function lineage(string $id): mixed
    {
        return $this->client->request('GET', '/v1/tickets/' . self::seg($id) . '/lineage');
    }
}
