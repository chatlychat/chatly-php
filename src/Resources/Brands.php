<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Brands — optional sub-tenants inside one workspace.
 *
 * `$chatly->brands->…`
 *
 * A brand carries its own name, logo, colour, support address and
 * help-centre hostname. Most workspaces have one (the default); a
 * multi-brand customer uses these to keep two products' conversations,
 * widgets and help centres apart without two accounts.
 */
final class Brands extends AbstractResource
{
    /**
     * `GET /v1/brands` — every brand, with usage counts.
     *
     * A bare array, `@PaginationExempt` — typically fewer than ten rows. Each
     * carries a `usage` object, which is what tells you whether a brand is
     * safe to retire.
     */
    public function list(): mixed
    {
        return $this->httpGet('/v1/brands');
    }

    /**
     * `POST /v1/brands` — create one.
     *
     * ─── `domain` AND `replyDomain` ARE DIFFERENT HOSTNAMES ───────────────
     *
     * `domain` is the brand's HELP-CENTRE hostname. `replyDomain` is the mail
     * domain notification replies are addressed at —
     * `reply+<conversation>.<sig>@<here>` — and it needs an MX record.
     * They are usually different (`help.acme.com` against `mail.acme.com`),
     * and pointing an MX at a help-centre hostname is the mistake these two
     * fields exist to keep apart.
     *
     * @param array<string,mixed> $brand `name` (required, ≤120) and `slug`
     *                                   (required, lowercase alphanumerics
     *                                   and hyphens, ≤64); `logoUrl`,
     *                                   `primaryColor` (`#RRGGBB`),
     *                                   `supportEmail`, `replyDomain`,
     *                                   `icons`.
     */
    public function create(array $brand): mixed
    {
        return $this->httpPost('/v1/brands', $brand);
    }

    /**
     * `PATCH /v1/brands/{id}` — partial update.
     *
     * The request schema is the create schema made partial, so every field
     * above is patchable — including `slug`, which appears in help-centre
     * URLs. Changing it does not leave a redirect behind.
     *
     * @param array<string,mixed> $changes
     */
    public function update(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/brands/' . self::seg($id), $changes);
    }

    /**
     * `DELETE /v1/brands/{id}` — SOFT delete.
     *
     * Answers **200 with `{ id }`**, not a 204 and not the brand. It sets
     * `deletedAt` rather than removing the row, so conversations, widgets and
     * help-centre pages that reference the brand keep resolving — which is
     * the point, and also why the id is worth having back: it is the only
     * thing in the response.
     */
    public function delete(string $id): mixed
    {
        return $this->httpDelete('/v1/brands/' . self::seg($id));
    }
}
