<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Transactional email copy — the five emails a workspace may override.
 *
 * `$chatly->emailTemplates->…`
 *
 * ─── A FIXED CATALOGUE, NOT A COLLECTION ─────────────────────────────────
 *
 * There are exactly five kinds and you cannot add a sixth. The id in every
 * path is the KIND — `workspace_invite`, not a uuid — so these routes read
 * as "the subject and body for this kind in this workspace", and a workspace
 * with no overrides still has five of them.
 */
final class EmailTemplates extends AbstractResource
{
    /**
     * The five overridable kinds.
     *
     * A closed enum on the server side, so a closed list here. Offered for
     * reference and iteration rather than enforced: an unknown kind reaches
     * `parseKind` and comes back as a 400 that names it, and the kind travels
     * as a PATH SEGMENT on three of these four routes — validating it here
     * would mean this SDK deciding which kinds exist, and going stale the
     * next time a sixth is added.
     */
    public const KINDS = [
        'workspace_invite',
        'trial_ending_reminder',
        'conversation_cap_warning',
        'hired_agent_engagement_invite',
        'hired_agent_engagement_started',
    ];

    /**
     * `GET /v1/email-templates` — all five kinds, with this workspace's
     * override where there is one.
     *
     * **A bare array of the CATALOGUE, not of the rows.** All five kinds come
     * back whether or not the workspace has customised any, and `override` is
     * `null` on the ones still using the built-in copy. So the length is
     * always five, and the thing to read is `override`.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/email-templates');
    }

    /**
     * `POST /v1/email-templates/preview` — render without saving.
     *
     * Answers the rendered subject, HTML and text, plus **any tokens you
     * wrote that this kind does not supply** — which is the field worth
     * reading. The renderer deliberately leaves an unknown token visible
     * rather than erasing it, and this list is what lets an editor warn about
     * a typo before it is ever saved and sent.
     *
     * Saves nothing, and answers 201 even so: Nest answers a POST with 201
     * absent an explicit code, and the status is part of the contract.
     *
     * @param string $kind    One of {@see KINDS}.
     * @param string $subject Unsaved subject. May be empty here — the preview
     *                        schema has no minimum, unlike the write.
     * @param string $body    Unsaved body.
     */
    public function preview(string $kind, string $subject, string $body): mixed
    {
        return $this->httpPost(
            '/v1/email-templates/preview',
            ['kind' => $kind, 'subject' => $subject, 'body' => $body],
        );
    }

    /**
     * `PUT /v1/email-templates/{kind}` — replace this workspace's copy.
     *
     * PUT, and both fields are required: a template is a subject AND a body,
     * and there is no patching half of one. Both are trimmed before the
     * length check, so a body of spaces is refused rather than stored and
     * sent. The request is strict — a misspelled field is a 400 rather than a
     * silently dropped edit.
     *
     * Answers the kind as stored, in the same shape as one element of
     * {@see list()}.
     *
     * @param string $kind One of {@see KINDS}; anything else is a 400 naming it.
     */
    public function update(string $kind, string $subject, string $body): mixed
    {
        return $this->httpPut(
            '/v1/email-templates/' . self::seg($kind, 'kind'),
            ['subject' => $subject, 'body' => $body],
        );
    }

    /**
     * `DELETE /v1/email-templates/{kind}` — back to the built-in copy.
     *
     * ─── IT DELETES THE OVERRIDE; IT DOES NOT COPY TODAY'S DEFAULT ────────
     *
     * So the workspace follows the built-in copy from here on, including
     * changes we make to it later. That is the difference between restoring
     * and resetting, and it is the behaviour you want.
     *
     * **Has a body, not a 204.** `{ restored: false }` with a 200 means the
     * workspace was already on the built-in — idempotent, and that flag is
     * the only way to tell "I removed your override" from "there was nothing
     * to remove". It does not return the restored template; read
     * {@see list()} for that.
     *
     * @param string $kind One of {@see KINDS}; anything else is a 400 naming it.
     */
    public function delete(string $kind): mixed
    {
        return $this->httpDelete('/v1/email-templates/' . self::seg($kind, 'kind'));
    }
}
