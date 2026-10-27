<?php

declare(strict_types=1);

namespace GenesisDB;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Client for GenesisDB.
 *
 *     $db = new Client(url: 'http://localhost:8080', token: getenv('GENESISDB_AUTH_TOKEN'));
 *     $db->commit([new NewEvent('app', '/customer/1', 'customer.created', ['name' => 'Bruce'])]);
 *     $events = $db->fetch('/customer');
 *
 * `url` and `token` fall back to GENESISDB_URL and GENESISDB_AUTH_TOKEN. Every method throws
 * GenesisDBException (or a subclass) on a non-2xx answer.
 */
final class Client
{
    public const USER_AGENT = 'genesisdb-php/2';
    private const HEARTBEAT = '{"payload":""}';

    private readonly string $url;
    private readonly string $token;
    private readonly ClientInterface $http;

    /**
     * @param string|null $url base URL, for example http://localhost:8080. Falls back to GENESISDB_URL
     * @param string|null $token bearer token. Falls back to GENESISDB_AUTH_TOKEN
     * @param float $timeout seconds, for non-streaming calls. 0 disables. Subscriptions never time out
     * @param ClientInterface|null $http bring your own Guzzle client (proxies, TLS, handler stack)
     */
    public function __construct(?string $url = null, ?string $token = null, float $timeout = 30.0, ?ClientInterface $http = null)
    {
        $url = $url ?: (getenv('GENESISDB_URL') ?: null);
        $token = $token ?: (getenv('GENESISDB_AUTH_TOKEN') ?: null);
        if ($url === null) {
            throw new \InvalidArgumentException('GenesisDB URL missing: pass url or set GENESISDB_URL');
        }
        if ($token === null) {
            throw new \InvalidArgumentException('GenesisDB token missing: pass token or set GENESISDB_AUTH_TOKEN');
        }
        $this->url = rtrim($url, '/');
        $this->token = $token;
        $this->http = $http ?? new HttpClient([RequestOptions::TIMEOUT => $timeout, RequestOptions::HTTP_ERRORS => false]);
    }

    // Health and status

    /** Liveness check. Needs no token on the server. Returns "Pong". */
    public function ping(): string
    {
        return $this->text('ping', 'GET', '/api/v2/ping');
    }

    /** Engine version, host, event counts, storage format and readiness. */
    public function status(): Status
    {
        return Status::fromArray($this->json('status', 'GET', '/api/v2/status'));
    }

    /** Verify the hash chain of the whole store. Administrator token. Returns the audit text. */
    public function audit(): string
    {
        return $this->text('audit', 'GET', '/api/v2/admin/audit');
    }

    // Events

    /**
     * Commit events atomically after checking the preconditions. Returns the stored events.
     * A failed precondition throws PreconditionFailedException and writes nothing.
     *
     * @param list<NewEvent> $events
     * @param list<Precondition> $preconditions
     * @return list<Event>
     */
    public function commit(array $events, array $preconditions = []): array
    {
        if ($events === []) {
            throw new \InvalidArgumentException('commit requires at least one event');
        }
        $body = ['events' => array_values($events)];
        if ($preconditions !== []) {
            $body['preconditions'] = array_values($preconditions);
        }
        return array_map(Event::fromArray(...), $this->ndjson('commit', 'POST', '/api/v2/commit', $body));
    }

    /**
     * Fetch the events of a subject and all of its descendants, in commit order.
     *
     * @return list<Event>
     */
    public function fetch(string $subject, ?FetchOptions $options = null): array
    {
        return array_map(Event::fromArray(...), $this->ndjson('fetch', 'POST', '/api/v2/fetch', self::fetchBody($subject, $options)));
    }

