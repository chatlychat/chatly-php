<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * Billing — plans and current usage.
 *
 * `$chatly->billing->…`
 */
final class Billing extends AbstractResource
{
    /** `GET /v1/billing/plans` — the plans available to this workspace. */
    public function plans(): mixed
    {
        return $this->httpGet('/v1/billing/plans');
    }

    /**
     * `GET /v1/billing/usage` — what this workspace has consumed in the
     * current period.
     *
     * The call to make before a bulk import, if you are near a seat or
     * contact limit.
     */
    public function usage(): mixed
    {
        return $this->httpGet('/v1/billing/usage');
    }
}
