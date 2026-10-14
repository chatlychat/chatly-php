<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Per-URL signing secrets for customer cards.
 *
 * `$chatly->customerCardEndpoints->…`
 *
 * ─── WHY THIS EXISTS BESIDE {@see CustomerCards} ─────────────────────────
 *
 * By default each CARD has its own secret, and a request to a url that serves
 * several cards is signed by the leading card of the group and carries
 * `x-chatly-card-id` so your verifier knows which. That means one verifier
 * holding several secrets, and a rotation with no overlap.
 *
 * Give the URL its own secret instead and both problems go away: one secret
 * per endpoint, and a two-phase rotation ({@see rotateSecret()} then
 * {@see activateSecret()}) with a real overlap window.
 *
 * **The trade is that the signed request stops carrying `x-chatly-card-id`.**
 * That is a change at your verifier, not a free upgrade — read
 * {@see create()} before switching a live endpoint.
 */
final class CustomerCardEndpoints extends AbstractResource
{
    /**
     * `GET /v1/customer-card-endpoints` — one row per distinct card url.
     *
     * A bare array, `@PaginationExempt` — a workspace has at most 25 cards.
     * **No secret, no prefix of one, no hash of one.** `cardKeys` is the
     * cards pointing at each url, and an EMPTY `cardKeys` means the secret
     * signs nothing — a leftover from a card that was repointed or deleted.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/customer-card-endpoints');
    }

    /**
     * `POST /v1/customer-card-endpoints` — give one url its own secret.
     *
     * The response carries `secret`, once.
     *
     * ─── DEPLOY THE SECRET BEFORE YOU CALL THIS ───────────────────────────
     *
     * From this call on, every request to that url is signed with THIS secret
     * and **no longer carries `x-chatly-card-id`**. A verifier that is still
     * looking up a per-card secret by that header starts failing immediately,
     * so the deploy comes first and this call second.
     *
     * A url no configured card uses is a **400**; a url that already has an
     * endpoint secret is a **409**.
     *
     * @param string $url Must match a configured card's url exactly.
     */
    public function create(string $url): mixed
    {
        return $this->httpPost('/v1/customer-card-endpoints', ['url' => $url]);
    }

    /**
     * `POST /v1/customer-card-endpoints/{id}/rotate-secret` — mint the NEXT
     * secret. It signs nothing yet.
     *
     * Phase one of two. The new secret comes back once with
     * `active: false`; the CURRENT secret keeps signing every request until
     * {@see activateSecret()} is called. That gap is the whole point — deploy
     * the new secret alongside the old one, then promote it.
     *
     * Calling this again before activating **replaces** the pending secret
     * rather than stacking a second one, so a lost secret is recoverable by
     * rotating again.
     *
     * Takes no body.
     */
    public function rotateSecret(string $id): mixed
    {
        return $this->httpPost('/v1/customer-card-endpoints/' . self::seg($id) . '/rotate-secret');
    }

    /**
     * `POST /v1/customer-card-endpoints/{id}/activate-secret` — promote the
     * pending secret. It starts signing now.
     *
     * Phase two. The pending secret becomes the only one that signs and the
     * previous one is **gone** — so make sure the new one is live at your end
     * first. A **409** if nothing is pending, which is also how you tell
     * "already promoted" from "never rotated".
     *
     * Takes no body.
     */
    public function activateSecret(string $id): mixed
    {
        return $this->httpPost('/v1/customer-card-endpoints/' . self::seg($id) . '/activate-secret');
    }

    /**
     * `DELETE /v1/customer-card-endpoints/{id}` — give up the endpoint secret.
     *
     * **This is a change at your verifier, not a cleanup.** Requests to that
     * url go back to being signed by the LEADING CARD of the group and to
     * carrying `x-chatly-card-id` again. A verifier written against the
     * endpoint secret alone starts failing the moment this returns.
     *
     * Answers 200 with a body, not a 204.
     */
    public function delete(string $id): mixed
    {
        return $this->httpDelete('/v1/customer-card-endpoints/' . self::seg($id));
    }
}