    /**
     * Subscribe to a subject and its descendants. Yields the history first, then new events as they are committed.
     * Iterates until the caller stops (break out of the loop) or the server ends the stream. A stream the server
     * ends with an error throws GenesisDBException.
     *
     * @param float|null $interval poll interval in seconds. Null uses the server default of one second
     * @return \Generator<int, Event>
     */
    public function subscribe(string $subject, ?float $interval = null, ?FetchOptions $options = null): \Generator
    {
        $body = self::fetchBody($subject, $options);
        if ($interval !== null) {
            $body['interval'] = $interval;
        }
        $response = $this->send('subscribe', 'POST', '/api/v2/subscribe', $body, [RequestOptions::STREAM => true, RequestOptions::TIMEOUT => 0, RequestOptions::READ_TIMEOUT => 0]);
        $stream = $response->getBody();
        if ($response->getStatusCode() !== 200) {
            throw GenesisDBException::forResponse('subscribe', $response->getStatusCode(), (string) $stream);
        }
        try {
            $eventName = '';
            foreach (self::lines($stream) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $eventName = trim(substr($line, 7));
                    continue;
                }
                if (!str_starts_with($line, 'data: ')) {
                    $eventName = '';
                    continue;
                }
                $payload = substr($line, 6);
                if ($eventName === 'error') {
                    $parsed = json_decode($payload, true);
                    throw new GenesisDBException('subscribe ended', 200, is_array($parsed) && isset($parsed['error']) ? (string) $parsed['error'] : $payload);
                }
                $eventName = '';
                if (trim($payload) === self::HEARTBEAT) {
                    continue;
                }
                yield Event::fromArray(self::decode('subscribe', $payload));
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * Run a GDBQL query. Returns the result rows, or a single-element list for a bare aggregate.
     *
     * @return list<mixed>
     */
    public function query(string $query): array
    {
        return $this->ndjson('query', 'POST', '/api/v2/query', ['query' => $query]);
    }

    /**
     * Distinct top level subjects, sorted.
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return array_map(fn (array $row) => (string) $row['subject'], $this->ndjson('subjects', 'GET', '/api/v2/subjects'));
    }

    /**
     * Distinct event types, sorted.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(fn (array $row) => (string) $row['type'], $this->ndjson('types', 'GET', '/api/v2/types'));
    }

    // Schemas

    /**
     * Register a schema for an event type. Throws ConflictException when the type already has one. Administrator token.
     *
     * @param array<string, mixed> $schema
     */
    public function registerSchema(string $eventType, array $schema): void
    {
        $this->send('register_schema', 'POST', '/api/v2/schemas', ['type' => $eventType, 'schema' => $schema === [] ? new \stdClass() : $schema]);
    }

    /**
     * Every registered schema.
     *
     * @return list<RegisteredSchema>
     */
    public function schemas(): array
    {
        return array_map(fn (array $row) => new RegisteredSchema((string) $row['type'], (array) $row['schema']), $this->ndjson('schemas', 'GET', '/api/v2/schemas'));
    }

    // Administration

    /**
     * Erase every reference payload below a subject. Inline event data is immutable and stays. Throws
     * NotFoundException when the subject has no events, UnprocessableException when it has no reference payloads.
     * Administrator token. Returns the server's confirmation text.
     */
    public function erase(string $subject): string
    {
        return $this->text('erase', 'POST', '/api/v2/admin/erase', ['subject' => $subject]);
    }

    // Transport

    /** @return array<string, mixed> */
    private static function fetchBody(string $subject, ?FetchOptions $options): array
    {
        $body = ['subject' => $subject];
        if ($options !== null && !$options->isEmpty()) {
            $body['options'] = $options;
        }
        return $body;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $extra
     */
    private function send(string $operation, string $method, string $path, ?array $body = null, array $extra = []): ResponseInterface
    {
        $options = $extra + [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $this->token, 'User-Agent' => self::USER_AGENT],
            RequestOptions::HTTP_ERRORS => false,
        ];
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }
        try {
            $response = $this->http->request($method, $this->url . $path, $options);
        } catch (GuzzleException $e) {
            throw new GenesisDBException($operation, 0, $e->getMessage(), $e);
        }
        $status = $response->getStatusCode();
        if (($status < 200 || $status > 299) && !($extra[RequestOptions::STREAM] ?? false)) {
            throw GenesisDBException::forResponse($operation, $status, (string) $response->getBody());
        }
        return $response;
    }

    /** @param array<string, mixed>|null $body */
    private function text(string $operation, string $method, string $path, ?array $body = null): string
    {
        return (string) $this->send($operation, $method, $path, $body)->getBody();
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function json(string $operation, string $method, string $path, ?array $body = null): array
    {
        return self::decode($operation, $this->text($operation, $method, $path, $body));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return list<mixed>
     */
    private function ndjson(string $operation, string $method, string $path, ?array $body = null): array
    {
        $rows = [];
        foreach (explode("\n", $this->text($operation, $method, $path, $body)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        return $rows;
    }

    /** @return array<mixed> */
    private static function decode(string $operation, string $text): array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GenesisDBException($operation, 0, 'invalid JSON in response: ' . $e->getMessage(), $e);
        }
        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /** @return \Generator<int, string> */
    private static function lines(StreamInterface $stream): \Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }
                usleep(10_000);
                continue;
            }
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                yield rtrim(substr($buffer, 0, $newline), "\r");
                $buffer = substr($buffer, $newline + 1);
            }
        }
        if ($buffer !== '') {
            yield rtrim($buffer, "\r");
        }
    }
}
