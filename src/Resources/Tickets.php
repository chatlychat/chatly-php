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
}
