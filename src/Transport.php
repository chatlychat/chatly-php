<?php

declare(strict_types=1);

namespace Chatly;

use Chatly\Errors\TransportException;

/**
 * The default transport: curl, and nothing else.
 *
 * `ext-curl` is in `require`, so this works on any PHP install that can run
 * the package. A customer should not inherit our dependency tree to send a
 * message, which is why there is no Guzzle here.
 */
final class Transport implements TransportInterface
{
    /**
     * @param array<int,mixed> $curlOptions Extra `CURLOPT_*` options, applied
     *                                      LAST so they win. The escape hatch
     *                                      for a corporate proxy
     *                                      (`CURLOPT_PROXY`), a private CA
     *                                      (`CURLOPT_CAINFO`) or forcing
     *                                      HTTP/1.1. Nothing in the SDK sets
     *                                      these for you.
     * @param float            $connectTimeout Seconds allowed for the TCP+TLS
     *                                      handshake alone. Separate from the
     *                                      total timeout so a black-holed host
     *                                      fails fast instead of burning the
     *                                      whole budget.
     */
    public function __construct(
        private readonly array $curlOptions = [],
        private readonly float $connectTimeout = 5.0,
    ) {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException(
                'Chatly\\Transport needs ext-curl. Install it, or pass your own '
                . 'Chatly\\TransportInterface (see Chatly\\Psr18Transport) to ChatlyClient.',
            );
        }
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('curl_init() failed');
        }

        /** @var array<string,string> $collected */
        $collected = [];
        $status = 0;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        /*
         * curl adds `Expect: 100-continue` for bodies over 1KB and then waits
         * a second for a response many servers never send. A bulk contact
         * import is exactly that size, so suppress it.
         */
        $headerLines[] = 'Expect:';

        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => $headerLines,
            // Milliseconds, so a sub-second timeout is expressible. The
            // whole-seconds options silently floor 0.5 to 0, which means "no
            // timeout at all".
            \CURLOPT_TIMEOUT_MS => (int) max(1, round($timeout * 1000)),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) max(1, round(min($this->connectTimeout, $timeout) * 1000)),
            /*
             * No redirect following. The API does not redirect; a redirect
             * here would mean something is in front of it, and replaying a
             * bearer token plus an idempotency key to a host we were told to
             * go to by an untrusted hop is how credentials leak.
             */
            \CURLOPT_FOLLOWLOCATION => false,
            // Accept-Encoding: whatever this build of curl can decode.
            \CURLOPT_ENCODING => '',
            \CURLOPT_HEADERFUNCTION => function ($_handle, string $line) use (&$collected, &$status): int {
                $length = \strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return $length;
                }
                if (str_starts_with($trimmed, 'HTTP/')) {
                    /*
                     * A new status line. Reset: a 100-continue or a proxy's
                     * `HTTP/1.1 200 Connection established` precedes the real
                     * response, and keeping their headers would attribute
                     * someone else's `retry-after` to the API.
                     */
                    $collected = [];
                    $parts = explode(' ', $trimmed, 3);
                    $status = isset($parts[1]) ? (int) $parts[1] : 0;

                    return $length;
                }
                $colon = strpos($trimmed, ':');
                if ($colon === false) {
                    return $length;
                }
                $name = strtolower(trim(substr($trimmed, 0, $colon)));
                $value = trim(substr($trimmed, $colon + 1));
                $collected[$name] = isset($collected[$name])
                    ? $collected[$name] . ', ' . $value
                    : $value;

                return $length;
            },
        ];

        if ($body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }

        foreach ($this->curlOptions as $option => $value) {
            $options[$option] = $value;
        }

        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($errno !== 0 || $raw === false) {
            throw new TransportException(
                sprintf('%s %s failed: %s (curl %d)', $method, $url, $error !== '' ? $error : 'unknown error', $errno),
                $errno,
            );
        }

        return new HttpResponse($status, $collected, \is_string($raw) ? $raw : '');
    }
}
