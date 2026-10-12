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
}
