<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Customer cards — panels in the agent sidebar, rendered from YOUR backend.
 *
 * `$chatly->customerCards->…`
 *
 * ─── HOW A CARD WORKS ────────────────────────────────────────────────────
 *
 * You configure a url. When an agent opens a conversation, we POST to it with
 * the contact, signed with a secret this resource mints, and render the
 * blocks you answer with. So the card's contents live in your system and
 * never in ours — which is the point, and also why everything here is about
 * urls, headers and secrets rather than data.
 *
 * A workspace may configure at most 25 cards.
 */
final class CustomerCards extends AbstractResource
{
    /**
     * `GET /v1/customer-cards` — every configured card.
     *
     * A bare array, `@PaginationExempt`. **Header NAMES only** — no values,
     * no secrets, no prefixes of either. There is no route anywhere that
     * reads a card secret back; {@see rotateSecret()} is the only way to get
     * a working one, and it gives you a new one.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/customer-cards');
    }

    /**
     * `POST /v1/customer-cards` — configure a card. Returns the secret ONCE.
     *
     * The response is the card with `secret` **beside** it, and it is the only
     * response on this resource that carries one. Store it before you do
     * anything else.
     *
     * ─── THE URL IS FETCHED BY OUR SERVER, NOT A BROWSER ──────────────────
     *
     * So the guard on it is about SSRF, not mixed content: https only, no
     * embedded credentials, and nothing resolving to a private IPv4 range,
     * link-local (including the cloud metadata address), `.local` /
     * `.internal`, a bare intranet name, or an IPv6 literal. A url that
     * trips any of those is a 400 at configure time rather than a card that
     * silently never renders.
     *
     * @param array<string,mixed> $card `key`, `title` (≤80), `url` (≤2000),
     *                                  `ttlSeconds` and optionally `headers`
     *                                  (names are letters, digits and hyphen
     *                                  only; a reserved one is refused —
     *                                  those are how your endpoint knows the
     *                                  request came from us) and `enabled`
     *                                  (defaults true). The body is strict: a
     *                                  misspelled field is a 400, not a
     *                                  dropped one.
     */
    public function create(array $card): mixed
    {
        return $this->httpPost('/v1/customer-cards', $card);
    }

    /**
     * `PATCH /v1/customer-cards/{id}` — change a card.
     *
     * An absent field is unchanged. **`headers: {}` CLEARS them** — that is
     * the one place where sending a value is not the same as leaving the key
     * out, so a form that round-trips an empty map wipes your auth header.
     *
     * Answers with the card as stored. No secret.
     *
     * @param array<string,mixed> $changes Any subset of the create fields,
     *                                     plus `position` (0–1000) to reorder
     *                                     the sidebar.
     */
    public function update(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/customer-cards/' . self::seg($id), $changes);
    }

    /**
     * `POST /v1/customer-cards/{id}/rotate-secret` — mint a new signing secret.
     *
     * **Update your endpoint BEFORE calling this.** The previous secret stops
     * verifying immediately and the new one is shown once; there is no
     * overlap window on a per-card secret. If you need one, give the url its
     * own endpoint secret instead — {@see CustomerCardEndpoints} has a
     * two-phase rotate that does overlap.
     *
     * Takes no body.
     */
    public function rotateSecret(string $id): mixed
    {
        return $this->httpPost('/v1/customer-cards/' . self::seg($id) . '/rotate-secret');
    }

    /**
     * `DELETE /v1/customer-cards/{id}` — remove a card.
     *
     * Answers 200 with a body, not a 204. The card stops being requested;
     * nothing in your system is told.
     */
    public function delete(string $id): mixed
    {
        return $this->httpDelete('/v1/customer-cards/' . self::seg($id));
    }
}
