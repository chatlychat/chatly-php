<?php

declare(strict_types=1);

namespace Chatly\Errors;

/**
 * The request never produced an HTTP response: DNS failure, refused
 * connection, TLS failure, timeout.
 *
 * Its own type, and deliberately not an HTTP status — `getStatus()` is 0.
 * "The API said no" and "I could not reach the API" need different handling,
 * and conflating them is how a DNS typo gets reported to a customer as a
 * server error.
 *
 * Retried by default (a connection error is safe to retry, and a retried
 * write reuses its original `Idempotency-Key`).
 */
final class TransportException extends ChatlyException
{
    /**
     * The underlying curl error number, when curl was the transport.
     *
     * 0 when it was not (a PSR-18 client, or an injected transport in a test).
     */
    public function getTransportErrorCode(): int
    {
        return $this->transportErrorCode;
    }

    public function __construct(
        string $message,
        private readonly int $transportErrorCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, 'transport_error', null, '', [], $previous);
    }
}
