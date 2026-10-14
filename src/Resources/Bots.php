<?php

declare(strict_types=1);

namespace Chatly\Resources;

use Chatly\Pagination\Page;

/**
 * Bots — Chatly-run AI personas, and your own externally hosted agents.
 *
 * `$chatly->bots->…`
 *
 * ─── TWO KINDS OF BOT BEHIND ONE COLLECTION ──────────────────────────────
 *
 * A **Chatly persona** is a prompt, a model, a KB scope and a tool list that
 * we run. An **external agent** (`type: 'external'`) is a webhook we POST a
 * signed delivery to and wait for a reply from; the `external*` methods and
 * the delivery log below only apply to those.
 *
 * Which bot answers a given conversation is not decided here — that is the
 * routing table, {@see BotRoutes}.
 */
final class Bots extends AbstractResource
{
    /**
     * `GET /v1/bots` — one page of configured bots.
     *
     * A real cursor page. Rows are PARTIAL when `?fields=` projects, so a
     * field you did not ask for is absent rather than null.
     *
     * @param array<string,mixed> $query `cursor`, `limit`, `sort`, `fields`.
     */
    public function list(array $query = []): Page
    {
        return $this->httpPage('/v1/bots', $query);
    }

    /**
     * Every bot, across pages.
     *
     * @param array<string,mixed> $query    As {@see list()}.
     * @param int|null            $maxPages Stop early. Null walks to the end.
     *
     * @return \Generator<int,mixed>
     */
    public function autoList(array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/bots', $query, $maxPages);
    }

    /** `GET /v1/bots/{id}` — one bot, in full. */
    public function get(string $id): mixed
    {
        return $this->client->request('GET', '/v1/bots/' . self::seg($id));
    }

    /**
     * `POST /v1/bots` — create a persona, or register an external agent.
     *
     * ─── THE BODY IS A UNION OF TWO DISJOINT SHAPES ───────────────────────
     *
     * Omit `type` (or send `'chatly'`) for a persona: `systemPrompt`,
     * `model`, `enabledTools`, `kbScope`, `guardrails`. Send
     * `type: 'external'` for your own agent: `webhookUrl`, `timeoutSeconds`,
     * `fallbackMessage`.
     *
     * **Neither half is strict, so a field from the wrong one is DROPPED, not
     * refused.** Copy the external example while meaning to create a persona
     * and you get a 201 and a bot with no prompt. That exact confusion was
     * reported against the generated docs, which rendered only the first
     * member of the union.
     *
     * On `type: 'external'` the response carries a signing secret and a `cb_`
     * token **shown exactly once and unrecoverable afterwards** — store them
     * before you do anything else with the response.
     *
     * @param array<string,mixed> $bot
     */
    public function create(array $bot): mixed
    {
        return $this->httpPost('/v1/bots', $bot);
    }

    /**
     * `PATCH /v1/bots/{id}` — partial update of a persona.
     *
     * Send only what changes. For the webhook, timeout and fallback copy of
     * an EXTERNAL agent use {@see updateExternal()}: those fields live on a
     * different route and are not accepted here.
     *
     * @param array<string,mixed> $changes `name`, `description`,
     *                                     `systemPrompt` (20–20,000),
     *                                     `model`, `status`, `enabledTools`,
     *                                     `kbScope`, `maxToolCalls` (1–32),
     *                                     `guardrails`.
     */
    public function update(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/bots/' . self::seg($id), $changes);
    }

    /**
     * `PATCH /v1/bots/{id}/external` — an external agent's delivery settings.
     *
     * ─── ABSENCE MEANS ABSENCE, DELIBERATELY ──────────────────────────────
     *
     * Every field is optional with no default, which is load-bearing for
     * `canStartConversations`: the create schema's version of that field
     * defaults to `false`, and a default on a PATCH field turns "leave it
     * alone" into "revoke it" for anybody who sends only `timeoutSeconds`.
     *
     * An EMPTY body is a 400 ("No fields to update") rather than a no-op 200.
     *
     * @param array<string,mixed> $changes `webhookUrl`, `timeoutSeconds`
     *                                     (1–60), `fallbackMessage` (1–500),
     *                                     `canStartConversations`.
     */
    public function updateExternal(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/bots/' . self::seg($id) . '/external', $changes);
    }

