<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Webhook subscriptions, and the DELIVERY history of each.
 *
 * `$chatly->webhooks->…`
 *
 * Verifying the signature on an INCOMING delivery is a different thing and
 * needs no API call: {@see \Chatly\Webhooks::verifySignature()}.
 *
 * A subscription's whole lifecycle is reachable from here — create, repin,
 * delete. {@see delete()} is the newest of the three and the docblock above
 * it says why it had to exist.
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

    /**
     * `GET /v1/webhooks` — the subscriptions this workspace has.
     *
     * A bare array, `@PaginationExempt`. No row carries the signing secret;
     * {@see create()} is the only response on this API that ever does.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/webhooks');
    }

    /**
     * `POST /v1/webhooks` — subscribe an endpoint to events.
     *
     * **The only response that carries `secret`.** No other route returns it
     * and there is no rotate; store it from this response or create a new
     * subscription. It is what {@see \Chatly\Webhooks::verifySignature()}
     * needs.
     *
     * ─── AN UNKNOWN `schemaVersion` IS REFUSED HERE AND TOLERATED LATER ───
     *
     * Loud on the write, generous on the read: a typo is a 400 now, costing
     * one round trip, whereas the dispatch path resolves an unrecognised
     * version to the latest rather than dropping the delivery. Refusing at
     * dispatch time would be an integration that goes quiet with no error
     * anywhere.
     *
     * @param string            $url        Where to POST.
     * @param array<int,string> $eventTypes At least one, from
     *                                      {@see events()}. An unknown name
     *                                      is a 400.
     * @param array<string,mixed> $options  Merged into the body.
     *                                      `description` (≤280) and
     *                                      `schemaVersion`, which defaults to
     *                                      the latest — pin it if you want
     *                                      payload stability across releases.
     */
    public function create(string $url, array $eventTypes, array $options = []): mixed
    {
        return $this->httpPost(
            '/v1/webhooks',
            ['url' => $url, 'eventTypes' => array_values($eventTypes)] + $options,
        );
    }

    /**
     * `GET /v1/webhooks/events` — every subscribable event type.
     *
     * `{ eventTypes: [...] }` — an object wrapping the list, not a bare
     * array. Static and workspace-independent. Read it rather than
     * hard-coding names: the set grows, and an event type that is PUBLISHED
     * internally is not necessarily on this allow-list — the `voice.*`
     * family, for instance, is not subscribable at all.
     */
    public function events(): mixed
    {
        return $this->httpGet('/v1/webhooks/events');
    }

    /**
     * `GET /v1/webhooks/schema-versions` — the pins available, oldest first.
     *
     * `{ latest, versions }`. Each version carries its own `eventTypes`,
     * which is the point: a consumer choosing a pin needs to see what each
     * one contains before writing the subscription.
     *
     * **`isDeprecated` is advisory and non-blocking.** Nothing refuses a
     * deprecated pin today, so treat it as a warning. A pin you never revisit
     * will keep working and keep being deprecated.
     */
    public function schemaVersions(): mixed
    {
        return $this->httpGet('/v1/webhooks/schema-versions');
    }

    /**
     * `PATCH /v1/webhooks/{id}/schema-version` — move an endpoint's pin.
     *
     * ─── READ `missingEventTypes` IN THE RESPONSE ─────────────────────────
     *
     * It names, **by name rather than by count**, the event types this
     * subscription is subscribed to that the version you just pinned does not
     * carry. A non-empty list means you have silently stopped receiving
     * those deliveries — the pin succeeded and the subscription is now
     * narrower than it reads.
     *
     * @param string $schemaVersion One of {@see schemaVersions()}. An
     *                              unrecognised value is a 400.
     */
    public function setSchemaVersion(string $id, string $schemaVersion): mixed
    {
        return $this->httpPatch(
            '/v1/webhooks/' . self::seg($id) . '/schema-version',
            ['schemaVersion' => $schemaVersion],
        );
    }

    /**
     * `DELETE /v1/webhooks/{id}` — remove a subscription.
     *
     * Answers **200 with `{ ok: true }`**, not a 204.
     *
     * ─── THE ROUTE A KEY COULD NOT REACH UNTIL 2026-10-14 ─────────────────
     *
     * It was the only write on this controller without `@AllowApiKey()`,
     * while create and the schema-version patch both had it — so a key could
     * ADD a delivery target and never remove one. A deploy pipeline that
     * registers an endpoint per environment leaked one into the customer's
     * workspace on every run with nothing able to clean up.
     *
     * **The workaround that used to be suggested — repoint the endpoint at a
     * dead url — is not one.** The subscription stays alive, every event
     * still fans out to it, and the delivery log fills with failures you then
     * have to read past in {@see deliveries()}. Delete it.
     *
     * It is safe by the argument {@see create()} already makes: both require
     * `integrations:write`, and a key that can add a delivery target can
     * already do the thing with consequences.
     */
    public function delete(string $id): mixed
    {
        return $this->httpDelete('/v1/webhooks/' . self::seg($id));
    }
}
