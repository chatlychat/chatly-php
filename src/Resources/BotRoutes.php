<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Bot routing — which assistant answers which conversation.
 *
 * `$chatly->botRoutes->…`
 *
 * An ORDERED table. The first route whose conditions match wins, so
 * `priority` is the whole semantics of this resource — see {@see resolve()}
 * for a way to find out what a change would do before you make it.
 */
final class BotRoutes extends AbstractResource
{
    /**
     * `GET /v1/bot-routes` — every rule, IN EVALUATION ORDER.
     *
     * A bare array, `@PaginationExempt`: a workspace keeps a handful of
     * routes. The order is the thing being returned — do not re-sort it
     * client-side and expect the result to describe what the server does.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/bot-routes');
    }

    /**
     * `GET /v1/bot-routes/fields` — the condition vocabulary, with types.
     *
     * `{ fields: [...] }`: the keys a condition may be keyed on and the
     * operators each accepts. A static catalogue with no tenant data in it,
     * which is why it needs only `triggers:read` — build a condition editor
     * from this rather than hard-coding field names that may move.
     */
    public function fields(): mixed
    {
        return $this->httpGet('/v1/bot-routes/fields');
    }

    /**
     * `POST /v1/bot-routes` — add a routing rule.
     *
     * @param array<string,mixed> $route `botId` (required) and `name`
     *                                   (required); `priority` (0–10,000),
     *                                   `enabled`, `channelTypes` (≤20),
     *                                   `conditions` (≤20, keyed on
     *                                   {@see fields()}), `brandId`, and
     *                                   `evaluate` — WHEN the route is
     *                                   consulted, which defaults in the
     *                                   database column rather than in the
     *                                   request schema, so omitting it gets
     *                                   the database's answer and the two can
     *                                   never disagree.
     */
    public function create(array $route): mixed
    {
        return $this->httpPost('/v1/bot-routes', $route);
    }

    /**
     * `PATCH /v1/bot-routes/{id}` — edit a rule.
     *
     * Partial. Note that moving `priority` reorders evaluation for every
     * other route too, so a one-field patch here can change which bot answers
     * conversations this rule does not even match.
     *
     * @param array<string,mixed> $changes Any subset of the create fields.
     */
    public function update(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/bot-routes/' . self::seg($id), $changes);
    }

    /**
     * `DELETE /v1/bot-routes/{id}` — remove a rule.
     *
     * Answers **200 with `{ deleted: true }`**, not a 204. Conversations
     * already assigned to the rule's bot stay with it; this only stops future
     * ones being routed there.
     */
    public function delete(string $id): mixed
    {
        return $this->httpDelete('/v1/bot-routes/' . self::seg($id));
    }

    /**
     * `POST /v1/bot-routes/resolve` — DRY RUN the table.
     *
     * ─── THE ONLY WAY TO SEE WHY A ROUTE DID NOT MATCH ────────────────────
     *
     * Answers with the selected route **or null**, plus EVERY route with its
     * own verdict in evaluation order, plus the event object the conditions
     * were actually evaluated against. The third part is the one that saves
     * the afternoon: a condition on `page.path` that never fires is usually a
     * context key that is not where you think it is, and this shows you the
     * input rather than making you infer it.
     *
     * Changes nothing — it needs only `triggers:read`. The route is
     * `@OptionalIdempotency()` because it is a read wearing a POST (the input
     * is too big for a query string); this SDK sends a key anyway, which the
     * API honours and never demands.
     *
     * ```php
     * $verdict = $chatly->botRoutes->resolve([
     *     'page'    => ['url' => 'https://acme.com/pricing'],
     *     'contact' => ['identityVerified' => true, 'attributes' => ['plan' => 'pro']],
     * ]);
     * ```
     *
     * @param array<string,mixed> $event `page` (`url` and/or `path` — either
     *                                   one is derived from the other, so a
     *                                   route keyed on `page.path` matches
     *                                   whichever you sent), `context`
     *                                   (`verified` / `unverified`, exactly
     *                                   as a condition reads them), `contact`
     *                                   (`identityVerified`, `attributes`).
     *                                   All optional and all defaulted, so an
     *                                   empty body resolves the unconditioned
     *                                   routes.
     */
    public function resolve(array $event = []): mixed
    {
        return $this->httpPost('/v1/bot-routes/resolve', $event);
    }
}
