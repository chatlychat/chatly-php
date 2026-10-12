<?php

declare(strict_types=1);

namespace Chatly;

/**
 * A raw HTTP response, as a transport hands it back.
 *
 * Deliberately dumb: status, headers, body bytes. Decoding, error mapping and
 * retry decisions all happen above this, so a custom transport only has to
 * produce one of these.
 */
final class HttpResponse
{
    /**
     * @param int                  $status  HTTP status code.
     * @param array<string,string> $headers Header names LOWERCASED. Repeated
     *                                      headers are joined with `, `,
     *                                      which is what RFC 9110 permits for
     *                                      every header this SDK reads.
     * @param string               $body    Response body, undecoded.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    /** One header by name, case-insensitively. Null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
