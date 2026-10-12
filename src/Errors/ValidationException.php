<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * HTTP 422 or 400 — the request body or query did not validate.
 *
 * This API answers a failed schema parse with **400** and the code
 * `validation_failed`; the SDK contract names 422. Both land here, because
 * they are one branch for a caller and neither is retryable.
 *
 * `getData()['details']` carries the per-field issues when the API sent them.
 */
final class ValidationException extends ChatlyException
{
    /**
     * Per-field validation issues, when the API included them.
     *
     * The platform's zod failures arrive as
     * `{"error":{"code":"validation_failed","details":{"issues":[…]}}}`.
     * Returns null when the body carried no `details` object — do not treat
     * an empty result as "the request was fine".
     *
     * @return array<string,mixed>|null
     */
    public function getDetails(): ?array
    {
        $data = $this->getData();
        if ($data === null || !isset($data['details']) || !\is_array($data['details'])) {
            return null;
        }

        return $data['details'];
    }
}
