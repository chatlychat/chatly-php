<?php

declare(strict_types=1);

namespace Chatly\Tests;

use Chatly\Errors\TransportException;
use Chatly\HttpResponse;
use Chatly\TransportInterface;

/**
 * The injected transport every test in this suite runs against.
 *
 * No network, no API key, no running API — and no default response. An
 * unqueued request is a `LogicException`, not an empty 200: a fake that
 * invents a response makes the suite agree with whatever the code did instead
 * of with what the server actually sends, and that has shipped bugs in this
 * product before.
 */
final class FakeTransport implements TransportInterface
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float}> */
    public array $requests = [];

    /** @var array<int,HttpResponse|TransportException> */
    private array $queue = [];

    /**
     * Queue a response.
     *
     * @param mixed                $body    An array/scalar is JSON-encoded; a
     *                                      string is sent verbatim, so a test
     *                                      can hand over malformed JSON.
     * @param array<string,string> $headers Header names must be lowercase, as
     *                                      a real transport normalises them.
     */
    public function push(int $status, mixed $body = null, array $headers = []): self
    {
        $encoded = match (true) {
            $body === null => '',
            \is_string($body) => $body,
            default => json_encode($body, \JSON_THROW_ON_ERROR),
        };
        $this->queue[] = new HttpResponse($status, $headers, $encoded);

        return $this;
    }

    /** Queue a connection-level failure. */
    public function pushTransportFailure(string $message = 'connection refused'): self
    {
        $this->queue[] = new TransportException($message, 7);

        return $this;
    }

    /** Queue the same response `$times` times. */
    public function pushRepeated(int $times, int $status, mixed $body = null, array $headers = []): self
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->push($status, $body, $headers);
        }

        return $this;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
    ): HttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
        ];

        if ($this->queue === []) {
            throw new \LogicException(sprintf(
                'FakeTransport: no response queued for request #%d (%s %s). '
                . 'Queue one — a default would make this test pass without evidence.',
                \count($this->requests),
                $method,
                $url,
            ));
        }

        $next = array_shift($this->queue);
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    public function count(): int
    {
        return \count($this->requests);
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float} */
    public function request(int $index = 0): array
    {
        if (!isset($this->requests[$index])) {
            throw new \LogicException(sprintf(
                'FakeTransport: no request #%d was made (%d total)',
                $index,
                \count($this->requests),
            ));
        }

        return $this->requests[$index];
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float} */
    public function last(): array
    {
        return $this->request(\count($this->requests) - 1);
    }

    /** A request header, case-insensitively. Null when absent. */
    public function header(string $name, int $index = 0): ?string
    {
        foreach ($this->request($index)['headers'] as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function url(int $index = 0): string
    {
        return $this->request($index)['url'];
    }

    public function method(int $index = 0): string
    {
        return $this->request($index)['method'];
    }

    public function body(int $index = 0): ?string
    {
        return $this->request($index)['body'];
    }

    /** The path, with no host and no query string. */
    public function path(int $index = 0): string
    {
        return (string) parse_url($this->url($index), \PHP_URL_PATH);
    }

    /** The raw query string, undecoded, so repeated keys stay visible. */
    public function query(int $index = 0): string
    {
        return (string) (parse_url($this->url($index), \PHP_URL_QUERY) ?? '');
    }

    /** @return array<string,mixed>|null */
    public function decodedBody(int $index = 0): ?array
    {
        $body = $this->body($index);
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);

        return \is_array($decoded) ? $decoded : null;
    }

    public function pendingResponses(): int
    {
        return \count($this->queue);
    }
}
