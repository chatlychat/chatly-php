<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Workspace configuration and roster.
 *
 * `$chatly->workspace->…`
 *
 * One namespace, five routes that do not share a URL prefix — `settings`
 * hangs off `/v1/workspace`, the rest are top-level collections. They are
 * grouped because they answer one question: how is this workspace set up?
 */
final class Workspace extends AbstractResource
{
    /** `GET /v1/workspace/settings` — the workspace's settings object. */
    public function settings(): mixed
    {
        return $this->httpGet('/v1/workspace/settings');
    }

    /** `GET /v1/labels` — conversation labels. */
    public function labels(): mixed
    {
        return $this->httpGet('/v1/labels');
    }

    /** `GET /v1/inboxes` — the inboxes conversations are routed into. */
    public function inboxes(): mixed
    {
        return $this->httpGet('/v1/inboxes');
    }

    /**
     * `GET /v1/teams/{id}/members` — one team's roster.
     *
     * This is where a key gets user ids from, including the ones that
     * `assigneeUserId` fields expect. Each row carries `userId` plus the user
     * identity columns and the member's TEAM role (`team_members.role`),
     * which is a different thing from their workspace role. `presence` is
     * absent: it lives on `memberships`, which this join does not touch.
     *
     * You need the team id from somewhere else — a key cannot enumerate
     * teams.
     *
     * @param array<string,mixed> $query `cursor`, `limit`.
     */
    public function teamMembers(string $teamId, array $query = []): mixed
    {
        return $this->client->request(
            'GET',
            '/v1/teams/' . self::seg($teamId, 'teamId') . '/members',
            $query,
        );
    }

    /**
     * `GET /v1/agents/skills` — the workspace's skill taxonomy.
     *
     * The distinct skill labels assigned to anyone, as
     * `['skills' => ['billing', 'german', …]]`. An empty array is a normal
     * first-load state, not an error — nobody has been given a skill yet.
     *
     * This is the taxonomy, not an assignment: which skills one agent holds
     * is `GET /v1/agents/{userId}/skills`, which requires a signed-in person
     * and is therefore not SDK surface.
     */
    public function agentSkills(): mixed
    {
        return $this->httpGet('/v1/agents/skills');
    }

    /**
     * `GET /v1/api-keys/scopes` — every scope a key can be granted.
     *
     * A bare array of `{ value, group }`, where `group` is the part of the
     * name before the colon — so a permissions UI can be grouped from this
     * without a second mapping. Static.
     *
     * The enum is built from the same `API_KEY_SCOPES` constant the guard and
     * the key service validate against, rather than being a copy of it, so
     * this list cannot drift from what a key will actually be allowed to ask
     * for.
     *
     * Pair it with {@see \Chatly\ChatlyClient::whoami()}: that says what THIS
     * key holds, this says what any key could.
     */
    public function keyScopes(): mixed
    {
        return $this->httpGet('/v1/api-keys/scopes');
    }

    /**
     * `GET /v1/workspace/secrets` — a census of every credential the
     * workspace holds.
     *
     * ─── EXISTENCE AND TIMESTAMPS. NEVER A VALUE ──────────────────────────
     *
     * One entry per stored credential across nine tables, and **no entry
     * carries the secret, a prefix of it, a hash of it, or its length.** This
     * is the "what do I need to rotate" endpoint, not a vault read.
     *
     * ─── TWO FIELDS TO READ BEFORE ACTING ON A NULL ───────────────────────
     *
     * `rotatedAtBasis` and `lastUsedBasis` say whether a null timestamp means
     * *"never"* or *"nothing records it"*. Those are very different answers to
     * "has this key ever been used", and treating the second as the first is
     * how a live credential gets revoked.
     *
     * And `truncated` on the envelope is true when at least one source hit
     * its per-source cap: **read it before concluding a credential does not
     * exist.** A census is the one question where a silently short answer is
     * worse than no answer, which is also why this is an object (`{ items,
     * total, truncated }`) rather than the bare array it looks like it should
     * be — and why it has no cursor: nine heterogeneous sources share no sort
     * key, so a page two would be unrelated to page one.
     *
     * Needs `workspace:write` AND `integrations:read`.
     */
    public function secrets(): mixed
    {
        return $this->httpGet('/v1/workspace/secrets');
    }

    /**
     * `GET /v1/workspaces/me` — the workspaces the caller can reach.
     *
     * ─── FOR A KEY THIS IS A LIST OF ONE, AND THAT IS THE POINT ───────────
     *
     * A bare ARRAY — `z.array(workspaceListItemSchema)`, not an object — and
     * it stays an array for a key rather than collapsing to the single row,
     * because the route is the cross-workspace one and a credential scoped to
     * one workspace is simply a short list. So `$ws[0]['slug']`, not
     * `$ws['slug']`.
     *
     * The cheap way to confirm **which tenant a key actually points at**:
     * `id`, `name`, `slug` and `plan` in one call, where
     * {@see \Chatly\ChatlyClient::whoami()} gives you only a workspace id to
     * look up.
     *
     * **`role` is a synthetic `restricted`, not a stored membership role.**
     * Every key gets it, and it is the honest value: what a key may do is its
     * scope set, which `GET /v1/me` answers. Reporting `owner` here because
     * the person who created the key was one would overstate the credential —
     * so do not branch on this field for a key.
     *
     * `@AllowWhenSuspended`, so it answers even when the active workspace is
     * suspended for non-payment. That exemption exists because this is how a
     * caller discovers the OTHER workspaces: without it a user whose active
     * workspace is suspended can switch but cannot see what to switch to,
     * stranded while a healthy workspace sits one click away.
     */
    public function mine(): mixed
    {
        return $this->httpGet('/v1/workspaces/me');
    }
}
