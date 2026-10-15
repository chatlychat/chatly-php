<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Visitors — opening a thread with somebody who has not written to you.
 *
 * `$chatly->visitors->…`
 *
 * ─── ONE METHOD, AND THE OTHER TWO ROUTES ARE NOT AN OVERSIGHT ───────────
 *
 * `GET /v1/visitors/live` (who is on the site right now) and
 * `GET /v1/visitors/{contactId}/pages` (one visitor's recent page views) sit
 * on the same controller and carry **no `@AllowApiKey()`**, so a `ck_` key
 * gets a 403 from both. They are therefore not surface and must not be added
 * here: shipping a method that always fails is worse than not shipping it,
 * because the failure arrives at runtime, in production, in somebody else's
 * integration. Reach them with a signed-in session, or not at all.
 *
 * {@see startConversation()} is the only key-accessible route on the
 * controller, and it opened to keys on 2026-10-14.
 */
final class Visitors extends AbstractResource
{
    /**
     * `POST /v1/visitors/{contactId}/start-conversation` — open a thread with
     * a contact who did **not** write first. Scope `conversations:write`.
     *
     * Answers `{ conversationId }`. Takes no body and no query parameters:
     * the contact id is the whole input.
     *
     * ─── A 403 HERE IS PROBABLY NOT YOUR SCOPES ──────────────────────────
     *
     * This route is **off by default, per workspace**. The flag is
     * `settings.proactiveOutreach.apiKeysMayStartConversations`; an owner or
     * admin turns it on with `PATCH /v1/workspace/settings`, which needs a
     * signed-in person and so is not reachable from this SDK. You can read
     * its current value with `$chatly->workspace->settings()`, which is.
     *
     * So a {@see \Chatly\Errors\PermissionException} from this method means
     * one of two quite different things, and the workspace setting is the
     * likelier one: **the scope is not the opt-in.** `conversations:write`
     * was already grantable and is already held by keys minted for ticket
     * bulk actions and schedule-send, so opening this route on the
     * permission alone would have handed cold outreach to credentials nobody
     * re-reviewed. Check the setting before you go looking at your key.
     *
     * ─── THE OTHER TWO THINGS TO KNOW ────────────────────────────────────
     *
     * **Three per contact per hour.** Counted in Redis per workspace and per
     * CONTACT rather than per caller — the abuse shape here is N threads
     * aimed at one person, which a per-caller limit cannot see. The fourth
     * call inside the window is a
     * {@see \Chatly\Errors\RateLimitException}.
     *
     * ─── TWO DIFFERENT 429s, AND ONE OF THEM HAS NO Retry-After ──────────
     *
     * `getRetryAfter()` reads response HEADERS, and the per-contact limit
     * sends none. `consumePerContactOutreachToken` throws a shared
     * `RateLimitedError`, whose TTL travels in the response BODY at
     * `error.details.retryAfterSeconds`; the exception filter sets no
     * headers, and the only thing that synthesises a plain `Retry-After` is a
     * Fastify `onSend` hook that derives it from the `Retry-After-{bucket}`
     * headers `@nestjs/throttler` emits. This refusal does not come from the
     * throttler, so there are no bucket headers and nothing to derive from.
     *
     * So on the per-contact limit **`getRetryAfter()` returns null** and the
     * wait is readable only from `getData()['details']['retryAfterSeconds']`.
     * The route also inherits the global throttler buckets (`short`, 30/s,
     * and `login`, 100/15min), and a 429 from THOSE does carry the suffixed
     * headers and so does populate `getRetryAfter()`. Branch on the presence
     * of the value, not on the status.
     *
     * **An offline visitor is not notified.** The greeting is persisted and
     * the widget renders it from history on their next page view; no email,
     * no push, no provider message, and nothing queued or dropped. An
     * integration that needs to reach somebody who has left the site wants an
     * email or SMS conversation, not this.
     *
     * ─── IDEMPOTENCY: REQUIRED, UNLIKE ITS NEAREST SIBLING ───────────────
     *
     * The controller declares `@RequireIdempotencyKey()` at class level and
     * this handler does **not** opt out, so the key is demanded: without it
     * the API answers **428** in production. That is the opposite of
     * {@see Channels::rotateSecret()}, which declares `@NoIdempotency()` and
     * merely tolerates one. This SDK always sends a key, so the 428 is
     * unreachable through it — but pin one with
     * `$chatly->withIdempotencyKey(...)` if you want a retry of the *same*
     * logical outreach to replay rather than open a second thread.
     */
    public function startConversation(string $id): mixed
    {
        return $this->httpPost('/v1/visitors/' . self::seg($id, 'contactId') . '/start-conversation');
    }
}
