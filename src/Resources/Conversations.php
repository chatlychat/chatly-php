<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Conversations and the messages in them.
 *
 * `$chatly->conversations->…`
 */
final class Conversations extends AbstractResource
{
    /**
     * `GET /v1/conversations` — one page.
     *
     * Filtering uses the standard `filter[]` surface — `?filter[]=status:open`
     * — which this SDK sends as repeated parameters:
     *
     * ```php
     * $chatly->conversations->list(['filter[]' => ['status:open'], 'limit' => 50]);
     * ```
     *
     * @param array<string,mixed> $query `cursor`, `limit`, `filter[]`, `sort`, `fields`.
     */
    public function list(array $query = []): Page
    {
        return $this->httpPage('/v1/conversations', $query);
    }

    /**
     * Every conversation, across pages.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function autoList(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/conversations', $query, $maxPages);
    }

    /** `GET /v1/conversations/{id}` — one thread's metadata. */
    public function get(string $id): mixed
    {
        return $this->client->request('GET', '/v1/conversations/' . self::seg($id));
    }

    /**
     * `GET /v1/conversations/{id}/messages` — one page of a thread.
     *
     * Read the thread before replying to it. Each message carries an
     * `llmText` field — that turn rendered for a language model — which saves
     * reconstructing it from `body`, `blocks`, `attachments` and the
     * `kind`/`authorType` pair that decides whether the customer ever saw the
     * text.
     *
     * @param array<string,mixed> $query `cursor`, `limit` (default 50).
     */
    public function messages(string $id, array $query = []): Page
    {
        return $this->httpPage('/v1/conversations/' . self::seg($id) . '/messages', $query);
    }

    /**
     * Every message in a thread, across pages.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function autoMessages(string $id, array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/conversations/' . self::seg($id) . '/messages', $query, $maxPages);
    }

    /**
     * `POST /v1/conversations/{id}/messages` — reply.
     *
     * The one write into a thread an API key can make.
     *
     * Who it is attributed to is decided server-side from the key: a plain
     * key sends as `system` with no author, and a key linked to a machine
     * user sends as that agent. Link the key to a machine user if you want
     * the reply to look like it came from someone.
     *
     * ```php
     * $chatly->conversations->send($id, 'On it — give me five minutes.');
     * $chatly->conversations->send($id, 'Noted internally', ['kind' => 'note']);
     * ```
     *
     * @param array<string,mixed> $options Merged into the body. `kind`
     *                                     (`text`, `rich`, `note`,
     *                                     `system_event`; defaults to `text`
     *                                     server-side), `attachments`,
     *                                     `metadata`, `blocks`.
     */
    public function send(string $id, string $body, array $options = []): mixed
    {
        return $this->httpPost(
            '/v1/conversations/' . self::seg($id) . '/messages',
            ['body' => $body] + $options,
        );
    }

    /**
     * `GET /v1/conversations/unattended` — threads waiting on a human.
     *
     * A fixed-shape read, not a paginated list.
     *
     * @param array<string,mixed> $query
     */
    public function unattended(array $query = []): mixed
    {
        return $this->httpGet('/v1/conversations/unattended', $query);
    }

    /**
     * `GET /v1/conversations/{id}/agent-handling` — "do I still own this
     * thread?"
     *
     * The poll a BYO agent makes to discover that a human took over. A read,
     * so a read-only monitoring key can make it.
     */
    public function agentHandling(string $id): mixed
    {
        return $this->client->request('GET', '/v1/conversations/' . self::seg($id) . '/agent-handling');
    }

    /**
     * `PUT /v1/conversations/{id}/agent-handling` — report progress.
     *
     * `$state` is `in_progress`, `handled` or `handed_off`. PUT because the
     * request carries the whole desired value of a single-valued state.
     *
     * **Only a machine principal may call this.** A key that is not linked to
     * a machine user gets a 403, and so does a human session: the column
     * records what a customer's own agent reports about its own progress, and
     * a human who wants the thread assigns it instead.
     */
    public function setAgentHandling(string $id, string $state, ?string $reason = null): mixed
    {
        $body = ['state' => $state];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return $this->httpPut('/v1/conversations/' . self::seg($id) . '/agent-handling', $body);
    }

    /**
     * `GET /v1/conversations/{id}/generated-replies` — pending AI proposals
     * on this thread.
     */
    public function generatedReplies(string $id): mixed
    {
        return $this->client->request('GET', '/v1/conversations/' . self::seg($id) . '/generated-replies');
    }

    /**
     * `POST /v1/conversations/{id}/generated-replies` — propose a reply for a
     * human to approve.
     *
     * This is how a BYO agent suggests words without sending them. The
     * proposal lands in the agent's panel; approving it is a separate action
     * a person takes.
     *
     * @param string|null         $basedOnMessageId The inbound message this
     *                            answers. It must be a message ON this
     *                            conversation and FROM the contact — the API
     *                            refuses an agent's own message by name,
     *                            because a proposal anchored to your own last
     *                            reply would read as never stale no matter
     *                            what the customer said next. Omit it for an
     *                            unprompted follow-up.
     * @param array<string,mixed> $options Merged into the body.
     */
    public function generateReply(
        string $id,
        string $body,
        ?string $basedOnMessageId = null,
        array $options = [],
    ): mixed {
        $payload = ['body' => $body];
        if ($basedOnMessageId !== null) {
            $payload['basedOnMessageId'] = $basedOnMessageId;
        }

        return $this->httpPost(
            '/v1/conversations/' . self::seg($id) . '/generated-replies',
            $payload + $options,
        );
    }

    /**
     * `GET /v1/conversations/{id}/scheduled-messages` — pending sends on this
     * thread.
     */
    public function scheduledMessages(string $id): mixed
    {
        return $this->client->request('GET', '/v1/conversations/' . self::seg($id) . '/scheduled-messages');
    }

    /**
     * `POST /v1/conversations/{id}/scheduled-messages` — send later.
     *
     * ```php
     * $chatly->conversations->scheduleMessage(
     *     $id,
     *     'Following up on your ticket.',
     *     new DateTimeImmutable('+2 hours'),
     * );
     * ```
     *
     * A `DateTimeInterface` is converted to UTC `…Z`, which is the only form
     * the API's schema accepts — an offset like `+02:00` is a 400. A string
     * is sent through untouched, so you can pass your own.
     *
     * Requires both `messages:write` and `conversations:write`: a scheduled
     * message IS a message, and a key allowed to schedule but not to send
     * could otherwise send one by scheduling it for a second from now.
     */
    public function scheduleMessage(string $id, string $body, \DateTimeInterface|string $sendAt): mixed
    {
        return $this->httpPost(
            '/v1/conversations/' . self::seg($id) . '/scheduled-messages',
            ['body' => $body, 'sendAt' => self::instant($sendAt)],
        );
    }

    /**
     * `DELETE /v1/conversations/scheduled-messages/{scheduledId}` — cancel a
     * pending send.
     *
     * Note the path: it hangs off the collection, not off a conversation, so
     * you need only the scheduled-message id. A send that already went
     * answers with what happened rather than a 200 implying the customer
     * never saw it.
     */
    public function cancelScheduled(string $scheduledId): mixed
    {
        return $this->httpDelete(
            '/v1/conversations/scheduled-messages/' . self::seg($scheduledId, 'scheduledId'),
        );
    }
}
