<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Webhook DELIVERY history — what we sent, what came back.
 *
 * `$chatly->webhooks->…`
 *
 * Verifying the signature on an INCOMING delivery is a different thing and
 * needs no API call: {@see \Chatly\Webhooks::verifySignature()}.
 */
final class Webhooks extends AbstractResource
{
    /**
     * `GET /v1/webhooks/deliveries` — attempts, newest first.
     *
     * @param array<string,mixed> $query `cursor`, `limit`, plus `endpointId`
     *                                   and `status` to narrow.
     */
    public function deliveries(array $query = []): Page
    {
        return $this->httpPage('/v1/webhooks/deliveries', $query);
    }

    /**
     * Every delivery attempt, across pages.
     *
     * @param array<string,mixed> $query
     *
     * @return \Generator<int,mixed>
     */
    public function autoDeliveries(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/webhooks/deliveries', $query, $maxPages);
    }

    /**
     * `GET /v1/webhooks/deliveries/{id}` — one attempt, including the exact
     * body we POSTed.
     *
     * That body is what you need to re-verify a signature offline: pass it to
     * {@see \Chatly\Webhooks::verifySignature()} with `tolerance: INF`, since
     * its timestamp is as old as the delivery.
     */
    public function delivery(string $id): mixed
    {
        return $this->client->request('GET', '/v1/webhooks/deliveries/' . self::seg($id));
    }

    /**
     * `POST /v1/webhooks/deliveries/{id}/redeliver` — re-send the recorded
     * payload.
     *
     * **Queued, not delivered.** The response says `queued: true`; the
     * outcome shows up as a NEW attempt in {@see deliveries()}. It goes to
     * the endpoint's CURRENT url, which may not be the one the original
     * attempt used.
     */
    public function redeliver(string $id): mixed
    {
        return $this->httpPost('/v1/webhooks/deliveries/' . self::seg($id) . '/redeliver');
    }
}
