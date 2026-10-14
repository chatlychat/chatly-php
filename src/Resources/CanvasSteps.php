<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Canvas steps — the half-finished interactive moments in a conversation.
 *
 * `$chatly->canvasSteps->…`
 *
 * ─── WHAT A STEP IS ──────────────────────────────────────────────────────
 *
 * When your app answers a widget canvas with `{ pending: true }`, the visitor
 * is left watching a progress line and a step row is written. You then go and
 * do the slow thing — take a payment, call a warehouse — and come back with
 * {@see resolve()} to put the next screen in front of them.
 *
 * These two routes are the whole of that contract from your side.
 */
final class CanvasSteps extends AbstractResource
{
    /**
     * `GET /v1/canvas-steps/{id}` — is this step still pending?
     *
     * `status` is `pending`, `resolved` or `expired`, and this route exists
     * for exactly one question an integrator has to answer: *did I take money
     * for a step I never managed to resolve?* `settledAt` is when it stopped
     * being pending, either way, and `expiresAt` is the deadline.
     *
     * **It carries no canvas.** What the visitor is looking at is readable
     * from the conversation; this is the step's state, not its content.
     */
    public function get(string $id): mixed
    {
        return $this->client->request('GET', '/v1/canvas-steps/' . self::seg($id));
    }

    /**
     * `POST /v1/canvas-steps/{id}/resolve` — replace the progress line.
     *
     * Publishes `message.updated`, so an open widget updates without a
     * reload. The conversation and the message come from the STEP ID and are
     * never read from your request — which is why the response echoes them
     * back: they are the two ids you did not supply, and the pair to write
     * into your own ledger beside the charge.
     *
     * ─── TWO REFUSALS WORTH TELLING APART ─────────────────────────────────
     *
     * **404** — the id names nothing in this workspace. Including, and this
     * is the trap, when you resolve from inside the very request that
     * answered `{ pending: true }`: the row is written *after* we answer the
     * visitor, so there is a window in which your own step id does not exist
     * yet. Resolve from a job, not from that handler.
     *
     * **409** — already resolved, or expired. Safe to treat as "somebody else
     * got there first": the claiming update carries `status = 'pending'` in
     * its WHERE, so exactly one resolve ever wins.
     *
     * That built-in single-use guard is why the route is
     * `@OptionalIdempotency()` rather than demanding a key. This SDK sends
     * one regardless; the API honours it and never requires it.
     *
     * @param array<string,mixed> $canvas The next canvas to show — the same
     *                                    shape your webhook would have
     *                                    returned had it been able to answer
     *                                    immediately. The body is strict, so
     *                                    a stray field is a 400 rather than a
     *                                    dropped one.
     */
    public function resolve(string $id, array $canvas): mixed
    {
        return $this->httpPost(
            '/v1/canvas-steps/' . self::seg($id) . '/resolve',
            ['canvas' => $canvas],
        );
    }
}
