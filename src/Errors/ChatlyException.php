<?php

declare(strict_types=1);

namespace Chatly\Errors;

use Chatly\HttpResponse;

/**
 * Base class for every failure this SDK raises.
 *
 * A caller must be able to tell "my key is wrong" from "my key lacks a
 * scope" without parsing a message string, so the branch-worthy cases get
 * their own subclass ({@see AuthenticationException}, {@see PermissionException},
 * {@see NotFoundException}, {@see ConflictException}, {@see ValidationException},
 * {@see RateLimitException}, {@see ServerException}) and a failure to reach
 * the API at all gets {@see TransportException}, which is deliberately NOT an
 * HTTP status.
 *
 * Anything this class does not model is preserved in {@see getBody()}: the
 * raw response text, exactly as received.
 */
class ChatlyException extends \RuntimeException
{
    /**
     * @param int         $status    HTTP status, or 0 when the request never got a response.
     * @param string|null $errorCode The API's `code` field, when the body carried one.
     * @param string|null $requestId The `x-request-id` response header, when present.
     * @param string      $body      The raw response body.
     * @param array<string,string> $headers Response headers, names lowercased.
     */
    public function __construct(
        string $message,
        private readonly int $status = 0,
        ?string $errorCode = null,
        private readonly ?string $requestId = null,
        private readonly string $body = '',
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        /*
         * `Exception::getCode()` is final and cannot be overridden, and
         * `Exception::__construct` types its `$code` parameter as `int` — so
         * the API's string code is written straight onto the inherited
         * (untyped, protected) property. That makes `getCode()` return the
         * API's code, which is what a caller expects of a `code` accessor,
         * at the cost of a `mixed` return type. Use `getErrorCode()` when you
         * want `?string` back from a static analyser.
         */
        $this->code = $errorCode;
    }

    /** HTTP status, or 0 for a {@see TransportException} (no response arrived). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The API's machine-readable `code` — `not_found`, `rate_limited`,
     * `validation_failed`, … — or null when the body carried none.
     *
     * Inherited and final; see the constructor for why it is typed `mixed`.
     * {@see getErrorCode()} is the same value, typed.
     */
    public function getErrorCode(): ?string
    {
        $code = $this->code;

        return \is_string($code) ? $code : null;
    }

    /**
     * The `x-request-id` response header.
     *
     * **Expect null from production today.** The platform's public API
     * reference states it neither reads nor emits `X-Request-Id`; the header
     * is read here because the SDK contract specifies it and because a
     * reverse proxy in front of a self-hosted install may well add one. To
     * correlate a call with your own logs, key on the `Idempotency-Key` you
     * sent instead — {@see \Chatly\ChatlyClient::withIdempotencyKey()}.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** The raw response body, for anything the typed fields lose. */
    public function getBody(): string
    {
        return $this->body;
    }

    /** Response headers, names lowercased. Empty for a transport failure. */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The decoded error payload, or null when the body was not JSON.
     *
     * Unwrapped: the API nests its error under an `error` key
     * (`{"error":{"code":…,"message":…,"details":…}}`), so this returns the
     * INNER object. `details` is where a validation failure puts its per-field
     * issues.
     *
     * @return array<string,mixed>|null
     */
    public function getData(): ?array
    {
        $decoded = json_decode($this->body, true);
        if (!\is_array($decoded)) {
            return null;
        }
        if (isset($decoded['error']) && \is_array($decoded['error'])) {
            return $decoded['error'];
        }

        return $decoded;
    }

    /**
     * Build the right subclass for a non-2xx response.
     *
     * The envelope is `{"error":{"code","message","details"}}` — that nesting
     * is the shape `wireError()` in the platform's own source produces, and a
     * client that read `code` at the top level is a bug this product has
     * shipped before. A flat `{"code","message"}` body is accepted too, so a
     * proxy-generated error page does not cost us the status.
     */
    public static function fromResponse(HttpResponse $response): self
    {
        $data = json_decode($response->body, true);
        $envelope = null;
        if (\is_array($data)) {
            $envelope = isset($data['error']) && \is_array($data['error']) ? $data['error'] : $data;
        }

        $code = null;
        if (\is_array($envelope) && isset($envelope['code']) && \is_string($envelope['code'])) {
            $code = $envelope['code'];
        }

        $message = null;
        if (\is_array($envelope) && isset($envelope['message']) && \is_string($envelope['message'])) {
            $message = $envelope['message'];
        }
        $status = $response->status;
        $message ??= self::fallbackMessage($status);

        $requestId = $response->header('x-request-id');
        $body = $response->body;
        $headers = $response->headers;

        return match (true) {
            $status === 401 => new AuthenticationException($message, $status, $code, $requestId, $body, $headers),
            $status === 403 => new PermissionException($message, $status, $code, $requestId, $body, $headers),
            $status === 404 => new NotFoundException($message, $status, $code, $requestId, $body, $headers),
            $status === 409 => new ConflictException($message, $status, $code, $requestId, $body, $headers),
            /*
             * 400 AND 422. The contract names 422 as the validation status,
             * and this API answers a failed zod parse with 400
             * `validation_failed` (see `app-exception.filter.ts`). Both are
             * "you sent something I cannot accept", both are the same branch
             * for a caller, and neither is retryable.
             */
            $status === 422 || $status === 400 => new ValidationException($message, $status, $code, $requestId, $body, $headers),
            $status === 429 => new RateLimitException($message, $status, $code, $requestId, $body, $headers),
            $status >= 500 => new ServerException($message, $status, $code, $requestId, $body, $headers),
            default => new self($message, $status, $code, $requestId, $body, $headers),
        };
    }

    private static function fallbackMessage(int $status): string
    {
        return match (true) {
            $status === 401 => 'Unauthenticated: the API key was rejected',
            $status === 403 => 'Forbidden: the API key lacks a required scope',
            $status === 404 => 'Not found',
            $status === 409 => 'Conflict',
            $status === 429 => 'Rate limited',
            $status >= 500 => "Server error (HTTP {$status})",
            default => "Request failed (HTTP {$status})",
        };
    }
}
