<?php

declare(strict_types=1);

namespace Chatly;

use Chatly\Errors\TransportException;

/**
 * The seam between this SDK and the network.
 *
 * Implement it to run the SDK's whole surface against a fake — that is how
 * this package's own suite runs with no network, no API key and no API. The
 * default is {@see Transport} (curl); {@see Psr18Transport} adapts a PSR-18
 * client if you already have one configured.
 */
interface TransportInterface
{
    /**
     * Perform one HTTP request. No retrying here — the client owns that.
     *
     * @param string               $method  Uppercase HTTP verb.
     * @param string               $url     Absolute URL, query string included.
     * @param array<string,string> $headers Header name => value.
     * @param string|null          $body    Request body, already serialised.
     * @param float                $timeout Total seconds allowed for the call.
     *
     * @throws TransportException When no response could be obtained. An HTTP
     *                            error status is NOT a transport failure:
     *                            return it and let the client map it.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse;
}
