<?php

declare(strict_types=1);

namespace Chatly;

use Chatly\Errors\TransportException;

/**
 * Optional adapter for an existing PSR-18 HTTP client.
 *
 * Use it when your application already configures one — proxy, retry
 * middleware, request logging — and you want the SDK's calls to go through
 * the same stack.
 *
 * ─── WHY THE CONSTRUCTOR TAKES `object` ───────────────────────────────────
 *
 * `psr/http-client` and `psr/http-factory` are **not** dependencies of this
 * package, only `suggest`ions. Type-hinting `ClientInterface` would make them
 * required and every user of the curl transport would install them for
 * nothing. So the three collaborators are structurally typed and checked at
 * construction instead; you get a clear error immediately rather than a
 * `Call to undefined method` on your first request.
 *
 * ```php
 * $chatly = new ChatlyClient(
 *     apiKey: $key,
 *     transport: new Psr18Transport($psr18Client, $requestFactory, $streamFactory),
 * );
 * ```
 */
final class Psr18Transport implements TransportInterface
{
    /**
     * @param object $client         A PSR-18 `ClientInterface` (needs `sendRequest`).
     * @param object $requestFactory A PSR-17 `RequestFactoryInterface`.
     * @param object $streamFactory  A PSR-17 `StreamFactoryInterface`.
     */
    public function __construct(
        private readonly object $client,
        private readonly object $requestFactory,
        private readonly object $streamFactory,
    ) {
        self::requireMethod($client, 'sendRequest', 'a PSR-18 ClientInterface');
        self::requireMethod($requestFactory, 'createRequest', 'a PSR-17 RequestFactoryInterface');
        self::requireMethod($streamFactory, 'createStream', 'a PSR-17 StreamFactoryInterface');
    }

    /**
     * `$timeout` is ignored here on purpose, and that is worth stating
     * rather than hiding: a PSR-18 client's timeout is its own
     * configuration and the interface has no way to set one per request.
     * Configure it on the client you pass in. The curl transport does
     * honour it.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse {
        try {
            /** @var object $request */
            $request = $this->requestFactory->createRequest($method, $url);
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            if ($body !== null) {
                $request = $request->withBody($this->streamFactory->createStream($body));
            }
            /** @var object $response */
            $response = $this->client->sendRequest($request);
        } catch (\Throwable $e) {
            /*
             * Every PSR-18 failure mode — ClientExceptionInterface,
             * NetworkExceptionInterface, RequestExceptionInterface — plus
             * anything a middleware throws, becomes the SDK's own transport
             * error. Callers catch one type regardless of which transport is
             * in use.
             */
            throw new TransportException(
                sprintf('%s %s failed: %s', $method, $url, $e->getMessage()),
                0,
                $e,
            );
        }

        /** @var array<string,string> $out */
        $out = [];
        /** @var array<string,array<int,string>> $responseHeaders */
        $responseHeaders = $response->getHeaders();
        foreach ($responseHeaders as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', $values);
        }

        return new HttpResponse(
            (int) $response->getStatusCode(),
            $out,
            (string) $response->getBody(),
        );
    }

    private static function requireMethod(object $subject, string $method, string $what): void
    {
        if (!method_exists($subject, $method)) {
            throw new \InvalidArgumentException(sprintf(
                'Psr18Transport expects %s: %s has no %s() method.',
                $what,
                $subject::class,
                $method,
            ));
        }
    }
}