    /**
     * `POST /v1/bots/{id}/external/rotate-secret` — new signing secret.
     *
     * **The old secret stopped working the moment this returned**, and the new
     * one is shown once. There is no overlap window, so deploy the new secret
     * to your verifier in the same breath — every delivery between this call
     * and that deploy fails signature verification at your end.
     *
     * Takes no body.
     */
    public function rotateExternalSecret(string $id): mixed
    {
        return $this->httpPost('/v1/bots/' . self::seg($id) . '/external/rotate-secret');
    }

    /**
     * `GET /v1/bots/{id}/deliveries` — an external agent's delivery log.
     *
     * Newest first. **A list row does NOT carry the signed payload** — a page
     * of fifty would be megabytes of transcript. Read one with
     * {@see delivery()} when you need the body.
     *
     * @param array<string,mixed> $query `cursor`, `limit`, and `status` to
     *                                   narrow to failures.
     */
    public function deliveries(string $id, array $query = []): Page
    {
        return $this->httpPage('/v1/bots/' . self::seg($id) . '/deliveries', $query);
    }

    /**
     * Every delivery to one agent, across pages.
     *
     * @param array<string,mixed> $query As {@see deliveries()}.
     *
     * @return \Generator<int,mixed>
     */
    public function autoDeliveries(string $id, array $query = [], ?int $maxPages = null): \Generator
    {
        yield from $this->httpWalk('/v1/bots/' . self::seg($id) . '/deliveries', $query, $maxPages);
    }

    /**
     * `GET /v1/bots/{id}/deliveries/{deliveryId}` — one delivery, with the
     * exact bytes that were signed and POSTed.
     *
     * That `payload` is what you need to re-verify a signature offline: hand
     * it to {@see \Chatly\Webhooks::verifySignature()} with `tolerance: INF`,
     * since its timestamp is as old as the delivery.
     */
    public function delivery(string $id, string $deliveryId): mixed
    {
        return $this->client->request(
            'GET',
            '/v1/bots/' . self::seg($id) . '/deliveries/' . self::seg($deliveryId, 'deliveryId'),
        );
    }

    /**
     * `POST /v1/bots/{id}/deliveries/{deliveryId}/redeliver` — send a
     * recorded delivery again, from attempt 0.
     *
     * **Queued, not delivered.** The retry ladder starts over and the outcome
     * shows up as a new row in {@see deliveries()}. The payload is the one we
     * stored, so a redelivery replays the original conversation state rather
     * than today's.
     *
     * Takes no body.
     */
    public function redeliver(string $id, string $deliveryId): mixed
    {
        return $this->httpPost(
            '/v1/bots/' . self::seg($id) . '/deliveries/'
            . self::seg($deliveryId, 'deliveryId') . '/redeliver',
        );
    }

    /**
     * `POST /v1/bots/{id}/test-delivery` — send a signed test and report back.
     *
     * The call to make while standing an external agent up. The response
     * carries what YOUR endpoint answered — up to 2,000 characters of its
     * body — plus the signature header we sent and the exact payload we
     * signed, which between them settle every "is it my verifier or your
     * signature" argument without a packet capture.
     *
     * Takes no body.
     */
    public function testDelivery(string $id): mixed
    {
        return $this->httpPost('/v1/bots/' . self::seg($id) . '/test-delivery');
    }

    /**
     * `GET /v1/bots/{id}/analytics` — one bot's performance rollup.
     *
     * ─── READ THE NULLS, THEY ARE NOT ZEROS ───────────────────────────────
     *
     * Latency is p50/p95/max and never a mean — one 30s timeout drags the
     * mean of a hundred 200ms calls to 498ms, which describes neither
     * population. **Every rate is `null`, not `0`, when its `sampleSize` is
     * 0**, so a brand-new bot reports "no data" rather than "0% resolution".
     * `volume.retrying`, `reliability.fallbackCount` and
     * `reliability.retriedCount` are null for a Chatly persona, which has no
     * delivery ladder to count.
     *
     * Needs `reports:read`, not a bot scope.
     *
     * @param int|null $days Window, clamped server-side. A non-numeric value
     *                       is a 400 rather than a silent default, and
     *                       `from`/`to`/`weeks`/`date` are refused BY NAME —
     *                       this report takes `days` only.
     */
    public function analytics(string $id, ?int $days = null): mixed
    {
        return $this->client->request(
            'GET',
            '/v1/bots/' . self::seg($id) . '/analytics',
            $days === null ? [] : ['days' => $days],
        );
    }
}
